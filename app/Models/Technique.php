<?php

namespace App\Models;

use Database\Factories\TechniqueFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Technique extends Model
{
    /** @use HasFactory<TechniqueFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'type',
        'title',
        'version',
        'note',
    ];

    protected $dateFormat = 'Y-m-d H:i:s';

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
        return $this->belongsToMany(Documentation::class)->withPivot('relation_id');
    }

    public function implementations()
    {
        return $this->belongsToMany(Implementation::class, 'technique_implementation')->withPivot('relation_id');
    }
}
