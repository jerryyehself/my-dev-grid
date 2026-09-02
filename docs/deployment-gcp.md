# Deploying to GCP (Cloud Run + Cloud SQL)

Backend deploy target: Cloud Run (managed) + Cloud SQL for PostgreSQL, region
`asia-east1`. Tracks issue [#10](https://github.com/jerryyehself/my-dev-grid/issues/10).

What's already done in this repo (no action needed):

- `Dockerfile` — multi-stage build (Node for frontend assets, PHP for the
  app), runs nginx + php-fpm + supervisord, reads `$PORT` at startup.
- `docker/` — nginx config template, supervisord config, entrypoint script.
- `.env.production.example` — reference for every env var/secret the Cloud
  Run service needs (not read by the app; documentation only).
- `.github/workflows/deploy-cloud-run.yml` — build → push → migrate →
  deploy, authenticated via Workload Identity Federation.

Everything below this line is GCP console/CLI work only **you** can do —
it needs your own GCP billing account and IAM permissions, so none of it was
done automatically. Steps assume the `gcloud` CLI, logged in
(`gcloud auth login`) with an active billing account. Replace
`PROJECT_ID` with whatever project ID you choose throughout.

## 1. Create the project and enable APIs

```bash
gcloud projects create PROJECT_ID
gcloud config set project PROJECT_ID
gcloud billing projects link PROJECT_ID --billing-account=BILLING_ACCOUNT_ID

gcloud services enable \
  run.googleapis.com \
  sqladmin.googleapis.com \
  artifactregistry.googleapis.com \
  secretmanager.googleapis.com \
  iamcredentials.googleapis.com \
  cloudresourcemanager.googleapis.com
```

## 2. Artifact Registry

```bash
gcloud artifacts repositories create my-dev-grid \
  --repository-format=docker \
  --location=asia-east1 \
  --description="my-dev-grid container images"
```

This is `GCP_ARTIFACT_REGISTRY_REPO` below.

## 3. Cloud SQL for PostgreSQL (minimal spec)

```bash
gcloud sql instances create my-dev-grid-db \
  --database-version=POSTGRES_16 \
  --tier=db-f1-micro \
  --region=asia-east1 \
  --storage-size=10GB \
  --storage-auto-increase \
  --no-backup   # drop this flag once you want automated backups

gcloud sql databases create my_dev_grid --instance=my-dev-grid-db

gcloud sql users create my_dev_grid_app \
  --instance=my-dev-grid-db \
  --password="CHOOSE_A_STRONG_PASSWORD"
```

Note the instance connection name from `gcloud sql instances describe
my-dev-grid-db --format='value(connectionName)'` — it looks like
`PROJECT_ID:asia-east1:my-dev-grid-db`. That's `CLOUD_SQL_CONNECTION_NAME`
below. `db-f1-micro` is Cloud SQL's smallest tier; resize later with
`gcloud sql instances patch` if the app outgrows it.

## 4. Secret Manager

Three secrets, matching what the Dockerfile/workflow reference:

```bash
# APP_KEY: generate locally, don't reuse any key from a real .env
php artisan key:generate --show   # copy the "base64:..." output

printf '%s' 'base64:...'                | gcloud secrets create APP_KEY       --data-file=-
printf '%s' 'CHOOSE_A_STRONG_PASSWORD'  | gcloud secrets create DB_PASSWORD   --data-file=- # same password as step 3
printf '%s' 'ghp_...'                   | gcloud secrets create GITHUB_TOKEN  --data-file=- # a GitHub token with read access for GitService's API calls
```

Check `.env.example` if the app grows more required secrets later — anything
that's currently a blank/sensitive value there (not `AWS_*`, which stays
unused/blank) should get the same treatment.

## 5. Runtime service account (what Cloud Run runs *as*)

This is **not** the account GitHub Actions uses to deploy — it's the identity
the running container itself has, so it can reach Secret Manager and Cloud
SQL.

```bash
gcloud iam service-accounts create my-dev-grid-run \
  --display-name="my-dev-grid Cloud Run runtime"

gcloud projects add-iam-policy-binding PROJECT_ID \
  --member="serviceAccount:my-dev-grid-run@PROJECT_ID.iam.gserviceaccount.com" \
  --role="roles/secretmanager.secretAccessor"

gcloud projects add-iam-policy-binding PROJECT_ID \
  --member="serviceAccount:my-dev-grid-run@PROJECT_ID.iam.gserviceaccount.com" \
  --role="roles/cloudsql.client"
```

Email is `CLOUD_RUN_SERVICE_ACCOUNT` below.

## 6. Deployer service account + Workload Identity Federation

This is the identity GitHub Actions impersonates to build/push/deploy — no
downloaded JSON key involved.

```bash
gcloud iam service-accounts create my-dev-grid-deployer \
  --display-name="my-dev-grid GitHub Actions deployer"

DEPLOYER="my-dev-grid-deployer@PROJECT_ID.iam.gserviceaccount.com"

for ROLE in roles/artifactregistry.writer roles/run.admin roles/run.developer roles/iam.serviceAccountUser; do
  gcloud projects add-iam-policy-binding PROJECT_ID \
    --member="serviceAccount:${DEPLOYER}" \
    --role="${ROLE}"
done

# Workload Identity Pool + Provider trusting GitHub's OIDC tokens
gcloud iam workload-identity-pools create github-pool \
  --location=global \
  --display-name="GitHub Actions pool"

gcloud iam workload-identity-pools providers create-oidc github-provider \
  --location=global \
  --workload-identity-pool=github-pool \
  --display-name="GitHub OIDC provider" \
  --attribute-mapping="google.subject=assertion.sub,attribute.repository=assertion.repository" \
  --attribute-condition="assertion.repository=='jerryyehself/my-dev-grid'" \
  --issuer-uri="https://token.actions.githubusercontent.com"

# Allow only workflows running in this repo to impersonate the deployer SA
gcloud iam service-accounts add-iam-policy-binding "${DEPLOYER}" \
  --role="roles/iam.workloadIdentityUser" \
  --member="principalSet://iam.googleapis.com/projects/PROJECT_NUMBER/locations/global/workloadIdentityPools/github-pool/attribute.repository/jerryyehself/my-dev-grid"
```

`PROJECT_NUMBER` (not the project *ID*) comes from `gcloud projects describe
PROJECT_ID --format='value(projectNumber)'`.

The full provider resource name for `GCP_WORKLOAD_IDENTITY_PROVIDER` is:

```
projects/PROJECT_NUMBER/locations/global/workloadIdentityPools/github-pool/providers/github-provider
```

## 7. GitHub repository variables

Repo → Settings → Secrets and variables → Actions → **Variables** tab (not
Secrets — none of these are sensitive on their own, and no GitHub secret is
needed at all since WIF is keyless and the actual app secrets stay in Secret
Manager):

| Variable | Value |
|---|---|
| `GCP_PROJECT_ID` | `PROJECT_ID` |
| `GCP_REGION` | `asia-east1` |
| `GCP_WORKLOAD_IDENTITY_PROVIDER` | full provider name from step 6 |
| `GCP_DEPLOYER_SERVICE_ACCOUNT` | `my-dev-grid-deployer@PROJECT_ID.iam.gserviceaccount.com` |
| `GCP_ARTIFACT_REGISTRY_REPO` | `my-dev-grid` |
| `CLOUD_RUN_SERVICE` | `my-dev-grid-api` (or whatever name you prefer) |
| `CLOUD_RUN_SERVICE_ACCOUNT` | `my-dev-grid-run@PROJECT_ID.iam.gserviceaccount.com` |
| `CLOUD_SQL_CONNECTION_NAME` | `PROJECT_ID:asia-east1:my-dev-grid-db` |
| `DB_DATABASE` | `my_dev_grid` |
| `DB_USERNAME` | `my_dev_grid_app` |
| `CLOUD_RUN_MIGRATE_JOB` | `my-dev-grid-migrate` (optional but recommended — see below) |

`.github/workflows/deploy-cloud-run.yml` documents these same variables at
the top of the file.

If this repo also uses a GitHub **environment** named `production` for
protection rules, add the variables there instead (the workflow targets
`environment: production`); otherwise create that environment (Settings →
Environments) or remove the `environment:` line from the workflow.

## 8. First deploy

Push to `main`, or run the workflow manually (Actions tab → "Deploy to Cloud
Run" → Run workflow). It will:

1. Build the image from `Dockerfile` and push it to Artifact Registry.
2. Deploy/update a Cloud Run Job (`CLOUD_RUN_MIGRATE_JOB`) with this image
   and run `php artisan migrate --force` via `--wait`, so the schema exists
   before the new revision serves traffic. Leave `CLOUD_RUN_MIGRATE_JOB`
   unset to skip this and run migrations yourself instead.
3. Deploy the Cloud Run service with `--allow-unauthenticated` (this app has
   no auth in front of it today — restrict with `gcloud run services
   remove-iam-policy-binding ... --member=allUsers --role=roles/run.invoker`
   later if that changes).

After the first successful deploy, get the service URL:

```bash
gcloud run services describe "$CLOUD_RUN_SERVICE" --region=asia-east1 --format='value(status.url)'
```

Set `APP_URL` to that value — either add it to the `env_vars` block in the
workflow and redeploy, or set it directly:

```bash
gcloud run services update "$CLOUD_RUN_SERVICE" --region=asia-east1 \
  --set-env-vars="APP_URL=https://your-service-url"
```

## 9. Sanity checks

- `curl https://your-service-url/up` should return the framework's built-in
  health check (200 OK) — confirms nginx, php-fpm, and the app booted.
- `gcloud run services logs read "$CLOUD_RUN_SERVICE" --region=asia-east1`
  (or Cloud Logging) if it doesn't; `clear_env` misconfiguration or a bad
  `DB_HOST` typically shows up immediately here.
- Hit an API route, e.g. `/api/scopes`, to confirm the Postgres connection
  actually works end-to-end (not just that the container started).

## Notes / things to revisit later

- **Local dev is unaffected.** `.env.example` still defaults to
  `DB_CONNECTION=sqlite`; nothing here changes how `php artisan serve` /
  `composer run dev` work locally.
- **Storage is ephemeral.** The container's filesystem resets on every new
  revision/instance. Nothing in this app currently writes to local disk at
  runtime (no `Storage::` calls in `app/`), so this is safe today — if that
  changes (file uploads, etc.), switch `FILESYSTEM_DISK` to a GCS-backed
  disk rather than `local`.
- **Cost control.** `--min-instances=0` in the workflow means the service
  scales to zero (cheapest, but cold starts) and `db-f1-micro` is the
  smallest Cloud SQL tier. Both are easy to size up later
  (`gcloud run services update` / `gcloud sql instances patch`) once real
  traffic shows up.
- **Secret rotation.** `gcloud secrets versions add APP_KEY --data-file=-`
  (etc.) adds a new version; the workflow always references `:latest`, so
  the next deploy picks it up automatically — no workflow change needed.
