<?php

/*
 * 每日同步（`github:sync-repos`）從各 repo 的套件清單（package.json、composer.json、
 * requirements.txt）推「專案 uses 技術」的邊時，用的「套件 → 技術」對照表。
 *
 * - 只有列在這裡的套件會變成邊，其餘一律略過。ESLint、Prettier、PHPUnit、Pint 這類開發用工具
 *   不列，圖譜上就不會出現；要讓某個套件上圖，在這裡加一列即可，不用改程式。
 * - 不分 dependencies／devDependencies（require／require-dev）：Vite、TypeScript、Tailwind 這類
 *   技術通常就裝在 devDependencies，要不要上圖由這張表決定，不看裝在哪一區。
 * - key 是套件名稱，一律小寫（pip 套件名先正規化：小寫，`_`、`.` 視為 `-`），完全比對，
 *   不做前綴或模糊比對（列了 `vue` 不會讓 `vue-router` 跟著上圖）。
 * - value 是 [技術名稱, scope]，scope 是 `language`、`framework`、`packagetool` 其中之一。
 *   技術名稱要跟既有技術（包含 GitHub topic 建出來的）同名，比對時不分大小寫，
 *   寫成別的名字會多出一筆重複的技術。已在 SaveReposDataService::AUTHORITY 的名稱會再被
 *   換成標準名稱。
 * - topic 不受這張表影響，仍照原來的方式補上邊，當作手動補充。
 */
return [

    'npm' => [
        'vue' => ['Vue', 'framework'],
        'nuxt' => ['Nuxt', 'framework'],
        'react' => ['React', 'framework'],
        '@angular/core' => ['Angular', 'framework'],
        'express' => ['Express', 'framework'],
        'pinia' => ['Pinia', 'packagetool'],
        'vuetify' => ['Vuetify', 'packagetool'],
        '@vueuse/core' => ['vueuse-core', 'packagetool'],
        'vue-i18n' => ['i18n', 'packagetool'],
        '@nuxtjs/i18n' => ['i18n', 'packagetool'],
        'tailwindcss' => ['tailwind', 'packagetool'],
        '@tailwindcss/vite' => ['tailwind', 'packagetool'],
        '@tailwindcss/postcss' => ['tailwind', 'packagetool'],
        'bootstrap' => ['Bootstrap', 'packagetool'],
        'vite' => ['Vite', 'packagetool'],
        'typescript' => ['TypeScript', 'language'],
        'chart.js' => ['chartjs', 'packagetool'],
        'cheerio' => ['cheerio', 'packagetool'],
        'exceljs' => ['exceljs', 'packagetool'],
        'html5-qrcode' => ['html5-qrcode', 'packagetool'],
        'isbn3' => ['isbn3', 'packagetool'],
        '@line/bot-sdk' => ['linebot', 'packagetool'],
        'three' => ['Three.js', 'packagetool'],
        'd3' => ['D3', 'packagetool'],
        'd3-force' => ['D3', 'packagetool'],
        'force-graph' => ['force-graph', 'packagetool'],
        '3d-force-graph' => ['force-graph', 'packagetool'],
    ],

    'composer' => [
        'laravel/framework' => ['Laravel', 'framework'],
        'symfony/framework-bundle' => ['Symfony', 'framework'],
        'laravel/sanctum' => ['Sanctum', 'packagetool'],
        'laravel/socialite' => ['Socialite', 'packagetool'],
        'livewire/livewire' => ['Livewire', 'packagetool'],
        'inertiajs/inertia-laravel' => ['Inertia', 'packagetool'],
        'filament/filament' => ['Filament', 'packagetool'],
        'dedoc/scramble' => ['Scramble', 'packagetool'],
    ],

    'pip' => [
        'django' => ['Django', 'framework'],
        'flask' => ['Flask', 'framework'],
        'fastapi' => ['FastAPI', 'framework'],
        'pandas' => ['pandas', 'packagetool'],
        'numpy' => ['NumPy', 'packagetool'],
        'matplotlib' => ['Matplotlib', 'packagetool'],
        'rdflib' => ['rdflib', 'packagetool'],
        'graphviz' => ['graphviz', 'packagetool'],
        'beautifulsoup4' => ['BeautifulSoup', 'packagetool'],
    ],

];
