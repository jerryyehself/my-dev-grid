<?php

namespace App\Traits;

trait SetCURIEAttribute
{

    public function getFullCallNumberAttribute()
    {
        return $this->class_number . $this->call_number;
    }

    public function getCURIEAttribute()
    {
        $lable = $this->name ?? $this->title;
        return strtoupper(class_basename($this)) . ": {$this->FullCallNumber} {$lable}";
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_class', 'id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_class');
    }

    public function getSiblingsAttribute()
    {
        return is_null($this->parent_class) ? collect() : self::where('parent_class', $this->parent_class)
            ->where('id', '!=', $this->id)
            ->get();
    }


    public function getParentSubjectOfAttribute()
    {
        return is_null($this->parent) ? collect() : $this->parent->subjectOf;
    }

    public function getParentObjectOfAttribute()
    {
        return is_null($this->parent) ? collect() : $this->parent->objectOf;
    }
}
