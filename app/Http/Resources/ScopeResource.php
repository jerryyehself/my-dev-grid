<?php

namespace App\Http\Resources;

use App\Models\Scope;
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
            'comment' => $this->comment,
            'note' => $this->note,
            'created_at' => optional($this->created_at)->format('Y-m-d H:i:s'),
            'updated_at' => optional($this->updated_at)->format('Y-m-d H:i:s'),
            'CURIE' =>  strtoupper(class_basename(Scope::class)) . ": {$this->FullCallNumber} {$this->name}",
            'parent' => new ScopeResource($this->whenLoaded('parent')),
            'children' => ScopeResource::collection($this->whenLoaded('children')),
            'siblings' => $this->siblings,
            'subject_of' => RelationResource::collection($this->whenLoaded('subjectOf')),
            'object_of' => RelationResource::collection($this->whenLoaded('objectOf')),
        ];
    }
}
