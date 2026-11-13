<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class EntityRelation extends Model
{
    use HasFactory;

    protected $fillable = [
        'entity_type',
        'subject_id',
        'object_id',
        'relation_id',
    ];

    public const ENTITY_TYPES = [
        'documentation' => Documentation::class,
        'technique' => Technique::class,
        'implementation' => Implementation::class,
    ];

    protected static function booted()
    {
        static::saving(function (EntityRelation $entityRelation) {
            $entityRelation->assertValidEntityReferences();
        });
    }

    public function relation()
    {
        // withTrashed：Relation 被軟刪除後，連結仍可能存在，
        // 歷史上的邊仍應能解析出它的謂詞名稱。
        return $this->belongsTo(Relation::class)->withTrashed();
    }

    protected function assertValidEntityReferences(): void
    {
        $entityClass = self::ENTITY_TYPES[$this->entity_type] ?? null;

        if (! $entityClass) {
            throw new InvalidArgumentException(
                "Invalid entity_type \"{$this->entity_type}\". Must be one of: ".implode(', ', array_keys(self::ENTITY_TYPES))
            );
        }

        if (! $entityClass::where('id', $this->subject_id)->exists()) {
            throw new InvalidArgumentException("subject_id {$this->subject_id} does not exist in {$entityClass}.");
        }

        if (! $entityClass::where('id', $this->object_id)->exists()) {
            throw new InvalidArgumentException("object_id {$this->object_id} does not exist in {$entityClass}.");
        }
    }
}
