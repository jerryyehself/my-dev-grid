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
