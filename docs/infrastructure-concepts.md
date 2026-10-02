# 基礎架構決策紀錄（跨雲端平台參考用）

`docs/deployment-gcp.md` 記的是「在 GCP 上具體怎麼做」（`gcloud` 指令、資源名稱）。
這份文件記的是**上一層的概念**——每個服務解決的是什麼問題、為什麼選這種形狀、
換到別的 GCP 專案或換到 AWS 時，該找哪個對應服務。指令會過期、會換平台；這幾個
判斷不會，這才是真正值得留下來的部分。

## 服務對照表

| 要解決的問題 | 這個專案在 GCP 上用的 | AWS 對應服務 | 為什麼選這個形狀 |
|---|---|---|---|
| 跑容器化的 Web 應用，流量小、希望沒人用時不收費 | **Cloud Run**（`--min-instances=0`） | **App Runner**（最接近的 scale-to-zero 容器託管）；或 **Fargate + ALB**（更多控制、但沒有原生 scale-to-zero，閒置時 ALB 仍計費） | 個人 side project 流量稀疏，「沒流量時真的是 $0」比「效能/控制力」重要得多 |
| 正式關聯式資料庫（Postgres） | **Cloud SQL for PostgreSQL**（`db-f1-micro`，最小規格） | **RDS for PostgreSQL**（`db.t4g.micro` 等最小規格） | 兩邊都**不是** serverless——不管有沒有流量，instance 存在就按小時計費，這是要接受的固定成本，不是這次選型能省掉的 |
| 想要「真正的」資料庫 scale-to-zero | （GCP 目前沒有對等的免費/低成本選項） | **Aurora Serverless v2** | 這次沒選，因為 v2 沒有真正縮到 0（有最低 ACU 費用），對這個專案的規模不見得比固定小 instance 划算，但值得知道 AWS 這邊多一個選項 |
| 存放敏感設定值（金鑰、密碼） | **Secret Manager** | **AWS Secrets Manager**（幾乎同名同概念）；更省錢的替代是 **Systems Manager Parameter Store**（SecureString 類型） | 兩邊都有小額免費額度，這個規模用不到收費門檻 |
| 存放 CI build 出來的容器 image | **Artifact Registry** | **ECR (Elastic Container Registry)** | 概念完全一樣：私有容器 registry，按儲存空間計費，image 會累積、需要 retention policy |
| CI/CD 免長期金鑰登入雲端 | **Workload Identity Federation**，信任 GitHub Actions 的 OIDC token | **IAM OIDC Identity Provider** + `sts:AssumeRoleWithWebIdentity` | 這是產業標準模式（OIDC federation for CI/CD），GCP 跟 AWS 只是各自的實作/命名不同，機制完全一樣：讓 GitHub 簽發的短期身分令牌換取雲端這邊的臨時憑證，永遠不用下載、保管一把長期有效的金鑰檔案 |

## 跨平台都適用的判斷原則（不是 GCP 特有）

這些是這次設計裡真正「學會了、換平台也用得上」的部分：

1. **兩個身分分開，不要混用**：「CI/CD 拿來部署的身分」跟「應用程式運行時的身分」權限範圍完全不同，這次是 `my-dev-grid-deployer`（能 build/push/deploy）vs `my-dev-grid-run`（只能讀 Secret Manager + 連資料庫）。換到 AWS 一樣要分兩個 IAM Role，不要為了省事共用一個——共用等於運行中的應用程式繼承了部署身分的權限，攻擊面不必要地變大。
2. **Region 全部釘死同一個**：同一個專案裡的資源跨 region 互打會有額外網路費用，這條規則在任何雲端平台都成立，不是 GCP 特有的坑。
3. **Scale-to-zero 是低流量個人專案的預設省錢手段**，只要平台提供這個選項就該用（GCP 的 `--min-instances=0`、AWS App Runner 的自動暫停），除非流量規模大到冷啟動延遲變成真的問題。
4. **免費試用額度是「時間到就結束」，不是「額度用完才結束」**——GCP 這次是 $300/90 天，AWS 的免費層也是類似的「額度 + 時間雙重上限」設計，申請任何一邊都要抓「到期日」而不是只抓「額度金額」來規劃。
5. **無狀態的服務永遠比常駐的資料庫便宜**：這次帳單真正的持續支出來源是 Cloud SQL,不是 Cloud Run——任何平台的「serverless 運算」跟「傳統資料庫」都會有這種不對稱,規劃預算時資料庫那塊才是真正該精算的地方。
6. **最小權限原則落到 IAM role 上**：每個 service account/IAM role 只給它實際要用到的最小權限集合（例如 deployer 不需要 `secretmanager.secretAccessor`,runtime 不需要 `run.admin`),這個判斷邏輯完全平台無關。

## 為什麼是 PostgreSQL，不是 MySQL（2026-10-02 補記）

2026-08-26 技術棧定案時直接選了 PostgreSQL，當時沒有留下理由；2026-10-02 建 Cloud SQL 時使用者問起（主控台的免費試用預設 MySQL），比較後確認維持 PostgreSQL，使用者要求記下來。

- **程式本身不挑資料庫**：後端查詢都走 Eloquent，沒有用到任何一邊的專屬語法（`DB::raw` 只用在帶字串常數的欄位別名）。本機開發用 SQLite。所以這不是「非 PostgreSQL 不可」，是「PostgreSQL 留下的擴展空間比較多」。
- **擴展空間（Cloud SQL 有支援的擴充，查證於 2026-10-02，[Cloud SQL 擴充清單](https://cloud.google.com/sql/docs/postgres/extensions)）**：
  - `pgvector`：存語意向量，之後可以做「跟這篇文章相關的筆記」這類推薦，適合知識庫網站。
  - `ltree`：樹狀階層，對應 scope 的父子分類。
  - JSON 欄位的查詢能力也比 MySQL 完整。
- **「圖形支援比較好」要打折**：PostgreSQL 的圖形資料庫擴充 Apache AGE（[官網](https://age.apache.org)，可用 Cypher 查詢）**不在 Cloud SQL 的擴充清單上**，要用得自己架 PostgreSQL 或換平台。「沿著關係找好幾層」用的遞迴查詢（`WITH RECURSIVE`），PostgreSQL 和 MySQL 8 都有（[MySQL 手冊](https://dev.mysql.com/doc/refman/8.0/en/with.html)），這點打平。以目前幾十個節點的規模，路徑查詢由後端程式自己算，不需要圖形資料庫。
- **MySQL 對這個專案沒有獨有的優勢**：Laravel 兩邊支援一樣完整；主控台的免費試用是 MySQL、us-central1、Enterprise Plus 大規格，30 天後要升級，不適合長期放網站。唯一的非技術考量是台灣 PHP／Laravel 職缺較常見 MySQL（觀察，沒有數據），但 SQL 基本功通用。
- **換掉的成本**：部署 workflow 寫死 `DB_CONNECTION=pgsql`／5432，CI 同時測 SQLite 與 PostgreSQL 16（2026-09-09 因一個只在 PostgreSQL 出現的 bug 加的），都要重做。
- **版本固定 PostgreSQL 16**：主控台預設是 18，但 CI 用 `postgres:16`，正式環境跟測試同版。要升版時 CI 一起改。

## 這次具體選擇的規格（會過期的部分，僅供對照）

- Region: `asia-east1`
- Cloud SQL tier: `db-f1-micro`，10GB 儲存
- Cloud Run: `--min-instances=0`
- 免費試用額度：$300，90 天效期（GCP，查證於 2026-09）

這幾個數字會隨時間/促銷方案改變，實際規劃時上官方 pricing 頁面查最新值，這份文件
不負責保持數字最新——它負責保留「為什麼是這個形狀」，不負責保留「現在確切多少
錢」。
