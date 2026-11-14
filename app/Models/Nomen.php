<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 同步或匯入時讀到的一個名稱字串，在某個作品裡出現的一筆證據（`nomens` 表）。
 *
 * 一列不是一個 LRM nomen：LRM 2024 版的 nomen 身分是 res＋字串，用
 * `DISTINCT (res_type, res_id, string)` 推得出來，不另存。為什麼這樣設計見 migration
 * `2026_11_13_000000_create_nomens_table.php`。
 *
 * 目前是影子模式：只記錄，圖譜的邊照舊由同步與匯入程式決定，這張表不影響任何 API 輸出。
 * 只有 `NomenRecorder` 寫入、`NomenResolver` 更新對上的節點，沒有後台或 API 可以改。
 */
class Nomen extends Model
{
    protected $table = 'nomens';

    protected $dateFormat = 'Y-m-d H:i:s';

    protected $fillable = [
        'string',
        'normalized',
        'source',
        'scheme',
        'context_type',
        'context_id',
        'predicate_id',
        'weight',
        'res_type',
        'res_id',
        'resolution',
        'locale',
        'first_seen_at',
        'last_seen_at',
    ];

    public const SOURCE_GITHUB_LANGUAGE = 'github_language';

    public const SOURCE_GITHUB_TOPIC = 'github_topic';

    /** repo 套件清單裡、列在 `config/package_technologies.php` 的套件名稱；`scheme` 是生態系（npm、composer、pip）。 */
    public const SOURCE_PACKAGE = 'package_manifest';

    public const SOURCE_ABOUT_TECHNIQUES = 'front_matter.about_techniques';

    public const SOURCE_ABOUT_PROJECTS = 'front_matter.about_projects';

    public const CONTEXT_IMPLEMENTATION = 'implementation';

    public const CONTEXT_DOCUMENTATION = 'documentation';

    /**
     * 沒有作品位置的來源（例如之後的登記檔、後台）用的固定值。
     *
     * 唯一索引在 SQLite 與 PostgreSQL 都把 NULL 當成彼此不同，`context_*` 留空的話重跑就會多一列，
     * 所以兩欄都不可為空，改用這組固定值。
     */
    public const NO_CONTEXT_TYPE = 'none';

    public const NO_CONTEXT_ID = 0;

    public const RES_TECHNIQUE = 'technique';

    public const RES_IMPLEMENTATION = 'implementation';

    /** 對不上任何節點。 */
    public const UNRESOLVED = 'unresolved';

    /** 對上不只一個節點，同一個作品裡的其他名稱也分不出來。 */
    public const AMBIGUOUS = 'ambiguous';

    /** 對上了，但證據不夠（GitHub 語言的位元組數太少），不足以撐起一條邊。 */
    public const BELOW_THRESHOLD = 'below_threshold';

    /** topic 對上的節點，同一個 repo 的語言或套件清單也撐得起同一個節點。 */
    public const CORROBORATED = 'corroborated';

    protected function casts(): array
    {
        return [
            'context_id' => 'integer',
            'predicate_id' => 'integer',
            'weight' => 'integer',
            'res_id' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /** 這一列對上的節點能不能撐起它主張的邊。 */
    public function isUsable(): bool
    {
        return $this->res_id !== null
            && ! in_array($this->resolution, [self::UNRESOLVED, self::AMBIGUOUS, self::BELOW_THRESHOLD], true);
    }
}
