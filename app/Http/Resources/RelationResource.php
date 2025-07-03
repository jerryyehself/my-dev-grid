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
            'note' => $this->note,
            'reverse_id' => $this->reverse_id,
            'created_at' => optional($this->created_at)->format('Y-m-d H:i:s'),
            'updated_at' => optional($this->updated_at)->format('Y-m-d H:i:s'),
            'CURIE' => strtoupper(class_basename(Relation::class)) . ": {$this->FullCallNumber} {$this->name}",
            'parent' => new RelationResource($this->whenLoaded('parent')),
            'children' => RelationResource::collection($this->whenLoaded('children')),
            'subject' => optional($this->subject)->id,
            'object' => optional($this->object)->id,
        ];
    }
}
