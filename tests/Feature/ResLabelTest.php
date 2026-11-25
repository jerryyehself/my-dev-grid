<?php

namespace Tests\Feature;

use App\Enums\ResLabelType;
use App\Models\Implementation;
use App\Models\ResLabel;
use App\Models\Scope;
use App\Models\Technique;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;
use ValueError;

/**
 * `ResLabel`：res 的別名。資料表沒有外鍵（`res_type` 決定指向哪張表），所以 model 在存檔前檢查
 * `res_type`、`res_id`、`label_type`，並由 `label` 算出比對鍵 `normalized`。
 *
 * 資料取自詞表設計的實例：tailwind 的舊正式名與 topic 寫法（同義），以及兩個都叫 token 的概念詞（同形異義）。
 */
class ResLabelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function technique(string $title, string $scope = 'packagetool'): Technique
    {
        return Technique::factory()->create(['title' => $title, 'type' => Scope::where('name', $scope)->value('id')]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function label(Technique $technique, array $attributes): ResLabel
    {
        return ResLabel::create($attributes + ['res_type' => 'technique', 'res_id' => $technique->id, 'label_type' => ResLabelType::Alt]);
    }

    #[TestDox('同義：一個技術可以有可顯示的別名與只比對的別名')]
    public function test_a_technique_can_carry_alt_and_hidden_labels(): void
    {
        $tailwind = $this->technique('Tailwind CSS');

        $this->label($tailwind, ['label' => 'tailwind', 'locale' => 'zh-Hant', 'note' => '舊正式名']);
        $this->label($tailwind, ['label' => 'tailwindcss', 'label_type' => ResLabelType::Hidden, 'note' => 'GitHub topic／套件寫法']);

        $rows = ResLabel::where('res_type', 'technique')->where('res_id', $tailwind->id)->orderBy('normalized')->get();
        $this->assertSame(['tailwind', 'tailwindcss'], $rows->pluck('normalized')->all());
        $this->assertSame([ResLabelType::Alt, ResLabelType::Hidden], $rows->pluck('label_type')->all());
        $this->assertSame(['alt', 'hidden'], DB::table('res_labels')->orderBy('normalized')->pluck('label_type')->all());
    }

    #[TestDox('同形異義：兩個技術都可以有別名 token')]
    public function test_two_techniques_can_both_carry_the_alias_token(): void
    {
        $authToken = $this->technique('token（驗證）', 'packagetool');
        $llmToken = $this->technique('token（LLM）', 'packagetool');

        $this->label($authToken, ['label' => 'token']);
        $this->label($authToken, ['label' => '存取權杖']);
        $this->label($authToken, ['label' => 'access token', 'locale' => 'en']);
        $this->label($llmToken, ['label' => 'token']);
        $this->label($llmToken, ['label' => '詞元']);

        $this->assertEqualsCanonicalizing(
            [$authToken->id, $llmToken->id],
            ResLabel::where('normalized', 'token')->pluck('res_id')->all(),
        );
        $this->assertSame('accesstoken', ResLabel::where('label', 'access token')->value('normalized'));
    }

    /** @return iterable<string, array{string}> */
    public static function sameKeySpellings(): iterable
    {
        yield '大小寫不同' => ['Token'];
        yield '全形' => ['ＴＯＫＥＮ'];
        yield '頭尾空白' => [' token '];
    }

    #[DataProvider('sameKeySpellings')]
    #[TestDox('同一個 res 不能再存一個正規化後相同的別名')]
    public function test_the_same_res_cannot_store_the_same_key_twice(string $spelling): void
    {
        $authToken = $this->technique('token（驗證）', 'packagetool');
        $this->label($authToken, ['label' => 'token']);

        try {
            // 包在巢狀 transaction（savepoint）裡：PostgreSQL 上一句失敗會讓整個 transaction 失效
            DB::transaction(fn () => $this->label($authToken, ['label' => $spelling, 'label_type' => ResLabelType::Hidden]));
            $this->fail("「{$spelling}」跟「token」是同一個鍵，不該存得進去");
        } catch (UniqueConstraintViolationException) {
            $this->assertSame(1, ResLabel::count());
        }
    }

    #[TestDox('normalized 由 label 算出，不接受外面給的值；改 label 會跟著重算')]
    public function test_normalized_is_always_derived_from_the_label(): void
    {
        $vue = $this->technique('Vue', 'framework');

        $label = ResLabel::create(['res_type' => 'technique', 'res_id' => $vue->id, 'label' => 'Vue.js', 'label_type' => 'alt', 'normalized' => 'something-else']);
        $this->assertSame('vuejs', $label->fresh()->normalized);

        $label->update(['label' => 'VueJS 2']);
        $this->assertSame('vuejs2', $label->fresh()->normalized);
    }

    #[TestDox('專案也可以有別名')]
    public function test_an_implementation_can_carry_a_label(): void
    {
        $project = Implementation::factory()->create(['title' => 'my-dev-grid']);

        ResLabel::create(['res_type' => 'implementation', 'res_id' => $project->id, 'label' => 'dev grid', 'label_type' => 'alt']);

        $this->assertSame('devgrid', ResLabel::where('res_type', 'implementation')->value('normalized'));
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidResTypes(): iterable
    {
        yield '沒有別名的種類（文件）' => ['documentation'];
        yield '大小寫不同' => ['Technique'];
        yield 'model 類別名' => [Technique::class];
        yield '空字串' => [''];
        yield 'NULL' => [null];
    }

    #[DataProvider('invalidResTypes')]
    #[TestDox('res_type 不是 technique 或 implementation 時不存')]
    public function test_an_unknown_res_type_is_rejected(mixed $resType): void
    {
        $vue = $this->technique('Vue', 'framework');

        $this->assertRejected(InvalidArgumentException::class, ['res_type' => $resType, 'res_id' => $vue->id, 'label' => 'vuejs', 'label_type' => 'alt']);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidResIds(): iterable
    {
        yield '不存在' => ['missing'];
        yield '已軟刪除' => ['trashed'];
        yield '只存在於另一種 res（專案的 id）' => ['other_type'];
        yield '零' => ['zero'];
        yield '負數' => ['negative'];
        yield '小數' => ['decimal'];
        yield '字母' => ['letters'];
        yield 'NULL' => ['null'];
    }

    #[DataProvider('invalidResIds')]
    #[TestDox('res_id 指不到還在的那種 res 時不存')]
    public function test_a_res_id_that_does_not_point_to_a_live_row_is_rejected(string $case): void
    {
        $trashed = $this->technique('Hack', 'language');
        $trashed->delete();
        $project = Implementation::factory()->create();
        $unused = max(Technique::withTrashed()->max('id'), $project->id) + 1000;
        // 讓專案的 id 不會剛好也是某個技術的 id
        $otherType = Implementation::factory()->create();
        $otherType->forceFill(['id' => $unused])->save();

        $resId = match ($case) {
            'missing' => $unused + 1,
            'trashed' => $trashed->id,
            'other_type' => $unused,
            'zero' => 0,
            'negative' => -1,
            'decimal' => '1.5',
            'letters' => 'abc',
            'null' => null,
        };

        $this->assertRejected(InvalidArgumentException::class, ['res_type' => 'technique', 'res_id' => $resId, 'label' => 'hack', 'label_type' => 'alt']);
    }

    /** @return iterable<string, array{mixed, class-string}> */
    public static function invalidLabelTypes(): iterable
    {
        yield '正式名不在這張表' => ['pref', ValueError::class];
        yield '大小寫不同' => ['ALT', ValueError::class];
        yield 'SKOS 的屬性名' => ['altLabel', ValueError::class];
        yield 'NULL' => [null, InvalidArgumentException::class];
    }

    #[DataProvider('invalidLabelTypes')]
    #[TestDox('label_type 不是 alt 或 hidden 時不存')]
    public function test_an_unknown_label_type_is_rejected(mixed $labelType, string $exception): void
    {
        $vue = $this->technique('Vue', 'framework');

        $this->assertRejected($exception, ['res_type' => 'technique', 'res_id' => $vue->id, 'label' => 'vuejs', 'label_type' => $labelType]);
    }

    /** @return iterable<string, array{mixed}> */
    public static function emptyLabels(): iterable
    {
        yield '空字串' => [''];
        yield '只有空白' => ['   '];
        yield '只有標點' => ['——'];
        yield 'NULL' => [null];
    }

    #[DataProvider('emptyLabels')]
    #[TestDox('label 正規化後什麼都不剩時不存')]
    public function test_a_label_without_letters_or_digits_is_rejected(mixed $label): void
    {
        $vue = $this->technique('Vue', 'framework');

        $this->assertRejected(InvalidArgumentException::class, ['res_type' => 'technique', 'res_id' => $vue->id, 'label' => $label, 'label_type' => 'alt']);
    }

    /**
     * @param  class-string<\Throwable>  $exception
     * @param  array<string, mixed>  $attributes
     */
    private function assertRejected(string $exception, array $attributes): void
    {
        try {
            DB::transaction(fn () => ResLabel::create($attributes));
            $this->fail('應該被擋下：'.json_encode($attributes, JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            $this->assertInstanceOf($exception, $e, $e->getMessage());
        }

        $this->assertSame(0, ResLabel::count());
    }
}
