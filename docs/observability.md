# 可觀測性：日誌、request id、錯誤收集

正式環境（Cloud Run）怎麼從日誌與告警判斷發生了什麼事。部署與 GCP 設定見 `docs/deployment-gcp.md`。

## 記了什麼

`LOG_CHANNEL=cloud_run`（`config/logging.php`，部署 workflow 已設定）時，每一筆日誌是寫到 stderr 的**一行 JSON**，Cloud Logging 會解析成 `jsonPayload`。本機（`.env.example` 的 `stack`／`single`）不受影響，維持人看得懂的文字格式。

| 欄位 | 內容 |
|---|---|
| `severity` | `DEBUG`／`INFO`／`NOTICE`／`WARNING`／`ERROR`／`CRITICAL`／`ALERT`／`EMERGENCY`，Cloud Logging 抽成 LogEntry 的嚴重度 |
| `message` | 日誌文字；有例外時是 PHP 格式的堆疊（見下方「錯誤怎麼進 Error Reporting」） |
| `time` | UTC 時間戳 |
| `request_id` | 這個請求的 id；同一個請求的每一行都一樣 |
| `logging.googleapis.com/trace`、`…/trace_sampled` | 請求帶 `X-Cloud-Trace-Context` 且設定了 `GOOGLE_CLOUD_PROJECT` 時才有；讓這行日誌歸到該請求的 request log 底下 |
| `httpRequest` | 只在 `ERROR` 以上才有：`requestMethod`、`requestUrl`（不含 query string）、`userAgent`、`remoteIp`、`referer`、`protocol`。Cloud Run 本來就會替每個請求自動寫一筆帶 `httpRequest` 的 request log，所以一般日誌不重複帶 |
| `log_message` | 例外日誌中，呼叫端給的文字與例外訊息不同時才有 |
| `context`、`extra` | 其餘的 context 與 extra；請求內容（body）與 query string 不記錄 |

Cloud Logging 欄位名稱出處（引述自官方文件，取用日期 2026-10-07）：

- Cloud Run 文件〈Use simple text vs structured JSON in logs〉：「you can send a simple text string or send a single line of serialized JSON, also called "structured" data. This is picked up and parsed by Cloud Logging and is placed into `jsonPayload`」。
- 同一頁：「some special fields are stripped from the `jsonPayload` and are written to the corresponding field in the generated `LogEntry`」；例如「if your JSON includes a `severity` property, it is removed from the `jsonPayload` and appears instead as the log entry's `severity`. The `message` property is used as the main display text」。<https://docs.cloud.google.com/run/docs/logging>
- 同一頁：要把容器日誌關聯到 request log，「use a structured JSON log line that contains a `logging.googleapis.com/trace` field with the trace identifier extracted from the `X-Cloud-Trace-Context` header」（範例值格式 `projects/PROJECT_ID/traces/TRACE_ID`）。
- 特殊欄位表（`severity`、`message`、`httpRequest`、`logging.googleapis.com/trace`、`logging.googleapis.com/trace_sampled`、`logging.googleapis.com/labels` 等）：<https://docs.cloud.google.com/logging/docs/agent/configuration#special-fields>。這張表寫在 Logging agent 的文件裡；Cloud Run 文件指向它作為特殊欄位的定義，`httpRequest` 在 Cloud Run 上是否同樣被抽出為推論，尚未在實際環境量過。

## 怎麼篩出單一請求

每個回應都帶 `X-Request-Id` 標頭。`AssignRequestId` middleware 取值的順序：請求自己帶的 `X-Request-Id`（只接受 1–128 個 `A-Za-z0-9._-`，其他丟棄重產）、Cloud Run 加的 `X-Cloud-Trace-Context` 裡的 trace id、都沒有就產生 UUID。在 Cloud Run 上通常是第二種，所以 request id 等於 Cloud Logging 的 trace id。

Logs Explorer 查詢：

```
resource.type="cloud_run_revision"
jsonPayload.request_id="<X-Request-Id 的值>"
```

只在 request id 等於 trace id（上面第二種情況）時，也可以用 trace 一次撈出 request log 與應用程式日誌：

```
trace="projects/<PROJECT_ID>/traces/<X-Request-Id 的值>"
```

命令列：

```bash
gcloud logging read 'jsonPayload.request_id="<id>"' --project=<PROJECT_ID> --freshness=1d --format=json
```

只看出錯的：加上 `severity>=ERROR`。

瀏覽器端的 JavaScript 預設讀不到跨來源回應的 `X-Request-Id`（`config/cors.php` 的 `exposed_headers` 目前是空的）；前端若要在錯誤畫面顯示它，要先把 `X-Request-Id` 加進 `exposed_headers`。從開發者工具的 Network 面板看得到。

## 錯誤怎麼進 Error Reporting

不需要第三方 SDK。Cloud Run 上，Error Reporting 會從 Cloud Logging 的日誌自動辨識錯誤，條件（引述自官方文件）：

- 〈Format a log entry to report error events〉：「When you write log entries by using Cloud Logging, the `LogEntry` object must contain a stack trace or a formatted `ReportedErrorEvent` object.」；堆疊要寫成「A multi-line `textPayload`」或「A `jsonPayload` that includes a `message`, `stack_trace`, or `exception` field」；「If the `message` field is evaluated and if it isn't empty, then the stack trace is captured only when the field contains a stack trace in one of the supported programming language formats.」，格式不支援就不會被收。<https://docs.cloud.google.com/error-reporting/docs/formatting-error-messages>
- 同一頁：支援的監控資源包含 `cloud_run_revision` 與 `cloud_run_jobs`。
- PHP 的堆疊格式，出自 `projects.events.report` 的 `message` 欄位說明：「PHP: Must be prefixed with "PHP (Notice|Parse error|Fatal error|Warning): " and contain the result of (string)$exception.」<https://docs.cloud.google.com/error-reporting/reference/rest/v1beta1/projects.events/report>
- 嚴重度：〈Instrument PHP apps for Error Reporting〉的 Cloud Run 一節：「Error Reporting automatically creates an error event when a log entry contains a stack trace and the severity level of the log entry isn't set or is set to at least `ERROR`.」<https://docs.cloud.google.com/error-reporting/docs/setup/php>

實作：Laravel 的例外處理器記錄未處理例外時，會用 `ERROR` 等級並在 context 帶 `exception`。`CloudLoggingFormatter` 看到 `exception` 就把 `message` 換成 `PHP Fatal error:  Uncaught <(string) $exception>` 加上 `thrown in <檔案> on line <行號>`（跟 PHP 自己印 uncaught exception 的格式相同），同時把堆疊放進單行 JSON（換行跳脫為 `\n`）。`ERROR` 以上才會被 Error Reporting 收；`WARNING` 以下即使帶例外也不會。

Laravel 預設不回報的例外（驗證失敗、404、未登入、`abort(4xx)` 等）不會產生錯誤日誌，所以也不會出現在 Error Reporting。其中 403 與 429 會另外記一筆 `WARNING` 的安全事件（沒有堆疊，見下一節）。

## 安全與稽核事件

登入、token、權限被拒、頻率限制、後台資料變更各記一筆（#103）。事件名稱取自 OWASP〈Logging Vocabulary Cheat Sheet〉<https://cheatsheetseries.owasp.org/cheatsheets/Logging_Vocabulary_Cheat_Sheet.html>（取用日期 2026-10-12）。這一節同時是 ASVS 5.0 16.1.1 要求的日誌清單：「Verify that an inventory exists documenting the logging performed at each layer of the application's technology stack, what events are being logged, log formats, where that logging is stored, how it is used, how access to it is controlled, and for how long logs are kept.」<https://github.com/OWASP/ASVS/blob/master/5.0/en/0x25-V16-Security-Logging-and-Error-Handling.md>

### 格式與查詢

事件走一般的 `Log` facade，跟其他日誌同一個 `cloud_run` channel、同一種單行 JSON，自動帶 `request_id` 與 trace。

| 位置 | 內容 |
|---|---|
| `message` | `<事件名稱> <中文說明>`，例如 `authn_login_fail google 登入失敗（session）：identity_not_bound` |
| `jsonPayload.context.event` | 事件名稱本身，篩選用這個 |
| `jsonPayload.context.*` | 其他欄位（下表） |

vocabulary 把參數寫成位置參數字串（例：`"event": "authn_login_fail:joebob1"`）；這裡改成具名欄位（`user_id`、`reason`…），`event` 只放名稱，Cloud Logging 才能逐欄篩選。

```
resource.type="cloud_run_revision"
jsonPayload.context.event="authn_login_fail"
```

全部安全事件：`jsonPayload.context.event:*`。某個使用者的登入紀錄：`jsonPayload.context.event="authn_login_success" AND jsonPayload.context.user_id=1`。

### 事件表

等級照 vocabulary，只有 `authz_fail` 不同（見表下）。

| 事件 | 等級 | 什麼時候 | 欄位 |
|---|---|---|---|
| `authn_login_success` | INFO | OAuth callback 找到綁定（Google、LINE；Triple 的 session 模式與前端的 token 模式）、帳密登入成功 | `user_id`、`auth_method`（`google`／`line`／`password`）、`auth_mode`（`session`／`token`） |
| `authn_login_fail` | WARNING | 見下方「失敗原因」 | `auth_method`、`auth_mode`、`reason`、`source_ip`，依原因另有 `user_id`、`provider_user_id`、`provider_error`、`error_class`、`access_token_id` |
| `authn_token_created` | INFO | 發 token：登入開新的 refresh token 家族（`reason=login`）、refresh 換發（`rotation`）、OAuth 回呼的短效 token（`oauth_callback`） | `user_id`、`reason`、`family_id`、`refresh_token_id`、`access_token_id`、`abilities` |
| `authn_token_revoked` | INFO | 撤銷整個 refresh token 家族，`reason` 是 `logout`、`reuse`、`max_lifetime` | `user_id`、`reason`、`family_id`、`refresh_tokens_revoked`（刪掉幾支） |
| `authn_token_reuse` | CRITICAL | 已用掉的 refresh token 在寬限秒數之後又被送來（視為被偷，整個家族撤銷） | `user_id`、`family_id`、`refresh_token_id`、`source_ip` |
| `session_expired` | INFO | 換發時超過 90 天絕對上限 | `user_id`、`reason=max_lifetime`、`family_id` |
| `session_logout` | INFO | `POST /auth/logout`（Triple）、`POST /api/auth/logout`（前端） | `auth_mode`；認得出是誰時有 `user_id`；token 模式另有 `access_token_id` |
| `authz_fail` | WARNING | 任何 403：Origin 檢查（`EnsureFrontendOrigin`）、`abort(403)`、Policy 拒絕 | `http_method`、`route`（路由樣板）、`resource`（路徑，不含 query string）、`reason`（程式裡寫的固定訊息）、`source_ip`；已驗證的請求有 `user_id` |
| `excess_rate_limit_exceeded` | WARNING | 任何 429 | `http_method`、`route`、`max`（limiter 上限：login 5、social-login 20、token-refresh 30、api 60）、`retry_after`、`source_ip`；已驗證的請求有 `user_id` |
| `authz_admin` | WARNING | 已登入使用者在 HTTP 請求裡新增、修改、刪除 Scope／Relation／Documentation／Technique／Implementation，或改它們之間的 pivot | `user_id`、`action`（`create`／`update`／`delete`／`sync`）、`object`（`scope`…）、`object_id`；`update` 有 `fields`（改了哪些欄位）；`sync` 有 `relation`、`attached`、`detached`、`updated`（id 清單） |

`authn_login_fail` 的 `reason`：

| `reason` | 情況 |
|---|---|
| `identity_not_bound` | provider 帳號沒有對應的 `oauth_identities`（關閉註冊，見 `SocialAuthController`）；帶 `provider_user_id` |
| `invalid_state` | Socialite 的 state 驗證失敗 |
| `provider_denied` | provider 導回來時帶了 `error` 參數（例如使用者按取消的 `access_denied`）；帶 `provider_error` |
| `provider_error` | 其他向 provider 取資料失敗的情況；帶 `error_class`（例外類別名，不含訊息） |
| `invalid_credentials` | 帳密錯誤；帳號存在時帶 `user_id` |
| `invalid_refresh_token` | 帶了 refresh cookie 但無效或過期 |
| `callback_token_already_used` | 同一支 OAuth 回呼 token 被同時拿來換兩次，後到的那個 |

OAuth 的三種例外（`invalid_state`、`provider_denied`、`provider_error`）記完照原樣丟出，回應跟改動前一樣是 500，也照樣有一筆 `ERROR` 日誌。

刻意不記：寬限秒數內的併發換發（409，正常情況）、沒帶 cookie 的 refresh（前端每次開機都會打，沒登入過的訪客每一頁都是）、401、驗證失敗（422）。

等級與 vocabulary 不同的地方：`authz_fail` vocabulary 寫 CRITICAL，這裡用 WARNING——這個 app 的 403 絕大多數是 Origin 檢查擋掉 curl、爬蟲或設定錯誤的前端，不是越權；CRITICAL 會讓之後「ERROR 以上就通知」的告警一直誤觸發。vocabulary 沒有 `session_end`：使用者自己登出用 `session_logout`，其他原因結束用 `session_expired`。

### 記哪些識別資料、不記什麼

不記：密碼、access／refresh token 的值、OAuth 的 `code` 與 `state`（路徑一律不含 query string）、session id、金鑰、請求內容、OAuth 例外的訊息（可能含 provider 回應片段）、後台資料的欄位值（OWASP〈Logging Cheat Sheet〉"Data to exclude"：<https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html#data-to-exclude>）。

| 識別資料 | 記不記 | 理由 |
|---|---|---|
| `user_id`（`users.id`） | 記 | 內部主鍵，回答「誰」需要它；不記 email、姓名 |
| token／家族 id（`access_token_id`、`refresh_token_id`、`family_id`） | 記 | 資料庫主鍵與 UUID，不是秘密：cookie 是 `{id}\|{秘密}`，只有 id 換不到任何東西，資料庫也只存秘密的 SHA-256。撤銷、重放要靠它們追到是哪一次登入 |
| `provider_user_id`（Google `sub`、LINE userId） | 只在 `identity_not_bound` | 首次綁定唯一需要的值（專案擁有者靠它手動建 `oauth_identities`）。2026-10-12 之前這筆日誌還記了 provider 給的 email 與姓名，已移除：建綁定用不到，打到這裡的多半是跟系統無關的陌生人。要認出哪一筆是自己，看自己登入那一刻的時間，或該次回應的 `X-Request-Id` |
| 帳密登入失敗時輸入的 email | 不記 | 可能是別人的 email；帳號存在時改記它的 `user_id` |
| `source_ip` | 只在失敗／被擋類事件（`authn_login_fail`、`authn_token_reuse`、`authz_fail`、`excess_rate_limit_exceeded`） | 偵測暴力嘗試、判斷要不要封鎖需要 IP，事件本身要能單獨看懂（ASVS 16.2.1 "when, where, who, what"）。值跟 rate limit 的 key 相同（`AppServiceProvider::clientIp`，X-Forwarded-For 最右邊）。成功類事件不記：`user_id` 已經說明是誰，IP 也本來就在 Cloud Run 自動寫的 request log（同一個 trace）裡 |
| User-Agent | 不記 | 在 request log 裡 |

vocabulary 的隱私註記：「All fields logged after event type should be considered optional. ... logging user IP address may be useful for detection and response, but may be considered personally identifiable information when combined with other data and subject to regulation or deletion requests.」

保留期限：跟其他日誌一樣在 Cloud Logging 的 `_Default` bucket。官方文件：「If you don't customize the retention period, the default is `30 days`.」（這句出自建立 user-defined bucket 的步驟；`_Default` bucket 實際設定以 `gcloud logging buckets describe _Default --location=global --project=<PROJECT_ID>` 為準），可設定範圍「between 1 day and 3650 days」，超過預設的部分另外收費。<https://docs.cloud.google.com/logging/docs/buckets>（取用日期 2026-10-12）。所以陌生人的 `provider_user_id`、IP 預設 30 天後自動刪除。

### 後台稽核紀錄為什麼不存資料庫

評估過 `spatie/laravel-activitylog` 與自建資料表，最後只寫日誌、不建資料表（沒有 migration）：

- ASVS 5.0 16.4.3：「Verify that logs are securely transmitted to a logically separate system for analysis, detection, alerting, and escalation. The aim is to ensure that if the application is breached, the logs are not compromised.」存在 app 自己的資料庫裡，app 用的同一組資料庫帳號就能改、能刪；Cloud Logging 是另一個系統，app 的程式碼沒有修改日誌的路徑（服務帳戶在 GCP 有哪些日誌權限要另外確認，見「還需要專案擁有者手動做的事」第 7 步）。
- 不加相依套件、不加資料表、每次寫入不多一個 INSERT。
- 代價：預設只留 30 天；只記欄位名稱、不記值（Markdown 內文可能很長，整行超過 php-fpm 的 `log_limit` 會被拆成多行，JSON 就壞了）；要看改成什麼值，靠資料庫本身或備份。
- 只記「已登入使用者在 HTTP 請求裡的變更」。seeder、`snapshot:github-repos` 這類指令沒有登入使用者，不記（一次上百筆的系統批次寫入）；`php artisan tinker` 手動改的也不會記。
- pivot 的 `sync()` 不觸發 model 事件，由 `SyncsPivotRelations::syncPivot()` 另外記。`updated` 是 sync 重寫過 pivot 欄位的既有關聯：sync 對既有關聯一律下 UPDATE，值沒變也列在裡面。

需要更長的保留期限時，把安全事件導到另一個 bucket（第 7 步），不用改程式。

## 相關設定在哪

| 項目 | 位置 |
|---|---|
| channel、formatter、processor | `config/logging.php` 的 `cloud_run` |
| request id | `app/Http/Middleware/AssignRequestId.php`，在 `bootstrap/app.php` 以 `prepend` 掛成最外層全域 middleware |
| JSON 格式 | `app/Logging/CloudLoggingFormatter.php`、`app/Logging/RequestLogProcessor.php` |
| 安全與稽核事件 | 名稱與等級 `app/Logging/SecurityEvent.php`、寫法 `app/Logging/SecurityLog.php`；403／429 在 `bootstrap/app.php` 的 `respond()`；後台稽核 `app/Logging/AdminAudit.php`（`AppServiceProvider::boot` 掛 observer，pivot 在 `app/Traits/SyncsPivotRelations.php`） |
| `LOG_CHANNEL=cloud_run`、`GOOGLE_CLOUD_PROJECT` | `.github/workflows/deploy-cloud-run.yml`（服務；兩個 Job 也用 `cloud_run`）、`.env.production.example` |
| php-fpm `log_limit` | `Dockerfile`。php-fpm 預設把超過 1024 字元的 worker 輸出折成多行，會讓單行 JSON 失效；`message` 另外在 30000 位元組截斷，確保一行在上限內 |

php.net 對 `log_limit` 的說明：「Log limit for the logged lines which allows to log messages longer than 1024 characters without wrapping. Default value: 1024.」<https://www.php.net/manual/en/install.fpm.configuration.php>

## 還需要專案擁有者手動做的事

以下需要 GCP／Sentry 的帳號權限，沒有自動化。

### 1. 部署後確認

1. 部署完成後，到 Logs Explorer 確認新的日誌是 JSON（`jsonPayload` 有 `request_id`），且 Cloud Run 服務沒有因為 `log_limit` 設定而啟動失敗。
2. 刻意製造一個例外（例如暫時在測試環境加一條丟例外的路由），確認：Logs Explorer 有該筆 `ERROR` 日誌、`message` 是 `PHP Fatal error:  Uncaught …`；Error Reporting（Console → Error Reporting）出現含堆疊的錯誤群組。
3. 用回應的 `X-Request-Id` 在 Logs Explorer 篩出該請求的所有日誌。

### 2. 通知管道

建兩個：email，加上手機推播（Google Cloud 行動應用程式，不用寫程式就有手機通知）。

Email（`<EMAIL>` 換成你的信箱）：

```bash
gcloud beta monitoring channels create \
  --display-name="my-dev-grid alerts" \
  --type=email \
  --channel-labels=email_address=<EMAIL> \
  --project=<PROJECT_ID>
# 記下輸出的 name：projects/<PROJECT_ID>/notificationChannels/<ID>
```

手機推播：在手機安裝 Google Cloud app 並登入同一個帳號，之後在 Monitoring → Alerting → Edit notification channels 的「Mobile Devices」就會出現這支手機，建立告警時可以一起勾選。官方支援的管道類型見〈Notification channel types〉<https://cloud.google.com/monitoring/support/notification-options>；LINE 不在其中（LINE Notify 已於 2025-03-31 終止服務，要改走 Messaging API 並自建轉發，目前沒有做）。

### 3. 存活檢查（uptime check）

每 10 分鐘從 3 個地區打一次 `/up`（Laravel 內建的健康檢查路由，見 `bootstrap/app.php`）。`--regions` 至少要 3 個；每月約 3 × 6 × 24 × 30 ≈ 13,000 次，在每個專案每月 100 萬次的免費額度內（Cloud Monitoring 價目頁，取用日期 2026-10-08：<https://cloud.google.com/stackdriver/pricing>）。

```bash
gcloud monitoring uptime create my-dev-grid-api-up \
  --resource-type=uptime-url \
  --resource-labels=host=<CLOUD_RUN_HOST>,project_id=<PROJECT_ID> \
  --protocol=https --path=/up \
  --period=10 --timeout=30 \
  --regions=asia-pacific,usa-oregon,europe \
  --project=<PROJECT_ID>
```

`<CLOUD_RUN_HOST>` 是服務網址去掉 `https://`（`gcloud run services describe <SERVICE_NAME> --region=asia-east1 --format='value(status.url)'`）。

再替它建告警：主控台 Monitoring → Uptime checks → 點這個檢查 → Create alert → 通知管道勾第 2 步的 email 與手機。條件建議「失敗地區數 ≥ 2」，**不要設成 1 個地區失敗就通知**：服務 `min-instances=0`，冷啟動偶發的 502（#91）會讓單一地區的單次檢查失敗，門檻太低會變成誤報。

`--period`、`--regions` 的可用值以 `gcloud monitoring uptime create --help` 為準（`--period` 可選 1、5、10、15 分鐘）。告警目前不收費：價目頁寫明 "Starting no sooner than September 1, 2027, Cloud Monitoring will begin charging for alerting"。

### 4. 5xx 比例告警

通知管道用第 2 步建立的。把下面存成 `5xx-alert.json`（`<SERVICE_NAME>` 是 Cloud Run 服務名稱＝repo variable `CLOUD_RUN_SERVICE`；`<CHANNEL_NAME>` 是第 2 步 email 管道的 name）：

```json
{
  "displayName": "my-dev-grid 5xx ratio > 5%",
  "combiner": "OR",
  "conditions": [
    {
      "displayName": "5xx / all requests > 5% for 5 min",
      "conditionThreshold": {
        "filter": "metric.type=\"run.googleapis.com/request_count\" AND resource.type=\"cloud_run_revision\" AND resource.label.service_name=\"<SERVICE_NAME>\" AND metric.label.response_code_class=\"5xx\"",
        "denominatorFilter": "metric.type=\"run.googleapis.com/request_count\" AND resource.type=\"cloud_run_revision\" AND resource.label.service_name=\"<SERVICE_NAME>\"",
        "aggregations": [
          { "alignmentPeriod": "300s", "perSeriesAligner": "ALIGN_RATE", "crossSeriesReducer": "REDUCE_SUM" }
        ],
        "denominatorAggregations": [
          { "alignmentPeriod": "300s", "perSeriesAligner": "ALIGN_RATE", "crossSeriesReducer": "REDUCE_SUM" }
        ],
        "comparison": "COMPARISON_GT",
        "thresholdValue": 0.05,
        "duration": "300s",
        "trigger": { "count": 1 }
      }
    }
  ],
  "notificationChannels": ["<CHANNEL_NAME>"]
}
```

```bash
gcloud alpha monitoring policies create --policy-from-file=5xx-alert.json --project=<PROJECT_ID>
```

等價的主控台路徑：Monitoring → Alerting → Create policy → Select a metric：Cloud Run Revision → Request Count → 篩選 `service_name`＝服務名稱、`response_code_class`＝`5xx` → 設為相對於所有請求的比例（Add ratio／denominator 不篩 `response_code_class`）→ 門檻 5%、持續 5 分鐘 → 選通知管道。

這份 JSON 與指令依 Cloud Monitoring 的 AlertPolicy 格式撰寫，**沒有在實際專案執行過**（推論）；`gcloud alpha` 指令群組若改名，以主控台建立。流量很小的服務，一次 5xx 就可能超過 5%，視情況調高門檻或拉長 `duration`。

驗收「告警實際觸發過一次」：在測試環境或暫時的路由讓服務連續回 500，等一個 `duration` 後確認收到通知，再還原。

### 5. 選用：Sentry

Cloud Error Reporting 已涵蓋「有堆疊的例外」。若之後想要 release 追蹤、前端錯誤、使用者影響數等，再評估 Sentry 的 Laravel SDK（`sentry/sentry-laravel`，需要建立 Sentry 帳號與專案、把 DSN 放進 Secret Manager）。目前沒有安裝。

### 6. 排查 #91

用上述工具重現與查詢 #91，需要正式環境的 Cloud Logging 權限；結果寫成事故報告。

### 7. 安全事件：保留期限、權限、告警（選用）

以下指令**沒有在實際專案執行過**（推論），旗標以 `gcloud logging ... --help` 為準。

1. 確認 `_Default` bucket 目前保留幾天：`gcloud logging buckets describe _Default --location=global --project=<PROJECT_ID>`。
2. 要讓安全事件留得比一般日誌久（例如一年），另建一個 bucket 並用 sink 只把安全事件導過去，一般日誌維持 30 天：

   ```bash
   gcloud logging buckets create security-audit \
     --location=global --retention-days=400 --project=<PROJECT_ID>

   gcloud logging sinks create security-audit \
     logging.googleapis.com/projects/<PROJECT_ID>/locations/global/buckets/security-audit \
     --log-filter='resource.type="cloud_run_revision" AND jsonPayload.context.event:*' \
     --project=<PROJECT_ID>
   ```

   超過預設 30 天的部分會收保留費用（見上方「保留期限」的出處）；這個站的安全事件量很小。
3. 確認 Cloud Run 服務帳戶在 IAM 上的角色。app 只把日誌寫到 stderr、不呼叫 Cloud Logging API，用不到任何能刪除日誌或改 bucket／sink 的權限；若服務帳戶是預設的 Compute Engine 帳戶且帶 `roles/editor`，權限比需要的大，依 ASVS 16.4.2（「Verify that logs are protected from unauthorized access and cannot be modified.」）考慮換成專用的服務帳戶。另外確認能讀 Logs Explorer 的人只有專案擁有者（安全事件裡有 IP 與 provider 使用者 id）。
4. 選用：替 `authn_token_reuse` 建 log-based 告警（Logs Explorer 用 `jsonPayload.context.event="authn_token_reuse"` 查詢 → Create alert），通知管道用第 2 步建立的。這個事件出現代表 refresh token 很可能被偷。
