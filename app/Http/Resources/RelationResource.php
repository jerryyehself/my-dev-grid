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
            'created_at' => optional($this->created_at)->format('Y-m-d H:i:s'),
            'updated_at' => optional($this->updated_at)->format('Y-m-d H:i:s'),
            'parent' => new RelationResource($this->whenLoaded('parent')),
            'CURIE' => strtoupper(class_basename(Relation::class)) . ": {$this->FullCallNumber} {$this->name}",
            'subject' => new RelationResource($this->whenLoaded('subject')),
            'object' => new RelationResource($this->whenLoaded('object')),
        ];
    }
}
