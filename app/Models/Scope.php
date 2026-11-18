<?php

namespace App\Models;

use App\Traits\ResolvesPositiveIntegerRouteKeys;
use App\Traits\SetCURIEAttribute;
use Closure;
use Database\Factories\ScopeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Scope extends Model
{
    /** @use HasFactory<ScopeFactory> */
    use HasFactory, ResolvesPositiveIntegerRouteKeys, SetCURIEAttribute, SoftDeletes;

    protected $fillable = [
        'class_number',
        'call_number',
        'parent_class',
        'name',
        'comment',
        'note',
    ];

    protected $appends = ['ReferenceCode', 'FullCallNumber', 'NewChildCallNumber'];

    protected $dateFormat = 'Y-m-d H:i:s';

    protected static function booted()
    {
        static::deleting(function ($scope) {
            if (! $scope->isForceDeleting()) {
                $scope->subjectOf->each->delete();
                $scope->objectOf->each->delete();
            }
        });
    }

    /**
     * 刪除這個分類時會連帶軟刪除的述詞（主體或客體是這個分類、還沒被刪除的）。
     * 跟 booted() 的 deleting 連帶刪除是同一批；主體與客體都是自己的述詞只算一次。
     *
     * @return array<int, int> 述詞 id
     */
    public function cascadedRelationIds(): array
    {
        return Relation::where(fn ($query) => $query
            ->where('subject_id', $this->id)
            ->orWhere('object_id', $this->id))
            ->pluck('id')
            ->all();
    }

    /**
     * 刪除這個分類前要檢查的引用數量：連帶被軟刪除的那批述詞，還被多少東西引用。
     * 軟刪除述詞不會處理引用它的邊與 reverse_id，所以任何一項大於 0 都不能刪。
     * 計法見 Relation::referenceCountsForBatchDeletion()；同一批述詞之間互為反向不算。
     *
     * @return array{links: int, inverse_of: int}
     */
    public function predicateReferenceCountsForDeletion(): array
    {
        return Relation::referenceCountsForBatchDeletion($this->cascadedRelationIds());
    }

    public function getAllRelationsAttribute()
    {
        return $this->subjectOf->concat($this->objectOf);
    }

    public function subjectOf()
    {
        return $this->hasMany(Relation::class, 'subject_id');
    }

    public function objectOf()
    {
        return $this->hasMany(Relation::class, 'object_id');
    }

    /**
     * 屬於這個 scope 的實體。
     *
     * 外鍵欄位名是 `type`，不是 `scope_id`——三個實體模型的 `scope()` 都是
     * `belongsTo(Scope::class, 'type')`，這裡只是把同一條關聯反過來定義，
     * 讓 `withCount()` 用得上。名字不好，但那是既有 schema，不在這次範圍內改。
     *
     * 三個都會套用各自的 SoftDeletes 全域 scope，所以計數不含已軟刪除的實體。
     */
    public function documentations()
    {
        return $this->hasMany(Documentation::class, 'type');
    }

    public function techniques()
    {
        return $this->hasMany(Technique::class, 'type');
    }

    public function implementations()
    {
        return $this->hasMany(Implementation::class, 'type');
    }

    /**
     * 詳情／一覽畫面要的全部計數，一次查完。
     *
     * **一定要用這個，不要在 Resource 裡逐筆 `->count()`。** 這條規則不是風格偏好，
     * 是這個 repo 剛付過學費的東西：PR #60 在 `RelationResource` 裡天真地加計數，
     * `/api/relations` 從 11 次查詢變成 344 次。`withCount()` 是加相關子查詢到同一句
     * SELECT 裡，不論幾筆都還是 1 次查詢，`ScopeCountsTest` 有查詢數上限的測試守著。
     *
     * 為什麼頂層與子層要載同一組計數：兩層要顯示的東西不一樣（頂層有述詞定義、
     * 沒有實體；子層有實體、沒有述詞定義），但那是**呈現**的差異，不是查詢的差異。
     * 讓後端回同一組形狀、由畫面決定顯示哪幾格，比讓後端猜呼叫端是哪一種畫面簡單。
     */
    public function scopeWithDetailCounts($query)
    {
        return $query->withCount(self::detailCounts());
    }

    /**
     * `withCount()`／`loadCount()` 要數的關聯。清單與詳情共用，兩邊的數字才不會對不上。
     *
     * `documentations` 沒登入時不數草稿（`Documentation::visibleToViewer()`），`implementations`
     * 沒登入時不數設為不公開的專案（`Implementation::visibleToViewer()`）：讀取端點是公開的，
     * 計數若把它們算進去，訪客對得出「清單上少了幾個」。回應的欄位名仍是
     * `documentations_count`、`implementations_count`（`withCount` 的別名取關聯名）。登入後數全部。
     *
     * @return array<int|string, string|Closure>
     */
    public static function detailCounts(): array
    {
        return [
            'children',
            'siblings',
            'subjectOf',
            'objectOf',
            'documentations' => fn ($query) => $query->visibleToViewer(),
            'techniques',
            'implementations' => fn ($query) => $query->visibleToViewer(),
        ];
    }
}
