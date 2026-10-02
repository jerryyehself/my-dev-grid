> 這份文件整理的是一段**教育性問答**，起點是使用者提到「以前最多學到 jQuery，這塊比較不熟」，針對 `my-dev-grid-front` 現況延伸出的一連串問題。**全程沒有異動任何程式碼**，純粹是建立概念用的參考筆記。專案現況：`my-dev-grid-front` 是 Vue 3 + Vite 的純 SPA（`createWebHistory`，沒有 SSR），部署在 GCP Cloud Run。

## 一、Laravel Sanctum 兩種認證模式——token 跟 cookie 到底差在哪

### 1. 運作機制本身不同

| | SPA cookie 模式 | API token 模式 |
|---|---|---|
| 誰發、怎麼帶 | 後端登入成功後發一個 **session cookie**，瀏覽器自動存、自動帶——前端只要 `fetch` 加 `credentials: 'include'`，不用自己管 token | 後端發一組**明文字串**（Sanctum 格式是 `id\|明文`，資料庫只存 SHA-256 雜湊值，不是 JWT），前端要自己存起來，每次請求手動加 `Authorization: Bearer <token>` header |
| 誰主動判斷「這個請求算不算已登入」 | 瀏覽器自動決定要不要帶 cookie（同源／CORS 規則） | 前端程式碼自己決定要不要帶這個 header——完全手動 |
| 登出/撤銷 | 後端刪 session 記錄，cookie 自然失效 | 後端刪 `personal_access_tokens` 表裡那一列，該 token 立刻失效——**可以只撤銷某一支裝置的 token，不影響其他裝置**，cookie 模式做不到這麼細的粒度（除非另外設計多 session 追蹤） |

### 2. 網域限制——這是這個專案卡住的真正原因

**cookie 模式要求前後端共用同一個 top-level domain**（已查證 [Laravel 官方文件](https://laravel.com/docs/13.x/sanctum)：子網域可以不同，例如 `app.mydomain.com`／`api.mydomain.com`，但根網域一定要一樣——瀏覽器不會讓一個網域的回應幫另一個完全無關的網域設 cookie）。**token 模式完全沒有這個限制**，天生跨網域友善，這也是為什麼很多前後端分離、各自部署在不同服務商的專案預設選 token。

這個專案現況：後端在 Cloud Run 用預設網域（`*.run.app`），前端沒有自己的網域——兩邊沒有共同根網域可用，這正是 cookie 模式目前做不到、而不是「選了比較差的方案」的原因。

### 3. 安全性權衡——各有各的弱點，不是誰絕對比較安全

- **cookie 模式的弱點是 CSRF**（跨站請求偽造：使用者已登入的瀏覽器被騙著發出一個他不知情的請求）。Sanctum 有內建 CSRF 防護機制來擋這個，但要正確設定才有效。
- **token 模式的弱點是 XSS**（如果網站被注入惡意 script，那段 script 能不能偷到 token，取決於 token 存在哪裡）。這是 token 模式**真正需要小心的地方**：2026 年的業界共識（OWASP 立場，[參考](https://www.wisp.blog/blog/understanding-token-storage-local-storage-vs-httponly-cookies)）是**不要把 token 存在 `localStorage`／`sessionStorage`**——任何跑在頁面上的 script（不管是不是惡意的）都能直接讀到裡面所有值。目前業界收斂的做法是：**短效的 access token 存在 JavaScript 記憶體裡（重新整理就消失，不落地）**，配一支存在 `httpOnly` cookie 裡的 refresh token 負責換發新的 access token——這樣即使被 XSS 打到，攻擊者也拿不到能長期使用的憑證。**這個專案如果真的走最簡單的 token 實作（存 `localStorage`，永久有效直到手動登出），要清楚知道這是用實作簡單換取一定的 XSS 風險，不是沒有代價的選擇**——規模夠小、攻擊面有限時這個代價通常是可接受的，但值得留意，不是「選了 token 模式就等於絕對安全」。

### 4. 實作複雜度

cookie 模式要多設定：CORS 的 `supports_credentials`、`SANCTUM_STATEFUL_DOMAINS`、`SESSION_DOMAIN` 前面加點涵蓋子網域、前端 `fetch`/axios 開 `withCredentials`。token 模式相對單純：後端發 token、前端存起來、每個請求手動加 header——這也是這個專案最後選它的原因之一，不只是網域限制逼的，本身也比較好上手。

### 5. 這個專案最後的決定（`decision-register.md` D-56，2026-09-22）

**先用 API token 模式**，`statefulApi()` 從跨 origin 認證路徑上拿掉，D-34（登入/授權）直接照這個基礎往下做。SPA cookie 模式沒有被否決，是被記成一筆**低優先度、不影響現在進度的技術債**（見 `management-debt-ledger.md`「Sanctum cookie 模式／自訂網域」列）：等之後真的買了自訂網域、前後端也都遷到同一個根網域下，才需要重新評估要不要換成 cookie 模式——換的理由會是想降低 token 儲存的 XSS 曝險，不是 token 模式本身有已知的功能缺陷。

## 二、前端能不能掛 GitHub Pages？

**可以，但有代價**：GitHub Pages 只能放靜態檔案（純前端沒問題），但**原生不支援伺服器端的 URL rewrite**，SPA 的前端路由（例如直接輸入網址進到某個深層頁面）在其他平台（Vercel/Netlify/Cloudflare Pages）都有一行設定就能做到的「未知路徑一律導回 `index.html`」，GitHub Pages 得靠 hash 路由模式（網址帶 `#`）或 404 頁面重導向的 trick 迂迴繞過。跨網域打後端 API 一樣要處理 CORS，這點跟其他託管平台一樣，不是 GitHub Pages 特有的問題。**優點是完全免費、設定最簡單**，適合純展示、不追求乾淨網址的專案；這個專案目前選擇 Cloud Run 是因為前後端本來就要考慮部署耦合，不是因為 GitHub Pages 技術上做不到。

## 三、前端雲端服務的市場版圖

**業界排序（依對這個規模專案的適配度，非死板名次）**：Vercel（Next.js 官方親兒子，DX 最完整）≈ Cloudflare Pages（免費額度最大方）> Netlify（老牌，功能齊全但近年聲量降低）> GitHub Pages（最陽春但最簡單）。

**免費方案額度（2026 現況，已查證）**：
- **Vercel Hobby**：100GB/月頻寬
- **Netlify Free**：100GB/月頻寬，超過後 $55/100GB
- **Cloudflare Pages Free**：**頻寬完全不計費**（每個方案含免費版都無限頻寬），只限制建置次數（免費版每月 500 次）

Cloudflare Pages 這點對高流量靜態站特別有吸引力，因為另外兩家超額都要額外收費。

**為什麼一般不跟後端雲端服務（GCP/AWS/Azure）掛在一起**：前端靜態託管跟後端運算是完全不同的計費/最佳化模型——前端要的是全球 CDN 邊緣節點 + 極簡的 git push 部署流程，後端要的是彈性運算/資料庫。Vercel/Netlify/Cloudflare Pages 都是圍繞「前端框架的開發體驗」重新設計整條部署鏈，而 AWS/GCP/Azure 雖然也有對應產品（Amplify、Firebase Hosting、Static Web Apps），卻普遍被認為 DX 不如專門玩家——推測原因是這些巨頭的核心誘因在企業級運算合約，前端靜態託管本身毛利低、非戰略要地，做到「堪用」就停手，沒有動力像 Vercel 一樣把 git push 到上線的每一步摩擦都磨掉。

**遷移成本**：先上 GitHub Pages 這類免費方案、之後真的要商用再換到 Vercel/Cloudflare，是合理路徑——純靜態前端搬家的成本主要是重新設定 DNS/建置流程，不是程式碼要改，遷移門檻本身不高，不用因為怕以後要換就現在過度設計。

**這塊值不值得學**：屬於「一次設定、之後很少碰」的技能，跟天天要寫的框架技能不同量級——概念上值得懂（CDN/邊緣渲染/CI 部署的基本原理），但不必花太多時間深挖某一家平台的專屬功能，除非工作內容真的常態接觸。

## 四、Next.js／Nuxt.js 的定位

**Next.js 是幹嘛的**：React 官方推薦的 meta-framework，把 SSR/SSG/ISR 這些渲染策略、路由、API routes 都包裝成約定優於配置的整合方案，由 Vercel 開發、也是 Vercel 平台的頭號親兒子。**Next.js 16 起，Turbopack（Vercel 自己寫的 Rust bundler）正式取代 webpack 成為 dev 跟 build 的預設值**——生產建置從 webpack 的 24.5 秒降到 5.7 秒（[nextjs.org 官方部落格](https://nextjs.org/blog/next-16-3-turbopack)、[progosling.com](https://progosling.com/en/dev-digest/2026-02/nextjs-16-turbopack-default)）。**注意 Turbopack 不是 Vite 生態的東西**，是 Vercel 自研、只服務 Next.js 自己的獨立專案。

**Nuxt.js 的定位**：Vue 版本的對應概念，但走**平台中立**路線——靠自己的 Nitro 引擎產生對應各家平台（Vercel／Netlify／Cloudflare）的部署格式，Nuxt 3 起預設用 **Vite** 當開發/建置引擎（不是自己寫 bundler）。

**Vercel 對 Nuxt 有沒有特殊優勢**：**沒有**——Vercel 是通用靜態/Serverless 託管平台，本來就能跑任何框架輸出的產物；Nuxt 對它沒有 Next.js 那種「官方框架」的血緣關係，Nitro 對每個平台的整合都是同一套一視同仁的 preset，沒有為 Vercel 特別加值。

**這個專案要不要為了文章規劃導入 Nuxt**：**建議不要**。現有規劃只是「文章/部落格內容」，這不是非 SSR/SSG 不可的需求——搜尋引擎索引可以靠 prerender 一次性生成，不需要整套 meta-framework 的路由/資料抓取慣例。導入 Nuxt 意味著現有的 Vue Router、`src/api/client.ts` 這些手刻邏輯都要照 Nuxt 的檔案慣例重新組織，換來的東西（SSR/自動路由）目前的需求規模用不到，成本效益不成比例。

## 五、Vite 跟 Nuxt 的關係——不是競品

這是個常見誤解，特地釐清：**Vite 是建置工具/開發伺服器**（處理「怎麼把原始碼變成瀏覽器能跑的東西、開發時怎麼快速看到更新」），**Nuxt 是應用框架**（處理「頁面路由怎麼組織、資料怎麼抓、SSR 怎麼跑」）。兩者是**不同層級、疊在一起用**的關係，不是二選一——Nuxt 3 起底層直接用 Vite 當它的建置引擎，就像 Next.js 底層用 Turbopack 一樣。

**檔案/資料夾慣例是誰負責的**：Nuxt 的 `pages/`（自動路由）、`components/`（自動註冊元件）、`composables/`（自動引入）這類「約定優於配置」慣例，是**框架層**（Nuxt 自己）的職責，**不是** webpack/Vite 這類建置工具的範圍——建置工具只管「怎麼打包」，不管「專案資料夾要長什麼樣」。這點也是釐清 Vite/Nuxt 分工的關鍵：換掉底層建置工具（webpack→Vite）不會影響這些檔案慣例，因為兩者本來就不同層。

## 六、webpack 退場了嗎？純 SPA 需要 Next/Nuxt 嗎？

**webpack 沒有真的退場，但已經不是新專案的預設選擇**。State of JS 2025 調查數據（[2025.stateofjs.com](https://2025.stateofjs.com/en-US/libraries/build-tools/)、[devclass.com](https://www.devclass.com/development/2026/02/10/javascript-survey-reveals-gripes-against-date-handling-webpack-and-nextjs-and-that-typescript-has-won/4090262)）：webpack 使用率仍有 **86.4%**（略高於 Vite 的 84.4%，反映大量既有專案的存量），但滿意度只有 **14% 正面／37% 負面**（淨值 -23），對照 Vite 的 **56% 正面／1% 負面**（淨值 +55）——用量高是因為舊專案還在用，不是新專案還在選它。**Vite 8（2026-03 穩定版）進一步把底層換成 Rolldown（Rust 寫的統一 bundler，取代原本 esbuild+Rollup 雙引擎），建置速度再提升一個量級**（[vite.dev 官方公告](https://vite.dev/blog/announcing-vite8)）。

**純 SPA、且後端已經獨立分開的情況下，Next.js／Nuxt.js 確實沒有必要**——這個推論基本正確。兩個 meta-framework 存在的核心價值是 SSR/SSG/API routes 這類「前後端整合在同一個專案裡」的能力；一個純 CSR 的 SPA、後端是獨立的 Laravel API，用不到這些整合能力，硬套上去只是多背一層框架重量（路由慣例、建置管線、部署格式都要跟著換），沒有換到對應的好處。**這正是 `my-dev-grid-front` 現在的狀態**：Vue 3 + Vite 的純 SPA，這個選擇本身沒有問題，不需要為了「看起來更潮」改採 Nuxt。

## 七、前端何時脫離「瀏覽器直譯」？HMR 是什麼？

**轉捩點大約在 2012-2015 年**，幾股力量匯合造成的，不是單一事件：
- **Browserify（2011）／Webpack（2012）**：讓「用 npm 模組寫的程式」能打包成瀏覽器看得懂的東西——**「打包」從這裡開始變成必要步驟**。
- **React JSX（2013）**：`<div>{x}</div>` 根本不是合法 JS，一定要經過 Babel 轉譯——**「轉譯」變成必要步驟的起點，不是效能優化，是語法本身不合法**。
- **Vue 單檔元件 `.vue`、TypeScript**：同樣是瀏覽器完全不認得的格式/語法，一定要編譯過才能跑。

**HMR 不等於「起 server + 手動整頁 refresh」**：後者（jQuery 時代就有，例如 `python -m http.server`）整頁重新載入時，記憶體裡所有 state（表單、路由位置、元件內部狀態）全部歸零；**HMR 只抽換改動的模組，不 reload 整頁，state 完整保留**——這是質的差異，不是「另一種看更新的方式」。Vite 的 HMR 特別快，是因為開發時直接靠瀏覽器原生 ES modules 逐檔載入，不用像傳統 webpack-dev-server 那樣重新打包整個 dependency graph。

**正式上線一定要 build，這個推論是對的**：只要原始碼有 JSX/TypeScript/`.vue` 檔，或想要 tree-shaking/minify 這些最佳化，上線前都得跑一次 build。這跟開發時的 dev server 是**完全獨立的兩件事**——dev server 解決「開發時怎麼即時看到效果」，build 解決「怎麼產出效能最佳化的正式產出物」，Vite 開發時用瀏覽器原生 ESM，正式 build 卻改走 Rollup（或 Vite 8 起的 Rolldown）打包，兩者甚至是不同引擎。

---

**這輪討論後續衍生出一個真的專案決定**：Sanctum 認證模式定案為 API token（`decision-register.md` D-56），D-34 因此解除阻塞；SPA cookie 模式改記成低優先度技術債，等之後買自訂網域再重新評估。其餘部分（webpack/Vite/Next/Nuxt/build 概念）純粹建立概念，沒有異動任何專案決定——目前的 Vue 3 + Vite 純 SPA 架構、部署在 Cloud Run 的選擇，不需要因為那部分討論改動任何東西。
