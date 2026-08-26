<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ImplementationResource extends JsonResource
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
            'sub_title' => $this->sub_title,
            'description' => $this->description,
            'url' => $this->url,
            'git_repo_id' => $this->git_repo_id,
            'is_visible' => $this->is_visible,
            'maintain_status' => $this->maintain_status,
            'created_at' => optional($this->created_at)->format('Y-m-d H:i:s'),
            'updated_at' => optional($this->updated_at)->format('Y-m-d H:i:s'),
            'scope' => new ScopeResource($this->whenLoaded('scope')),
            'documentations' => DocumentationResource::collection($this->whenLoaded('documentations')),
            'techniques' => TechniqueResource::collection($this->whenLoaded('techniques')),
        ];
    }
}
