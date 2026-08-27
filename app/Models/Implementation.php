<?php

namespace App\Models;

use Database\Factories\ImplementationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Implementation extends Model
{
    /** @use HasFactory<ImplementationFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'type',
        'title',
        'sub_title',
        'description',
        'url',
        'git_repo_id',
        'git_repo_created_at',
        'is_visible',
        'maintain_status',
    ];

    protected $casts = [
        'is_visible' => 'boolean',
        'maintain_status' => 'boolean',
        'git_repo_created_at' => 'datetime',
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
