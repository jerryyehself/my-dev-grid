#!/usr/bin/env bash
#
# One-shot setup for everything in docs/deployment-gcp.md steps 1-6 (project,
# APIs, Artifact Registry, Cloud SQL, Secret Manager, the two service
# accounts + Workload Identity Federation), then (if `gh` is installed and
# authenticated) pushes the resulting values straight into the GitHub repo's
# Actions Variables — closing the loop from "gcloud commands typed by hand"
# to "one script run".
#
# This is a run-once setup script, not idempotent infrastructure-as-code —
# re-running it against the same PROJECT_ID will fail on "already exists"
# for whichever resources it already created. If you need to change
# something afterward, use the specific `gcloud ... update`/`patch` command
# for that resource instead of re-running this whole script.
#
# Usage:
#   PROJECT_ID=my-dev-grid-prod BILLING_ACCOUNT_ID=XXXXXX-XXXXXX-XXXXXX \
#     ./scripts/gcp-setup.sh
#
# Required env vars:
#   PROJECT_ID           globally unique GCP project id you're choosing
#   BILLING_ACCOUNT_ID   from `gcloud billing accounts list`
# Optional env vars:
#   REGION               default asia-east1 (matches the rest of this repo)
#   GITHUB_REPO          default jerryyehself/my-dev-grid — set to "" to skip
#                        the `gh variable set` step entirely
#   DB_PASSWORD          if unset, you'll be prompted (input hidden)

set -euo pipefail

: "${PROJECT_ID:?Set PROJECT_ID, e.g. PROJECT_ID=my-dev-grid-prod}"
: "${BILLING_ACCOUNT_ID:?Set BILLING_ACCOUNT_ID — find yours with: gcloud billing accounts list}"
REGION="${REGION:-asia-east1}"
GITHUB_REPO="${GITHUB_REPO-jerryyehself/my-dev-grid}"
SQL_INSTANCE="my-dev-grid-db"
ARTIFACT_REPO="my-dev-grid"
RUNTIME_SA="my-dev-grid-run"
DEPLOYER_SA="my-dev-grid-deployer"
WIF_POOL="github-pool"
WIF_PROVIDER="github-provider"

if [ -z "${DB_PASSWORD:-}" ]; then
  read -rsp "Choose a strong DB_PASSWORD for my_dev_grid_app: " DB_PASSWORD
  echo
fi

echo "==> 1. Project + APIs"
gcloud projects create "$PROJECT_ID"
gcloud config set project "$PROJECT_ID"
gcloud billing projects link "$PROJECT_ID" --billing-account="$BILLING_ACCOUNT_ID"
gcloud services enable \
  run.googleapis.com \
  sqladmin.googleapis.com \
  artifactregistry.googleapis.com \
  secretmanager.googleapis.com \
  iamcredentials.googleapis.com \
  cloudresourcemanager.googleapis.com

echo "==> 2. Artifact Registry"
gcloud artifacts repositories create "$ARTIFACT_REPO" \
  --repository-format=docker \
  --location="$REGION" \
  --description="my-dev-grid container images"

echo "==> 3. Cloud SQL for PostgreSQL (db-f1-micro, no automated backups yet — see docs/deployment-gcp.md notes)"
gcloud sql instances create "$SQL_INSTANCE" \
  --database-version=POSTGRES_16 \
  --tier=db-f1-micro \
  --region="$REGION" \
  --storage-size=10GB \
  --storage-auto-increase \
  --no-backup

gcloud sql databases create my_dev_grid --instance="$SQL_INSTANCE"
gcloud sql users create my_dev_grid_app \
  --instance="$SQL_INSTANCE" \
  --password="$DB_PASSWORD"

CLOUD_SQL_CONNECTION_NAME=$(gcloud sql instances describe "$SQL_INSTANCE" --format='value(connectionName)')

echo "==> 4. Secret Manager (APP_KEY generated here — not reused from any local .env)"
APP_KEY="base64:$(openssl rand -base64 32)"
printf '%s' "$APP_KEY"    | gcloud secrets create APP_KEY     --data-file=-
printf '%s' "$DB_PASSWORD" | gcloud secrets create DB_PASSWORD --data-file=-
echo "NOTE: GITHUB_TOKEN, GOOGLE_CLIENT_SECRET, LINE_CLIENT_SECRET are not created here —"
echo "      paste them in yourself once you have real values:"
echo "      printf '%s' 'ghp_...'  | gcloud secrets create GITHUB_TOKEN         --data-file=-"
echo "      printf '%s' 'GOCSPX-...' | gcloud secrets create GOOGLE_CLIENT_SECRET --data-file=-"
echo "      printf '%s' '...'      | gcloud secrets create LINE_CLIENT_SECRET   --data-file=-"

echo "==> 5. Runtime service account"
gcloud iam service-accounts create "$RUNTIME_SA" \
  --display-name="my-dev-grid Cloud Run runtime"

gcloud projects add-iam-policy-binding "$PROJECT_ID" \
  --member="serviceAccount:${RUNTIME_SA}@${PROJECT_ID}.iam.gserviceaccount.com" \
  --role="roles/secretmanager.secretAccessor" >/dev/null

gcloud projects add-iam-policy-binding "$PROJECT_ID" \
  --member="serviceAccount:${RUNTIME_SA}@${PROJECT_ID}.iam.gserviceaccount.com" \
  --role="roles/cloudsql.client" >/dev/null

echo "==> 6. Deployer service account + Workload Identity Federation"
gcloud iam service-accounts create "$DEPLOYER_SA" \
  --display-name="my-dev-grid GitHub Actions deployer"

DEPLOYER_EMAIL="${DEPLOYER_SA}@${PROJECT_ID}.iam.gserviceaccount.com"
for ROLE in roles/artifactregistry.writer roles/run.admin roles/run.developer roles/iam.serviceAccountUser; do
  gcloud projects add-iam-policy-binding "$PROJECT_ID" \
    --member="serviceAccount:${DEPLOYER_EMAIL}" \
    --role="$ROLE" >/dev/null
done

gcloud iam workload-identity-pools create "$WIF_POOL" \
  --location=global \
  --display-name="GitHub Actions pool"

gcloud iam workload-identity-pools providers create-oidc "$WIF_PROVIDER" \
  --location=global \
  --workload-identity-pool="$WIF_POOL" \
  --display-name="GitHub OIDC provider" \
  --attribute-mapping="google.subject=assertion.sub,attribute.repository=assertion.repository" \
  --attribute-condition="assertion.repository=='${GITHUB_REPO}'" \
  --issuer-uri="https://token.actions.githubusercontent.com"

PROJECT_NUMBER=$(gcloud projects describe "$PROJECT_ID" --format='value(projectNumber)')

gcloud iam service-accounts add-iam-policy-binding "$DEPLOYER_EMAIL" \
  --role="roles/iam.workloadIdentityUser" \
  --member="principalSet://iam.googleapis.com/projects/${PROJECT_NUMBER}/locations/global/workloadIdentityPools/${WIF_POOL}/attribute.repository/${GITHUB_REPO}"

WIF_PROVIDER_NAME="projects/${PROJECT_NUMBER}/locations/global/workloadIdentityPools/${WIF_POOL}/providers/${WIF_PROVIDER}"

echo
echo "==> Done. Resolved values:"
echo "GCP_PROJECT_ID=${PROJECT_ID}"
echo "GCP_REGION=${REGION}"
echo "GCP_WORKLOAD_IDENTITY_PROVIDER=${WIF_PROVIDER_NAME}"
echo "GCP_DEPLOYER_SERVICE_ACCOUNT=${DEPLOYER_EMAIL}"
echo "GCP_ARTIFACT_REGISTRY_REPO=${ARTIFACT_REPO}"
echo "CLOUD_RUN_SERVICE_ACCOUNT=${RUNTIME_SA}@${PROJECT_ID}.iam.gserviceaccount.com"
echo "CLOUD_SQL_CONNECTION_NAME=${CLOUD_SQL_CONNECTION_NAME}"
echo "DB_DATABASE=my_dev_grid"
echo "DB_USERNAME=my_dev_grid_app"

if [ -n "$GITHUB_REPO" ] && command -v gh >/dev/null 2>&1; then
  echo
  echo "==> gh CLI found — pushing these into ${GITHUB_REPO}'s Actions Variables"
  gh variable set GCP_PROJECT_ID --repo "$GITHUB_REPO" --body "$PROJECT_ID"
  gh variable set GCP_REGION --repo "$GITHUB_REPO" --body "$REGION"
  gh variable set GCP_WORKLOAD_IDENTITY_PROVIDER --repo "$GITHUB_REPO" --body "$WIF_PROVIDER_NAME"
  gh variable set GCP_DEPLOYER_SERVICE_ACCOUNT --repo "$GITHUB_REPO" --body "$DEPLOYER_EMAIL"
  gh variable set GCP_ARTIFACT_REGISTRY_REPO --repo "$GITHUB_REPO" --body "$ARTIFACT_REPO"
  gh variable set CLOUD_RUN_SERVICE_ACCOUNT --repo "$GITHUB_REPO" --body "${RUNTIME_SA}@${PROJECT_ID}.iam.gserviceaccount.com"
  gh variable set CLOUD_SQL_CONNECTION_NAME --repo "$GITHUB_REPO" --body "$CLOUD_SQL_CONNECTION_NAME"
  gh variable set DB_DATABASE --repo "$GITHUB_REPO" --body "my_dev_grid"
  gh variable set DB_USERNAME --repo "$GITHUB_REPO" --body "my_dev_grid_app"
  echo "Pushed 8 of the 12 repo variables automatically."
else
  echo
  echo "==> gh CLI not found/authenticated (or GITHUB_REPO unset) — copy the 8 values"
  echo "    above into GitHub Settings > Secrets and variables > Actions > Variables by hand."
fi

echo
echo "==> Still needs a human, no way around it (docs/deployment-gcp.md step 7):"
echo "  - CLOUD_RUN_SERVICE            pick a name, e.g. my-dev-grid-api"
echo "  - CLOUD_RUN_MIGRATE_JOB        pick a name, e.g. my-dev-grid-migrate"
echo "  - SANCTUM_STATEFUL_DOMAINS     the production host — only known after step 8's first deploy"
echo "  - GOOGLE_CLIENT_ID/REDIRECT_URI, LINE_CLIENT_ID/REDIRECT_URI"
echo "                                 from the real Google Cloud Console OAuth app / LINE Developers console"
echo "  These need a real decision or a real external app — nothing to automate here."
