<?php

namespace App\Models;

use App\Traits\ResolvesPositiveIntegerRouteKeys;
use Database\Factories\TechniqueFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $deprecated_at 停用時間，null 代表仍在使用（見 deprecate()）
 * @property-read string $label 顯示用的名稱（見 labelFrom()）
 */
class Technique extends Model
{
    /** @use HasFactory<TechniqueFactory> */
    use HasFactory, ResolvesPositiveIntegerRouteKeys, SoftDeletes;

    protected $fillable = [
        'type',
        'title',
        'version',
        'note',
    ];

    protected $dateFormat = 'Y-m-d H:i:s';

    /** 沿「改用」往下找的步數上限，跟 `Relation` 一樣。正常資料不會有環（改用對象一定是使用中的），這是防直接改資料庫的情況。 */
    private const MAX_REPLACEMENT_HOPS = 10;

    /**
     * deprecated_at 與 replaced_by_id 刻意不在 $fillable 裡：只有停用的動作會寫，
     * `PUT /api/techniques/{technique}` 的 update($validated) 碰不到。
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['deprecated_at' => 'datetime'];
    }

    /**
     * 顯示用的名稱：「{title} {version}」，version 留空就只有 title（例如「Vue 3」／「Vue」）。
     *
     * 同一個技術的每個版本是獨立的一筆（title 相同、version 填主版號，2026-09-30），
     * 只顯示 title 的話「Vue」和「Vue 3」會長得一模一樣。規則只寫在這裡一份：
     * 拿得到 model 的地方用 `$technique->label`，只拿得到原始欄位的地方（例如
     * `RelationEdgeQuery` 的 UNION 查詢結果）呼叫 `labelFrom()`。前端的同一條規則在
     * my-dev-grid-front 的 `src/api/techniqueLabel.ts`，兩邊格式要一致。
     */
    public static function labelFrom(string $title, ?string $version): string
    {
        return filled($version) ? "{$title} {$version}" : $title;
    }

    protected function label(): Attribute
    {
        return Attribute::get(fn () => self::labelFrom($this->title, $this->version));
    }

    public function scope()
    {
        return $this->belongsTo(Scope::class, 'type');
    }

    public function documentations()
    {
        return $this->belongsToMany(Documentation::class)->withPivot('relation_id')->withTimestamps();
    }

    public function implementations()
    {
        return $this->belongsToMany(Implementation::class, 'technique_implementation')->withPivot('relation_id', 'manually_set_at')->withTimestamps();
    }

    /**
     * 停用後改用的技術。會套 SoftDeletes，指到已軟刪除的技術時解析成 null。
     *
     * @return BelongsTo<self, $this>
     */
    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_id');
    }

    public function isDeprecated(): bool
    {
        return $this->deprecated_at !== null;
    }

    /**
     * 標為停用，可指定改用哪一筆。已經停用的再送一次，保留原本的停用時間，只更新改用對象。
     *
     * 只改這兩欄；把連結搬到改用對象（合併）是 `TechniqueMerger` 的事。用 save()，稽核紀錄（AdminAudit）才記得到。
     */
    public function deprecate(?self $replacement): void
    {
        $this->deprecated_at ??= now();
        $this->replaced_by_id = $replacement?->id;
        $this->save();
    }

    /** 取消停用，連同改用對象一起清掉。合併時搬走的連結不會搬回來。 */
    public function undeprecate(): void
    {
        $this->deprecated_at = null;
        $this->replaced_by_id = null;
        $this->save();
    }

    /**
     * 從 $technique 沿「改用」往下走過的技術，第一個是 $technique 自己。
     *
     * 停在：仍在使用的、已停用但沒有改用對象的、改用對象找不到的（已刪除），或走滿 MAX_REPLACEMENT_HOPS 步。
     *
     * @param  callable(int): (self|null)  $find  依 id 取技術；可以傳查資料庫的，也可以傳查已載入清單的
     * @return non-empty-list<self>
     */
    public static function replacementPath(self $technique, callable $find): array
    {
        $path = [$technique];

        for ($hops = 0; $technique->isDeprecated() && $technique->replaced_by_id !== null && $hops < self::MAX_REPLACEMENT_HOPS; $hops++) {
            $technique = $find((int) $technique->replaced_by_id);
            if ($technique === null) {
                break;
            }
            $path[] = $technique;
        }

        return $path;
    }

    /**
     * 程式該用的那一筆：仍在使用的就是自己；已停用的沿「改用」找到仍在使用的那一筆。
     * 停用而沒有改用對象、改用對象已刪除，或一路都是停用的，回 null，呼叫端要跳過這筆。
     *
     * @param  callable(int): (self|null)|null  $find  同 replacementPath()；不給就查資料庫
     */
    public function inUse(?callable $find = null): ?self
    {
        $path = self::replacementPath($this, $find ?? fn (int $id) => static::find($id));
        $last = $path[array_key_last($path)];

        return $last->isDeprecated() ? null : $last;
    }
}
