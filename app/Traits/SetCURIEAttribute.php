<?php

namespace App\Traits;

trait SetCURIEAttribute
{
    public function getFullCallNumberAttribute()
    {
        return $this->class_number.$this->call_number;
    }

    public function getReferenceCodeAttribute()
    {
        $label = $this->name ?? $this->title;

        return strtoupper(class_basename($this)).": {$this->FullCallNumber} {$label}";
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_class', 'id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_class');
    }

    /**
     * Other records sharing this record's immediate parent (same
     * `parent_class`) — includes the record itself, since Eloquent
     * builds this relation's query against a fresh, key-less instance
     * when eager loading (`Builder::getRelation()` calls the relation
     * method on `newInstance()`), so a `where(id, '!=', $this->id)`
     * baked in here would silently degrade to `id is not null` under
     * eager loading and filter nothing. Callers exclude the record
     * itself once the relation is actually loaded (see ScopeResource).
     *
     * A top-level record (no `parent_class`) has no siblings by
     * definition, so the extra `whereNotNull` cancels out the
     * `parent_class IS NULL` constraint that `hasMany` would otherwise
     * build and yields an empty set.
     */
    public function siblings()
    {
        return $this->hasMany(self::class, 'parent_class', 'parent_class')
            ->whereNotNull('parent_class');
    }

    public function getParentSubjectOfAttribute()
    {
        return is_null($this->parent) ? collect() : $this->parent->subjectOf;
    }

    public function getParentObjectOfAttribute()
    {
        return is_null($this->parent) ? collect() : $this->parent->objectOf;
    }

    public function getNewChildCallNumberAttribute()
    {
        if ($this->call_number !== '00') {
            return null;
        }

        $maxCallNumber = self::where('class_number', $this->class_number)
            ->max('call_number');

        $newNumber = intval($maxCallNumber) + 1;

        return str_pad($newNumber, 2, '0', STR_PAD_LEFT);
    }
}
