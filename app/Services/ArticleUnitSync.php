<?php

namespace App\Services;

use App\Models\ArticleUnit;
use App\Models\Documentation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use LogicException;

/**
 * 把一篇文章目前的內文切成段落單位（`ArticleUnitSplitter`），跟 `article_units` 裡上次存的單位對上，
 * 寫回資料庫。由文章匯入（`ArticleSourceService`）在新增、更新文章時呼叫，跟寫文章在同一個 transaction。
 *
 * 對上同一個單位的順序：
 * 1. 明確 ID（標題行尾的 `{#id}`，導言固定是 `lead`）相同。已移除的單位也算，ID 回到原稿就復原。
 * 2. 還沒對上的，跟「還沒有 ID」的舊單位比：父單位是同一列、正規化後的標題相同。
 * 3. 再對不上的，跟剩下還沒有 ID 的舊單位比內容的文字相似度（`similarity()`），
 *    不低於 `SIMILARITY_THRESHOLD` 才算同一個；全部配對依分數由高到低配，不照文件順序搶。
 * 4. 都對不上就是新單位。
 *
 * 第 2、3 步只拿還沒有 ID 的舊單位當候選：`unit_id` 一旦有值就不改、也不清掉，所以有 ID 的舊單位只能
 * 用第 1 步對上，原稿拿掉 `{#id}` 的話舊單位會標成移除，新的單位另外一列。還沒有 ID 的舊單位在第 2、3 步
 * 對上有 ID 的新單位時，補上這個 ID（原稿第一次補寫 `{#id}` 時，列的身分不變）。
 *
 * 父單位要先對上，子單位的第 2 步才比得了；父單位可能要到第 3 步才對上，所以第 2、3 步輪流跑到沒有新的配對為止。
 *
 * 這次沒出現的舊單位不刪，填 `removed_at`（已經填過的不再改時間）。`content_hash` 是標題與內容的雜湊，
 * 標題或內容變了才會變；ID 寫法（`{#id}`）不算在內。
 */
final class ArticleUnitSync
{
    /**
     * 第 3 步的門檻：兩段內容的字元二元組 Dice 係數。
     *
     * 0.8 沒有這個語料的校準資料，是保守的選擇：對不上只會讓舊單位標成移除、另建一列，
     * 錯配則會把另一段的 ID 掛到不相干的內容上。
     */
    public const SIMILARITY_THRESHOLD = 0.8;

    /** 內容（去掉空白後）少於這麼多字元的單位不做第 3 步：只有容器作用的 H2 內容很短，相似度沒有意義。 */
    public const MIN_SIMILARITY_LENGTH = 20;

    public function __construct(private readonly ArticleUnitSplitter $splitter = new ArticleUnitSplitter) {}

    /**
     * @throws InvalidArgumentException 內文的 `{#id}` 重複或太長（匯入時 `ArticleSourceParser` 已經先擋下）
     * @throws LogicException 寫入時撞到 `(article_id, unit_id)` 唯一鍵，代表比對有 bug
     */
    public function sync(Documentation $article): void
    {
        $split = $this->splitter->split((string) $article->body);
        $this->assertStorable($split);
        $units = $split['units'];

        /** @var Collection<int, ArticleUnit> $existing */
        $existing = ArticleUnit::where('article_id', $article->id)->orderBy('position')->orderBy('id')->get();

        $matches = $this->match($units, $existing);

        try {
            $this->write($article, $units, $existing, $matches);
        } catch (UniqueConstraintViolationException $e) {
            // 不讓它以原本的型別往外丟：匯入把這個型別當成「同一篇文章剛被匯入過」，訊息會誤導人
            throw new LogicException('段落單位寫入時撞到 (article_id, unit_id) 唯一鍵，比對結果有誤。', previous: $e);
        }
    }

    /**
     * @param  list<array{unit_id: string|null, parent_index: int|null, heading: string|null, role: string, level: int|null, content: string}>  $units
     * @param  Collection<int, ArticleUnit>  $existing
     * @param  array<int, ArticleUnit>  $matches
     */
    private function write(Documentation $article, array $units, Collection $existing, array $matches): void
    {
        $now = now();
        $rows = [];
        foreach ($units as $index => $unit) {
            $row = $matches[$index] ?? new ArticleUnit(['article_id' => $article->id]);
            $row->fill([
                // 第 1 步對上時兩邊相同；第 2、3 步對上的舊單位沒有 ID，新單位有就補上，沒有就維持空值
                'unit_id' => $unit['unit_id'],
                'role' => $unit['role'],
                'level' => $unit['level'],
                'heading' => $unit['heading'],
                'content' => $unit['content'],
                'content_hash' => self::contentHash($unit['heading'], $unit['content']),
                'position' => $index,
                'removed_at' => null,
            ]);
            $row->save();
            $rows[$index] = $row;
        }

        // 新單位存過才有 id，父單位的指標最後一起補
        foreach ($units as $index => $unit) {
            $rows[$index]->parent_id = $unit['parent_index'] === null ? null : $rows[$unit['parent_index']]->id;
            $rows[$index]->save();
        }

        $kept = array_flip(array_map(fn (ArticleUnit $row) => $row->id, $rows));
        foreach ($existing as $row) {
            if (! isset($kept[$row->id]) && $row->removed_at === null) {
                $row->removed_at = $now;
                $row->save();
            }
        }
    }

    /**
     * 標題與內容的 SHA-256。每個值前面加上長度（標題是 null 時寫成 `-`），
     * 標題與內容的分界不會因為內容裡的字元而跟另一組值算出同一個字串。
     */
    public static function contentHash(?string $heading, string $content): string
    {
        return hash('sha256', ($heading === null ? '-' : strlen($heading).':'.$heading)."\n".strlen($content).':'.$content);
    }

    /**
     * 比對用的標題：連續空白併成一個、去掉頭尾空白、轉小寫。
     */
    public static function normalizeHeading(?string $heading): ?string
    {
        if ($heading === null) {
            return null;
        }

        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $heading)));
    }

    /**
     * 兩段內容的相似度：去掉所有空白後，字元二元組（多重集合）的 Dice 係數，0 到 1。
     * 逐字元（不是逐位元組）切，中文一個字算一個字元。任一邊少於兩個字元時是 0。
     */
    public static function similarity(string $a, string $b): float
    {
        $left = self::bigrams($a);
        $right = self::bigrams($b);
        $total = array_sum($left) + array_sum($right);
        if ($total === 0) {
            return 0.0;
        }

        $shared = 0;
        foreach ($left as $bigram => $count) {
            $shared += min($count, $right[$bigram] ?? 0);
        }

        return 2 * $shared / $total;
    }

    /**
     * @param  array{units: list<array<string, mixed>>, duplicate_ids: list<string>}  $split
     */
    private function assertStorable(array $split): void
    {
        if ($split['duplicate_ids'] !== []) {
            throw new InvalidArgumentException('段落單位的 ID 重複：'.implode('、', $split['duplicate_ids']));
        }
        foreach ($split['units'] as $unit) {
            if ($unit['unit_id'] !== null && strlen($unit['unit_id']) > ArticleUnit::MAX_UNIT_ID_LENGTH) {
                throw new InvalidArgumentException('段落單位的 ID 超過 '.ArticleUnit::MAX_UNIT_ID_LENGTH.' 字元。');
            }
        }
    }

    /**
     * 依第 1 到第 3 步把新切出的單位對上舊單位。
     *
     * @param  list<array{unit_id: string|null, parent_index: int|null, heading: string|null, level: int|null, content: string}>  $units
     * @param  Collection<int, ArticleUnit>  $existing
     * @return array<int, ArticleUnit> 新單位在清單裡的位置 => 對上的舊單位
     */
    private function match(array $units, Collection $existing): array
    {
        $matches = [];

        $byId = $existing->whereNotNull('unit_id')->keyBy('unit_id');
        foreach ($units as $index => $unit) {
            if ($unit['unit_id'] !== null && $byId->has($unit['unit_id'])) {
                $matches[$index] = $byId->get($unit['unit_id']);
            }
        }

        // 候選：還沒有 ID 的舊單位，還在的排前面，再依上次的位置
        $candidates = $existing->whereNull('unit_id')
            ->sortBy(fn (ArticleUnit $row) => [$row->removed_at === null ? 0 : 1, $row->position, $row->id])
            ->values()
            ->all();

        do {
            $before = count($matches);
            $this->matchByHeading($units, $candidates, $matches);
            $this->matchBySimilarity($units, $candidates, $matches);
        } while (count($matches) > $before);

        return $matches;
    }

    /**
     * 第 2 步：父單位是同一列、正規化後的標題相同。父單位還沒對上（新的父單位）就跳過：
     * 舊單位不可能指向還不存在的列。
     *
     * @param  list<array{parent_index: int|null, heading: string|null}>  $units
     * @param  array<int, ArticleUnit>  $candidates  對上的會被拿掉
     * @param  array<int, ArticleUnit>  $matches
     */
    private function matchByHeading(array $units, array &$candidates, array &$matches): void
    {
        foreach ($units as $index => $unit) {
            if (isset($matches[$index]) || $unit['heading'] === null) {
                continue;
            }
            if ($unit['parent_index'] !== null && ! isset($matches[$unit['parent_index']])) {
                continue;
            }
            $parentId = $unit['parent_index'] === null ? null : $matches[$unit['parent_index']]->id;
            $heading = self::normalizeHeading($unit['heading']);

            foreach ($candidates as $key => $row) {
                if ($row->parent_id === $parentId && self::normalizeHeading($row->heading) === $heading) {
                    $matches[$index] = $row;
                    unset($candidates[$key]);
                    break;
                }
            }
        }
    }

    /**
     * 第 3 步：跟剩下的候選比內容相似度，不低於門檻的配對全部列出來，分數由高到低配，
     * 每個新單位、每個候選最多用一次。同分時先配排在前面的新單位，再取排在前面的候選
     * （還在的優先，再依上次的位置）。
     *
     * 不照文件順序一個一個挑：兩段彼此相似的節同時換標題時，排在前面的那節會先搶走另一節的列。
     *
     * @param  list<array{content: string, level: int|null}>  $units
     * @param  array<int, ArticleUnit>  $candidates  對上的會被拿掉
     * @param  array<int, ArticleUnit>  $matches
     */
    private function matchBySimilarity(array $units, array &$candidates, array &$matches): void
    {
        $pairs = [];
        foreach ($units as $index => $unit) {
            // 導言一定有 ID（lead），只走第 1 步
            if (isset($matches[$index]) || $unit['level'] === null || ! self::longEnough($unit['content'])) {
                continue;
            }

            foreach ($candidates as $key => $row) {
                if (! self::longEnough($row->content)) {
                    continue;
                }
                $score = self::similarity($unit['content'], $row->content);
                if ($score >= self::SIMILARITY_THRESHOLD) {
                    $pairs[] = [$score, $index, $key];
                }
            }
        }

        usort($pairs, fn (array $a, array $b) => [$b[0], $a[1], $a[2]] <=> [$a[0], $b[1], $b[2]]);

        foreach ($pairs as [, $index, $key]) {
            if (isset($matches[$index]) || ! isset($candidates[$key])) {
                continue;
            }
            $matches[$index] = $candidates[$key];
            unset($candidates[$key]);
        }
    }

    private static function longEnough(string $content): bool
    {
        return mb_strlen((string) preg_replace('/\s+/u', '', $content)) >= self::MIN_SIMILARITY_LENGTH;
    }

    /** @return array<string, int> 二元組 => 出現次數 */
    private static function bigrams(string $text): array
    {
        $chars = mb_str_split((string) preg_replace('/\s+/u', '', $text));
        $counts = [];
        for ($i = 0, $n = count($chars) - 1; $i < $n; $i++) {
            $bigram = $chars[$i].$chars[$i + 1];
            $counts[$bigram] = ($counts[$bigram] ?? 0) + 1;
        }

        return $counts;
    }
}
