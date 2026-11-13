<?php

namespace Tests\Feature;

use App\Models\Nomen;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * 2026_11_13_000000_create_nomens_table：唯一鍵在 SQLite 與 PostgreSQL 上都要擋得住重複的證據列，
 * 而且 down() 只拿掉這張新表。
 */
class CreateNomensTableMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_11_13_000000_create_nomens_table.php';

    /** @return array<string, mixed> */
    private static function row(array $overrides = []): array
    {
        return $overrides + [
            'string' => 'Laravel',
            'normalized' => 'laravel',
            'source' => 'admin',
            'scheme' => null,
            'context_type' => Nomen::NO_CONTEXT_TYPE,
            'context_id' => Nomen::NO_CONTEXT_ID,
            'resolution' => Nomen::UNRESOLVED,
            'first_seen_at' => '2026-11-13 00:00:00',
            'last_seen_at' => '2026-11-13 00:00:00',
        ];
    }

    #[TestDox('沒有作品位置的來源用固定值，重跑 upsert 兩次仍只有一列')]
    public function test_a_source_without_a_work_position_is_deduplicated_through_the_sentinel(): void
    {
        $keys = ['source', 'context_type', 'context_id', 'normalized'];

        DB::table('nomens')->upsert([self::row()], $keys, ['last_seen_at']);
        DB::table('nomens')->upsert([self::row(['last_seen_at' => '2026-11-14 00:00:00'])], $keys, ['last_seen_at']);

        $this->assertSame(1, DB::table('nomens')->count());
        $this->assertSame('2026-11-14 00:00:00', DB::table('nomens')->value('last_seen_at'));
    }

    #[TestDox('同一來源、同一作品、同一個鍵直接插入第二列會被唯一索引擋下')]
    public function test_a_second_row_with_the_same_key_is_rejected(): void
    {
        DB::table('nomens')->insert(self::row());

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('nomens')->insert(self::row(['string' => 'laravel']));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function rowsThatDifferInOneKeyColumn(): iterable
    {
        yield '來源欄位不同' => [['source' => 'front_matter.about_projects']];
        yield '作品不同' => [['context_type' => Nomen::CONTEXT_DOCUMENTATION, 'context_id' => 1]];
        yield '鍵不同' => [['string' => 'Laravel 11', 'normalized' => 'laravel11']];
    }

    #[DataProvider('rowsThatDifferInOneKeyColumn')]
    #[TestDox('鍵的四欄有一欄不同就是另一列')]
    public function test_rows_that_differ_in_one_key_column_coexist(array $overrides): void
    {
        DB::table('nomens')->insert(self::row());
        DB::table('nomens')->insert(self::row($overrides));

        $this->assertSame(2, DB::table('nomens')->count());
    }

    /** @return iterable<string, array{string}> */
    public static function requiredColumns(): iterable
    {
        foreach (['string', 'normalized', 'source', 'context_type', 'context_id', 'resolution', 'first_seen_at', 'last_seen_at'] as $column) {
            yield $column => [$column];
        }
    }

    #[DataProvider('requiredColumns')]
    #[TestDox('鍵與必填欄位不能是 NULL，作品位置留空會被擋下')]
    public function test_required_columns_reject_null(string $column): void
    {
        $this->expectException(QueryException::class);
        DB::table('nomens')->insert(self::row([$column => null]));
    }

    #[TestDox('down() 只刪掉 nomens，既有的表都還在；up() 可以再建回來')]
    public function test_down_drops_only_the_nomens_table(): void
    {
        $migration = require database_path(self::MIGRATION);
        $tablesBefore = collect(Schema::getTableListing())->map(fn ($t) => last(explode('.', $t)))->sort()->values();

        $migration->down();

        $this->assertFalse(Schema::hasTable('nomens'));
        $this->assertEquals(
            $tablesBefore->reject(fn ($t) => $t === 'nomens')->values(),
            collect(Schema::getTableListing())->map(fn ($t) => last(explode('.', $t)))->sort()->values(),
        );

        $migration->up();
        $this->assertTrue(Schema::hasTable('nomens'));
    }
}
