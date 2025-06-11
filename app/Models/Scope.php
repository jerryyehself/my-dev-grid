<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Scope extends Model
{
    /** @use HasFactory<\Database\Factories\ScopeFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'class_number',
        'call_number',
        'is_scope_lead',
        'name',
        'comment',
        'note'
    ];

    protected $appends = ['CURIE', 'FullCallNumber'];

    protected static function booted()
    {
        static::deleting(function ($scope) {
            if (! $scope->isForceDeleting()) {
                $scope->subjectOf->each->delete();
                $scope->objectOf->each->delete();
            }
        });
    }

    public function getFullCallNumberAttribute()
    {
        return $this->class_number . $this->call_number;
    }

    public function getCURIEAttribute()
    {
        return "{$this->name}: {$this->class_number}{$this->call_number}";
    }

    public function getAllRelationsAttribute()
    {
        return $this->subjectOf->concat($this->objectOf);
    }

    public function subjectOf()
    {
        return $this->hasMany(Relation::class, 'subject_id');
    }

    public function objectOf()
    {
        return $this->hasMany(Relation::class, 'object_id');
    }
}
