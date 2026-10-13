<?php

namespace App\Logging;

use App\Models\Documentation;
use App\Models\Implementation;
use App\Models\Relation;
use App\Models\Scope;
use App\Models\Technique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * 後台資料變更的稽核紀錄（issue #103）：誰、何時、新增／修改／刪除了哪一筆。
 * 事件名稱是 OWASP Logging Vocabulary 的 `authz_admin`（「All activity by privileged
 * users such as admin should be recorded」），寫進日誌，不另外建資料表——理由見
 * docs/observability.md「後台稽核紀錄為什麼不存資料庫」。
 *
 * 掛法：AppServiceProvider 對 MODELS 裡的每個 model 呼叫 observe()；pivot 的
 * sync() 不會觸發 model 事件，由 SyncsPivotRelations::syncPivot() 呼叫 pivotSynced()。
 *
 * 只記「已登入使用者在 HTTP 請求裡做的變更」。seeder、`snapshot:github-repos`
 * 這類指令沒有登入使用者，不記——那些是系統自己的批次寫入，一次上百筆，會把
 * 稽核紀錄淹掉。
 *
 * 只記欄位名稱，不記欄位值：文件內文（Markdown）可能很長，整行 JSON 超過 php-fpm 的
 * log_limit 會被拆成多行（見 CloudLoggingFormatter）；要看改了什麼值，用資料庫本身。
 */
final class AdminAudit
{
    /**
     * routes/api.php 的 store/update/destroy 會寫的 model。刪除 Scope 時，Scope::booted()
     * 會一筆一筆刪掉以它為主詞／受詞的 Relation，那幾筆也會各記一筆 delete。
     *
     * @var list<class-string<Model>>
     */
    public const MODELS = [
        Scope::class,
        Relation::class,
        Documentation::class,
        Technique::class,
        Implementation::class,
    ];

    /** 每次寫入都會變、沒有稽核意義的欄位 */
    private const IGNORED_FIELDS = ['created_at', 'updated_at'];

    public function created(Model $model): void
    {
        self::record('create', $model);
    }

    public function updated(Model $model): void
    {
        $fields = array_values(array_diff(array_keys($model->getChanges()), self::IGNORED_FIELDS));
        if ($fields === []) {
            return;
        }

        self::record('update', $model, ['fields' => $fields]);
    }

    public function deleted(Model $model): void
    {
        self::record('delete', $model);
    }

    /**
     * `updated` 是 sync() 重寫過 pivot 欄位（relation_id）的既有關聯——sync() 對既有關聯
     * 一律下 UPDATE，值沒變也會列在裡面，所以它代表「這次表單送出時仍保留的關聯」。
     *
     * @param  array{attached?: array<int, mixed>, detached?: array<int, mixed>, updated?: array<int, mixed>}  $changes  sync() 的回傳值
     */
    public static function pivotSynced(BelongsToMany $relation, array $changes): void
    {
        $changes = array_map(array_values(...), array_intersect_key($changes, array_flip(['attached', 'detached', 'updated'])));
        if (array_filter($changes) === []) {
            return;
        }

        self::record('sync', $relation->getParent(), ['relation' => $relation->getRelationName()] + $changes);
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private static function record(string $action, Model $model, array $fields = []): void
    {
        $userId = SecurityLog::authenticatedUserId();
        if ($userId === null) {
            return;
        }

        $object = Str::snake(class_basename($model));
        $id = $model->getKey();

        SecurityLog::write(SecurityEvent::AuthzAdmin, "使用者 {$userId} {$action} {$object} #{$id}", [
            'user_id' => $userId,
            'action' => $action,
            'object' => $object,
            'object_id' => $id,
        ] + $fields);
    }
}
