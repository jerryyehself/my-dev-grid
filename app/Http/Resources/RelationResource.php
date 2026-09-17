<?php

namespace App\Http\Resources;

use App\Models\Relation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RelationResource extends JsonResource
{
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
            'created_at' => optional($this->created_at)->format('Y-m-d H:i:s'),
            'updated_at' => optional($this->updated_at)->format('Y-m-d H:i:s'),
            'ReferenceCode' => $this->ReferenceCode,
            'parent' => new RelationResource($this->whenLoaded('parent')),
            'children' => RelationResource::collection($this->whenLoaded('children')),
            'subject' => optional($this->subject)->id,
            'object' => optional($this->object)->id,
            'new_child_call_number' => $this->NewChildCallNumber,
        ];
    }
}
