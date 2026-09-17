<?php

namespace App\Http\Resources;

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
            'reverse' => new RelationResource($this->whenLoaded('reverse')),
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
