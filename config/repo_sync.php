<?php

/*
 * 每日同步（`github:sync-repos`）的 repo 設定。
 */
return [

    /*
     * 不寫技術邊的 repo（repo 名稱，不分大小寫）。
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
        'my-profile',
        'laravel-migration-visualizer-pages',
    ],

];
