<?php

namespace App\Http\Resources;

use App\Models\Technique;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class TechniqueResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Technique $technique */
        $technique = $this->resource;

        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'version' => $this->version,
            'note' => $this->note,
            /** 是否已停用。停用的技術不會被刪除；有改用對象時，同步與匯入文章改連到改用對象。 */
            'deprecated' => $technique->isDeprecated(),
            /** 停用的時間（`Y-m-d H:i:s`）；仍在使用時為 null。 */
            'deprecated_at' => $technique->deprecated_at?->format('Y-m-d H:i:s'),
            /** 停用後改用的技術 id；沒有指定或仍在使用時為 null。 */
            'replaced_by_id' => $technique->replaced_by_id,
            'created_at' => optional($this->created_at)->format('Y-m-d H:i:s'),
            'updated_at' => optional($this->updated_at)->format('Y-m-d H:i:s'),
            'relation_id' => $this->when(isset($this->pivot), fn () => $this->pivot->relation_id),
            // 只有技術與實作之間的邊有這一欄（其他連結表沒有），而且只在登入時給：訪客用不到「誰定的」這個資訊
            /** 這條邊的關係在後台手動指定的時間；有值時 GitHub 同步不改它。只有登入、而且是技術與實作之間的邊時才會附上。 */
            'manually_set_at' => $this->when(
                isset($this->pivot) && array_key_exists('manually_set_at', $this->pivot->getAttributes()) && User::viewerIsSignedIn(),
                fn () => $this->pivot->manually_set_at === null ? null : Carbon::parse($this->pivot->manually_set_at)->format('Y-m-d H:i:s'),
            ),
            'scope' => new ScopeResource($this->whenLoaded('scope')),
            'documentations' => DocumentationResource::collection($this->whenLoaded('documentations')),
            'implementations' => ImplementationResource::collection($this->whenLoaded('implementations')),
        ];
    }
}
