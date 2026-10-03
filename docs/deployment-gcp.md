# 部署到 GCP（Cloud Run + Cloud SQL）

後端部署目標：Cloud Run（全代管）+ Cloud SQL for PostgreSQL，區域為 `asia-east1`。追蹤 issue [#10](https://github.com/jerryyehself/my-dev-grid/issues/10)。

這個 repo 裡已經做好的部分（不用再動）：

- `Dockerfile` — 多階段建置（Node 負責前端資源，PHP 負責應用程式），用 supervisord 同時跑 nginx 和 php-fpm，啟動時讀取 `$PORT`。
- `docker/` — nginx 設定範本、supervisord 設定、entrypoint 腳本。
- `.env.production.example` — 列出 Cloud Run 服務需要的每個環境變數和機密（應用程式不會讀這個檔，只是說明用）。
- `.github/workflows/deploy-cloud-run.yml` — 建置 → 推送 → 跑 migration → 部署 → 更新每天同步 GitHub 用的 Cloud Run Job，用 Workload Identity Federation 登入 GCP。

以下全部是 GCP 主控台／CLI 上的操作，**只有你本人**能做——需要你自己的 GCP 帳單帳戶和 IAM 權限，所以都沒有自動化。以下步驟假設你已經裝好 `gcloud` CLI、登入過（`gcloud auth login`），而且有可用的帳單帳戶。文中的 `PROJECT_ID` 一律換成你自己取的專案 ID。

**捷徑**：`scripts/gcp-setup.sh` 會一次跑完下面第 1～6 步（內容就是同樣的 `gcloud` 指令包成一支腳本，不是另一套工具）；如果裝了 `gh` CLI 且已登入，還會把產生的值直接寫進這個 repo 的 GitHub Actions Variables：

```bash
PROJECT_ID=your-project-id BILLING_ACCOUNT_ID=XXXXXX-XXXXXX-XXXXXX \
  ./scripts/gcp-setup.sh
```

這是只跑一次的腳本，不是可重複執行的 IaC（基礎設施即程式碼）——對同一個 `PROJECT_ID` 再跑一次，會在已經建立過的資源上報「already exists」失敗。它會填好第 7 步表格 19 個變數裡的 **9 個**；剩下 **10 個**（`CLOUD_RUN_SERVICE`、`CLOUD_RUN_MIGRATE_JOB`、`CLOUD_RUN_SYNC_JOB`、`APP_URL`、`FRONTEND_URL`、`SANCTUM_STATEFUL_DOMAINS`、`GOOGLE_CLIENT_ID`、`GOOGLE_REDIRECT_URI`、`LINE_CLIENT_ID`、`LINE_REDIRECT_URI`）不是要你自己取名，就是要等第 8 步第一次部署後才有網址，或需要真的去申請外部 OAuth 應用程式，都沒辦法寫進腳本。不管用不用腳本，第 8 步（第一次部署）和第 10 步（每天同步的排程）都要手動做；每個值是什麼、為什麼，看下面各步驟。

## 1. 建立專案並啟用 API

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
  cloudresourcemanager.googleapis.com \
  cloudscheduler.googleapis.com
```

`cloudscheduler.googleapis.com` 是第 10 步每天同步 GitHub 用的。

## 2. Artifact Registry

```bash
gcloud artifacts repositories create my-dev-grid \
  --repository-format=docker \
  --location=asia-east1 \
  --description="my-dev-grid container images"
```

這個名稱就是第 7 步的 `GCP_ARTIFACT_REGISTRY_REPO`。

## 3. Cloud SQL for PostgreSQL（最小規格）

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

用 `gcloud sql instances describe my-dev-grid-db --format='value(connectionName)'` 查出 instance 的連線名稱並記下來，格式像 `PROJECT_ID:asia-east1:my-dev-grid-db`，這就是第 7 步的 `CLOUD_SQL_CONNECTION_NAME`。`db-f1-micro` 是 Cloud SQL 最小的規格，之後應用程式不夠用再用 `gcloud sql instances patch` 升級。

## 4. Secret Manager

先建三個機密，名稱要和 Dockerfile／workflow 引用的一致：

```bash
# APP_KEY: generate locally, don't reuse any key from a real .env
php artisan key:generate --show   # copy the "base64:..." output

printf '%s' 'base64:...'                | gcloud secrets create APP_KEY       --data-file=-
printf '%s' 'CHOOSE_A_STRONG_PASSWORD'  | gcloud secrets create DB_PASSWORD   --data-file=- # same password as step 3
printf '%s' 'ghp_...'                   | gcloud secrets create GITHUB_TOKEN  --data-file=- # a GitHub token with read access for GitService's API calls
```

之後應用程式如果多了必填的機密，對照 `.env.example`：凡是目前留白或屬於敏感值的項目，都照同樣方式放進 Secret Manager（`AWS_*` 除外，那些沒有用到，保持空白）。

**登入介面已經上線（PR #33～#35），下面這兩個也已經接進 workflow**，建立方式和上面的 `APP_KEY`／`DB_PASSWORD`／`GITHUB_TOKEN` 相同：

```bash
printf '%s' 'GOCSPX-...' | gcloud secrets create GOOGLE_CLIENT_SECRET --data-file=-
printf '%s' '...'        | gcloud secrets create LINE_CLIENT_SECRET   --data-file=-
```

另外 5 個（`GOOGLE_CLIENT_ID`、`GOOGLE_REDIRECT_URI`、`LINE_CLIENT_ID`、`LINE_REDIRECT_URI`、`SANCTUM_STATEFUL_DOMAINS`）不是敏感值，和第 7 步的 `DB_DATABASE`／`DB_USERNAME` 一樣設成一般的 repository **variables** 就好，不用放進 Secret Manager。這 7 個都已經寫進 `.github/workflows/deploy-cloud-run.yml` 的 `env_vars`／`secrets` 區塊，但要等你實際建好對應的機密和變數（第 4、7 步）才會生效——真正的值得先在 Google Cloud Console／LINE Developers 建立 OAuth 應用程式才拿得到，這只有你能做。**`--allow-unauthenticated` 跟這件事無關**（見第 8 步的更正）：它是 IAM 層級的公開存取開關，和這次登入功能在應用程式層加上的 Sanctum session 驗證是兩回事。

## 5. 執行用 service account（Cloud Run 執行時的身分）

這個**不是** GitHub Actions 部署時用的帳戶，而是容器執行時本身的身分，讓它能讀 Secret Manager、連 Cloud SQL。

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

它的 email 就是第 7 步的 `CLOUD_RUN_SERVICE_ACCOUNT`。

## 6. 部署用 service account + Workload Identity Federation

GitHub Actions 建置、推送、部署時，會以這個身分的名義操作（impersonate），全程不需要下載 JSON 金鑰。

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

`PROJECT_NUMBER`（專案編號，不是專案 *ID*）用 `gcloud projects describe PROJECT_ID --format='value(projectNumber)'` 查。

`GCP_WORKLOAD_IDENTITY_PROVIDER` 要填的完整 provider 資源名稱是：

```
projects/PROJECT_NUMBER/locations/global/workloadIdentityPools/github-pool/providers/github-provider
```

## 7. GitHub repository 變數

Repo → Settings → Secrets and variables → Actions → **Variables** 分頁（不是 Secrets 分頁：這些值本身都不敏感；WIF 不用金鑰，應用程式真正的機密都留在 Secret Manager，所以完全不需要 GitHub secret）：

| Variable | Value |
|---|---|
| `GCP_PROJECT_ID` | `PROJECT_ID` |
| `GCP_REGION` | `asia-east1` |
| `GCP_WORKLOAD_IDENTITY_PROVIDER` | 來自第 6 步的完整 provider 名稱 |
| `GCP_DEPLOYER_SERVICE_ACCOUNT` | `my-dev-grid-deployer@PROJECT_ID.iam.gserviceaccount.com` |
| `GCP_ARTIFACT_REGISTRY_REPO` | `my-dev-grid` |
| `CLOUD_RUN_SERVICE` | `my-dev-grid-api`（或你偏好的任何名稱） |
| `CLOUD_RUN_SERVICE_ACCOUNT` | `my-dev-grid-run@PROJECT_ID.iam.gserviceaccount.com` |
| `CLOUD_SQL_CONNECTION_NAME` | `PROJECT_ID:asia-east1:my-dev-grid-db` |
| `DB_DATABASE` | `my_dev_grid` |
| `DB_USERNAME` | `my_dev_grid_app` |
| `SANCTUM_STATEFUL_DOMAINS` | 提供 Triple 後台的正式環境主機（例如 `my-dev-grid-api-xxxxx.a.run.app`，或之後接自訂網域就換成那個） |
| `GOOGLE_CLIENT_ID` | 你在 Google Cloud Console 建立的 Google OAuth 應用程式提供 |
| `GOOGLE_REDIRECT_URI` | 例如 `https://your-service-url/auth/google/callback` |
| `LINE_CLIENT_ID` | 你在 LINE Developers 建立的 LINE Login channel 提供 |
| `LINE_REDIRECT_URI` | 例如 `https://your-service-url/auth/line/callback` |
| `APP_URL` | 這個 Cloud Run 服務自己的網址，第 8 步第一次部署後才查得到 |
| `FRONTEND_URL` | 前端網址 `https://jerrylib.com`。CORS 只放行它，OAuth 登入完成也導回它；沒設會退回 `http://localhost:5173`，正式前端的請求全部被擋 |
| `CLOUD_RUN_MIGRATE_JOB` | `my-dev-grid-migrate`（可不設，但建議設——見第 8 步） |
| `CLOUD_RUN_SYNC_JOB` | `my-dev-grid-github-sync`（可不設，但不設就不會每天同步 GitHub——見第 10 步） |

`.github/workflows/deploy-cloud-run.yml` 檔案開頭也列了同一批變數。

workflow 指定了 `environment: production`。如果這個 repo 有用名為 `production` 的 GitHub **environment** 設保護規則，變數要改加在那個 environment 裡；沒有的話，就去 Settings → Environments 建一個，或把 workflow 裡的 `environment:` 那行拿掉。

## 8. 第一次部署

push 到 `main`，或手動執行 workflow（Actions 分頁 → "Deploy to Cloud Run" → Run workflow）。它會：

1. 用 `Dockerfile` 建置映像檔，推送到 Artifact Registry。
2. 用這個映像檔部署／更新 Cloud Run Job（`CLOUD_RUN_MIGRATE_JOB`），並用 `--wait` 跑完 `php artisan migrate --force`，確保新 revision 開始接流量前 schema 已經建好。不設 `CLOUD_RUN_MIGRATE_JOB` 就會跳過這步，migration 改由你自己跑。
3. 用 `--allow-unauthenticated` 部署 Cloud Run 服務。**登入功能上線後這個選項仍然保留**（2026-09-08 更正：本文件早期版本暗示應用程式「前面有驗證」之後就會拿掉）。它是 IAM 層級的開關，決定 Cloud Run 接不接受請求；公開的唯讀網站（`my-dev-grid-front`）和登入頁本身，都需要未登入的請求也能打到服務。拿掉它等於把整個公開網站擋在外面，而不只是保護寫入端點。寫入權限改由應用程式層控管（Sanctum session 驗證 + Policies，`PR #32`）——那才是正確、長久的位置，不是 Cloud Run 的 IAM 綁定。
4. 設了 `CLOUD_RUN_SYNC_JOB` 的話，用同一個映像檔建立／更新每天同步 GitHub 的 Cloud Run Job。這步**不會**執行同步，只是讓 Job 跟服務保持同一版程式；每天叫它的排程在第 10 步設。

第一次部署成功後，查出服務網址：

```bash
gcloud run services describe "$CLOUD_RUN_SERVICE" --region=asia-east1 --format='value(status.url)'
```

把這個網址填進第 7 步的 `APP_URL` 變數，再手動跑一次 workflow（Actions 分頁 → "Deploy to Cloud Run" → Run workflow）。`APP_URL` 已經寫在 workflow 的 `env_vars` 裡，**不要**改用 `gcloud run services update --set-env-vars` 直接設：下次部署時，workflow 會用變數的值（沒設就是空字串）蓋掉它。

## 9. 部署後的基本檢查

- `curl https://your-service-url/up` 應該回傳框架內建的健康檢查（200 OK），代表 nginx、php-fpm 和應用程式都有起來。
- 如果沒有，看 `gcloud run services logs read "$CLOUD_RUN_SERVICE" --region=asia-east1`（或 Cloud Logging）；`clear_env` 設定錯誤或 `DB_HOST` 打錯，通常一眼就會在這裡看到。
- 打一支 API，例如 `/api/scopes`，確認 Postgres 連線真的整條通了（不只是容器有起來）。

## 10. 每天同步 GitHub（Cloud Scheduler）

後端每天要跑一次 `php artisan github:sync-repos`：從 GitHub 抓公開 repo，更新專案和技術資料。Laravel 的排程（`routes/console.php`）要靠主機上的 cron 每分鐘跑 `schedule:run` 才會觸發，Cloud Run 沒有 cron，所以正式環境改成 **Cloud Scheduler 每天叫一次 Cloud Run Job，由 Job 跑這個指令**。

Job 由部署 workflow 建立與更新（第 8 步第 4 點），這裡只要設一次「每天叫它」的排程。前提是第 7 步設了 `CLOUD_RUN_SYNC_JOB`，而且第 8 步至少部署成功過一次，Job 已經存在。

```bash
REGION=asia-east1
SYNC_JOB=my-dev-grid-github-sync   # same as CLOUD_RUN_SYNC_JOB in step 7

# A service account whose only job is letting Cloud Scheduler start this one Job
gcloud iam service-accounts create my-dev-grid-scheduler \
  --display-name="my-dev-grid Cloud Scheduler invoker"
SCHEDULER_SA="my-dev-grid-scheduler@PROJECT_ID.iam.gserviceaccount.com"

# Grant it on this Job only, not the whole project (so it can't start the migrate Job)
gcloud run jobs add-iam-policy-binding "$SYNC_JOB" \
  --region="$REGION" \
  --member="serviceAccount:${SCHEDULER_SA}" \
  --role="roles/run.invoker"

# Every day at 03:17 Taiwan time
gcloud scheduler jobs create http my-dev-grid-github-sync-daily \
  --location="$REGION" \
  --schedule="17 3 * * *" \
  --time-zone="Asia/Taipei" \
  --uri="https://run.googleapis.com/v2/projects/PROJECT_ID/locations/${REGION}/jobs/${SYNC_JOB}:run" \
  --http-method=POST \
  --oauth-service-account-email="$SCHEDULER_SA"
```

- **為什麼另外開一個 service account**：Google 官方範例用的是專案預設的 compute service account，但那個帳戶權限很大。這裡另開一個，只給它「啟動這一個 Job」的權限（`roles/run.invoker` 綁在 Job 上，不是整個專案）。
- **跑指令的人要有的權限**：Cloud Scheduler Admin，以及能以 `my-dev-grid-scheduler` 的身分操作。專案 Owner 兩個都有。
- **時間**：選半夜、避開整點，是為了避開整點大量排程同時觸發的延遲；要改時間用 `gcloud scheduler jobs update http my-dev-grid-github-sync-daily --location="$REGION" --schedule=...`。`routes/console.php` 裡的 `daily()` 在正式環境不會生效，改那裡沒有用。

**設好之後確認一次**，不用等到隔天：

```bash
# Trigger once now
gcloud scheduler jobs run my-dev-grid-github-sync-daily --location="$REGION"

# See whether the Job execution succeeded
gcloud run jobs executions list --job="$SYNC_JOB" --region="$REGION" --limit=3
```

執行成功之後，到 Cloud Logging 看這個 Job 的紀錄，應該要有 `GitHub repos synced.`，**而且不能有** `GitService::get_repos failed`。GitHub API 呼叫失敗時，程式只記一筆錯誤就正常結束，所以「執行成功」不代表真的抓到資料（例如 `GITHUB_TOKEN` 過期）。

**暫停或拿掉**：`gcloud scheduler jobs pause|resume|delete my-dev-grid-github-sync-daily --location="$REGION"`。暫停不影響網站本身，只是資料不再每天更新。

**同步會改哪些資料**：只新增或更新，不刪除任何東西。但每個專案的標題、描述、網址、維護狀態，每次都會用 GitHub 上的值覆蓋；在後台手動改過這幾個欄位，隔天會被蓋回去。

**費用**：大約 0 元。

- **Cloud Scheduler**：每個帳單帳戶有 3 個免費排程，這裡只用 1 個。
- **Cloud Run Job**：每次跑不到一分鐘，一個月約 30 次，遠低於每月 24 萬 vCPU 秒的免費額度。
- **GitHub API**：每次呼叫約 17 次，不收費。

## 注意事項 / 之後要重新檢視的東西

- **本機開發不受影響。** `.env.example` 預設仍是 `DB_CONNECTION=sqlite`，`php artisan serve`／`composer run dev` 在本機的用法完全不變。
- **容器的儲存空間不會保留。** 每個新 revision／instance 的檔案系統都是全新的。這個應用程式目前執行時不會寫本機磁碟（`app/` 裡沒有 `Storage::` 呼叫），所以現在沒問題；之後如果有檔案上傳之類的需求，要把 `FILESYSTEM_DISK` 改成用 GCS 的磁碟，不能用 `local`。
- **成本控制。** workflow 裡的 `--min-instances=0` 代表沒流量時服務會縮到 0 台（最省錢，但會有冷啟動），`db-f1-micro` 則是 Cloud SQL 最小的規格。等真的有流量，兩者都很容易再調大（`gcloud run services update`／`gcloud sql instances patch`）。
- **更換機密。** 用 `gcloud secrets versions add APP_KEY --data-file=-`（其他機密同理）新增一個版本即可；workflow 一律引用 `:latest`，下次部署就會自動用新值，不用改 workflow。
