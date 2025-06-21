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
}
