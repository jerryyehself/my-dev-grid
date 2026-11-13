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
     * 與此紀錄共用同一個直接父層（相同的 `parent_class`）的其他紀錄，包含紀錄本身。
     * 之所以不在這裡排除自己：eager loading 時 Eloquent 會在一個新的、沒有 key 的 instance 上
     * 建立這個關聯的查詢（`Builder::getRelation()` 會對 `newInstance()` 呼叫關聯方法），
     * 如果把 `where(id, '!=', $this->id)` 寫死在這裡，eager loading 時會悄悄退化成 `id is not null`，
     * 什麼都濾不掉。要排除自己的話，由呼叫端在關聯真正載入後處理（見 ScopeResource）。
     *
     * 頂層紀錄（沒有 `parent_class`）根據定義沒有兄弟，因此多加的 `whereNotNull` 會抵銷
     * `hasMany` 原本加上的 `parent_class IS NULL` 條件，結果是空集合。
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

    /**
     * 這裡的「child」是 call_number 序列裡的下一號，跟 parent_class 是不是有
     * 設值無關——call_number 純粹是同一個 class_number 分組內的流水號（'00'
     * 是先建的那筆，之後遞增），不代表也不要求 parent_class 指向 '00' 那筆。
     * 兩者是正交的兩件事：class_number/call_number 負責分組跟排序，parent_class
     * 才是唯一表達「真正的子謂詞」語意的欄位（見 Relation::LOCKED_FIELDS 的
     * 說明）。例如 ancestorOf（call_number '10'）就沒有設 parent_class，
     * 這是刻意的，不是遺漏。
     */
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
