<?php

namespace App\Models;

use App\Exceptions\RelationLockedException;
use App\Traits\SetCURIEAttribute;
use Database\Factories\RelationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Relation extends Model
{
    /** @use HasFactory<RelationFactory> */
    use HasFactory, SetCURIEAttribute, SoftDeletes;

    protected $fillable = [
        'subject_id',
        'object_id',
        'parent_class',
        'class_number',
        'call_number',
        'name',
        'note',
        'source_vocabulary',
        'source_term',
        'reverse_id',
    ];

    protected $dateFormat = 'Y-m-d H:i:s';

    protected $appends = ['ReferenceCode', 'NewChildCallNumber'];

    /**
     * Once a Relation is referenced by an existing pivot link, its identity
     * (subject_id/object_id/name/class_number/call_number/parent_class) is
     * locked read-only — only note may still change. Semantics only evolve
     * forward (new child relations) or disappear (soft delete), never
     * mutate in place.
     *
     * `parent_class` 的語意鎖死為「且僅為 rdfs:subPropertyOf」（真正的子謂詞：
     * 這個關係的每一筆事實都必然蘊含父關係成立）。明確排除兩種常見誤用：
     * - 反向關係（A 的 reverse 是 B）——用 reverse_id 表達，不是 parent_class。
     * - 同組代表／群組聚合——用 class_number（+ call_number 當純流水號）表達，
     *   不是 parent_class。
     * 只有在「同一個既有謂詞真的出現 2 個以上子變體，且每個子變體的每一筆
     * 事實都確實蘊含父謂詞，且確實有查詢需要一次撈出父謂詞下所有子變體」
     * 三個條件同時成立時，才用 parent_class 建立真正的子謂詞；否則優先維持
     * 平輩，在 note 裡用散文交叉引用（比照 SPDX RelationshipType 的做法）。
     */
    /** isReferenced() 要查的四張連結表,同時也是 scopeWithReferenceCounts() 預載的對象。 */
    public const LINK_RELATIONS = [
        'documentationImplementationLinks',
        'documentationTechniqueLinks',
        'techniqueImplementationLinks',
        'entityRelations',
    ];

    public const LOCKED_FIELDS = ['subject_id', 'object_id', 'name', 'class_number', 'call_number', 'parent_class'];

    /**
     * Relation 互斥性檢查——尚未實作，研究依據記在這裡（2026-09-14），不是只留在
     * 對話紀錄裡。待決項目：使用者要不要做「幫每個 relation 標互斥分組、儲存時
     * 驗證」（例如 descendantOf/accompanies/precedes 三選一，理論上不該同時成立）。
     *
     * 查過的理論依據：
     * - OWL 2 有正式機制 owl:propertyDisjointWith／owl:AllDisjointProperties，
     *   宣告屬性互斥（同一對實體不能同時具備兩個互斥屬性）。
     *   https://www.w3.org/TR/owl-ref/
     *   https://www.w3.org/2007/OWL/wiki/FullSemanticsDisjointProperties
     *   （查不到這個機制在實際 production ontology 裡的普及率統計，不編造數字）
     * - 索引典標準（ISO 25964、SKOS）只定義等同／層級／關聯三大類關係，
     *   沒有找到「互斥關係類型」被正式定義過。
     *   https://en.wikipedia.org/wiki/Thesaurus_(information_retrieval)
     * - 結論：本體論有正式概念可參照命名/語意，但沒有「大家都這樣做」的
     *   普及先例可以直接抄；索引典完全沒有對應概念。
     *
     * 查過的「客觀判斷互斥性」方法論（不是純人工主觀貼標籤）：
     * - Enriching Ontologies with Disjointness Axioms using LLMs
     *   https://arxiv.org/html/2410.03235v1
     *   邏輯推理＋LLM 混合，三層：(1) 既有類別層級關係推導（子類的子類也互斥）
     *   (2) 查資料庫是否存在「同時具備兩者」的真實個體 (3) 前兩者都無法確定
     *   才調用 LLM 語意判斷。DBpedia 實驗：51 萬個互斥軸，約 98% 類別參與。
     * - Learning Disjointness Axioms With Association Rule Mining
     *   https://link.springer.com/chapter/10.1007/978-3-662-45495-4_3
     *
     * 對照這個專案現況，兩個方法目前都證據力不足，不是方法本身有問題：
     * - 「邏輯推導」用不上：目前沒有任何一筆真實 relation 設定非 null 的
     *   parent_class（唯一有賦值的地方是 RelationSeeder.php 裡一段被註解掉、
     *   從未執行的隨機測試資料生成函式），沒有階層資料可以推。
     * - 「資料共現檢查」證據力弱：這個專案只有 14 條 relation 定義，資料規模
     *   遠小於論文用的 DBpedia，「沒觀察到共存」在這種樣本數下大機率只是
     *   資料太少還沒遇到，不是真的互斥的可靠證據。
     * - LLM 語意判斷因成本考量，使用者選擇先不掛。
     *
     * 尚未定案是否要做；若要做，先從資料共現檢查起步（唯一目前有材料可用的
     * 方法），明確標註其證據力弱、僅供參考，不當作確定結論。
     */
    protected static function booted()
    {
        static::updating(function (Relation $relation) {
            if (! $relation->isReferenced()) {
                return;
            }

            $lockedChanges = array_intersect(array_keys($relation->getDirty()), self::LOCKED_FIELDS);

            if (! empty($lockedChanges)) {
                throw new RelationLockedException($lockedChanges);
            }
        });

        // reverse_id 一定要雙向。見下方 syncReverse() 的註解。
        static::saved(function (Relation $relation) {
            $relation->syncReverse();
        });
    }

    /**
     * 把 reverse_id 的配對補成雙向。
     *
     * 這張表的不變量是：**reverse_id 要嘛是 null，要嘛指向一條回指自己的關係**，
     * 不存在「A 指向 B、但 B 指向別人（或誰都不指）」這種單向狀態。圖譜的每一條邊
     * 都必須帶述詞（三張 pivot 表與 entity_relations 都有 relation_id），述詞的反向
     * 壞掉，圖就會變成單向的。
     *
     * 在此之前，這個不變量**沒有任何東西在維護**——15 條之所以全部成對，純粹是因為
     * RelationSeeder 剛好寫對了。StoreRelationRequest 的 reverse_id 只有
     * `nullable|exists:relations,id`，可以建出單向指過去的關係；booted() 原本只在
     * updating 時擋 LOCKED_FIELDS，跟配對無關。開放 CRUD 介面讓人手動建 relation 之後，
     * 這個洞就會被踩到，所以在介面上線之前補（2026-09-16）。
     *
     * 對稱關係（reverse_id 指向自己）是合法的、而且實際存在：`accompanies`
     * （id 13，Impl→Impl，class 22 call 20）的 reverse_id 就是它自己——
     * A accompanies B 等價於 B accompanies A，不需要第二條。這種情況直接跳過，
     * 因為它本來就已經滿足不變量。
     *
     * 用 saveQuietly() 寫回去，避免再觸發一次 saved 造成無限遞迴；reverse_id 不在
     * LOCKED_FIELDS 裡，所以即使對方已被引用（isReferenced）也允許補上配對。
     *
     * **解除配對也必須是雙向的**（2026-09-17 補）。原本這個方法遇到 reverse_id 為 null
     * 就直接 return，結果是把 A 的反向清空之後，B 還指著 A——正是這個方法存在要消滅的
     * 單向狀態。編輯介面上「有沒有反向關係」是一個 checkbox，勾掉就是把 reverse_id 設回
     * null，所以這條路徑是日常操作，不是邊角案例。
     */
    public function syncReverse(): void
    {
        // 先解除舊伴侶：任何「指著我、但我已經不指它」的關係都要斷開，
        // 否則清空或改指別人之後會留下單向的殘骸。
        static::where('reverse_id', $this->id)
            ->where('id', '!=', $this->id)
            ->when($this->reverse_id, fn ($q) => $q->where('id', '!=', $this->reverse_id))
            ->get()
            ->each(function (self $orphan) {
                $orphan->reverse_id = null;
                $orphan->saveQuietly();
            });

        if (is_null($this->reverse_id) || $this->reverse_id === $this->id) {
            return;
        }

        $reverse = static::find($this->reverse_id);

        if ($reverse && $reverse->reverse_id !== $this->id) {
            $reverse->reverse_id = $this->id;
            $reverse->saveQuietly();
        }
    }

    /**
     * 反向關係本身。
     *
     * 在此之前只有裸的 reverse_id 欄位，於是 syncReverse()、ReverseIsAvailable
     * 各自手動 find() 一次，而 RelationResource 也只吐得出 id——前端要顯示
     * 「這條的反向是 specifiedBy」就得自己再查一次。清單頁 ->with('reverse')
     * 也能一併避開 N+1。
     *
     * belongsTo 會套用 Relation 自己的 SoftDeletes，所以指向一筆已軟刪除的關係時
     * 這裡解析成 null，而不是回傳一筆前端看不到的資料。
     */
    public function reverse()
    {
        return $this->belongsTo(self::class, 'reverse_id');
    }

    public function subject()
    {
        return $this->belongsTo(Scope::class, 'subject_id');
    }

    public function object()
    {
        return $this->belongsTo(Scope::class, 'object_id');
    }

    public function documentationImplementationLinks()
    {
        return $this->hasMany(DocumentationImplementationLink::class);
    }

    public function documentationTechniqueLinks()
    {
        return $this->hasMany(DocumentationTechniqueLink::class);
    }

    public function techniqueImplementationLinks()
    {
        return $this->hasMany(TechniqueImplementationLink::class);
    }

    public function entityRelations()
    {
        return $this->hasMany(EntityRelation::class);
    }

    /**
     * 這條關係是否已經被真實的邊引用——**包含反向那條的引用**。
     *
     * 邊只存單向，反向是靠 reverse_id 推出來的：`GraphController` 在路徑反著走的時候，
     * 回傳的 `predicate` 是**反向關係的名字**（見該檔 291-296 行）。實際驗證過，
     * `/api/graph/path?start=technique-1&end=documentation-1` 回的是
     * `{"predicate":"specifiedBy","relation_id":3,"storedDirection":"reverse"}`。
     *
     * 所以在 2026-09-17 之前這個方法是**單向的、因此是錯的**：只看自己那四張連結表，
     * 於是每一對關係都剛好一半鎖定、一半可改——`specs` 有 5 筆邊所以鎖定，而它的反向
     * `specifiedBy` 是 0 筆所以完全可改，儘管那 5 筆邊反著讀顯示的正是 `specifiedBy`。
     * 最誇張的是 `uses`(84 筆) 與 `used`(0 筆)：`used` 的名字正在被 84 筆邊使用，卻
     * 可以隨意改名、改主詞受詞、改分類號，追溯改變那些邊在使用者眼中的意義——正是
     * LOCKED_FIELDS 存在要防的事，只是從另一個方向進來。
     *
     * 修正後鎖定數從 6 條變成 11 條（共 15）；剩下可改的 4 條是 documents、
     * documentedBy、assists、assisted-by，那兩對確實兩邊都沒有任何邊。
     *
     * 只往下追一層就夠：反向的反向就是自己（不變量由 syncReverse() 與
     * ReverseIsSwapped 維護），再往下會繞回原點。
     */
    public function isReferenced(): bool
    {
        // RelationResource 一列會問三次(is_referenced / referenced_via /
        // locked_fields),沒有記憶化就是三倍的查詢。
        if (! is_null($this->isReferencedMemo)) {
            return $this->isReferencedMemo;
        }

        return $this->isReferencedMemo = $this->resolveIsReferenced();
    }

    protected ?bool $isReferencedMemo = null;

    protected function resolveIsReferenced(): bool
    {
        if ($this->hasOwnReferences()) {
            return true;
        }

        // 對稱關係（自己就是自己的反向）上面那一步已經涵蓋，不用再查一次。
        if (is_null($this->reverse_id) || $this->reverse_id === $this->id) {
            return false;
        }

        // relationLoaded() 時直接用預載的那筆,沒預載才退回查一次。
        $reverse = $this->relationLoaded('reverse') ? $this->reverse : static::find($this->reverse_id);

        return (bool) $reverse?->hasOwnReferences();
    }

    /**
     * 只看這一筆自己的四張連結表，不看反向。
     *
     * 公開而非 protected，是因為前端需要分辨「被自己的邊鎖住」與「被反向的邊鎖住」
     * ——鎖定欄位的畫面要能說明理由（規格的 G5），而「這條的反向 `uses` 有 84 筆邊」
     * 跟「這條自己有 84 筆邊」對使用者是兩件不同的事。
     */
    public function hasOwnReferences(): bool
    {
        foreach (self::LINK_RELATIONS as $relation) {
            $countKey = Str::snake($relation).'_count';

            // 已經用 withReferenceCounts() 預載過就直接讀,不要再打一次資料庫。
            // 沒有這一步的話 /api/relations 從 11 次查詢暴增到 344 次(實測)。
            if (array_key_exists($countKey, $this->attributes)) {
                if ($this->attributes[$countKey] > 0) {
                    return true;
                }

                continue;
            }

            if ($this->{$relation}()->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * 這條述詞自己有幾筆邊——四張連結表的總和，**不含反向那條的**。
     *
     * 跟 isReferenced() 的差別要講清楚，因為兩者故意不一樣：isReferenced() 把反向的
     * 引用也算進來（鎖不鎖定看的是「這個名字有沒有正在被邊使用」，而邊只存單向），
     * 這裡要的是**這條述詞自己的邊有幾筆**，也就是詳情頁那份清單的長度。
     * `uses` 是 84、它的反向 `used` 是 0——兩者都 is_referenced=true，但清單長度不同。
     *
     * 跟 hasOwnReferences() 一樣，預載過（withReferenceCounts）就直接讀，沒預載才
     * 退回查一次；清單頁一定要預載，否則每一列各查四次。
     */
    public function ownEdgesCount(): int
    {
        return collect(self::LINK_RELATIONS)->sum(function (string $relation) {
            $countKey = Str::snake($relation).'_count';

            return array_key_exists($countKey, $this->attributes)
                ? (int) $this->attributes[$countKey]
                : $this->{$relation}()->count();
        });
    }

    /**
     * 反向那條自己有幾筆邊。沒有反向（或反向是自己）就是 0——對稱關係的邊
     * 已經算在 ownEdgesCount() 裡，再加一次會變兩倍。
     */
    public function reverseEdgesCount(): int
    {
        if (is_null($this->reverse_id) || $this->reverse_id === $this->id) {
            return 0;
        }

        $reverse = $this->relationLoaded('reverse') ? $this->reverse : static::find($this->reverse_id);

        return $reverse ? $reverse->ownEdgesCount() : 0;
    }

    /**
     * 把 isReferenced() 需要的計數一次載齊——自己的四張連結表,加上反向那條的四張。
     *
     * 清單頁一定要用這個,否則每一列都會各自去查 8 次。實測 /api/relations:
     * 不預載 344 次查詢,預載後 20 次。
     */
    public function scopeWithReferenceCounts($query)
    {
        return $query
            ->withCount(self::LINK_RELATIONS)
            ->with(['reverse' => fn ($q) => $q->withCount(self::LINK_RELATIONS)]);
    }
}
