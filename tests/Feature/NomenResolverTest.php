<?php

namespace Tests\Feature;

use App\Models\Implementation;
use App\Models\Nomen;
use App\Models\Scope;
use App\Models\Technique;
use App\Services\NomenResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * `NomenResolver::resolveAll()`：每個字串依來源對到哪個節點、用了哪條規則、證據夠不夠。
 * 只寫 `nomens` 的 `res_*` 與 `resolution`，不碰任何邊。
 */
class NomenResolverTest extends TestCase
{
    use RefreshDatabase;

    private const SYNC_TIME = '2026-11-13 03:17:00';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function technique(string $title, string $scope, ?string $version = null): Technique
    {
        return Technique::factory()->create(['type' => Scope::where('name', $scope)->value('id'), 'title' => $title, 'version' => $version]);
    }

    private function nomen(string $source, string $string, int $contextId = 1, ?int $weight = null, string $seenAt = self::SYNC_TIME): Nomen
    {
        return Nomen::create([
            'string' => $string,
            'normalized' => NomenResolver::normalize($string),
            'source' => $source,
            'context_type' => str_starts_with($source, 'github_') ? Nomen::CONTEXT_IMPLEMENTATION : Nomen::CONTEXT_DOCUMENTATION,
            'context_id' => $contextId,
            'weight' => $weight,
            'resolution' => Nomen::UNRESOLVED,
            'first_seen_at' => $seenAt,
            'last_seen_at' => $seenAt,
        ]);
    }

    private function package(?string $ecosystem, string $name, int $contextId = 1, string $seenAt = self::SYNC_TIME): Nomen
    {
        return Nomen::create([
            'string' => $name,
            'normalized' => NomenResolver::normalize($name),
            'source' => Nomen::SOURCE_PACKAGE,
            'scheme' => $ecosystem,
            'context_type' => Nomen::CONTEXT_IMPLEMENTATION,
            'context_id' => $contextId,
            'resolution' => Nomen::UNRESOLVED,
            'first_seen_at' => $seenAt,
            'last_seen_at' => $seenAt,
        ]);
    }

    /** @return array{0: string|null, 1: int|null, 2: string} */
    private function resolved(Nomen $nomen): array
    {
        $nomen->refresh();

        return [$nomen->res_type, $nomen->res_id, $nomen->resolution];
    }

    private function resolve(): void
    {
        (new NomenResolver)->resolveAll();
    }

    #[TestDox('topic 的各種寫法依規則對到節點，並記下用了哪條規則')]
    public function test_topic_spellings_resolve_by_rule(): void
    {
        $vue = $this->technique('Vue', 'framework');
        $vue3 = $this->technique('Vue', 'framework', '3');
        $tailwind = $this->technique('Tailwind CSS', 'packagetool');
        $nuxt = $this->technique('Nuxt', 'framework');
        $heroku = $this->technique('Heroku', 'packagetool');
        $rows = [
            'vue' => [$this->nomen('github_topic', 'vue'), $vue->id, 'exact'],
            'Vue 3' => [$this->nomen('github_topic', 'vue-3', 2), $vue3->id, 'exact'],
            'vuejs' => [$this->nomen('github_topic', 'vuejs', 3), $vue->id, 'variant:suffix'],
            'tailwind' => [$this->nomen('github_topic', 'tailwind'), $tailwind->id, 'variant:suffix'],
            'nuxt3 版本節點不存在時對到沒有版本的那筆' => [$this->nomen('github_topic', 'nuxt3'), $nuxt->id, 'part:version'],
            'procfile' => [$this->nomen('github_topic', 'procfile'), $heroku->id, 'crosswalk'],
        ];

        $this->resolve();

        foreach ($rows as $name => [$nomen, $resId, $rule]) {
            $this->assertSame(['technique', $resId, $rule], $this->resolved($nomen), $name);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function namesThatMustStayUnresolved(): iterable
    {
        yield '結尾是數字但字根不是技術' => ['isbn3'];
        yield '中間有數字的套件名' => ['html5-qrcode'];
        yield '字根太短不去掉 css' => ['scss'];
        yield '只有字尾' => ['css'];
        yield '字根太短不補 js' => ['vu'];
    }

    #[DataProvider('namesThatMustStayUnresolved')]
    #[TestDox('規則不會把套件名或太短的字根拆錯')]
    public function test_names_that_must_stay_unresolved(string $name): void
    {
        $this->technique('Vue', 'framework');
        $this->technique('isbn', 'environment');
        $this->technique('HTML', 'language');
        $this->technique('s', 'packagetool');
        $nomen = $this->nomen('github_topic', $name);

        $this->resolve();

        $this->assertSame([null, null, Nomen::UNRESOLVED], $this->resolved($nomen));
    }

    #[TestDox('結尾是數字的鍵本身對得到節點時，用完全相等，不拆版本')]
    public function test_exact_match_wins_over_version_split(): void
    {
        $this->technique('isbn', 'packagetool');
        $isbn3 = $this->technique('isbn3', 'packagetool');
        $nomen = $this->nomen('github_topic', 'isbn3');

        $this->resolve();

        $this->assertSame(['technique', $isbn3->id, 'exact'], $this->resolved($nomen));
    }

    #[TestDox('GitHub 語言只對得到 language 分類，同名的框架節點不算')]
    public function test_github_language_only_matches_language_scope(): void
    {
        $this->technique('Vue', 'framework');
        $php = $this->technique('PHP', 'language');
        $vue = $this->nomen('github_language', 'Vue', weight: 9000);
        $phpRow = $this->nomen('github_language', 'PHP', weight: 50000);

        $this->resolve();

        $this->assertSame([null, null, Nomen::UNRESOLVED], $this->resolved($vue));
        $this->assertSame(['technique', $php->id, 'exact'], $this->resolved($phpRow));
    }

    #[TestDox('對照表不受語言的分類限制：Linguist 的 Procfile 對到套件工具 Heroku')]
    public function test_crosswalk_reaches_outside_the_language_scope(): void
    {
        $heroku = $this->technique('Heroku', 'packagetool');
        $withoutBytes = $this->nomen('github_language', 'Procfile', 1);
        // 位元組數太少時節點照樣記下，只是證據不夠
        $tiny = $this->nomen('github_language', 'Procfile', 2, 30);

        $this->resolve();

        $this->assertSame(['technique', $heroku->id, 'crosswalk'], $this->resolved($withoutBytes));
        $this->assertSame(['technique', $heroku->id, Nomen::BELOW_THRESHOLD], $this->resolved($tiny));
    }

    #[TestDox('C、C++、C# 各自對到自己的語言')]
    public function test_c_family_languages_stay_distinct(): void
    {
        $ids = [];
        $rows = [];
        foreach (['C', 'C++', 'C#'] as $name) {
            $ids[$name] = $this->technique($name, 'language')->id;
            $rows[$name] = $this->nomen('github_language', $name);
        }

        $this->resolve();

        foreach ($rows as $name => $nomen) {
            $this->assertSame($ids[$name], $this->resolved($nomen)[1], $name);
        }
    }

    #[TestDox('文章的專案名稱只對到 project 分類的專案')]
    public function test_about_projects_match_projects_by_title(): void
    {
        $project = Implementation::factory()->create(['type' => Scope::where('name', 'project')->value('id'), 'title' => 'isbn-scanner']);
        Implementation::factory()->create(['type' => Scope::where('name', 'work')->value('id'), 'title' => 'other-work']);
        $hit = $this->nomen('front_matter.about_projects', 'ISBN Scanner');
        $miss = $this->nomen('front_matter.about_projects', 'other work');

        $this->resolve();

        $this->assertSame(['implementation', $project->id, 'exact'], $this->resolved($hit));
        $this->assertSame([null, null, Nomen::UNRESOLVED], $this->resolved($miss));
    }

    #[TestDox('對上兩個節點時選同一作品已經對到的那個，分不出來就是 ambiguous')]
    public function test_ambiguous_names_prefer_the_node_already_seen_in_the_same_work(): void
    {
        $language = $this->technique('Schema', 'language');
        $this->technique('schema', 'packagetool');
        // 語言只能對到 language 分類，所以 repo 1 的語言明確對到 language 那一筆
        $this->nomen('github_language', 'Schema', 1);
        $sameWork = $this->nomen('github_topic', 'schema', 1);
        $otherWork = $this->nomen('github_topic', 'schema', 2);

        $this->resolve();

        $this->assertSame(['technique', $language->id, Nomen::CORROBORATED], $this->resolved($sameWork));
        $this->assertSame([null, null, Nomen::AMBIGUOUS], $this->resolved($otherWork));
    }

    #[TestDox('之後才出現的節點，讓以前對不上的名稱自動接上')]
    public function test_late_binding_resolves_a_name_once_its_node_appears(): void
    {
        $nomen = $this->nomen('front_matter.about_techniques', 'Next.js');
        $this->resolve();
        $this->assertSame(Nomen::UNRESOLVED, $this->resolved($nomen)[2]);

        $next = $this->technique('Next.js', 'framework');
        $this->resolve();

        $this->assertSame(['technique', $next->id, 'exact'], $this->resolved($nomen));
    }

    /** @return iterable<string, array{array<string, array<string, int|null>>, string, string, string}> */
    public static function languageEvidence(): iterable
    {
        yield '占比低、只在一個 repo（Hack 的情況）' => [[1 => ['PHP' => 250000, 'Hack' => 15000]], 'Hack', '1', Nomen::BELOW_THRESHOLD];
        yield '不到 1 KB，就算占比高' => [[1 => ['Shell' => 900]], 'Shell', '1', Nomen::BELOW_THRESHOLD];
        yield '剛好 1 KB 而且占比高' => [[1 => ['Shell' => 1024]], 'Shell', '1', 'exact'];
        yield '1 KB 少 1 個位元組' => [[1 => ['Shell' => 1023]], 'Shell', '1', Nomen::BELOW_THRESHOLD];
        yield '占比剛好 20%' => [[1 => ['PHP' => 8000, 'Hack' => 2000]], 'Hack', '1', 'exact'];
        yield '占比低但另一個 repo 也過了下限（Dockerfile 的情況）' => [[1 => ['PHP' => 900000, 'Dockerfile' => 4000], 2 => ['TypeScript' => 9000, 'Dockerfile' => 2000]], 'Dockerfile', '1', 'exact'];
        yield '另一個 repo 也有但沒過下限' => [[1 => ['PHP' => 900000, 'Dockerfile' => 4000], 2 => ['TypeScript' => 9000, 'Dockerfile' => 500]], 'Dockerfile', '1', Nomen::BELOW_THRESHOLD];
        yield '另一個 repo 沒有位元組數（舊快照）時當成通過' => [[1 => ['PHP' => 900000, 'Dockerfile' => 4000], 2 => ['Dockerfile' => null]], 'Dockerfile', '1', 'exact'];
        yield '自己沒有位元組數時當成通過' => [[1 => ['PHP' => null, 'Shell' => null]], 'Shell', '1', 'exact'];
    }

    /** @param  array<int, array<string, int|null>>  $repos  repo => 語言 => 位元組數 */
    #[DataProvider('languageEvidence')]
    #[TestDox('GitHub 語言的證據門檻：至少 1 KB，而且占 20% 以上或其他 repo 也有')]
    public function test_language_evidence_threshold(array $repos, string $language, string $repo, string $expected): void
    {
        foreach (['PHP', 'Hack', 'Shell', 'Dockerfile', 'TypeScript'] as $name) {
            $this->technique($name, 'language');
        }
        $rows = [];
        foreach ($repos as $contextId => $bytes) {
            foreach ($bytes as $name => $weight) {
                $rows["{$contextId}:{$name}"] = $this->nomen('github_language', $name, $contextId, $weight);
            }
        }

        $this->resolve();

        $this->assertSame($expected, $this->resolved($rows["{$repo}:{$language}"])[2]);
    }

    #[TestDox('上一次同步才看到的語言不算進這次的證據')]
    public function test_rows_not_seen_in_the_latest_sync_do_not_count_as_evidence(): void
    {
        $this->technique('PHP', 'language');
        $dockerfile = $this->technique('Dockerfile', 'language');
        $this->nomen('github_language', 'PHP', 1, 900000);
        $row = $this->nomen('github_language', 'Dockerfile', 1, 4000);
        $this->nomen('github_language', 'Dockerfile', 2, 2000, seenAt: '2026-11-01 03:17:00');

        $this->resolve();

        $this->assertSame(['technique', $dockerfile->id, Nomen::BELOW_THRESHOLD], $this->resolved($row));
    }

    #[TestDox('topic 對到的節點同 repo 的語言也撐得起時記成 corroborated，否則維持比對規則')]
    public function test_topics_are_corroborated_only_by_a_usable_language_in_the_same_repo(): void
    {
        $php = $this->technique('PHP', 'language');
        $laravel = $this->technique('laravel', 'packagetool');
        $hack = $this->technique('Hack', 'language');
        $this->nomen('github_language', 'PHP', 1, 50000);
        $this->nomen('github_language', 'Hack', 1, 100);
        $phpTopic = $this->nomen('github_topic', 'php', 1);
        $laravelTopic = $this->nomen('github_topic', 'laravel', 1);
        $hackTopic = $this->nomen('github_topic', 'hack', 1);
        $otherRepo = $this->nomen('github_topic', 'php', 2);

        $this->resolve();

        $this->assertSame(['technique', $php->id, Nomen::CORROBORATED], $this->resolved($phpTopic));
        $this->assertSame(['technique', $laravel->id, 'exact'], $this->resolved($laravelTopic));
        $this->assertSame(['technique', $hack->id, 'exact'], $this->resolved($hackTopic));
        $this->assertSame(['technique', $php->id, 'exact'], $this->resolved($otherRepo));
    }

    #[TestDox('套件名照對照表換成技術名稱再對節點，不分分類，名稱裡的點不會被當成巢狀 key')]
    public function test_packages_resolve_through_the_package_map(): void
    {
        $laravel = $this->technique('laravel', 'packagetool');
        $vue = $this->technique('Vue', 'framework');
        $this->technique('Vue', 'framework', '3');
        $typescript = $this->technique('TypeScript', 'language');
        $chartjs = $this->technique('chartjs', 'packagetool');
        $rows = [
            'composer 的 laravel/framework 對到 laravel' => [$this->package('composer', 'laravel/framework'), $laravel->id],
            '套件名不分大小寫，只對到沒有版本的 Vue' => [$this->package('npm', 'Vue'), $vue->id],
            '對照表歸為 language 的套件' => [$this->package('npm', 'typescript'), $typescript->id],
            'chart.js' => [$this->package('npm', 'chart.js'), $chartjs->id],
        ];

        $this->resolve();

        foreach ($rows as $name => [$nomen, $resId]) {
            $this->assertSame(['technique', $resId, NomenResolver::PACKAGE_MAP], $this->resolved($nomen), $name);
        }
    }

    /** @return iterable<string, array{string|null, string}> */
    public static function packagesThatMustStayUnresolved(): iterable
    {
        yield '不在對照表裡的套件' => ['npm', 'eslint'];
        yield '名稱相近但沒列（只列了 vue）' => ['npm', 'vue-router'];
        yield '列在別的生態系' => ['npm', 'laravel/framework'];
        yield '不認得的生態系' => ['cargo', 'vue'];
        yield '沒有生態系' => [null, 'vue'];
        yield '生態系字串剛好是一筆對照的路徑' => ['npm.vue', 'vue'];
        yield '對照表有、但站上還沒有這個技術' => ['npm', 'pinia'];
    }

    #[DataProvider('packagesThatMustStayUnresolved')]
    #[TestDox('套件只認對照表：不在表裡、生態系不對、技術還不存在的都對不上')]
    public function test_packages_that_must_stay_unresolved(?string $ecosystem, string $name): void
    {
        $this->technique('Vue', 'framework');
        $this->technique('laravel', 'packagetool');
        $this->technique('eslint', 'packagetool');
        $this->technique('vue-router', 'packagetool');
        $nomen = $this->package($ecosystem, $name);

        $this->resolve();

        $this->assertSame([null, null, Nomen::UNRESOLVED], $this->resolved($nomen));
    }

    #[TestDox('topic 對到的節點同 repo 的套件撐得起時也記成 corroborated')]
    public function test_topics_are_corroborated_by_a_package_in_the_same_repo(): void
    {
        $laravel = $this->technique('laravel', 'packagetool');
        $this->package('composer', 'laravel/framework', 1);
        $sameRepo = $this->nomen('github_topic', 'laravel', 1);
        $otherRepo = $this->nomen('github_topic', 'laravel', 2);
        // 上一次同步才看到的套件不算
        $this->package('composer', 'laravel/framework', 3, seenAt: '2026-11-01 03:17:00');
        $staleRepo = $this->nomen('github_topic', 'laravel', 3);

        $this->resolve();

        $this->assertSame(['technique', $laravel->id, Nomen::CORROBORATED], $this->resolved($sameRepo));
        $this->assertSame(['technique', $laravel->id, 'exact'], $this->resolved($otherRepo));
        $this->assertSame(['technique', $laravel->id, 'exact'], $this->resolved($staleRepo));
    }

    #[TestDox('節點被軟刪除後，原本對到它的名稱變回 unresolved')]
    public function test_soft_deleted_nodes_are_not_matched(): void
    {
        $php = $this->technique('PHP', 'language');
        $nomen = $this->nomen('github_language', 'PHP');
        $this->resolve();
        $this->assertSame($php->id, $this->resolved($nomen)[1]);

        $php->delete();
        $this->resolve();

        $this->assertSame([null, null, Nomen::UNRESOLVED], $this->resolved($nomen));
    }

    #[TestDox('不認得的來源一律 unresolved')]
    public function test_unknown_source_is_unresolved(): void
    {
        $this->technique('PHP', 'language');
        $nomen = $this->nomen('github_language', 'PHP');
        $nomen->update(['source' => 'github_languages']);

        $this->resolve();

        $this->assertSame([null, null, Nomen::UNRESOLVED], $this->resolved($nomen));
    }
}
