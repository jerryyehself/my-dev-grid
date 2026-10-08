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

Laravel 預設不回報的例外（驗證失敗、404、未登入、`abort(4xx)` 等）不會產生日誌，所以也不會出現在 Error Reporting。

## 相關設定在哪

| 項目 | 位置 |
|---|---|
| channel、formatter、processor | `config/logging.php` 的 `cloud_run` |
| request id | `app/Http/Middleware/AssignRequestId.php`，在 `bootstrap/app.php` 以 `prepend` 掛成最外層全域 middleware |
| JSON 格式 | `app/Logging/CloudLoggingFormatter.php`、`app/Logging/RequestLogProcessor.php` |
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

再替它建告警：主控台 Monitoring → Uptime checks → 點這個檢查 → Create alert → 通知管道勾上一步的 email 與手機。條件建議「失敗地區數 ≥ 2」，**不要設成 1 個地區失敗就通知**：服務 `min-instances=0`，冷啟動偶發的 502（#91）會讓單一地區的單次檢查失敗，門檻太低會變成誤報。

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
