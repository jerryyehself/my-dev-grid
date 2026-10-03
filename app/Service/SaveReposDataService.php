<?php

namespace App\Service;

use App\Models\Documentation;
use App\Models\EntityRelation;
use App\Models\Implementation;
use App\Models\Relation;
use App\Models\Scope;
use App\Models\Technique;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * save git data
 *
 * Pulls every public repo from GitService, upserts each one as an
 * Implementation (type: project), and links it to a Technique per
 * language/topic via the existing `usedBy` Relation (Technique usedBy
 * Implementation — the reverse of `uses`, because `technique_implementation`
 * always has the Technique as subject). A `topics` entry is
 * classified `framework` scope when it's a key in FRAMEWORK_BASE_LANGUAGE,
 * otherwise `packagetool` (see class_number/call_number in ScopeSeeder).
 *
 * Two additional, hand-maintained relations are filled in where known:
 * - A framework/library topic (e.g. `vue`) is linked to its single base
 *   language Technique (e.g. `JavaScript`) via `requires` (same-type,
 *   `entity_relations`) — see FRAMEWORK_BASE_LANGUAGE.
 * - A Technique with a known official documentation site gets a
 *   Documentation (type: sourcesite) linked to it via `specs` — see
 *   OFFICIAL_DOCS.
 * Both maps are deliberately small: a Technique not listed just doesn't
 * get the extra edge, rather than guessing at one.
 *
 * NOTE(2026-09-07): before this fix, every `topics` entry — framework
 * topics included — was unconditionally classified `packagetool`. A
 * one-time backfill migration (`..._reclassify_framework_topics_...`)
 * re-points any pre-existing `packagetool`-scope Technique row whose title
 * matches a FRAMEWORK_BASE_LANGUAGE key over to `framework` **in place**
 * (same row id, so `technique_implementation`/`entity_relations`/
 * `documentation_technique` rows already pointing at it stay valid). That
 * migration's topic list is a point-in-time snapshot of
 * FRAMEWORK_BASE_LANGUAGE as of the fix — it is intentionally NOT kept in
 * sync with future edits to the const below. If FRAMEWORK_BASE_LANGUAGE
 * ever gains a new key, any `packagetool`-scope Technique already synced
 * under that title needs its own follow-up backfill (same pattern, new
 * migration) — this in-code reclassification only affects future syncs.
 */
class SaveReposDataService
{
    public $gitService;

    /**
     * @var array<string, int>
     */
    private array $scopeIdCache = [];

    /**
     * GitHub repo `topics` (as GitHub stores them: lowercase) that are a
     * framework/library with one unambiguous base language, mapped to that
     * language exactly as GitHub's languages API names it.
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
    ];

    /**
     * Official documentation site URL for a Technique, keyed by its title
     * lowercased (looked up case-insensitively since 2026-09-30, when topic
     * variants started resolving to canonical names like `Vue`).
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
     *                                  repo data (e.g. a JSON snapshot loaded by
     *                                  `GitHubReposSnapshotSeeder`). Defaults to a live
     *                                  `GitService::get_repos()` call.
     */
    public function __construct(?Collection $repos = null)
    {
        $this->gitService = $repos ?? (new GitService)->get_repos();
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

        $this->gitService->each(function ($repo) use ($usesRelationId, $requiresRelationId, $specsRelationId, $isVersionOfRelationId) {
            $project = $this->save_repos_content($repo);

            $resolve = function (string $name, string $defaultScope) use ($requiresRelationId, $specsRelationId, $isVersionOfRelationId): Technique {
                [$title, $scopeName, $version] = self::AUTHORITY[mb_strtolower($name)] ?? [$name, $defaultScope, null];

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
                ->unique('id');

            // 同一個專案既有「Vue」（例如 languages API 算出的 .vue 檔）又有「Vue 3」（topic）時，
            // 只新建 Vue 3 的邊：用的是哪一版已經知道了，版本留空那筆從 isVersionOf 推得出來。
            // 但以前就建好的「Vue」邊還是照樣更新 last_seen_at——這次同步確實看到了，不更新的話
            // 它會被當成「以前用過、現在沒在用」
            $versionedTitles = $techniques->whereNotNull('version')->map(fn ($t) => mb_strtolower($t->title))->all();
            [$impliedBases, $techniques] = $techniques->partition(fn ($t) => $t->version === null && in_array(mb_strtolower($t->title), $versionedTitles, true));

            $this->link_project_to_techniques($project, $techniques, $usesRelationId);
            $this->link_project_to_techniques($project, $impliedBases, $usesRelationId, createMissing: false);
        });
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
     * If $framework is a known framework/library with a single base language
     * (FRAMEWORK_BASE_LANGUAGE, looked up by its lowercased title), find-or-create
     * that language's own Technique and link $framework to it via a same-type
     * `requires` EntityRelation. A no-op for anything not in the map. Only the
     * version-less record gets this edge; versions reach it via isVersionOf.
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
     * If $technique has a known official documentation site (OFFICIAL_DOCS),
     * find-or-create a Documentation (type: sourcesite) for it and link it
     * to $technique via `specs`. A no-op for any Technique not in the map.
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

    private function relation_id(string $name): int
    {
        return Relation::where('name', $name)->value('id')
            ?? throw new RuntimeException("Relation '{$name}' is not seeded; cannot sync GitHub repo data.");
    }
}
