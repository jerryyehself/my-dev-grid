<?php

namespace App\Models;

use App\Exceptions\RelationLockedException;
use App\Traits\SetCURIEAttribute;
use Database\Factories\RelationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

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

    public function isReferenced(): bool
    {
        return $this->documentationImplementationLinks()->exists()
            || $this->documentationTechniqueLinks()->exists()
            || $this->techniqueImplementationLinks()->exists()
            || $this->entityRelations()->exists();
    }
}
