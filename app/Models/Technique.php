<?php

namespace App\Models;

use Database\Factories\TechniqueFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Technique extends Model
{
    /** @use HasFactory<TechniqueFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'type',
        'title',
        'version',
        'note',
    ];

    protected $dateFormat = 'Y-m-d H:i:s';

    public function scope()
    {
        return $this->belongsTo(Scope::class, 'type');
    }

    public function documentations()
    {
        return $this->belongsToMany(Documentation::class)->withPivot('relation_id');
    }

    public function implementations()
    {
        return $this->belongsToMany(Implementation::class, 'technique_implementation')->withPivot('relation_id');
    }
}
