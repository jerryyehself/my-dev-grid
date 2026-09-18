<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScopeResource extends JsonResource
{
    /**
     * 要不要附上 `new_child_call_number`（新增子類時要用的下一個可用子類號）。
     *
     * **預設關閉，因為算它要打一次資料庫，而幾乎沒有人需要它。**
     * `SetCURIEAttribute::getNewChildCallNumberAttribute()` 會跑一句
     * `select max(call_number) where class_number = ?`——每序列化一個 Scope 一次。
     *
     * 在此之前它是無條件附上的，於是 `/api/scopes` 是 N+1：每一列都巢狀序列化自己的
     * `parent`，而父層的 `call_number` 正好都是 '00'（唯一會真的去查的分支），所以
     * **每多一筆 scope 就多一次查詢**（實測 4 筆 6 次、24 筆 26 次）。這個 N+1 比本次
     * 的計數功能更早存在，只是從來沒有人量過——`ScopeCountsTest` 的查詢數測試是這次
     * 才把它逼出來的。
     *
     * 實際的消費端只有一個，而且只打 show：Triple 的 `fetchCallNumberByClass()`
     * （`AppTripleEdit.vue` / `AppTripleNew.vue`）打 `/api/scopes/{id}`，拿它來預填
     * 新增表單的子類號。清單頁、巢狀的 parent/children 都沒有任何人讀這個欄位。
     * `my-dev-grid-front` 整個 repo 也沒有出現過這個欄位名。
     */
    protected bool $withFormHints = false;

    /**
     * 開啟 `new_child_call_number`。只有 show 這種「一次一筆、而且要拿來填表單」的
     * 回應才需要，清單與巢狀資源都不要開。
     */
    public function withFormHints(): static
    {
        $this->withFormHints = true;

        return $this;
    }

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
            'new_child_call_number' => $this->when(
                $this->withFormHints,
                fn () => $this->NewChildCallNumber
            ),

            // 以下計數只在查詢用了 withDetailCounts() 時才出現（whenCounted）。
            // 詳情頁靠這些顯示「子類 4 / 兄弟 3 / 述詞定義 4 / 實體 20」那一排，
            // 不需要為了一個數字把整批關聯撈回來。
            'children_count' => $this->whenCounted('children'),
            'siblings_count' => $this->whenCounted('siblings', fn () => $this->siblingsCountExcludingSelf()),
            'subject_of_count' => $this->whenCounted('subjectOf'),
            'object_of_count' => $this->whenCounted('objectOf'),

            // 屬於這個 scope 的實體。三族分開給，不要只給一個總數——
            // 一個 scope 實際上只會有其中一族（頂層三族都是 0），分開給才看得出
            // 是哪一族，也免得呼叫端自己去猜。
            'documentations_count' => $this->whenCounted('documentations'),
            'techniques_count' => $this->whenCounted('techniques'),
            'implementations_count' => $this->whenCounted('implementations'),
            'entities_count' => $this->whenCounted('documentations', fn () => (int) $this->documentations_count
                + (int) $this->techniques_count
                + (int) $this->implementations_count),
        ];
    }

    /**
     * `siblings` 這個關聯**包含自己**，所以它的 `withCount` 會多算一筆。
     *
     * 這不是疏漏，是 `SetCURIEAttribute::siblings()` 刻意的設計：eager loading 時
     * Eloquent 是對一個沒有 key 的 `newInstance()` 呼叫關聯方法來做 introspection，
     * 所以寫在關聯定義裡的 `where('id', '!=', $this->id)` 會退化成 `id IS NOT NULL`
     * ——等於沒濾掉任何東西（該檔案的註解記了這個 Eloquent 陷阱）。既有做法是
     * 由呼叫端在關聯載入後自己排除自己，上面 `siblings` 那一列就是這樣做的。
     *
     * 計數沒有「載入後再排除」這一步，所以這裡補上：有父層時扣掉自己那一筆；
     * 頂層 scope 依定義沒有兄弟（關聯本身就回空集合），不用扣。
     */
    private function siblingsCountExcludingSelf(): int
    {
        if (is_null($this->parent_class)) {
            return 0;
        }

        return max(0, (int) $this->siblings_count - 1);
    }
}
