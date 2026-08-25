<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Documentation extends Model
{
    /** @use HasFactory<\Database\Factories\DocumentationFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'type',
        'title',
        'url',
        'uri',
        'note',
        'status',
        'creation_date',
    ];
    protected $dateFormat = 'Y-m-d H:i:s';

    public function scope()
    {
        return $this->belongsTo(Scope::class, 'type');
    }

    public function techniques()
    {
        return $this->belongsToMany(Technique::class)->withPivot('relation_id');
    }

    public function implementations()
    {
        return $this->belongsToMany(Implementation::class)->withPivot('relation_id');
    }
}
