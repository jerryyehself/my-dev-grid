<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Relation extends Model
{
    /** @use HasFactory<\Database\Factories\RelationFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'subject_id',
        'object_id',
        'class_number',
        'call_number',
        'is_relation_lead',
        'title',
        'note'
    ];

    public function subject()
    {
        return $this->belongsTo(Scope::class, 'subject_id');
    }

    public function object()
    {
        return $this->belongsTo(Scope::class, 'object_id');
    }
}
