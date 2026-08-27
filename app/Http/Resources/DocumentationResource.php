<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentationResource extends JsonResource
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
            'type' => $this->type,
            'title' => $this->title,
            'url' => $this->url,
            'uri' => $this->uri,
            'note' => $this->note,
            'status' => $this->status,
            'creation_date' => $this->creation_date,
            'created_at' => optional($this->created_at)->format('Y-m-d H:i:s'),
            'updated_at' => optional($this->updated_at)->format('Y-m-d H:i:s'),
            'relation_id' => $this->when(isset($this->pivot), fn () => $this->pivot->relation_id),
            'scope' => new ScopeResource($this->whenLoaded('scope')),
            'techniques' => TechniqueResource::collection($this->whenLoaded('techniques')),
            'implementations' => ImplementationResource::collection($this->whenLoaded('implementations')),
        ];
    }
}
