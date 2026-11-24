<?php

namespace App\Models;

use App\Enums\ResLabelType;
use App\Services\NomenResolver;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * 人工宣告的「這個 res 也叫這個名字」（`res_labels` 表），也就是詞表裡的別名。
 *
 * 正式名不在這裡，是 res 自己的 title。跟 `Nomen` 分工：`Nomen` 是同步、匯入讀到字串的證據，
 * 這裡是人工維護的名稱。同一個字串可以是不同 res 的別名（同形異義），同一個 res 不能把同一個
 * 字串存兩次。為什麼這樣設計見 migration `2026_11_24_000000_create_res_labels_table.php`。
 *
 * 讀的地方：`NomenResolver` 的 `label` 規則、同步認技術（`SaveReposDataService::find_or_create_technique()`）。
 * 寫的地方：合併技術時把舊正式名寫成隱藏別名（`TechniqueMerger`）。還沒有後台編輯別名的 API，也不出現在任何 API 回應裡。
 */
class ResLabel extends Model
{
    protected $table = 'res_labels';

    protected $dateFormat = 'Y-m-d H:i:s';

    /** `normalized` 不開放填寫：一律在存檔前由 `label` 算出來，兩者不會對不上。 */
    protected $fillable = [
        'res_type',
        'res_id',
        'label',
        'label_type',
        'locale',
        'note',
    ];

    /**
     * `res_type` 能用的值 => 指向的 model。
     *
     * 跟 `nomens.res_type` 用同一組值。資料表沒有外鍵（指向的表隨 `res_type` 變動），所以存檔前在這裡檢查。
     */
    public const RES_TYPES = [
        Nomen::RES_TECHNIQUE => Technique::class,
        Nomen::RES_IMPLEMENTATION => Implementation::class,
    ];

    protected static function booted(): void
    {
        static::saving(function (ResLabel $label) {
            $label->assertValidRes();
            $label->assertValidLabelType();
            $label->normalized = $label->normalizedLabel();
        });
    }

    protected function casts(): array
    {
        return [
            'res_id' => 'integer',
            'label_type' => ResLabelType::class,
        ];
    }

    /**
     * 跟 `EntityRelation` 一樣：`res_type` 要是認得的種類，`res_id` 要指到那張表裡還在的一列（已軟刪除的不算）。
     *
     * 下面三個檢查都看寫進來的原值（`getAttributes()`），不看 cast 之後的值：integer cast 會把 '1.5' 變成 1、
     * 'abc' 變成 0，讓不合法的值看起來像合法的 id。
     */
    private function assertValidRes(): void
    {
        $resType = $this->getAttributes()['res_type'] ?? null;
        $modelClass = is_string($resType) ? (self::RES_TYPES[$resType] ?? null) : null;

        if (! $modelClass) {
            throw new InvalidArgumentException('Invalid res_type. Must be one of: '.implode(', ', array_keys(self::RES_TYPES)));
        }

        // 不是正整數的先擋掉，不拿去查：PostgreSQL 會丟型別錯誤，而且會讓整個 transaction 失效
        $resId = filter_var($this->getAttributes()['res_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($resId === false || ! $modelClass::whereKey($resId)->exists()) {
            throw new InvalidArgumentException("res_id does not point to an existing {$modelClass}.");
        }
        $this->res_id = $resId;
    }

    /** 不認得的字串在寫入屬性時就被 enum cast 擋下（`ValueError`）；這裡擋的是沒給值。 */
    private function assertValidLabelType(): void
    {
        $labelType = $this->getAttributes()['label_type'] ?? null;

        if (! is_string($labelType) || ResLabelType::tryFrom($labelType) === null) {
            throw new InvalidArgumentException('label_type is required. Must be one of: '.implode(', ', array_column(ResLabelType::cases(), 'value')));
        }
    }

    /** 比對用的鍵；`label` 正規化後什麼都不剩（空白、只有標點）時不存，這種別名永遠對不到任何字串。 */
    private function normalizedLabel(): string
    {
        $label = $this->getAttributes()['label'] ?? null;
        $normalized = is_string($label) ? NomenResolver::normalize($label) : '';

        if ($normalized === '') {
            throw new InvalidArgumentException('label must contain at least one letter or digit.');
        }

        return $normalized;
    }
}
