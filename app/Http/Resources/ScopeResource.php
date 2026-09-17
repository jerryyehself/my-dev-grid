<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScopeResource extends JsonResource
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
            // 父 Scope 的 id。store/update 收的就是這個欄位,不曝光的話呼叫端
            // 要改一筆 scope 時根本拿不到現值(巢狀的 parent 只有 whenLoaded 時才有)。
            'parent_class' => $this->parent_class,
            'full_call_number' => $this->FullCallNumber,
            'comment' => $this->comment,
            'note' => $this->note,
            'created_at' => optional($this->created_at)->format('Y-m-d H:i:s'),
            'updated_at' => optional($this->updated_at)->format('Y-m-d H:i:s'),
            'ReferenceCode' => $this->ReferenceCode,
            'parent' => new ScopeResource($this->whenLoaded('parent')),
            'children' => ScopeResource::collection($this->whenLoaded('children')),
            'siblings' => ScopeResource::collection($this->whenLoaded(
                'siblings',
                fn ($siblings) => $siblings->reject(fn ($sibling) => $sibling->is($this->resource))
            )),
            'subject_of' => RelationResource::collection($this->whenLoaded('subjectOf')),
            'object_of' => RelationResource::collection($this->whenLoaded('objectOf')),
            'new_child_call_number' => $this->NewChildCallNumber,
        ];
    }
}
