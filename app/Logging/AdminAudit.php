<?php

namespace App\Logging;

use App\Enums\SecurityEvent;
use App\Models\Documentation;
use App\Models\Implementation;
use App\Models\Relation;
use App\Models\Scope;
use App\Models\Technique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
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
 * 在 transaction 裡的變更，等 commit 之後才寫（`DB::afterCommit()`）：回滾的變更沒有真的發生，
 * 不留紀錄。不在 transaction 裡就馬上寫。要記的內容（使用者、欄位名稱）在變更當下就算好，不等 commit。
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
     * 從文章來源 repo 匯入（`import`：新增或更新內文）或連結（`link`：只寫來源欄位）一篇文章。
     * 新增、更新本身另有 `create`／`update` 的紀錄；這一筆補上來源是哪一版。不記內文與檔案路徑。
     *
     * @param  string  $action  import 或 link
     */
    public static function articleSourceImported(string $action, Documentation $documentation, string $sourceKey, string $blobSha, string $commitSha): void
    {
        self::record($action, $documentation, [
            'source_key' => $sourceKey,
            'blob_sha' => $blobSha,
            'commit_sha' => $commitSha,
        ]);
    }

    /**
     * 把一筆技術合併進另一筆（`TechniqueMerger`）。停用本身另有 `update` 的紀錄；這一筆記下連結怎麼搬。
     *
     * 刪掉的連結記整列的值（id、兩端、述詞、時間），改寫過的記原本的值（留下那列的 created_at、manually_set_at，
     * 改接的邊原本的兩端），要還原時照這筆寫回去：合併沒有備份表，
     * 這是唯一的紀錄。連結列只有 id 與時間，沒有內文，不會撐爆一行日誌（見類別說明）。
     *
     * @param  array{moved: array<string, list<int>>, removed: array<string, list<array<string, mixed>>>, updated: array<string, list<array<string, mixed>>>, label_id: int|null}  $summary
     */
    public static function techniqueMerged(Technique $from, Technique $into, array $summary): void
    {
        self::record('merge', $from, [
            'merged_into' => $into->id,
            'moved_links' => $summary['moved'],
            'removed_links' => $summary['removed'],
            'updated_links' => $summary['updated'],
            'res_label_id' => $summary['label_id'],
        ]);
    }

    /**
     * 新增（`attach`）或刪除（`detach`）技術之間的上下位或相關（`SemanticTechniqueEdges`）。
     * `entity_relations` 不在 MODELS 裡、沒有 model 事件的紀錄，這一筆是唯一的紀錄。
     *
     * 跟合併一樣記整列的值（id、兩端、述詞、時間）：刪掉的要還原時照這筆寫回去，新增的要撤回時查得到是哪一列。
     *
     * @param  string  $action  attach 或 detach
     * @param  string  $predicate  broader 或 related
     * @param  list<array<string, mixed>>  $rows  `entity_relations` 的整列
     */
    public static function semanticRelationChanged(string $action, Technique $technique, string $predicate, Technique $other, array $rows): void
    {
        self::record($action, $technique, [
            'predicate' => $predicate,
            'other_id' => $other->id,
            'entity_relations' => $rows,
        ]);
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
        $context = [
            'user_id' => $userId,
            'action' => $action,
            'object' => $object,
            'object_id' => $id,
        ] + $fields;

        DB::afterCommit(fn () => SecurityLog::write(SecurityEvent::AuthzAdmin, "使用者 {$userId} {$action} {$object} #{$id}", $context));
    }
}
