# 已知限制

這份清單記錄目前刻意先不處理的限制：問題是什麼、影響多大、為什麼現在不修、什麼時候該回頭處理。

- 每一條都要有「淘汰條件」。條件成立（問題已解決，或前提消失）就把整條刪掉，不留空殼。
- 每週上線前（流程見 [`release.md`](release.md)）檢查一次：「重新評估的條件」有沒有成立、「淘汰條件」有沒有達成、「程式位置」的檔案與行號有沒有對得上。
- 只寫已經公開在程式碼裡的事實與影響，不寫怎麼觸發。

## 文章匯入每篇都重算整張 `nomens`

- **問題**：匯入文章時，每一篇都會呼叫 `NomenResolver::resolveAll()`。它會載入整張 `nomens`，並重新讀取所有技術與專案來比對，所以匯入 N 篇就整張重算 N 次，而不是整批匯入完只算一次。
- **影響**：每篇文章多花的時間，跟 `nomens` 的列數與站上技術、專案的數量成正比。以目前的資料量來說，這段時間可以忽略。
- **為什麼現在不修**：影子模式（`NomenRecorder`）只是另外記一份，不影響原本寫的邊；每篇都算能讓單篇匯入完就拿到最新的解析結果，程式也比較單純。資料量小的時候，改成整批一次的收益看不出來。
- **重新評估的條件**：文章匯入明顯變慢；或站上技術與專案合計成長到數百筆以上。
- **淘汰條件**：匯入改成整批匯入完才呼叫一次 `resolveAll()`（做法方向：`ArticleAboutLinker` 只記錄，由 `ArticleSourceService` 在整批結束後呼叫 `NomenRecorder::resolve()`），這一條就刪掉。
- **程式位置**：`app/Services/NomenRecorder.php:85`（呼叫處）、`app/Services/NomenResolver.php:111`（`resolveAll()`）、`app/Services/ArticleAboutLinker.php:89`（每篇文章呼叫 `recordArticle()`）、`app/Services/ArticleSourceService.php:302`（逐篇呼叫 `ArticleAboutLinker::link()`）。

## 技術（techniques）沒有管理畫面

- **問題**：技術的寫入 API（`POST/PUT/DELETE /api/techniques`，需要登入）已經有了，但 `my-dev-grid-front` 沒有對應的管理畫面。技術資料目前靠 GitHub 同步自動 find-or-create 產生，沒有地方可以手動新增、改名或調整版本。
- **影響**：同步依語言、topic、套件推出的技術名稱就是最後的名稱；要修正只能直接呼叫 API 或改資料庫。
- **為什麼現在不修**：技術的內容目前幾乎都由同步產生，手動維護的需求還不多；管理畫面是一整塊前端功能，要等需求明確再做。
- **重新評估的條件**：需要經常手動修正技術的名稱、版本或分類，或同步推出的技術開始出現需要人工處理的重複或錯誤。
- **淘汰條件**：`my-dev-grid-front` 上線技術的管理畫面，這一條就刪掉。
- **程式位置**：`routes/api.php:79`（公開讀取）、`routes/api.php:95`（需登入的寫入）、`app/Services/SaveReposDataService.php:322`（`find_or_create_technique()`）。
