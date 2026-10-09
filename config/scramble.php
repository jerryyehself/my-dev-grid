<?php

return [
    /*
     * Which routes to document. String or array form; use Scramble::routes() for custom selection.
     *
     * 'api_path' => [
     *     'include' => 'api',
     *     'exclude' => ['api/internal'],
     * ],
     *
     * Without *, patterns match path segments (api matches api and api/users, not apiary).
     * With *, Str::is is used (e.g. api/v*).
     *
     * One static include → default server is /{include} and paths are stripped (/users).
     * Multiple includes or wildcards → server defaults to / and paths stay full (/api/users).
     * Override with `servers`, or use Scramble::registerApi() for separate bases.
     */
    'api_path' => 'api',

    /*
     * Your API domain. By default, app domain is used. This is also a part of the default API routes
     * matcher, so when implementing your own, make sure you use this config if needed.
     */
    'api_domain' => null,

    /*
     * The path where your OpenAPI specification will be exported.
     */
    'export_path' => 'api.json',

    /*
     * Cache configuration for the generated OpenAPI document.
     *
     * Use `scramble:cache` to warm the cache and `scramble:clear` to invalidate it.
     */
    'cache' => [
        'key' => 'scramble.openapi',
        'store' => 'file',
    ],

    'info' => [
        /*
         * API version.
         *
         * 只寫主版號：同一個主版號內的變更都向下相容（CHANGELOG.md、D-117），
         * 每次發版不用回來改這裡。
         */
        'version' => env('API_VERSION', '1'),

        /*
         * Description rendered on the home page of the API documentation (`/docs/api`).
         */
        'description' => <<<'MD'
            「IN / ARCHIVE」（jerrylib.com）的後端 API：知識圖譜的分類（scopes）、述詞（relations）、
            文件（documentations）、技術（techniques）、實作（implementations），以及圖譜查詢與登入。

            ## 驗證

            - 讀取（`GET`）都是公開的，不需要登入。
            - 寫入（`POST`／`PUT`／`DELETE`）需要 Sanctum API token：`Authorization: Bearer <token>`。
              token 由 `POST /auth/login` 取得，有效 15 分鐘，過期後用 `POST /auth/refresh` 以 refresh cookie 換發。
            - 寫入權限另外由伺服器端的 Policy 判斷，登入但沒有權限回 403。

            ## 錯誤格式

            4xx／5xx 一律是 [RFC 9457](https://www.rfc-editor.org/rfc/rfc9457) problem details，
            `Content-Type: application/problem+json`：

            ```json
            {
              "type": "about:blank",
              "title": "Unprocessable Content",
              "status": 422,
              "detail": "The name field is required.",
              "instance": "/api/scopes",
              "message": "The name field is required.",
              "errors": { "name": ["The name field is required."] }
            }
            ```

            `message`（所有錯誤）與 `errors`（422）是 Laravel 原本的欄位，為了向下相容而保留。
            500 不會包含例外內容或 stack trace。

            ## 頻率限制

            每個 IP 每分鐘 60 次；登入每分鐘 5 次，refresh／session／logout 每分鐘 30 次。
            超過時回 429，`Retry-After` header 是幾秒後可以再試。

            ## 分頁

            資源清單（`GET /scopes`、`/relations`、`/documentations`、`/techniques`、`/implementations`）
            不分頁，一次回傳全部，形狀是 `{ "type": "<資源名稱>", "data": [...] }`：這幾種資料量小且有上限，
            前端需要整份資料做篩選與圖譜繪製。會隨資料成長而變大的清單才分頁——目前是
            `GET /relations/{relation}/edges`，用 Laravel 的 length-aware 分頁
            （`?page=`、`?per_page=`，每頁預設 25 筆、最多 100 筆，回應附 `total`、`last_page` 等欄位）。
            MD,
    ],

    'ui' => [
        'title' => 'IN / ARCHIVE API',
    ],

    /*
     * Load Scramble's development tools on documentation pages. An explicit
     * SCRAMBLE_DEV_TOOLS value takes precedence over APP_DEBUG.
     */
    'dev_tools' => [
        'enabled' => env('SCRAMBLE_DEV_TOOLS', env('APP_DEBUG', false)),
    ],

    'renderer' => 'elements',

    'renderers' => [
        /*
         * Stoplight Elements config options: https://docs.stoplight.io/docs/elements/b074dc47b2826-elements-configuration-options
         */
        'elements' => [
            'view' => 'scramble::docs',
            'theme' => 'light',
            'hideTryIt' => false,
            'hideSchemas' => false,
            'logo' => '',
            // 文件頁的「Try It」不帶瀏覽器的 cookie：要呼叫需要登入的端點得自己填 bearer token。
            'tryItCredentialsPolicy' => 'omit',
            'layout' => 'responsive',
            'router' => 'hash',
        ],
        /*
         * Scalar API reference config options: https://scalar.com/products/api-references/configuration
         */
        'scalar' => [
            'view' => 'scramble::scalar',
            'cdn' => 'https://cdn.jsdelivr.net/npm/@scalar/api-reference',
            'theme' => 'laravel',
            'proxyUrl' => 'https://proxy.scalar.com',
            'darkMode' => false,
            'showDeveloperTools' => 'never',
            'agent' => ['disabled' => true],
            'credentials' => 'include',
        ],
    ],

    /*
     * The list of servers of the API. By default, when `null`, server URL will be created from
     * `scramble.api_path` and `scramble.api_domain` config variables. When providing an array, you
     * will need to specify the local server URL manually (if needed).
     *
     * Example of non-default config (final URLs are generated using Laravel `url` helper):
     *
     * ```php
     * 'servers' => [
     *     'Live' => 'api',
     *     'Prod' => 'https://scramble.dedoc.co/api',
     * ],
     * ```
     */
    'servers' => null,

    /**
     * Determines how Scramble stores the descriptions of enum cases.
     * Available options:
     * - 'description' – Case descriptions are stored as the enum schema's description using table formatting.
     * - 'extension' – Case descriptions are stored in the `x-enumDescriptions` enum schema extension.
     *
     *    @see https://redocly.com/docs-legacy/api-reference-docs/specification-extensions/x-enum-descriptions
     * - false - Case descriptions are ignored.
     */
    'enum_cases_description_strategy' => 'description',

    /**
     * Determines how Scramble stores the names of enum cases.
     * Available options:
     * - 'names' – Case names are stored in the `x-enumNames` enum schema extension.
     * - 'varnames' - Case names are stored in the `x-enum-varnames` enum schema extension.
     * - false - Case names are not stored.
     */
    'enum_cases_names_strategy' => false,

    /**
     * When Scramble encounters deep objects in query parameters, it flattens the parameters so the generated
     * OpenAPI document correctly describes the API. Flattening deep query parameters is relevant until
     * OpenAPI 3.2 is released and query string structure can be described properly.
     *
     * For example, this nested validation rule describes the object with `bar` property:
     * `['foo.bar' => ['required', 'int']]`.
     *
     * When `flatten_deep_query_parameters` is `true`, Scramble will document the parameter like so:
     * `{"name":"foo[bar]", "schema":{"type":"int"}, "required":true}`.
     *
     * When `flatten_deep_query_parameters` is `false`, Scramble will document the parameter like so:
     *  `{"name":"foo", "schema": {"type":"object", "properties":{"bar":{"type": "int"}}, "required": ["bar"]}, "required":true}`.
     */
    'flatten_deep_query_parameters' => true,

    /*
     * 文件頁（/docs/api）與 OpenAPI JSON（/docs/api.json）公開、唯讀，所以不掛
     * RestrictedDocsAccess（它預設只在 local 環境放行）。每次請求都會重新分析程式碼產生
     * 文件，套上跟 API 同一個 'api' limiter（每分鐘 60 次／IP）。
     */
    'middleware' => [
        'throttle:api',
    ],

    'extensions' => [],

    /*
     * Automatically document API security (OpenAPI `security` / `securitySchemes`) based on route
     * middleware.
     *
     * Disabled by default. Uncomment the line below to enable `MiddlewareAuthSecurityStrategy`.
     * When at least one documented route uses middleware matching the configured patterns (by default
     * `auth` and `auth:*`), bearer auth is applied globally. Routes without matching middleware are
     * marked as public (`security: []`).
     *
     * Set to `null` explicitly to disable. If you already configure security manually via
     * `afterOpenApiGenerated` / `extendOpenApi`, keep this disabled to avoid duplicate schemes.
     *
     * Customize with a class-string or [class, options]:
     *
     * 'security_strategy' => [
     *     \Dedoc\Scramble\SecurityDocumentation\MiddlewareAuthSecurityStrategy::class,
     *     [
     *         'middleware' => ['auth', 'auth:*'],
     *         'scheme' => \Dedoc\Scramble\Support\Generator\SecurityScheme::http('bearer'),
     *     ],
     * ],
     */
    // 'security_strategy' => \Dedoc\Scramble\SecurityDocumentation\MiddlewareAuthSecurityStrategy::class,
    'security_strategy' => null,
];
