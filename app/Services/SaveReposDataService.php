<?php

namespace App\Services;

use App\Models\Documentation;
use App\Models\EntityRelation;
use App\Models\Implementation;
use App\Models\Relation;
use App\Models\Scope;
use App\Models\Technique;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * 儲存 git 資料
 *
 * 從 GitService 拉取每一個公開 repo，並將每筆 upsert 成一個 Implementation（type: project），
 * 再依每個語言／topic，透過既有的 `usedBy` Relation 連到對應的 Technique
 * （Technique usedBy Implementation，是 `uses` 的反向，因為 `technique_implementation`
 * 永遠以 Technique 為 subject）。`topics` 的項目若是 FRAMEWORK_BASE_LANGUAGE 的 key，
 * 就歸類到 `framework` scope，否則歸為 `packagetool`（見 ScopeSeeder 的 class_number／call_number）。
 *
 * 除了語言與 topic，repo 的套件清單（package.json、composer.json、requirements.txt，見 GitService）
 * 裡出現的套件，只要列在 `config/package_technologies.php` 的對照表就也會連到對應的 Technique；
 * 沒列的（例如 ESLint 這類開發用工具）不會上圖。topic 沒有被取代，仍是手動補充的來源。
 * 其中 Vue、Nuxt、Bootstrap 還會依清單檔的版本限制取主版號（`vue@^3.5` → Vue 3），連到有版本的那一筆，
 * 讀不出主版號就連到不分版本的。
 *
 * `config/repo_sync.php` 的 `skip_technique_edges` 列出的 repo（改作或建置產物，用 `owner/name` 指定，
 * 別人名下的同名 repo 不受影響）只同步成專案，不寫任何技術邊，nomen 照記。
 *
 * 另外有兩組手動維護的關係，在已知時補上：
 * - framework／library 類的 topic（例如 `vue`）會透過 `requires`（同類型，
 *   `entity_relations`）連到它唯一的基礎語言 Technique（例如 `JavaScript`），見 FRAMEWORK_BASE_LANGUAGE。
 * - 有已知官方文件網站的 Technique，會透過 `specs` 連到一筆 Documentation（type: sourcesite），見 OFFICIAL_DOCS。
 * 兩張對照表刻意保持精簡：沒列出的 Technique 就不補這條額外的邊，而不是去猜。
 *
 * NOTE(2026-09-07): 在這次修正之前，每個 `topics` 項目（包括 framework 類）都無條件歸為
 * `packagetool`。一次性的 backfill migration（`..._reclassify_framework_topics_...`）
 * 會把既有、scope 為 `packagetool` 且 title 符合 FRAMEWORK_BASE_LANGUAGE 某個 key 的 Technique 列，
 * **原地**改指到 `framework`（同一個 row id，所以已指向它的 `technique_implementation`／`entity_relations`／
 * `documentation_technique` 仍然有效）。該 migration 的 topic 清單是修正當時 FRAMEWORK_BASE_LANGUAGE 的快照，
 * 刻意不跟下面 const 之後的修改同步。如果 FRAMEWORK_BASE_LANGUAGE 之後新增了 key，
 * 已經在該 title 下同步過的 `packagetool` Technique 需要另外補一次 backfill（同樣的做法，新的 migration）——
 * 程式裡的重新分類只影響之後的同步。
 */
class SaveReposDataService
{
    public $gitService;

    /**
     * @var array<string, int>
     */
    private array $scopeIdCache = [];

    /** 影子模式：另外記下讀到的語言、topic 與套件字串，不影響這裡寫的邊（見 `NomenRecorder`）。 */
    private NomenRecorder $nomens;

    /**
     * GitHub repo 的 `topics`（GitHub 儲存時皆為小寫），若是有唯一基礎語言的 framework／library，
     * 就對應到該語言，名稱與 GitHub languages API 的寫法完全一致。
     */
    private const FRAMEWORK_BASE_LANGUAGE = [
        'vue' => 'JavaScript',
        'vuejs' => 'JavaScript',
        'vue3' => 'JavaScript',
        'react' => 'JavaScript',
        'nextjs' => 'JavaScript',
        'nuxt' => 'JavaScript',
        'nuxtjs' => 'JavaScript',
        'express' => 'JavaScript',
        'angular' => 'TypeScript',
        'laravel' => 'PHP',
        'symfony' => 'PHP',
        'django' => 'Python',
        'flask' => 'Python',
        'rails' => 'Ruby',
        'spring' => 'Java',
        'spring-boot' => 'Java',
    ];

    /**
     * 權威控制（authority control）：GitHub 的語言名稱跟 topic 都是自由標籤，同一個技術會有
     * 不同寫法（languages API 給 `PHP`、topic 給 `php`；`vue`／`vuejs`，還有 languages API
     * 把 .vue 檔算成語言 `Vue`），也有把版本黏在名稱上的（`vue3`、`nuxt3`）。這張表把變體對到
     * 「標準名稱、類別、版本」，key 一律小寫。2026-09-30 使用者同意，起因是專案頁的技術標籤
     * 出現 PHP／php、Vue／vue 兩兩重複。
     *
     * 不在表裡的名稱照原樣建立，但比對既有技術時不分大小寫（find_or_create_technique），
     * 所以新的大小寫變體不用一個個登記。只有「名字不一樣」或「要拆出版本」的才需要列進來。
     * 不用通用規則拆版本號：`html5-qrcode`、`isbn3` 是套件名稱，拆了會錯。
     *
     * 版本各自是一筆 Technique（title 相同、version 填主版號），用 isVersionOf 連回版本留空
     * 的那一筆，見 2026_09_30_190100_add_technique_version_relations.php。
     *
     * @var array<string, array{0: string, 1: string, 2: string|null}> [標準名稱, scope, 版本]
     */
    private const AUTHORITY = [
        'php' => ['PHP', 'language', null],
        'python' => ['Python', 'language', null],
        'vue' => ['Vue', 'framework', null],
        'vuejs' => ['Vue', 'framework', null],
        'vue3' => ['Vue', 'framework', '3'],
        'nuxt' => ['Nuxt', 'framework', null],
        'nuxtjs' => ['Nuxt', 'framework', null],
        'nuxt3' => ['Nuxt', 'framework', '3'],
        'bootstrap5' => ['Bootstrap', 'packagetool', '5'],
        // Linguist 把 Procfile 當成一種「語言」，它其實是 Heroku 的部署設定檔
        'procfile' => ['Heroku', 'packagetool', null],
    ];

    /**
     * 同步時直接略過的 GitHub 語言名稱（小寫）：只放 Linguist 的偵測錯誤。`hack` 是把 PHP 檔誤判成
     * Hack 語言。topic 是擁有者刻意貼的標籤（描述 repo 在講什麼），不論是不是技術都不略過。
     * 略過的項目不建技術、也不更新 last_seen_at，所以以前已經連上的邊不會被刪，而是慢慢變成
     * 「很久沒看到」，由 TechniqueImplementationLink::staleCutoff() 判成過期。
     */
    public const IGNORED_LANGUAGES = ['hack'];

    /**
     * Technique 的官方文件網站 URL，以其 title 小寫為 key（自 2026-09-30 起以不分大小寫的方式查找，
     * 那時 topic 的變體開始解析成 `Vue` 之類的標準名稱）。
     */
    private const OFFICIAL_DOCS = [
        'vue' => 'https://vuejs.org/',
        'vuejs' => 'https://vuejs.org/',
        'react' => 'https://react.dev/',
        'angular' => 'https://angular.dev/',
        'laravel' => 'https://laravel.com/docs',
        'symfony' => 'https://symfony.com/doc/current/index.html',
        'django' => 'https://docs.djangoproject.com/',
        'flask' => 'https://flask.palletsprojects.com/',
        'rails' => 'https://guides.rubyonrails.org/',
        'spring' => 'https://spring.io/docs',
        'php' => 'https://www.php.net/docs.php',
        'javascript' => 'https://developer.mozilla.org/en-US/docs/Web/JavaScript',
        'typescript' => 'https://www.typescriptlang.org/docs/',
        'python' => 'https://docs.python.org/3/',
        'ruby' => 'https://www.ruby-lang.org/en/documentation/',
        'java' => 'https://docs.oracle.com/en/java/',
    ];

    /**
     * @param  Collection|null  $repos  Pre-fetched, already `GitService::clean_repo_info()`-shaped
     *                                  repo 資料（例如由 `GitHubReposSnapshotSeeder` 載入的 JSON 快照）。
     *                                  預設為即時呼叫 `GitService::get_repos()`。
     */
    public function __construct(?Collection $repos = null, ?NomenRecorder $nomens = null)
    {
        $this->gitService = $repos ?? (new GitService)->get_repos();
        $this->nomens = $nomens ?? new NomenRecorder(new NomenResolver);
    }

    public function save_repos_data(): void
    {
        // 語意是「專案 uses 技術」，但這裡寫的是 technique_implementation，那張表一律 Technique
        // 當主詞，所以存的是反向那一筆 usedBy（技術 usedBy 專案）。見
        // 2026_09_30_180000_fix_uses_relation_direction.php
        $usesRelationId = $this->relation_id('usedBy');
        $requiresRelationId = $this->relation_id('requires');
        $specsRelationId = $this->relation_id('specs');
        $isVersionOfRelationId = $this->relation_id('isVersionOf');
        $seenAt = now();

        $this->gitService->each(function ($repo) use ($usesRelationId, $requiresRelationId, $specsRelationId, $isVersionOfRelationId, $seenAt) {
            $project = $this->save_repos_content($repo);

            // 清單裡的 repo（config/repo_sync.php）不寫技術邊：連出來的技術不代表作者自己的技術。
            // 不建技術、不連邊、不更新 last_seen_at，舊的邊照 staleCutoff() 淡出；nomen 照記。
            if ($this->skips_technique_edges($repo)) {
                $this->nomens->recordRepo($project, $repo, $seenAt);

                return;
            }

            // $packageVersion 是套件清單檔讀到的主版號（只有 Vue、Nuxt、Bootstrap 會有，見 PackageTechniqueMap）。
            // AUTHORITY 已經指定版本的名稱（vue3、nuxt3…）以 AUTHORITY 為準
            $resolve = function (string $name, string $defaultScope, ?string $packageVersion = null) use ($requiresRelationId, $specsRelationId, $isVersionOfRelationId): Technique {
                [$title, $scopeName, $version] = self::AUTHORITY[mb_strtolower($name)] ?? [$name, $defaultScope, null];
                $version ??= $packageVersion;

                $base = $this->find_or_create_technique($title, $scopeName);
                $this->link_framework_to_base_language($base, $requiresRelationId);
                $this->link_official_docs($base, $specsRelationId);

                if ($version === null) {
                    return $base;
                }

                $versioned = $this->find_or_create_technique($title, $scopeName, $version);
                EntityRelation::firstOrCreate([
                    'entity_type' => 'technique',
                    'subject_id' => $versioned->id,
                    'object_id' => $base->id,
                    'relation_id' => $isVersionOfRelationId,
                ]);

                return $versioned;
            };

            $techniques = collect($repo['languages'] ?? [])
                ->reject(fn ($language) => $this->is_ignored_language($language))
                ->map(fn ($language) => $resolve($language, 'language'))
                ->concat(
                    // 是 FRAMEWORK_BASE_LANGUAGE 已知的 framework/library topic 就歸類到
                    // framework scope，其餘 topic（package/build tool 等）才落回 packagetool。
                    // AUTHORITY 裡有的以 AUTHORITY 為準。
                    collect($repo['topics'] ?? [])->map(fn ($topic) => $resolve(
                        $topic,
                        array_key_exists($topic, self::FRAMEWORK_BASE_LANGUAGE) ? 'framework' : 'packagetool',
                    ))
                )
                // 套件清單裡有、而且在對照表（config/package_technologies.php）裡的套件，
                // 補上它們代表的技術。topic 是手動補充，兩邊重複的技術靠下面的 unique 合併。
                ->concat(
                    collect(PackageTechniqueMap::techniquesFor($repo['packages'] ?? [], $repo['package_versions'] ?? []))
                        ->map(fn (array $technique) => $resolve($technique[0], $technique[1], $technique[2] ?? null))
                )
                ->unique('id');

            // 同一個專案既有「Vue」（例如 languages API 算出的 .vue 檔）又有「Vue 3」（topic）時，
            // 只新建 Vue 3 的邊：用的是哪一版已經知道了，版本留空那筆從 isVersionOf 推得出來。
            // 但以前就建好的「Vue」邊還是照樣更新 last_seen_at——這次同步確實看到了，不更新的話
            // 它會被當成「以前用過、現在沒在用」
            $versionedTitles = $techniques->whereNotNull('version')->map(fn ($t) => mb_strtolower($t->title))->all();
            [$impliedBases, $techniques] = $techniques->partition(fn ($t) => $t->version === null && in_array(mb_strtolower($t->title), $versionedTitles, true));

            $this->link_project_to_techniques($project, $techniques, $usesRelationId);
            $this->link_project_to_techniques($project, $impliedBases, $usesRelationId, createMissing: false);
            $this->nomens->recordRepo($project, $repo, $seenAt);
        });

        $this->nomens->resolve();
    }

    /**
     * 寫「技術 usedBy 專案」這條邊，順便記下這次同步看到它的時間（last_seen_at）。
     *
     * 不用 `$project->techniques()->syncWithoutDetaching()`：它只用 technique_id 比對既有的邊，
     * 遇到同一對專案／技術還有別的述詞（例如手動建的 assists）時，會把那一筆的 relation_id
     * 也改掉。這裡明確限定在 usedBy 這個述詞上。同步只新增、不刪邊，升級前的版本會留著。
     */
    private function link_project_to_techniques(Implementation $project, Collection $techniques, int $usesRelationId, bool $createMissing = true): void
    {
        $now = now();

        foreach ($techniques as $technique) {
            $link = DB::table('technique_implementation')->where([
                'implementation_id' => $project->id,
                'technique_id' => $technique->id,
                'relation_id' => $usesRelationId,
            ]);

            if ($link->exists()) {
                $link->update(['last_seen_at' => $now]);
            } elseif ($createMissing) {
                DB::table('technique_implementation')->insert([
                    'implementation_id' => $project->id,
                    'technique_id' => $technique->id,
                    'relation_id' => $usesRelationId,
                    'last_seen_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    /**
     * 擁有者與 repo 名稱都完全相符（`owner/name`，不分大小寫）才算。
     *
     * 只比名稱會誤傷別人名下的同名 repo（同步來源含協作者、組織成員看得到的 repo），
     * `my-profile-2` 這種只共用前綴的也不算。讀不到擁有者時無法確認身分，不略過；
     * 清單項目沒有 `/`（只寫名稱）時不會等於任何 `owner/name`，同樣不略過。
     */
    private function skips_technique_edges(array $repo): bool
    {
        $title = $repo['title'] ?? null;
        $owner = $repo['owner'] ?? null;

        if (! is_string($title) || $title === '' || ! is_string($owner) || $owner === '') {
            return false;
        }

        $skipped = array_map(
            fn ($fullName) => mb_strtolower(trim((string) $fullName)),
            (array) config('repo_sync.skip_technique_edges', []),
        );

        return in_array(mb_strtolower("{$owner}/{$title}"), $skipped, true);
    }

    private function is_ignored_language(string $name): bool
    {
        return in_array(mb_strtolower($name), self::IGNORED_LANGUAGES, true);
    }

    public function save_repos_content($repo)
    {
        return Implementation::updateOrCreate(
            ['git_repo_id' => $repo['id']],
            [
                'type' => $this->scope_id('project'),
                'title' => $repo['title'],
                'description' => $repo['description'] ?? null,
                'url' => $repo['html_url'] ?? null,
                'git_repo_created_at' => $repo['created_at'] ?? null,
                // maintain_status 對應「repo 目前是否仍在維護」，跟 GitHub 的
                // archived flag 相反（archived = 不再維護）；語意來源見
                // my-dev-grid-front 舊版 scripts/sync-projects.mjs 的
                // status/statusType 推導邏輯（同一批資料的原始設計）。
                'maintain_status' => ! ($repo['archived'] ?? false),
            ]
        );
    }

    /**
     * 比對既有技術時不分大小寫、也不看 scope：`TypeScript`（語言）跟 topic `typescript` 是
     * 同一個技術。版本要完全相同（null 對 null），Vue 跟 Vue 3 是兩筆。
     */
    private function find_or_create_technique(string $title, string $scopeName, ?string $version = null): Technique
    {
        $existing = Technique::whereRaw('lower(title) = ?', [mb_strtolower($title)])
            ->when($version === null, fn ($q) => $q->whereNull('version'), fn ($q) => $q->where('version', $version))
            ->orderBy('id')
            ->first();

        return $existing ?? Technique::create([
            'type' => $this->scope_id($scopeName),
            'title' => $title,
            'version' => $version,
        ]);
    }

    /**
     * 若 $framework 是有單一基礎語言的已知 framework／library（FRAMEWORK_BASE_LANGUAGE，以其小寫 title 查找），
     * 就找出或建立該語言自己的 Technique，並透過同類型的 `requires` EntityRelation 把 $framework 連過去。
     * 不在對照表中的項目不做任何事。只有沒有版本的紀錄會得到這條邊；各版本是透過 isVersionOf 連到它。
     */
    private function link_framework_to_base_language(Technique $framework, int $requiresRelationId): void
    {
        $baseLanguageName = self::FRAMEWORK_BASE_LANGUAGE[mb_strtolower($framework->title)] ?? null;

        if (! $baseLanguageName) {
            return;
        }

        $baseLanguage = $this->find_or_create_technique($baseLanguageName, 'language');

        EntityRelation::firstOrCreate([
            'entity_type' => 'technique',
            'subject_id' => $framework->id,
            'object_id' => $baseLanguage->id,
            'relation_id' => $requiresRelationId,
        ]);
    }

    /**
     * 若 $technique 有已知的官方文件網站（OFFICIAL_DOCS），就找出或建立一筆 Documentation（type: sourcesite），
     * 並透過 `specs` 連到 $technique。不在對照表中的 Technique 不做任何事。
     */
    private function link_official_docs(Technique $technique, int $specsRelationId): void
    {
        $url = self::OFFICIAL_DOCS[mb_strtolower($technique->title)] ?? null;

        if (! $url) {
            return;
        }

        $documentation = Documentation::firstOrCreate(
            ['url' => $url],
            [
                'type' => $this->scope_id('sourcesite'),
                'title' => "{$technique->title} 官方文件",
                'status' => 1,
            ]
        );

        $documentation->techniques()->syncWithoutDetaching([
            $technique->id => ['relation_id' => $specsRelationId],
        ]);
    }

    private function scope_id(string $name): int
    {
        return $this->scopeIdCache[$name] ??= Scope::where('name', $name)->value('id')
            ?? throw new RuntimeException("Scope '{$name}' is not seeded; cannot sync GitHub repo data.");
    }

    /**
     * 依名稱取同步要寫的述詞。述詞已棄用、有改用對象時，改用改用對象寫新的邊（見 `Relation::resolveByName()`）；
     * 既有的邊以 relation_id 為鍵更新 last_seen_at，所以舊述詞的邊從此不再更新，過一段時間會變成過期。
     *
     * 沒辦法往下找的棄用述詞（沒有改用對象，只有直接改資料庫才會發生），照舊用它寫，不讓同步停下來：
     * 停下來的話，所有技術邊都會因為沒再被看到而過期。兩種情況都記一筆日誌。
     */
    private function relation_id(string $name): int
    {
        $relation = Relation::resolveByName($name)
            ?? throw new RuntimeException("Relation '{$name}' is not seeded; cannot sync GitHub repo data.");

        if ($relation->isDeprecated()) {
            Log::warning("述詞「{$name}」已棄用但找不到可用的改用對象，同步照舊使用「{$relation->name}」。", ['relation_id' => $relation->id]);
        } elseif ($relation->name !== $name) {
            Log::info("述詞「{$name}」已棄用，同步改用「{$relation->name}」。", ['relation_id' => $relation->id]);
        }

        return $relation->id;
    }
}
