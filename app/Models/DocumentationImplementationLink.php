<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentationImplementationLink extends Model
{
    protected $table = 'documentation_implementation';

    protected $fillable = ['documentation_id', 'implementation_id', 'relation_id'];

    public function documentation()
    {
        return $this->belongsTo(Documentation::class);
    }

    public function implementation()
    {
        return $this->belongsTo(Implementation::class);
    }

    public function relation()
    {
        // withTrashed: a link can outlive the Relation it references once
        // that Relation is soft-deleted — the historical edge should still
        // resolve its predicate name.
        return $this->belongsTo(Relation::class)->withTrashed();
    }
}
