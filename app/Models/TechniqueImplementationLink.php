<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TechniqueImplementationLink extends Model
{
    protected $table = 'technique_implementation';

    protected $fillable = ['implementation_id', 'technique_id', 'relation_id'];

    public function implementation()
    {
        return $this->belongsTo(Implementation::class);
    }

    public function technique()
    {
        return $this->belongsTo(Technique::class);
    }

    public function relation()
    {
        // withTrashed: a link can outlive the Relation it references once
        // that Relation is soft-deleted — the historical edge should still
        // resolve its predicate name.
        return $this->belongsTo(Relation::class)->withTrashed();
    }
}
