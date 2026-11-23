<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 文章的一個段落單位（`article_units` 表），由 `ArticleUnitSync` 依文章內文寫入。
 *
 * 身分是 `(article_id, unit_id)`；`unit_id` 一旦有值就不改，原稿裡不見了也只填 `removed_at`，不刪列。
 * 目前沒有 API 讀這張表，也沒有後台可以改。
 *
 * 取用（檢索）時：`is_container` 的單位跳過；其餘的單位內容後面接上 `footnotes` 的各個 `content`；
 * 有 `chunks` 時改用各片，每片後面接上它 `footnotes` 列的標籤對應的腳註。各欄位的意思見 `ArticleUnitSplitter`。
 */
class ArticleUnit extends Model
{
    public const ROLE_INTRO = 'intro';

    public const ROLE_REFERENCES = 'references';

    public const ROLE_BODY = 'body';

    /** `unit_id` 是 varchar(255)；ID 只會是 ASCII，字元數等於位元組數。 */
    public const MAX_UNIT_ID_LENGTH = 255;

    protected $dateFormat = 'Y-m-d H:i:s';

    protected $fillable = [
        'article_id',
        'unit_id',
        'parent_id',
        'role',
        'level',
        'heading',
        'content',
        'content_hash',
        'is_container',
        'footnotes',
        'chunks',
        'position',
        'removed_at',
    ];

    protected function casts(): array
    {
        return [
            'article_id' => 'integer',
            'parent_id' => 'integer',
            'level' => 'integer',
            'position' => 'integer',
            'is_container' => 'boolean',
            'footnotes' => 'array',
            'chunks' => 'array',
            'removed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Documentation, $this> */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Documentation::class, 'article_id');
    }

    /** @return BelongsTo<ArticleUnit, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function isRemoved(): bool
    {
        return $this->removed_at !== null;
    }
}
