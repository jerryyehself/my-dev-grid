<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TechniqueImplementationLink extends Model
{
    protected $table = 'technique_implementation';

    protected $fillable = ['implementation_id', 'technique_id', 'relation_id'];

    /**
     * `last_seen_at` 是 GitHub 同步最近一次看到這條邊的時間，只是紀錄，不拿來判斷邊有沒有效：
     * 技術與實作之間的基本關聯不會過期，圖譜與最短路徑都照常用。
     */
    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

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
