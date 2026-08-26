<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Implementation extends Model
{
    /** @use HasFactory<\Database\Factories\ImplementationFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'type',
        'title',
        'sub_title',
        'description',
        'url',
        'git_repo_id',
        'is_visible',
        'maintain_status',
    ];

    protected $casts = [
        'is_visible' => 'boolean',
        'maintain_status' => 'boolean',
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

    public function techniques()
    {
        return $this->belongsToMany(Technique::class, 'technique_implementation')->withPivot('relation_id');
    }
}
