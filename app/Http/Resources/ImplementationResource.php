<?php

namespace App\Http\Resources;

use App\Models\Implementation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class ImplementationResource extends JsonResource
{
    /** @return array<string, mixed> */
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
            'git_repo_created_at' => optional($this->git_repo_created_at)->format('Y-m-d H:i:s'),
            // 沒登入的人本來就只看得到公開的專案，這個欄位對他們永遠是 true，沒有資訊量；
            // 只在登入時給，編輯畫面才拿得到現值。
            /** 是否公開顯示；設為 false 的實作，未登入時看不到。只有登入時才會附上。 */
            'is_visible' => $this->when(Implementation::viewerCanSeeHidden(), fn () => $this->is_visible),
            'maintain_status' => $this->maintain_status,
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
            'techniques' => TechniqueResource::collection($this->whenLoaded('techniques')),
        ];
    }
}
