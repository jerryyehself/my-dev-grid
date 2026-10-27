# 程式慣例

這個資料夾是後端程式慣例的唯一來源。不管是人、Claude 還是其他 agent，寫程式前都照這裡的規則；`CLAUDE.md` 只放連結，不重複內容。

| 檔案                           | 管什麼                                                                   |
| ------------------------------ | ------------------------------------------------------------------------ |
| [`structure.md`](structure.md) | 檔案放哪裡、各層負責什麼（controller、FormRequest、Resource、Policy、Service、Model） |
| [`naming.md`](naming.md)       | 類別、方法、資料表、欄位、路由、指令怎麼命名；程式碼風格（PSR／Pint）    |
| [`api.md`](api.md)             | 路由、回應形狀、錯誤格式、API 文件（Scramble）的寫法                     |
| [`data.md`](data.md)           | migration 與 seeder 的分工、資料修正、零停機的順序                       |
| [`testing.md`](testing.md)     | 測試格式與「好的測試」                                                   |
| [`security.md`](security.md)   | 授權、輸入驗證、網址、草稿可見性、憑證與日誌                             |
| [`issues.md`](issues.md)       | 開 issue：段落、大小、標籤、不能寫的內容                                 |

已經成文、不在這裡的規則：

- 語言、Git 授權、高風險改動與 PR 說明要寫的東西：`CLAUDE.md`
- 分次部署、PostgreSQL 鎖、回滾的細節：[`docs/zero-downtime-migrations.md`](../zero-downtime-migrations.md)
- 日誌欄位與安全事件表：[`docs/observability.md`](../observability.md)
- 部署：[`docs/deployment-gcp.md`](../deployment-gcp.md)

## 標記

- 沒標記的規則：現有程式碼已經這樣做，寫下來是為了讓新程式照著做。
- 「（提議）」：現有程式碼還沒照做，新程式照新規則，舊的改到時再補。
- 各檔最後的「目前已知不符的地方」：下次改到那個檔案時順手修，不用單獨開 PR（除非另外寫明）。

## 怎麼改這裡的規則

規則跟現有程式碼不一致時，可以先改規則，也可以先改程式碼，但同一個 PR 裡要讓兩邊對得上。不要在別的文件另外寫一份規則。
