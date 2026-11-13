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
        // withTrashed：Relation 被軟刪除後，連結仍可能存在，
        // 歷史上的邊仍應能解析出它的謂詞名稱。
        return $this->belongsTo(Relation::class)->withTrashed();
    }
}
