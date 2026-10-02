> 這份盤點涵蓋 `my-dev-grid-skills` 專案目前**現行中**與**已停用**的所有跨 session 協作／文件管理機制，只收「真的有實際施作過、可以講出使用時機跟效果」的機制——不含單純被評估過但從未採用的方案（那些列在文末「考慮過但未採用」，不佔正式欄位，因為它們沒有「施作期間」可講）。
>
> 「效果如何」欄位裡標成外部引用的數字，來源是這個專案研究時查到的論文/技術文章，**不是這個專案自己重現測量出來的**（除了詞彙表 A/B 測試跟文件地圖的變異度數字，那兩項是這個專案自己實測的）；標成「內部慣例，無正式引用」的，是工程常識性設計，沒有對照組數據。

## 現行機制

| 機制 | 使用時機 | 施作期間 | 效果如何 | 原型/參考來源 |
|---|---|---|---|---|
| 文件導覽地圖（`docs/README.md` §1 冷啟動閱讀順序） | 主 session 剛輪替、或新增文件時建立與維護 | 2026-09-01 起，持續中 | 33–44% 更少導覽步驟（Wilcoxon p=0.009, Cohen's d=0.92）；生成的描述子盲測正確率 100% vs 80%（p=0.002）；**7,012 個 Claude Code session 的場域研究顯示行為變異度降低 52%**。**主張效益是降變異度，不是降 token**——地圖本身沒有明顯降低平均 token 用量，只是讓剛輪替的 session 不用「全讀或亂猜」。 | Formal architecture descriptors as navigation primitives — [arxiv.org/pdf/2604.13108](https://arxiv.org/pdf/2604.13108) |
| 詞彙表（`docs/glossary.md`，A/B 驗證過） | 頻繁重複出現、容易歧義的專案自訂詞彙／meta-doc 用語 | 2026-09-01 建立，持續中 | tool-call 次數/延遲改善 **−27%/−40%**，token 數幾乎沒變（−1.7%），一個 citation-accuracy 案例改善。**沒有查到任何既有的 agent 導向受控詞彙表先例**，是這個專案自己 A/B 測出來的，沒有外部原型可引。 | 本專案自己的 A/B 實驗（`documentation-map-methodology.md` §"Built and A/B-validated anyway"），非外部論文 |
| 快查表（`<file>.quickref.md`，只列規則本身不列理由/範例） | 每份英文主版文件搭配維護一份，日常查閱優先讀這份，需要細節才展開讀全文 | 隨對應主文件建立起持續維護 | 沒有獨立做過 A/B 測試，邏輯上跟文件地圖同一種「先讀索引再展開」的變異度縮減效果，但沒有實測數字。已知風險：quickref/zh-TW/索引列三份衍生檔案要手動同步（3N 成長），2026-09-04 才修過一次偵測範圍太窄的漏洞。 | 邏輯延伸自上方 navigation-primitives 研究，非獨立驗證 |
| Readback（執行前先覆述理解，限定觸發，非全面） | 只在「任務有歧義／影響範圍大」**且**「做錯代價高」（例如 dispatch `model: opus` 或其他判斷密集型工作）同時成立才觸發；Haiku 派工完全不用 | 2026-09-01 起，持續中 | Verification-before-commitment 讓任務成功率 **52%→73%**，45% 失敗可恢復（p=0.0005）；確定性驗證可抓到 60–96% 失敗且零誤判；某機器人 propose-execute 框架省下 **80% LLM 呼叫次數**；74% 生產環境 agent 系統已用同樣的人機確認模式。 | Real-Time Detection and Repair of LLM Agent Failures — [arxiv.org/html/2608.02464v1](https://arxiv.org/html/2608.02464v1) |
| Verify before commit（同一原則涵蓋 5 種情境：subagent 自我回報／「已發生過」的宣稱／本地分支對遠端的認知／研究 subagent 自己的結論／壓縮後摘要） | 任何「即將被信任並據以行動」的資訊，動手前先對照真正來源查一次 | 由多次獨立踩坑逐步歸納成一條原則，持續中 | 與上方 readback 同一份研究佐證（52%→73% 任務成功率） | 同上 |
| Model routing（機械型工作明確指定 `model: "haiku"`） | 查找/驗證/小範圍明確修正這類定義清楚、不需要判斷力的工作 | 2026-09-01 起，持續中 | 這個專案沒有做過正式前後測量，純粹是「便宜模型做便宜的事」的常識性省錢 | 內部慣例，無正式引用 |
| 兩層跨 session 訊息機制（`create_trigger`+`fire_trigger` 緊急／`create_trigger`+`run_once_at` 不緊急） | 需要真的觸發喚醒對方的跨 session 溝通，依緊急程度分層挑一種，不能同時兩種 | 2026-09-04 從三層收斂成兩層（第三層即下方已退場的 `inbox.md`） | 避免同一則訊息因為 fire_trigger+run_once_at 同時生效而重複送達；設計目的是避免浪費喚醒成本，沒有正式測量省下多少 | 內部設計，無外部引用 |
| 逐檔 commit（多檔自動化流程每處理完一個單位就 commit+push，不累積到最後一次性 commit） | 每日文件精簡排程、批次翻譯等 loop 型任務 | 2026-09-01 起，持續中 | 把中斷後的重做成本從「整批重來」降到「只補沒做完的部分」，工程常識，無正式測量數字 | 內部設計，無外部引用 |
| 執行證據／問題紀錄檔（`docs/daily-doc-maintenance-log.md`：乾淨回 `finished`，有問題才詳細回報+寫檔） | 每日文件精簡排程的回報方式 | 2026-09-04 新增，持續中 | 直接針對 `inbox.md` 已證實過的「靜默失敗偵測不到」問題設計，但機制本身才剛上線，還沒有自己的獨立效果數字 | 自我參照 `inbox.md` 0% 讀取率的教訓，非外部研究 |
| 決策/技術債分類帳（`decision-register.md` ADR 索引 ＋ `management-debt-ledger.md`） | 任何正式決定（進索引）或刻意擱置的決定（進分類帳）——「決定沒進 git 就不算存在」 | 持續中 | 避免同一個決定被重複討論或遺失；ADR 格式本身是業界既有做法，這個專案借用其結構，沒有做量化前後對照 | Architecture Decision Records（Nygard 原始格式＋MADR）— [adr.github.io](https://adr.github.io/) |

## 已停用機制

| 機制 | 使用時機（已作廢） | 施作期間 | 效果如何 | 原型/參考來源 |
|---|---|---|---|---|
| `inbox.md`（跨 session 純 FYI 被動留言板） | 不需要對方立即回應、純粹留痕給對方「下次自然醒來」時順便看到 | 2026-08-26 建立 → **2026-09-04 正式退場凍結**，約 9 天 | **5 天實測 0% 讀取率**。根因：「檢查 inbox.md」這個步驟掛在一個角色 session 從未真正觸發過的流程上。查證業界類似做法（tuple space/Linda、actor mailbox/Erlang-Akka、Kafka 消費者 log、MetaGPT pub/sub、LangGraph 排程共享狀態）都沒有真正零成本、純被動、不搭配任何獨立可靠觸發機制的先例；唯一站得住腳的模式是 **gossip protocol（如 SWIM）搭上一個本來就會固定觸發的心跳訊號**。 | 這次專案自己派出的查證 subagent 掃過的產業案例（tuple space/actor mailbox/Kafka/MetaGPT/LangGraph/gossip-SWIM），屬於這次對話整理的綜合案例掃描，沒有留下單一可引用的論文網址 |
| 常駐角色 session（`[pm]`/`[frontend]`/`[backend]`/`[visual]`） | 分工明確、各自累積長期脈絡的角色制，任務直接派給對應角色的常駐 session | 專案早期建立 → **2026-09-01 全部退場**，改成一次性 `Agent` subagent 派工 | 退場依據是「三問測試」：(1) 要不要獨立等外部事件？(2) 要不要被別人事後接觸？(3) 累積的長期脈絡是否真的有價值？多數任務三個都是「否」，常駐反而增加輪替/閒置成本。沒有做退場前後的正式 token 用量對比。 | 內部三問測試設計，無外部引用 |

## 考慮過但未採用（沒有「施作期間」，故不進上面兩張表）

- **正式索引典/受控詞彙系統**（ISO 25964／SKOS，broader/narrower/related terms）——查無 agent 導向先例，且這個規模（約 30 個檔案、約 5 個歧義詞）比這套機制要攤銷的規模小了好幾個數量級，裁定先做輕量詞彙表就好。
- **PARA（Projects/Areas/Resources/Archives）文件頂層重組**——沒有證據顯示這個分法比現有 A–E 讀者需求分法更好，換掉只有重分類成本、沒有可量測的好處。
- **正式本體論工程（formal ontology engineering）**——對「省 token」這個目的沒有幫助；它的價值在產品本身的 Scope/Relation 模型設計，是另一個問題，不屬於這份盤點範圍。

---
*整理自 2026-09-01～2026-09-04 這幾天在 `my-dev-grid-skills` 專案裡的討論與查證過程，來源文件：`docs/documentation-map-methodology.md`、`docs/knowledge-organization-research-synthesis.md`、`docs/engineering-principles.md`、`docs/decision-register.md`、`docs/management-debt-ledger.md`。*
