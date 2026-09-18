<?php

namespace App\Http\Resources;

use App\Models\Relation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RelationResource extends JsonResource
{
    /**
     * 要不要附上 `new_child_call_number`。跟 `ScopeResource` 同一個問題、同一個修法。
     *
     * `getNewChildCallNumberAttribute()` 會跑一句 `select max(call_number)`，每序列化
     * 一筆 Relation 一次。無條件附上時 `/api/relations` 就是 N+1：實測 3 筆 6 次、
     * 23 筆 26 次——**每多一筆就多一次查詢**。這個 N+1 比本次的邊計數更早存在，
     * 只是 `RelationReferenceLockTest` 守的是查詢數上限（12），沒有守「筆數成長時
     * 查詢數不准跟著長」，所以一直沒被看見。
     *
     * 消費端只有一個而且只打 show：Triple 的 `fetchCallNumberByClass()`
     * （`AppTripleEdit.vue` / `AppTripleNew.vue`）打 `/api/relations/{id}` 預填新增
     * 表單的子類號。清單頁與巢狀資源沒有人讀。
     */
    protected bool $withFormHints = false;

    public function withFormHints(): static
    {
        $this->withFormHints = true;

        return $this;
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'class_number' => $this->class_number,
            'call_number' => $this->call_number,
            'full_call_number' => $this->FullCallNumber,
            'note' => $this->note,
            'reverse_id' => $this->reverse_id,
            // 反向關係本身。前端的「有沒有反向關係」checkbox 要顯示的是名字,
            // 只給 id 的話它得再查一次。已軟刪除的反向會解析成 null,不會吐出
            // 一筆前端看不到的資料。
            // 刻意**不**巢狀整個 RelationResource:那會讓反向那筆也去算自己的
            // is_referenced,而它的反向沒被預載,於是每一列多打好幾次資料庫
            // (實測 /api/relations 因此從 20 次變成 176 次)。前端的
            // 「有沒有反向關係」checkbox 只需要名字,給最小形狀就夠。
            'reverse' => $this->whenLoaded('reverse', fn () => $this->reverse ? [
                'id' => $this->reverse->id,
                'name' => $this->reverse->name,
            ] : null),
            // 這一筆能不能改。前端要在送出之前就把鎖定欄位變成唯讀,不能等後端報錯
            // ——尤其 RelationLockedException 目前是未處理的 500(見 issue 與規格 B2)。
            'is_referenced' => $this->isReferenced(),
            // 被自己的邊鎖住,還是被反向那條的邊鎖住。畫面上要能說明理由:
            // 「這條的反向 uses 有 84 筆邊」跟「這條自己有 84 筆邊」是兩件不同的事。
            'referenced_via' => $this->hasOwnReferences()
                ? 'self'
                : ($this->isReferenced() ? 'reverse' : null),
            // 鎖住時哪些欄位不能改。由後端給,前端不要自己寫死一份會漂移的清單。
            'locked_fields' => $this->isReferenced() ? Relation::LOCKED_FIELDS : [],
            // 詳情頁那兩格數字(規格 B5)。is_referenced 只是布林,回答不了「84」這個數。
            // 自己的邊與反向的邊分開給:uses 是 84、它的反向 used 是 0,兩者都
            // is_referenced=true,但清單長度不同,畫面上要講的也是兩件不同的事。
            // 邊的清單本身在 GET /api/relations/{id}/edges(分頁),不塞進這裡。
            'own_edges_count' => $this->ownEdgesCount(),
            'reverse_edges_count' => $this->reverseEdgesCount(),
            'created_at' => optional($this->created_at)->format('Y-m-d H:i:s'),
            'updated_at' => optional($this->updated_at)->format('Y-m-d H:i:s'),
            'ReferenceCode' => $this->ReferenceCode,
            'parent' => new RelationResource($this->whenLoaded('parent')),
            'children' => RelationResource::collection($this->whenLoaded('children')),
            'subject' => optional($this->subject)->id,
            'object' => optional($this->object)->id,
            'new_child_call_number' => $this->when(
                $this->withFormHints,
                fn () => $this->NewChildCallNumber
            ),
        ];
    }
}
