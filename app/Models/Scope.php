<?php

namespace App\Models;

use App\Traits\SetCURIEAttribute;
use Database\Factories\ScopeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Scope extends Model
{
    /** @use HasFactory<ScopeFactory> */
    use HasFactory, SetCURIEAttribute, SoftDeletes;

    protected $fillable = [
        'class_number',
        'call_number',
        'parent_class',
        'name',
        'comment',
        'note',
    ];

    protected $appends = ['ReferenceCode', 'FullCallNumber', 'NewChildCallNumber'];

    protected $dateFormat = 'Y-m-d H:i:s';

    protected static function booted()
    {
        static::deleting(function ($scope) {
            if (! $scope->isForceDeleting()) {
                $scope->subjectOf->each->delete();
                $scope->objectOf->each->delete();
            }
        });
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
