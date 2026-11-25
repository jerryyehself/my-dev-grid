<?php

/*
 * 技術詞表匯出成 SKOS（`App\Services\VocabularyExporter`）時用的設定。
 *
 * - `base_uri`：每個識別碼的共同前綴。詞表本身是 `{base_uri}vocabulary`，每個技術是
 *   `{base_uri}technique/{id}`。識別碼一旦公開就不該再變，正式的網域還沒定，所以先用
 *   保留給文件範例、不會解析到任何主機的 `.invalid` 網域當佔位值；定案後只改這一行，程式不用動。
 *   必須是 http 或 https 開頭、以 `/` 結尾，不然匯出會直接丟例外，不會產出半套的識別碼。
 * - `language`：正式名（title＋version）的語言標記，也是別名沒填 `locale` 時的預設值。
 * - `scheme_label`：詞表本身（skos:ConceptScheme）的名稱。
 */
return [

    'base_uri' => 'https://vocabulary.example.invalid/id/',

    'language' => 'zh-Hant',

    'scheme_label' => '技術詞表',

];
