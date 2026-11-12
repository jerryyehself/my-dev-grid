<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
    'github' => [
        'token' => env('GITHUB_TOKEN'),
    ],

    // 從另一個（私有）GitHub repo 匯入文章用，跟上面 GitService 的 GITHUB_TOKEN 分開：
    // 這把 token 只需要讀那一個 repo 的內容，權限範圍不同。
    // repo 是 `owner/name`，只從環境變數讀，不寫死在這個公開 repo 裡。
    // 沒設或設成空字串都當成 null：部署時 repository variable 沒建，Cloud Run 拿到的是空字串，
    // 這裡統一成 null，讓使用的地方只要判斷一種「沒設定」；設定缺漏不影響應用程式啟動。
    'github_articles' => [
        'token' => env('GITHUB_ARTICLES_TOKEN') ?: null,
        'repo' => env('GITHUB_ARTICLES_REPO') ?: null,
        'ref' => env('GITHUB_ARTICLES_REF') ?: 'main',
    ],

    // Google 是 Socialite 內建 driver，不需要額外套件。
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    // LINE 由 socialiteproviders/line 提供，'bot_prompt' 是該套件自己文件要求的設定鍵
    // （控制 LINE Login 畫面是否順便推廣加官方帳號好友，預設 'normal'）。
    'line' => [
        'client_id' => env('LINE_CLIENT_ID'),
        'client_secret' => env('LINE_CLIENT_SECRET'),
        'redirect' => env('LINE_REDIRECT_URI'),
        'bot_prompt' => env('LINE_BOT_PROMPT', 'normal'),
    ],

];
