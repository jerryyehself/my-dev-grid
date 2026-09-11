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

**Shortcut**: `scripts/gcp-setup.sh` runs steps 1-6 below in one shot (it's a
literal wrapper around the same `gcloud` commands, not a different tool) and,
if the `gh` CLI is installed and authenticated, pushes the resulting values
straight into this repo's GitHub Actions Variables too:

```bash
PROJECT_ID=your-project-id BILLING_ACCOUNT_ID=XXXXXX-XXXXXX-XXXXXX \
  ./scripts/gcp-setup.sh
```

It's a run-once script, not idempotent infrastructure-as-code — re-running it
against the same `PROJECT_ID` fails on "already exists" for whatever it
already created. Steps 7 (the four values only a human decision or a real
OAuth app can supply) and 8 (the first deploy itself) still need doing by
hand either way; read on for what those are and why each one can't be
scripted.

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

**Login UI has landed (PR #33-#35) — these two are now wired into the
workflow**, same treatment as `APP_KEY`/`DB_PASSWORD`/`GITHUB_TOKEN` above:

```bash
printf '%s' 'GOCSPX-...' | gcloud secrets create GOOGLE_CLIENT_SECRET --data-file=-
printf '%s' '...'        | gcloud secrets create LINE_CLIENT_SECRET   --data-file=-
```

The other 5 (`GOOGLE_CLIENT_ID`, `GOOGLE_REDIRECT_URI`, `LINE_CLIENT_ID`,
`LINE_REDIRECT_URI`, `SANCTUM_STATEFUL_DOMAINS`) aren't sensitive — same
treatment as `DB_DATABASE`/`DB_USERNAME` below, plain repository
**variables**, no Secret Manager entry needed. All 7 are now wired into
`.github/workflows/deploy-cloud-run.yml`'s `env_vars`/`secrets` blocks — the
workflow just won't do anything with them until the corresponding repo
variables/secrets actually exist (steps 4/7 below), since real values still
need a real Google Cloud Console / LINE Developers OAuth app, which is a
step only you can do. **`--allow-unauthenticated` itself was never part of
this** — see the correction in step 8 below; it's an IAM-level public-access
switch, orthogonal to the Sanctum session auth this login work adds at the
application layer.

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
| `SANCTUM_STATEFUL_DOMAINS` | the production host serving Triple 後台（例如 `my-dev-grid-api-xxxxx.a.run.app`，或之後接自訂網域就換成那個） |
| `GOOGLE_CLIENT_ID` | from the Google OAuth app you create in Google Cloud Console |
| `GOOGLE_REDIRECT_URI` | e.g. `https://your-service-url/auth/google/callback` |
| `LINE_CLIENT_ID` | from the LINE Login channel you create in LINE Developers |
| `LINE_REDIRECT_URI` | e.g. `https://your-service-url/auth/line/callback` |
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
3. Deploy the Cloud Run service with `--allow-unauthenticated`. **This stays
   on even after login ships** (corrected 2026-09-08 — an earlier version of
   this doc implied it would be dropped once the app had auth "in front of
   it"). It's an IAM-level switch controlling whether Cloud Run accepts
   requests at all; the public read-only site (`my-dev-grid-front`) and the
   login page itself both need unauthenticated requests to reach the
   service. Removing it would firewall off the whole public site, not just
   protect write endpoints. Access control for writes is handled at the
   application layer instead (Sanctum session auth + Policies, `PR #32`) —
   that's the correct and permanent place for it, not a Cloud Run IAM
   binding.

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
