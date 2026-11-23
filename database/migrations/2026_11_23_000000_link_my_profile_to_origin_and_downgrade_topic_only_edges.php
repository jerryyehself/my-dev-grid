<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 修正同步留下的兩種錯誤技術邊：my-profile 改作自外部 repo，以及只有 topic 證據卻是「使用」的邊。
 *
 * 同步改成依證據決定述詞、改作自外部 repo 的專案不寫技術邊之後，既有的資料還是舊的：
 * - my-profile 改作自 `memochou1993/nyan-profile`，連出來的技術不代表作者自己的技術。以前靠
 *   `config/repo_sync.php` 的清單略過，這次拿掉清單，改成資料庫裡的 isBasisFor 連結；連結建好之後，
 *   同步就不再碰它的技術邊。舊的邊不會自己消失（技術與實作之間的邊不過期），所以這裡清掉。
 * - 同步只升不降：已經是 `usedBy` 的邊，這次只剩 topic 證據也不改成 `subjectOfProject`（GitService 讀取失敗會回空陣列，
 *   自動降級會讓邊來回翻）。以前判錯的邊只能在這裡一次修正。
 *
 * 做的事：
 * 1. my-profile：只找 `implementations.url` 完全等於 MY_PROFILE_URL、沒有軟刪除的那一筆。找不到就跳過 1～3 步；
 *    找到不只一筆就停下來（不猜是哪一筆）。
 *    - 沒有同網址、沒刪除的 Documentation 才建一筆 `repository` 分類的（已發布，沒登入的訪客在圖上也看得到出處）。
 *    - 這一對文件與專案還沒有 isBasisFor 連結才新增。
 *    - my-profile 在 `technique_implementation` 的所有列刪掉，包括站主手動指定過的（改作的技術都不是作者的）。
 * 2. 人工看過預覽名單後決定的例外（KEEP_USED_BY、DELETE_EDGES），在第 3 步之前處理：
 *    - 用「專案 title＋技術 title」（小寫）配對，不寫死 edge id：預覽到上線之間同步多出或少了邊，也不會改錯列。
 *      技術 title 相同、版本不同的節點（Vue 與 Vue 3）算同一對。
 *    - 只動述詞是 `usedBy`、`manually_set_at` 是 null、專案與技術都沒有軟刪除的列（跟第 3 步的候選條件一樣）。
 *    - KEEP_USED_BY：證據沒抓到、其實有用到的技術，維持 `usedBy`，`manually_set_at` 設成執行時間，之後同步不再改它的述詞。
 *    - DELETE_EDGES：專案沒用到、也不是關於這個技術的邊，整列刪掉。
 *    - 一對都配不到（或專案 title 對到不只一個沒刪除的專案，分不出是哪一個）就跳過，記進 info log 的
 *      `exclusions_unmatched`，不丟例外。
 * 3. 只有 topic 證據的「使用」邊改成「關於」（`subjectOfProject`），名單在執行時從 `nomens` 算（topicOnlyEdges()）。
 *    條件全部符合才改：
 *    - 述詞就是 `usedBy`、`manually_set_at` 是 null，專案與技術都沒有軟刪除；
 *    - 同一對沒有站主手動指定的列，也還沒有 `subjectOfProject` 列；
 *    - 這個專案的 topic 解析出這個技術，語言統計與套件清單都沒有（比法跟同步一樣：小寫 title、不看版本）。
 *    `nomens` 只增不刪，以前看過的語言、套件都算證據，所以讀取失敗那幾天少掉的證據不會讓邊被誤降。
 *    沒有 topic 證據的不改（沒有記錄可以判斷）；專案有任何一筆套件字串對不到唯一的技術時，整個專案都不改
 *    （分不出那筆是哪個技術的證據）。
 *
 * 前置資料：`isBasisFor`、`subjectOfProject`、`repository` 分類由 ReferenceDataSeeder 種，部署流程是先 migrate 再 seed。
 * 這些述詞跟這支 migration 不能在同一次上線，缺了就丟例外、整支不執行（全新的資料庫沒有 my-profile 也沒有邊，不會走到）。
 *
 * AUTHORITY、IGNORED_LANGUAGES 是 2026-11-23 SaveReposDataService 同名常數的快照，刻意不跟著那邊改
 * （data.md：對照表寫成 migration 自己的常數）。只留 title，判斷證據時只比小寫 title。
 *
 * 執行前後的筆數記成一筆 info log。每一筆新建、刪掉、改過的資料都先記進 BACKUP，連同動作（`action`）與原因
 * （`reason`，見 REASON_* 常數），down() 照著還原，最後把那張表刪掉（做法同 2026_09_30_190200_merge_duplicate_techniques.php）。
 */
return new class extends Migration
{
    public const MY_PROFILE_URL = 'https://github.com/jerryyehself/my-profile';

    /** my-profile 的原作，存成一筆 repository 分類的 Documentation */
    public const ORIGIN = [
        'title' => 'memochou1993/nyan-profile',
        'url' => 'https://github.com/memochou1993/nyan-profile',
        'body' => 'my-profile 改作自這個 repo。',
    ];

    /** @var array<string, string> 小寫名稱 → 標準名稱（SaveReposDataService::AUTHORITY 的快照，只留 title） */
    public const AUTHORITY = [
        'php' => 'PHP',
        'python' => 'Python',
        'vue' => 'Vue',
        'vuejs' => 'Vue',
        'vue3' => 'Vue',
        'nuxt' => 'Nuxt',
        'nuxtjs' => 'Nuxt',
        'nuxt3' => 'Nuxt',
        'bootstrap5' => 'Bootstrap',
        'nextjs' => 'Next.js',
        'alpinejs' => 'Alpine.js',
        'reactjs' => 'React',
        'expressjs' => 'Express',
        'tailwindcss' => 'tailwind',
        'tailwind-css' => 'tailwind',
        'threejs' => 'Three.js',
        'd3js' => 'D3',
        'procfile' => 'Heroku',
    ];

    /** 同步略過的 GitHub 語言（小寫），不算程式證據 */
    public const IGNORED_LANGUAGES = ['hack'];

    /**
     * 人工確認有用到、只是程式證據沒抓到的技術（套件清單讀不到、語言統計沒列出）：維持 `usedBy` 並標成手動指定。
     *
     * @var list<array{0: string, 1: string}> [專案 title, 技術 title]，比對時不分大小寫
     */
    public const KEEP_USED_BY = [
        ['api-demo', 'PHP'],
        ['laravel-demo', 'PHP'],
        ['thesis', 'Python'],
        ['thesis', 'rdflib'],
        ['thesis', 'graphviz'],
        ['gym-people-count-parser', 'appscript'],
        ['gym-people-count-parser', 'cheerio'],
        ['gym-people-count-parser', 'linebot'],
        ['Laravel-LearningLibrary', 'Bootstrap'],
    ];

    /**
     * 人工確認專案沒用到、也不是關於這個技術的邊（topic 已經從 repo 拿掉）：整列刪掉，不降成「關於」。
     *
     * @var list<array{0: string, 1: string}> [專案 title, 技術 title]，比對時不分大小寫
     */
    public const DELETE_EDGES = [
        ['my-dev-grid', 'Vue'],
        ['my-dev-grid', 'tailwind'],
    ];

    /** BACKUP 的 `reason`：每一列為什麼被動到 */
    public const REASON_MY_PROFILE_ORIGIN = 'my-profile 改作自外部 repo：建原作出處與 isBasisFor 連結';

    public const REASON_MY_PROFILE_EDGE = 'my-profile 改作自外部 repo：技術邊不是作者自己的，刪除';

    public const REASON_KEEP_USED_BY = '人工確認有用到（程式證據沒抓到）：維持使用，標為手動指定';

    public const REASON_DELETE_EDGE = '人工確認沒用到、也不是關於這個技術：整列刪除';

    public const REASON_TOPIC_ONLY = '只有 topic 證據：使用改為關於';

    private const BACKUP = 'sync_predicate_fix_backups';

    public function up(): void
    {
        // 備份表也建在 transaction 裡：中途丟例外時連表一起退掉，不留半套
        DB::transaction(function () {
            Schema::create(self::BACKUP, function (Blueprint $table) {
                $table->id();
                $table->string('table_name', 50);
                $table->unsignedBigInteger('row_id');
                $table->string('action', 10)->comment('updated / deleted / created');
                $table->string('reason', 100)->comment('為什麼動這一列（REASON_* 常數）');
                $table->json('original')->nullable();
            });

            $before = $this->counts();
            $myProfile = $this->linkMyProfileToOrigin();
            $exclusions = $this->applyExclusions();
            $downgraded = $this->downgradeTopicOnlyEdges();
            $after = $this->counts();

            Log::info('資料修正：my-profile 改作自連結、只有 topic 證據的使用邊改為關於', [
                'before' => $before,
                'after' => $after,
                'my_profile' => $myProfile,
                'kept_used_by' => $exclusions['kept'],
                'deleted' => $exclusions['deleted'],
                'exclusions_unmatched' => $exclusions['unmatched'],
                'downgraded' => $downgraded,
            ]);
        });
    }

    /**
     * 目前「只有 topic 證據」的使用邊。只讀不寫，條件見類別說明第 3 點。
     *
     * @return Collection<int, object{id: int, implementation_id: int, technique_id: int, implementation_title: string, technique_title: string}>
     */
    public function topicOnlyEdges(): Collection
    {
        $usedBy = $this->relationId('usedBy');
        if ($usedBy === null) {
            return collect();
        }

        $candidates = DB::table('technique_implementation as ti')
            ->join('implementations as i', 'i.id', '=', 'ti.implementation_id')
            ->join('techniques as t', 't.id', '=', 'ti.technique_id')
            // DB::table() 不套軟刪除的全域 scope，要自己濾
            ->whereNull('i.deleted_at')
            ->whereNull('t.deleted_at')
            ->where('ti.relation_id', $usedBy)
            ->whereNull('ti.manually_set_at')
            ->orderBy('ti.id')
            ->get(['ti.id', 'ti.implementation_id', 'ti.technique_id', 'i.title as implementation_title', 't.title as technique_title']);

        if ($candidates->isEmpty()) {
            return collect();
        }

        $evidence = $this->evidence($candidates->pluck('implementation_id')->map(fn ($id) => (int) $id)->unique()->values()->all());
        $about = $this->relationId('subjectOfProject');

        return $candidates->filter(function (object $row) use ($evidence, $about) {
            $found = $evidence[(int) $row->implementation_id] ?? null;
            $title = mb_strtolower($row->technique_title);

            if ($found === null || $found['uncertain'] || ! isset($found['topic'][$title]) || isset($found['code'][$title])) {
                return false;
            }

            $others = DB::table('technique_implementation')
                ->where('implementation_id', $row->implementation_id)
                ->where('technique_id', $row->technique_id)
                ->where('id', '!=', $row->id)
                ->get(['relation_id', 'manually_set_at']);

            return $others->whereNotNull('manually_set_at')->isEmpty()
                && ($about === null || $others->where('relation_id', $about)->isEmpty());
        })->values();
    }

    /**
     * 每個專案在 `nomens` 裡的證據，小寫 title 當 key。
     *
     * 語言與 topic 照同步的做法換算名稱（AUTHORITY），不看 `nomens` 自己的對應結果：那邊的規則跟同步不一樣
     * （例如語言只能對到 language 分類，Linguist 的 `Vue` 對不到框架 Vue）。套件名要查套件對照表，這裡不另存一份，
     * 改用 `nomens` 記下的對應節點（`package_map`，跟同步查同一張表）；有任何一筆對不到唯一節點，就把專案標成不確定。
     *
     * @param  list<int>  $implementationIds
     * @return array<int, array{code: array<string, true>, topic: array<string, true>, uncertain: bool}>
     */
    private function evidence(array $implementationIds): array
    {
        $rows = DB::table('nomens')
            ->where('context_type', 'implementation')
            ->whereIn('context_id', $implementationIds)
            ->whereIn('source', ['github_language', 'github_topic', 'package_manifest'])
            ->orderBy('id')
            ->get(['context_id', 'source', 'string', 'res_type', 'res_id']);

        $packageTitles = DB::table('techniques')
            ->whereIn('id', $rows->where('source', 'package_manifest')->whereNotNull('res_id')->pluck('res_id')->map(fn ($id) => (int) $id)->unique()->values()->all())
            ->pluck('title', 'id');
        $canonical = fn (string $name) => mb_strtolower(self::AUTHORITY[mb_strtolower($name)] ?? $name);

        $evidence = [];
        foreach ($rows as $row) {
            $id = (int) $row->context_id;
            $evidence[$id] ??= ['code' => [], 'topic' => [], 'uncertain' => false];

            if ($row->source === 'github_language') {
                if (! in_array(mb_strtolower($row->string), self::IGNORED_LANGUAGES, true)) {
                    $evidence[$id]['code'][$canonical($row->string)] = true;
                }
            } elseif ($row->source === 'github_topic') {
                $evidence[$id]['topic'][$canonical($row->string)] = true;
            } elseif ($row->res_type === 'technique' && $row->res_id !== null && isset($packageTitles[(int) $row->res_id])) {
                $evidence[$id]['code'][mb_strtolower($packageTitles[(int) $row->res_id])] = true;
            } else {
                $evidence[$id]['uncertain'] = true;
            }
        }

        return $evidence;
    }

    /**
     * @return array{implementation_id: int|null, documentation_created: bool, link_created: bool, edges_deleted: int}
     */
    private function linkMyProfileToOrigin(): array
    {
        $result = ['implementation_id' => null, 'documentation_created' => false, 'link_created' => false, 'edges_deleted' => 0];

        $projects = DB::table('implementations')->where('url', self::MY_PROFILE_URL)->whereNull('deleted_at')->pluck('id');
        if ($projects->isEmpty()) {
            return $result;
        }
        if ($projects->count() > 1) {
            throw new RuntimeException('網址是 '.self::MY_PROFILE_URL.' 的專案不只一筆（id '.$projects->implode(', ').'），分不出要改哪一筆，這支 migration 不執行。');
        }

        $projectId = (int) $projects->first();
        $result['implementation_id'] = $projectId;

        $isBasisFor = $this->relationId('isBasisFor');
        $repository = DB::table('scopes')->where('name', 'repository')->whereNull('deleted_at')->value('id');
        if ($isBasisFor === null || $repository === null) {
            throw new RuntimeException('找不到述詞 isBasisFor 或分類 repository，這支 migration 不執行。要先部署種這些預設資料的那一版（ReferenceDataSeeder）。');
        }

        $documentationId = DB::table('documentations')->where('url', self::ORIGIN['url'])->whereNull('deleted_at')->orderBy('id')->value('id');
        if ($documentationId === null) {
            $documentationId = $this->insert('documentations', self::REASON_MY_PROFILE_ORIGIN, [
                'type' => $repository,
                'title' => self::ORIGIN['title'],
                'url' => self::ORIGIN['url'],
                'body' => self::ORIGIN['body'],
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $result['documentation_created'] = true;
        }

        $link = ['documentation_id' => $documentationId, 'implementation_id' => $projectId, 'relation_id' => $isBasisFor];
        if (! DB::table('documentation_implementation')->where($link)->exists()) {
            $this->insert('documentation_implementation', self::REASON_MY_PROFILE_ORIGIN, $link + ['created_at' => now(), 'updated_at' => now()]);
            $result['link_created'] = true;
        }

        foreach (DB::table('technique_implementation')->where('implementation_id', $projectId)->orderBy('id')->get() as $edge) {
            $this->delete('technique_implementation', self::REASON_MY_PROFILE_EDGE, $edge);
            $result['edges_deleted']++;
        }

        return $result;
    }

    /**
     * KEEP_USED_BY 標成手動指定、DELETE_EDGES 整列刪掉，條件見類別說明第 2 點。
     *
     * @return array{kept: list<string>, deleted: list<string>, unmatched: list<string>} 都是 `專案 → 技術`
     */
    private function applyExclusions(): array
    {
        $result = ['kept' => [], 'deleted' => [], 'unmatched' => []];

        $usedBy = $this->relationId('usedBy');
        $candidates = $usedBy === null ? collect() : DB::table('technique_implementation as ti')
            ->join('implementations as i', 'i.id', '=', 'ti.implementation_id')
            ->join('techniques as t', 't.id', '=', 'ti.technique_id')
            ->whereNull('i.deleted_at')
            ->whereNull('t.deleted_at')
            ->where('ti.relation_id', $usedBy)
            ->whereNull('ti.manually_set_at')
            ->orderBy('ti.id')
            ->get(['ti.id', 'i.title as implementation_title', 't.title as technique_title']);
        // 同一個 title 有好幾個沒刪除的專案時分不出是哪一個，那一對整個跳過
        $projectsPerTitle = DB::table('implementations')->whereNull('deleted_at')->pluck('title')
            ->countBy(fn ($title) => mb_strtolower((string) $title));

        foreach (['deleted' => self::DELETE_EDGES, 'kept' => self::KEEP_USED_BY] as $kind => $pairs) {
            foreach ($pairs as [$project, $technique]) {
                $edges = ($projectsPerTitle[mb_strtolower($project)] ?? 0) !== 1 ? collect() : $candidates->filter(
                    fn (object $row) => mb_strtolower($row->implementation_title) === mb_strtolower($project)
                        && mb_strtolower($row->technique_title) === mb_strtolower($technique),
                );

                if ($edges->isEmpty()) {
                    $result['unmatched'][] = "{$project} → {$technique}";

                    continue;
                }

                foreach ($edges as $edge) {
                    if ($kind === 'deleted') {
                        $this->delete('technique_implementation', self::REASON_DELETE_EDGE, DB::table('technique_implementation')->where('id', $edge->id)->first());
                    } else {
                        $this->update('technique_implementation', (int) $edge->id, self::REASON_KEEP_USED_BY, ['manually_set_at' => now(), 'updated_at' => now()]);
                    }
                    $result[$kind][] = "{$edge->implementation_title} → {$edge->technique_title}";
                }
            }
        }

        return $result;
    }

    /** @return list<string> 改過的邊，`專案 → 技術` */
    private function downgradeTopicOnlyEdges(): array
    {
        $edges = $this->topicOnlyEdges();
        if ($edges->isEmpty()) {
            return [];
        }

        $about = $this->relationId('subjectOfProject');
        if ($about === null) {
            throw new RuntimeException('找不到述詞 subjectOfProject，這支 migration 不執行。要先部署種這個述詞的那一版（ReferenceDataSeeder）。');
        }

        foreach ($edges as $edge) {
            $this->update('technique_implementation', (int) $edge->id, self::REASON_TOPIC_ONLY, ['relation_id' => $about, 'updated_at' => now()]);
        }

        return $edges->map(fn (object $edge) => "{$edge->implementation_title} → {$edge->technique_title}")->all();
    }

    /** @return array<string, int|null> */
    private function counts(): array
    {
        $count = fn (?int $relationId) => $relationId === null ? null : DB::table('technique_implementation')->where('relation_id', $relationId)->count();
        $myProfile = DB::table('implementations')->where('url', self::MY_PROFILE_URL)->whereNull('deleted_at')->pluck('id');

        return [
            'technique_implementation' => DB::table('technique_implementation')->count(),
            'used_by' => $count($this->relationId('usedBy')),
            'subject_of_project' => $count($this->relationId('subjectOfProject')),
            'my_profile_edges' => DB::table('technique_implementation')->whereIn('implementation_id', $myProfile->all())->count(),
            'documentation_implementation' => DB::table('documentation_implementation')->count(),
        ];
    }

    private function relationId(string $name): ?int
    {
        $id = DB::table('relations')->where('name', $name)->whereNull('deleted_at')->value('id');

        return $id === null ? null : (int) $id;
    }

    private function update(string $table, int $id, string $reason, array $values): void
    {
        $this->remember($table, $id, 'updated', $reason, DB::table($table)->where('id', $id)->first());
        DB::table($table)->where('id', $id)->update($values);
    }

    private function delete(string $table, string $reason, object $row): void
    {
        $this->remember($table, (int) $row->id, 'deleted', $reason, $row);
        DB::table($table)->where('id', $row->id)->delete();
    }

    private function insert(string $table, string $reason, array $values): int
    {
        $id = (int) DB::table($table)->insertGetId($values);
        $this->remember($table, $id, 'created', $reason, null);

        return $id;
    }

    private function remember(string $table, int $id, string $action, string $reason, ?object $original): void
    {
        DB::table(self::BACKUP)->insert([
            'table_name' => $table,
            'row_id' => $id,
            'action' => $action,
            'reason' => $reason,
            'original' => $original ? json_encode($original) : null,
        ]);
    }

    /**
     * 照備份倒著還原：改過的邊改回原本的述詞與 `manually_set_at`，刪掉的邊（my-profile 的、DELETE_EDGES 的）整列插回去，這支建的連結與 Documentation 刪掉
     * （只刪記錄過 id 的；原本就有的同網址 Documentation 不動）。之後站主連到那筆 Documentation 的其他連結會跟著刪
     * （外鍵 cascade）。
     *
     * 只給本機與測試用。正式環境要還原的話，寫新的 migration 照備份表往前修，不跑 rollback。
     */
    public function down(): void
    {
        if (! Schema::hasTable(self::BACKUP)) {
            return;
        }

        DB::transaction(function () {
            foreach (DB::table(self::BACKUP)->orderByDesc('id')->get() as $entry) {
                $original = $entry->original ? (array) json_decode($entry->original) : null;

                match ($entry->action) {
                    'created' => DB::table($entry->table_name)->where('id', $entry->row_id)->delete(),
                    'deleted' => DB::table($entry->table_name)->insert($original),
                    'updated' => DB::table($entry->table_name)->where('id', $entry->row_id)->update($original),
                };
            }
        });

        Schema::drop(self::BACKUP);
    }
};
