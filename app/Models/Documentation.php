<?php

namespace App\Models;

use Database\Factories\DocumentationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Documentation extends Model
{
    /** @use HasFactory<DocumentationFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'type',
        'title',
        'url',
        'uri',
        'body',
        'note',
        'status',
        'creation_date',
    ];

    protected $dateFormat = 'Y-m-d H:i:s';

    /** 已發布。0 是草稿（my-dev-grid-front 編輯頁的「草稿／已發布」切換）。 */
    public const STATUS_PUBLISHED = 1;

    /**
     * 這次請求看不看得到草稿。
     *
     * 讀取端點（index/show、/api/graph、述詞底下的邊）完全公開、沒有掛 auth middleware，
     * 所以要自己看請求有沒有帶登入態。`auth('sanctum')` 同時認 Bearer token
     * （my-dev-grid-front）與同源 session（Triple）。門檻跟寫入的 DocumentationPolicy
     * 一樣：有登入就行。
     *
     * 2026-09-30 之前沒有這道檢查：草稿的標題與內文從公開的 `/api/documentations`、
     * `/api/graph` 都拿得到，前端只是自己濾掉不顯示。
     */
    public static function viewerCanSeeDrafts(): bool
    {
        return auth('sanctum')->check();
    }

    /** 沒登入的請求只留已發布的。 */
    public function scopeVisibleToViewer(Builder $query): Builder
    {
        return self::viewerCanSeeDrafts() ? $query : $query->where('status', self::STATUS_PUBLISHED);
    }

    public function isPublished(): bool
    {
        return (int) $this->status === self::STATUS_PUBLISHED;
    }

    public function scope()
    {
        return $this->belongsTo(Scope::class, 'type');
    }

    public function techniques()
    {
        return $this->belongsToMany(Technique::class)->withPivot('relation_id');
    }

    public function implementations()
    {
        return $this->belongsToMany(Implementation::class)->withPivot('relation_id');
    }
}
