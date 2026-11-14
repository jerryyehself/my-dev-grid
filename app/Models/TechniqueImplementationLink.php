<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class TechniqueImplementationLink extends Model
{
    protected $table = 'technique_implementation';

    /** 比同步最後一次看到的時間還早超過這麼多天沒被看到的邊，算「過期」（程式碼已經拿掉、或是當初誤判）。 */
    public const STALE_AFTER_DAYS = 14;

    protected $fillable = ['implementation_id', 'technique_id', 'relation_id'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    /**
     * 過期的分界時間：所有同步邊最新的 last_seen_at 往前推 STALE_AFTER_DAYS 天。
     * 以最新一次同步為基準而不是 now()，同步停擺時邊不會因為時間流逝全部變成過期。
     * 還沒有任何同步過的邊就回 null（沒有基準，沒有邊過期）。
     */
    public static function staleCutoff(): ?Carbon
    {
        $latest = static::query()->max('last_seen_at');

        return $latest === null ? null : Carbon::parse($latest)->subDays(self::STALE_AFTER_DAYS);
    }

    /**
     * 這條邊過期了嗎。last_seen_at 是 null（開始記錄前就有、或手動建立）永遠不算過期。
     */
    public function isStale(?Carbon $cutoff): bool
    {
        return $cutoff !== null && $this->last_seen_at !== null && $this->last_seen_at->lt($cutoff);
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
