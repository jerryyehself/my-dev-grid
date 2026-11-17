<?php

/*
 * 每日同步（`github:sync-repos`）的 repo 設定。
 */
return [

    /*
     * 不寫技術邊的 repo，用 `擁有者/repo 名稱` 指定（跟 GitHub 網址最後兩段一樣，不分大小寫）。
     *
     * 同步來源讀 `/user/repos`，不只自己擁有的 repo，也包含協作者、組織成員身分看得到的 repo，
     * 別人名下可能有同名的 repo；只比對名稱會誤傷它們，所以一定要寫擁有者。
     * 只寫名稱（沒有 `/`）的項目不會比對到任何 repo。同步到的 repo 讀不到擁有者時，同樣不略過。
     *
     * 這些 repo 的語言、topic、套件讀到的技術都不連到專案，因為連出來的邊是錯的：
     * 它們是改作自別人的專案，或是另一個專案的建置產物，用到的技術不代表作者自己的技術。
     * 它們跟來源的關係改在後台用 isBasisFor／isBasedOnProject 記錄（見 RelationSeeder）。
     *
     * 只影響「技術邊」：repo 本身照常同步成 Implementation，nomen 也照常記
     * （NomenRecorder::recordRepo）。已經連上的邊不會被刪，也不再更新 last_seen_at，
     * 之後由 TechniqueImplementationLink::staleCutoff() 判成過期、慢慢淡出。
     *
     * @var list<string>
     */
    'skip_technique_edges' => [
        'jerryyehself/my-profile',
        'jerryyehself/laravel-migration-visualizer-pages',
    ],

];
