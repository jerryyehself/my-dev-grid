<?php

namespace App\Models;

use App\Traits\SetCURIEAttribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Relation extends Model
{
    /** @use HasFactory<\Database\Factories\RelationFactory> */
    use HasFactory, SoftDeletes, SetCURIEAttribute;

    protected $fillable = [
        'subject_id',
        'object_id',
        'parent_class',
        'class_number',
        'call_number',
        'name',
        'note',
        'reverse_id'
    ];

    protected $dateFormat = 'Y-m-d H:i:s';

    protected $appends = ['CURIE'];

    public function subject()
    {
        return $this->belongsTo(Scope::class, 'subject_id');
    }

    public function object()
    {
        return $this->belongsTo(Scope::class, 'object_id');
    }
}
