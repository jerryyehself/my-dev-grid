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
