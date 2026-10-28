<?php

namespace App\Models;

use Database\Factories\ImplementationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Implementation extends Model
{
    /** @use HasFactory<ImplementationFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'type',
        'title',
        'sub_title',
        'description',
        'url',
        'git_repo_id',
        'git_repo_created_at',
        'is_visible',
        'maintain_status',
    ];

    protected $casts = [
        'is_visible' => 'boolean',
        'maintain_status' => 'boolean',
        'git_repo_created_at' => 'datetime',
    ];

    protected $dateFormat = 'Y-m-d H:i:s';

    /**
     * 這次請求看不看得到設為不公開（`is_visible = false`）的專案。
     *
     * 讀取端點完全公開、沒有掛 auth middleware，所以要自己看請求有沒有帶登入態，
     * 門檻跟 `Documentation::viewerCanSeeDrafts()` 一樣：有登入就行。每個公開讀取
     * 都靠這一個方法判斷，不要在 controller 裡各自呼叫 `auth()`。
     */
    public static function viewerCanSeeHidden(): bool
    {
        return auth('sanctum')->check();
    }

    /**
     * 沒登入的請求只留公開的專案。
     *
     * 欄位帶表名：關聯載入（文件、技術底下的 `implementations`）時查詢會 join 連結表，
     * 帶表名才不用依賴連結表剛好沒有同名欄位。
     */
    public function scopeVisibleToViewer(Builder $query): Builder
    {
        return self::viewerCanSeeHidden() ? $query : $query->where($query->qualifyColumn('is_visible'), true);
    }

    /** 設為不公開的專案，不管這次請求有沒有登入。用來算出要從圖譜拿掉的節點。 */
    public function scopeHidden(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('is_visible'), false);
    }

    public function scope()
    {
        return $this->belongsTo(Scope::class, 'type');
    }

    public function documentations()
    {
        return $this->belongsToMany(Documentation::class)->withPivot('relation_id');
    }

    public function techniques()
    {
        return $this->belongsToMany(Technique::class, 'technique_implementation')->withPivot('relation_id');
    }
}
