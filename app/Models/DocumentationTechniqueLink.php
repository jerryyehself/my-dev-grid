<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentationTechniqueLink extends Model
{
    protected $table = 'documentation_technique';

    protected $fillable = ['documentation_id', 'technique_id', 'relation_id'];

    public function documentation()
    {
        return $this->belongsTo(Documentation::class);
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
