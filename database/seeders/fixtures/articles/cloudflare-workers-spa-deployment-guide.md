> 這份文件記錄把 `my-dev-grid-front`（Vue 3 + Vite 純 SPA）部署到 Cloudflare 的完整過程，包含選型理由、兩次真的踩到的部署失敗、根因與修法、CI 與 CD 的分工，以及部署完之後怎麼先擋住公開存取。所有踩坑都是這次實際部署時發生的，不是紙上談兵。

## 一、為什麼選 Cloudflare（而不是 Vercel）

免費前端託管的業界比較（詳見 `reports/frontend-build-tooling-qa.md` 第三節）：

| | Vercel | Cloudflare Pages/Workers | Netlify | GitHub Pages |
|---|---|---|---|---|
| 免費頻寬 | 100GB/月 | **無限**（只限建置次數，500 次/月） | 100GB/月（超過收費） | 無限但陽春 |
| 招牌優勢 | Next.js 官方親兒子 | 頻寬最大方 | 老牌、功能齊全 | 最簡單 |
| 對 Vue 的加成 | **沒有** | 沒有框架偏好，通用託管 | 沒有 | 沒有 |

**關鍵澄清**：一開始誤把「Vercel 業界排名第一」直接套用給這個專案，但那個排名是**為 Next.js/React 加權過的**——Vercel 對 Nuxt（更不用說純 Vue SPA）沒有任何框架血緣優勢，純粹當成一般靜態託管平台對待，跟 Cloudflare Pages 一視同仁。既然這個專案是純 Vue 3 + Vite SPA（連 Nuxt 都没用），唯一有意義的差異只剩頻寬額度，**Cloudflare 因為頻寬無限而勝出**。

## 二、Cloudflare Pages 現在其實是 Workers + Static Assets

實際部署時發現：這個帳號建立的 Cloudflare Pages 專案，底層執行環境其實是 **Workers 的 static assets 機制**（deploy log 顯示路徑是 `workers/scripts/...`、`workers/services/...`），不是傳統認知裡「Pages 是一套獨立系統」。這帶來兩個直接後果：

1. Workers static assets **內建 `.html`/`/index` 路徑正規化行為**，跟傳統 Netlify 風格的 `public/_redirects` 檔案會互相衝突（見下一節）。
2. SPA fallback 的正確設定方式是 `wrangler.jsonc`（Workers 的設定檔），不是 `_redirects`。

## 三、第一次部署失敗：`_redirects` 觸發無限迴圈

Vue Router 用 `createWebHistory`（真實網址、非 hash 模式），直接輸入深層網址（例如 `/articles/5`）或重新整理時，需要一個「未知路徑一律導回 `index.html`」的 fallback 設定，不然會 404。

**第一次的做法**（錯誤）：加 `public/_redirects`，內容 `/* /index.html 200`。這是 Netlify/傳統 Cloudflare Pages 的標準寫法，但這次部署直接失敗：

```
Invalid _redirects configuration:
Line 1: Infinite loop detected in this rule. This would cause a redirect to
strip `.html` or `/index` and end up triggering this rule again. [code: 100324]
```

**根因**：Workers static assets 自己的路徑正規化會把 `/index.html` 這類請求做內部處理，這條 catch-all 規則跟它自己的正規化互相觸發，被平台判定成無限迴圈，直接擋下整個部署——這是 Workers static assets 機制下的已知行為，不是這個專案設定錯誤。

**正確做法**：拿掉 `public/_redirects`，改在 `wrangler.jsonc` 設定：

```jsonc
{
  "name": "my-dev-grid-front",
  "compatibility_date": "2026-09-25",
  "assets": {
    "directory": "./dist",
    "not_found_handling": "single-page-application"
  }
}
```

`not_found_handling: "single-page-application"` 是 Cloudflare 官方文件（[Single Page Application · Cloudflare Workers docs](https://developers.cloudflare.com/workers/static-assets/routing/single-page-application/)）記載的正確 SPA fallback 設定，不需要額外的 `main` worker 進入點，跟平台自己的路徑正規化不衝突。

## 四、第二次部署失敗：缺少 `previews` 區塊

拿掉 `_redirects`、加上 `wrangler.jsonc` 之後，build 本身成功了（Vite 正常產出 `dist/`），但部署又失敗：

```
✘ [ERROR] Your Wrangler configuration is missing a `previews` block to run this command.
```

**根因**：這個 Cloudflare 專案的部署指令設定成 `npx wrangler preview`（用於 PR 預覽部署），這個指令要求設定檔裡一定要有 `previews` 區塊才會執行——內容可以是空的，`assets`/`compatibility_date` 這些設定留在最外層就好，不用搬進 `previews` 裡面。

**修法**：

```jsonc
{
  "name": "my-dev-grid-front",
  "compatibility_date": "2026-09-25",
  "assets": {
    "directory": "./dist",
    "not_found_handling": "single-page-application"
  },
  "previews": {}
}
```

補上之後這次真的部署成功。

## 五、CI（程式碼正確性）跟 CD（實際部署）是兩組獨立檢查

這次過程中很清楚看到一件事：PR 上同時掛著兩組獨立的檢查，**兩組都要過才能合併**：

1. **GitHub Actions（`ci.yml`）**：lint、type-check、test、build——只驗證程式碼本身正不正確、建置得起來，完全不會碰 Cloudflare。
2. **Cloudflare 自己的整合（`Workers Builds: my-dev-grid-front`）**：不只是建置，會**真的跑一次部署**到 Cloudflare 平台——上面兩次失敗（`_redirects` 無限迴圈、`previews` 缺失）都是這組檢查抓到的，純粹的 lint/type-check/build 完全測不出這類「build 得起來但部署設定有誤」的問題。

GitHub 的 `mergeable_state` 在只有第 1 組過、第 2 組還沒過時會顯示 `unstable`，不是 `clean`——合併前兩組都要看。

## 六、部署觸發規則：push 到哪個分支決定部署到哪裡

Cloudflare 的 GitHub 整合是「push 就建置」，但分兩種結果：

- **push 到 `main`**（production branch，可在 Workers & Pages → 該服務 → Settings → Build → **Branch control** 查看/修改）：觸發**正式環境**部署，真的更新正式網址。
- **push 到其他分支**（例如 PR 分支）：觸發**預覽部署**，有獨立的預覽網址，不影響正式站。

**額度提醒**：免費版建置次數上限每月 500 次，預覽跟正式部署都算——正常開發節奏不會碰到，但如果打算很密集地每改一行就 push 一次，要留意這個上限。

## 七、因為這次的教訓調整的分支策略

這次把 `_redirects`/`wrangler.jsonc` 這類部署設定檔的 PR，誤判成「一般前端實作細節」直接合併掉了兩次——但這些檔案直接對應 `CLAUDE.md` 裡「部署腳本...要先問」那條規則，不該自動合併。事後調整：

- `main` 接正式部署，之後開發改以 **`develop`** 為整合分支：feature 分支對 `develop` 開 PR，`develop` 穩定後才由使用者決定要不要合併進 `main` 觸發正式部署。
- `main` 現在也設了 GitHub 分支保護規則，**一律要走 PR，不能直接 push**（`GH013: Changes must be made through a pull request`）——這剛好跟上面的分支策略互相呼應，多一層保險。
- CI（`ci.yml`）觸發分支同步加上 `develop`，讓對 `develop` 開的 PR 也看得到檢查。
- `wrangler.jsonc`/`public/_redirects` 這類部署設定檔，即使改動本身是在修一個部署錯誤、驗證條件（CI 全過、無衝突、無未解決 comment）也全過，一樣要先問使用者，不能因為「這是修 bug」就自動歸類成可以直接合併。

## 八、部署完怎麼找到網址

**Workers & Pages → 點進該服務（`my-dev-grid-front`）→ Domains 頁籤**（較舊版介面是 Settings → Domains & Routes），會列出：

- **生產**網址：`my-dev-grid-front.<你的 workers.dev 子網域>.workers.dev`
- **預覽**網址：`*-my-dev-grid-front.<子網域>.workers.dev`

子網域（例如 `my-subdomain`）是綁在 Cloudflare 帳號上的固定字串，不是專案名稱的一部分。

## 九、部署完但還不想公開：Cloudflare Access

需求：網站已經部署、有真實網址，但還沒準備好給人看，只想自己能開。

**做法**：Cloudflare 今年（2026-08）推出的單一 Worker Access 開關，不用手動兜一整套 Zero Trust 設定：

1. **前置作業（帳號層級、只需要做一次）**：左側選單 **Zero Trust** → 第一次進去要設一個 **Team name**（純識別用的子網域，例如 `my-team` → `my-team.cloudflareaccess.com`，不影響網站本身網址）→ 選 **Zero Trust Free** 方案（$0，50 個名額，不會被收費；「名額」= 可登入驗證的 email 數量，自己用只佔 1 個）。
2. **回到該 Worker**：Workers & Pages → `my-dev-grid-front` → **Access** 頁籤。
3. **範圍**：選 **全部流量**（不是「僅預覽」，否則正式站網址還是誰都能看）。
4. **認證原則 → 新增政策**：認證方式的下拉選單預設是「Cloudflare 帳戶」（等於任何有 Cloudflare 帳戶的人都能登入，太寬鬆），改選 **Email**，填入自己的 email；動作維持 **允許**；**工作階段持續時間**（登入一次後多久內不用重新驗證，跟「誰能登入」無關，純粹是重複驗證的頻率，選預設值或長一點都可以，例如 24 小時）。

**運作方式**：任何人打開這個網址，會先跳出頁面要求輸入 email → Cloudflare 寄一組**一次性驗證碼**到那個信箱 → 要打開信箱把驗證碼填回網頁才能真的進去。所以是**需要真的能收到那個信箱的信**才能看，不是知道 email 地址就能進去——等同只有自己能看，是真的有效的存取控制。想公開的時候，把這個 Access 設定關掉即可，不影響部署本身，是可逆的開關。

## 十、綁自訂網域，但上線前繼續鎖著（2026-09-30 實作）

網域 `jerrylib.com` 在 Cloudflare Registrar 購買（名稱伺服器自動就是 Cloudflare 的，DNSSEC 在 **DNS → 設定** 一鍵啟用，DS 記錄會自動補；2026-09-30 啟用後幾小時內生效，可以用 `curl -s https://rdap.cloudflare.com/rdap/v1/domain/jerrylib.com` 看 `secureDNS.delegationSigned` 是不是 `true` 來確認）。網站放在**主網域本身**，子網域留給以後的其他專案（例如 `isbn.jerrylib.com`）。

**要注意：第九節那個 Worker 的 Access 開關只管 `workers.dev` 網址，不會自動保護自訂網域。** 所以順序要反過來——**先設 Access，再綁網域**，不然綁好到補上 Access 之間，網站是公開的。

1. **Zero Trust → Access 控制 → 應用程式 → 新增應用程式 → 自我裝載**
   - 名稱：`jerrylib 上線前`（正式公開時刪的就是這個）。
   - 目的地／公開主機名稱：子網域留空、網域選 `jerrylib.com`、路徑留空。
   - Access 原則：新建一條，動作 **允許**，規則 **包含 → 電子郵件**（完整地址）。**不要用「電子郵件網域」**——填 `gmail.com` 等於所有 Gmail 使用者都能進。
   - 認證、MFA、工作階段（24 小時）都用預設值。
   - 第九節自動建的那個應用程式（`my-dev-grid-front - Cloudflare Workers`）不用改，兩個各管各的網址。
2. **Workers 和 Pages → `my-dev-grid-front` → 設定 → 自訂網域與路由 → 新增 → 自訂網域** → `jerrylib.com`，環境選**正式**（預覽是分支／PR 的測試版本，會一直變）。DNS 記錄和 HTTPS 憑證 Cloudflare 自動建，不要自己手動加 A／CNAME 記錄。
3. **驗證**（從外部、沒登入的狀態）：`curl -sI https://jerrylib.com/` 跟任何深層路徑（例如 `/about`）都應該回 `302`，導向 `<team>.cloudflareaccess.com/cdn-cgi/access/login/jerrylib.com…`，而不是直接回網站的 `200`。

**正式公開時要做的事**：刪掉 `jerrylib 上線前` 這個 Access 應用程式；`workers.dev` 網址可以在 Worker 設定裡直接停用（只留一個正式網址，也避免後端 CORS 只放行一個來源時，從 `workers.dev` 進來的人全部被擋）。

**還沒做的**：`www.jerrylib.com` 目前沒有 DNS 記錄（打不開），之後加一條導向規則轉到主網域；後端的 `FRONTEND_URL` 改填 `https://jerrylib.com`；`@jerrylib.com` 信箱要用 Email Routing 或設成「不寄信」的防偽冒記錄，還沒決定。
