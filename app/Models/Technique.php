<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Technique extends Model
{
    /** @use HasFactory<\Database\Factories\TechniqueFactory> */
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
        return $this->belongsToMany(Documentation::class);
    }

    public function implementations()
    {
        return $this->belongsToMany(Implementation::class, 'technique_implementation');
    }
}
