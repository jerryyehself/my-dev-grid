# 零停機 migration 指引

改資料表結構之前照這份做。`CLAUDE.md`「資料庫結構變更的順序」是規則的精簡版；這份補上為什麼、怎麼分次、PostgreSQL 與 Laravel 的細節。這份只是文件，沒有任何自動檢查（D-125），追蹤 issue [#99](https://github.com/jerryyehself/my-dev-grid/issues/99)。

**版本**：Laravel 13.30.1（`composer.lock`）。正式環境是 Cloud SQL PostgreSQL 18（`docs/infrastructure-concepts.md`；`docs/deployment-gcp.md` 第 3 步原本寫 16，已更正），CI 也是 `postgres:18`（`.github/workflows/tests.yml`）。下面的實測是在本機 PostgreSQL 16.14 上做的，引用的也是 PostgreSQL 16 文件；用到的鎖等級與 DDL 行為在 18 沒有已知差異，但沒有在 18 上重量過〔推論〕。

**標記**：每個說法都標了根據。

- 〔實測〕：在本機 PostgreSQL 16.14、Laravel 13.30.1 上跑過，或從本 repo 的 GitHub Actions 紀錄查到
- 〔引用〕：官方文件或公開的工程文章原文，附網址
- 〔推論〕：從程式碼或上面兩種資料推出來的，沒有單獨驗證
- 〔未驗證〕：查不到、也沒辦法在本機確認

---

## 1. 部署順序：哪一段時間新舊版本同時在跑

`.github/workflows/deploy-cloud-run.yml` 的順序〔實測：讀 workflow〕：

1. 建置映像檔、推上 Artifact Registry
2. **Run database migrations**：用新映像檔更新 Cloud Run Job，執行 `php artisan migrate --force`，`--wait` 等它跑完
3. **Deploy to Cloud Run**：部署新 revision，就緒後切流量
4. **Update GitHub sync job**：讓每天同步 GitHub 的 Job 也換成新映像檔

會用到這個資料庫的程式有三份，換到新版本的時間點都不一樣：

| 時段 | 資料庫結構 | 服務（Cloud Run revision） | 每天同步的 Job |
| --- | --- | --- | --- |
| 第 2 步之前 | 舊 | 舊 | 舊 |
| 第 2 步結束 → 第 3 步切流量 | **新** | **舊** | 舊 |
| 第 3 步切流量的過程 | 新 | **新舊兩版同時接請求** | 舊 |
| 第 4 步之後 | 新 | 新 | 新 |

- **舊程式跑在新結構上**：migration 那步結束到部署那步結束，2026-10-04、10-06 三次成功的部署都是 16～20 秒〔實測：GitHub Actions step 時間〕。但這段時間沒有上限：2026-10-02 run [36956715462](https://github.com/jerryyehself/my-dev-grid/actions/runs/36956715462) migration 成功、部署失敗，舊版就一直跑在新結構上，直到下一次部署成功〔實測〕。
- **切流量的時候兩版並存**：〔引用〕Cloud Run 文件：「In flight requests won't be dropped and may be directed to either a new revision or a previous revision during the transition period.」（[Rollbacks, gradual rollouts, and traffic migration](https://cloud.google.com/run/docs/rollouts-rollbacks-traffic-migration)）。舊實例關閉前，「requests already being processed are given time to complete」（[Container runtime contract](https://cloud.google.com/run/docs/container-contract)）。workflow 用 `deploy-cloudrun` action，`no_traffic` 預設 `false`，新 revision 就緒後拿到流量（[action README](https://github.com/google-github-actions/deploy-cloudrun/blob/v2/README.md)）〔引用〕。
- **同步 Job 最後才換**：上面查的三次成功部署，第 4 步都沒有真的執行（2026-10-06 03:03 那次標記 `skipped`，另外兩次耗時 0 秒）〔實測〕，看起來是 `CLOUD_RUN_SYNC_JOB` 沒設〔推論〕。如果 Job 其實存在，它會一直跑某個舊映像檔，結構也要讓它能跑〔推論〕。repo 變數這邊看不到，所以〔未驗證〕。
- **連續合併會跳過中間的部署**：workflow 的 `concurrency` 一個 group 只留一個排隊中的 run。〔引用〕GitHub 文件：「By default, any existing pending job or workflow in the same concurrency group will be canceled and the new queued job or workflow will take its place.」（[Control the concurrency of workflows and jobs](https://docs.github.com/en/actions/how-tos/write-workflows/choose-when-workflows-run/control-workflow-concurrency)）。所以分次部署的第 1 步和第 2 步如果合併得太近，第 1 步可能根本沒部署，第 2 步的 migration 就會在**第 0 版**程式還在跑的時候執行〔推論〕。

**結論**：每一支 migration 都要讓「上一版程式」和「這一版程式」在新結構上都能正常跑。一次部署只能做一步。

---

## 2. 哪些可以一次部署，哪些要分次

鎖等級和會不會重寫整張表，都是在本機 PostgreSQL 16.14、一張 20 萬列的表上量的：在 transaction 裡執行 DDL 後查 `pg_locks`，並比對 `pg_relation_filenode` 有沒有變〔實測〕。「上一版程式還能跑嗎」那一欄是從程式碼推的〔推論〕。

| 操作 | 鎖 | 重寫整張表 | 上一版程式還能跑嗎 | 做法 |
| --- | --- | --- | --- | --- |
| 建新資料表 | — | — | 能（舊程式不知道這張表） | 一次部署 |
| 加可為 null 的欄位 | ACCESS EXCLUSIVE（很短） | 否 | 能 | 一次部署，加 `lock_timeout`（§4.2） |
| 加 NOT NULL＋常數預設值的欄位 | ACCESS EXCLUSIVE（很短） | 否 | 能（舊程式 INSERT 不帶這欄，吃預設值） | 一次部署 |
| 加 NOT NULL、沒有預設值的欄位 | ACCESS EXCLUSIVE | 否 | **不能**：舊程式的 INSERT 會違反 NOT NULL〔推論〕 | 改成上一列，或照 §3.4 分次 |
| 加欄位，預設值是 volatile 函式（如 `clock_timestamp()`） | ACCESS EXCLUSIVE＋SHARE | **是** | 能 | 先加可為 null 的欄位，再回填 |
| 建索引（一般） | SHARE（擋寫入） | 否 | 能 | 改用 `->online()`（§4.3） |
| 建索引 `->online()`（CONCURRENTLY） | SHARE UPDATE EXCLUSIVE（不擋讀寫） | 否 | 能 | 一次部署 |
| 欄位改名 | ACCESS EXCLUSIVE | 否 | **不能** | §3.1 |
| 刪除欄位 | ACCESS EXCLUSIVE | 否 | **不能**（還在讀寫這欄的話） | §3.2 |
| 改型別，例如 `varchar` → `bigint` | ACCESS EXCLUSIVE＋SHARE | **是** | 看情況，通常不能 | §3.3 |
| 改型別 `varchar(255)` → `text` | ACCESS EXCLUSIVE | 否 | 能 | 一次部署 |
| 既有欄位加 NOT NULL | ACCESS EXCLUSIVE＋**全表掃描** | 否 | 看舊程式會不會寫入 null | §3.4 |
| 加外鍵 `constrained()` | SHARE ROW EXCLUSIVE，兩張表都鎖〔引用，見下〕 | 否 | 能（舊程式只要不寫入違反外鍵的資料） | 大表用 `NOT VALID`＋`VALIDATE` |

依據：

- 〔引用〕「An ACCESS EXCLUSIVE lock is acquired unless explicitly noted.」「ADD FOREIGN KEY requires only a SHARE ROW EXCLUSIVE lock. Note that ADD FOREIGN KEY also acquires a SHARE ROW EXCLUSIVE lock on the referenced table」（[ALTER TABLE](https://www.postgresql.org/docs/16/sql-altertable.html)）
- 〔引用〕「Adding a column with a volatile DEFAULT or changing the type of an existing column will require the entire table and its indexes to be rewritten. As an exception, when changing the type of an existing column, if the USING clause does not change the column contents and the old type is either binary coercible to the new type or an unconstrained domain over the new type, a table rewrite is not needed.」（同上）
- 〔引用〕ACCESS EXCLUSIVE「Conflicts with locks of all modes」，`SELECT` 拿的 ACCESS SHARE 也會被擋（[Explicit Locking](https://www.postgresql.org/docs/16/explicit-locking.html)）

**目前這幾張表都很小，鎖只會持有幾毫秒**〔推論，正式環境的筆數〔未驗證〕〕。真正會造成停機的是 §4.2 的「鎖排隊」，不是鎖持有的時間。

---

## 3. Expand／contract：分次部署

〔引用〕Martin Fowler／Danilo Sato：「Parallel change, also known as expand and contract, is a pattern to implement backward-incompatible changes to an interface in a safe manner, by breaking the change into three distinct phases: expand, migrate, and contract.」「Most database refactorings follow the parallel change pattern, where the migrate phase is the transition period between the original and the new schema, until all database access code has been updated to work with the new schema.」（[ParallelChange](https://martinfowler.com/bliki/ParallelChange.html)）

### 3.0 通則

- 每一步一個 PR。**上一步的部署 workflow 綠燈、新 revision 拿到 100% 流量之後，才合併下一步**（§1 的 `concurrency`）。
- 每一步都檢查兩件事：上一版程式能不能跑在這一步的結構上（§1）；這一步上線後如果要退回上一版程式，上一版能不能跑在這一步的結構上（§6）。
- **資料庫欄位改名，不代表 API 欄位要跟著改**。前端（`my-dev-grid-front`）是另外部署的，`ImplementationResource` 可以繼續輸出舊的 key，對應到新欄位。要改 API 是另一件事，順序跟這份一樣：先兩個都給，前端改完再拿掉。
- 回填的 migration 照 `CLAUDE.md`：PR 寫執行前後的筆數。

以下用本 repo 的表舉例，**都是假設的情境，不是待辦**。

### 3.1 欄位改名：`implementations.sub_title` → `subtitle`

先問值不值得改：這欄出現在 `Implementation::$fillable`、`ImplementationResource`、`Store/UpdateImplementationRequest`，改名要四次部署。

| 次 | migration | 程式 |
| --- | --- | --- |
| 1 | 加 `subtitle`（nullable） | 寫入時兩欄都寫（例如 model 的 `saving` 事件把 `sub_title` 複製到 `subtitle`），讀取仍讀 `sub_title` |
| 2 | 回填：`UPDATE implementations SET subtitle = sub_title WHERE subtitle IS DISTINCT FROM sub_title` | 改讀 `subtitle`，**仍然兩欄都寫**；API 照樣輸出 `'sub_title' => $this->subtitle` |
| 3 | — | 不再寫 `sub_title`，`$fillable` 等所有地方拿掉 |
| 4 | 刪 `sub_title` | — |

為什麼第 2 次還要兩欄都寫：第 2 次上線後如果退回第 1 版，第 1 版讀的是 `sub_title`，這段期間寫入的資料不能只在 `subtitle`〔推論〕。為什麼第 1 次不順便回填：第 1 次的 migration 跑完到新版上線之間，第 0 版寫入的資料只有 `sub_title`，要等第 1 版完全上線再回填才補得齊〔推論〕。

### 3.2 刪除欄位：`documentations.uri`

| 次 | migration | 程式 |
| --- | --- | --- |
| 1 | — | 拿掉所有對 `uri` 的引用：`Documentation::$fillable`、`DocumentationResource`、`Store/UpdateDocumentationRequest`。前端如果還在讀這個 API 欄位，要先改前端 |
| 2 | `dropColumn('uri')` | — |

- 為什麼要先改程式：第 2 次的 migration 跑完時，接流量的是第 1 版。第 1 版如果還把 `uri` 放在 `$fillable` 裡寫入，INSERT／UPDATE 會因為欄位不存在而失敗〔推論〕。
- `down()` 只能把欄位加回來，資料回不來。第 2 次之前先做一次 on-demand 備份（§6.3）。
- 〔引用〕PostgreSQL 的 DROP COLUMN 本身很快：「The DROP COLUMN form does not physically remove the column, but simply makes it invisible to SQL operations. ... Thus, dropping a column is quick」（[ALTER TABLE](https://www.postgresql.org/docs/16/sql-altertable.html)）

### 3.3 改型別：`implementations.git_repo_id` 從 `string` 改成 `bigint`

GitHub 的 repo id 是數字，但這欄是 `string`。

**為什麼不直接 `->change()`**：

- 會重寫整張表，期間持有 ACCESS EXCLUSIVE〔實測，§2〕。
- 舊程式不相容：`Store/UpdateImplementationRequest` 允許 `git_repo_id` 是任意字串（`nullable|string|max:255`），改成 `bigint` 後，舊版收到非數字的值就會寫入失敗〔推論〕。`ImplementationResource` 輸出的型別也會從字串變成數字，前端吃不吃得下要另外確認〔推論〕。

改成開一個新欄位，等於「改名＋改型別」：

| 次 | migration | 程式 |
| --- | --- | --- |
| 1 | 加 `github_repo_id`（`unsignedBigInteger`，nullable）；索引另外一支 migration 用 `->online()`（§4.3） | 兩欄都寫（`SaveReposDataService`、`GitService` 是主要寫入點），讀取仍讀舊欄位 |
| 2 | 先查有沒有非數字的值：`SELECT id, git_repo_id FROM implementations WHERE git_repo_id !~ '^[0-9]+$'`，有的話先決定怎麼處理；再回填 `UPDATE ... SET github_repo_id = git_repo_id::bigint WHERE github_repo_id IS NULL AND git_repo_id ~ '^[0-9]+$'` | `updateOrCreate` 的查詢條件改用 `github_repo_id`，仍兩欄都寫 |
| 3 | — | 不再寫 `git_repo_id` |
| 4 | 刪 `git_repo_id` | — |

只有在**舊程式相容新型別、而且表夠小**的時候，才考慮一次 `->change()`。Laravel 在 PostgreSQL 上可以用 `->using('git_repo_id::bigint')` 指定轉換方式〔引用〕：「When changing a column's type on PostgreSQL, you may use the `using` modifier to specify the expression used to cast the existing values」（[Laravel 13.x Migrations](https://laravel.com/docs/13.x/migrations#modifying-columns)）。

### 3.4 既有欄位加 NOT NULL：`documentations.status`

現況：`status` 可為 null，預設值 1。

**先確認 null 代表什麼**：`GraphController::hiddenNodeIds()` 把 `status IS NULL` 當成「未發布」，不給未登入的人看〔實測：讀程式碼〕。所以回填要填 `0`（草稿），**不能填預設值 `1`**，否則原本藏起來的草稿會直接公開〔推論〕。

| 次 | migration | 程式 |
| --- | --- | --- |
| 1 | — | 寫入端保證不會寫 null（FormRequest 或 model 補值）。null 會寫進去，是因為 Eloquent 會把 `null` 明確寫進 INSERT，DB 預設值只套在沒出現在 INSERT 裡的欄位（`2026_09_23_010518_make_call_number_nullable...` 的註解記過這個坑） |
| 2 | 回填 `UPDATE documentations SET status = 0 WHERE status IS NULL`，然後加 NOT NULL（下面兩種寫法擇一） | `GraphController` 的 `orWhereNull` 可以留到下一次再拿掉 |

**小表的寫法**（現在的 `documentations` 屬於這種）。在 PostgreSQL 與 SQLite 上都跑過 `up`／`down`〔實測〕：

```php
public function up(): void
{
    if (DB::getDriverName() === 'pgsql') {
        DB::statement("SET LOCAL lock_timeout = '5s'");
    }

    Schema::table('documentations', function (Blueprint $table) {
        // change() 要把想保留的 modifier 全部寫出來，沒寫的會被拿掉
        $table->integer('status')->default(1)->comment('狀態')->nullable(false)->change();
    });
}
```

還有 null 的時候，這支 migration 會失敗並回滾（`SQLSTATE[23502]: Not null violation`），不會只改一半〔實測〕。

**大表的寫法**：`SET NOT NULL` 會在 ACCESS EXCLUSIVE 下掃整張表。先加一個 `NOT VALID` 的 CHECK，再用 `VALIDATE` 掃表（只拿 SHARE UPDATE EXCLUSIVE），最後 `SET NOT NULL` 就不用再掃一次。

- 〔引用〕「if a valid CHECK constraint exists (and is not dropped in the same command) which proves no NULL can exist, then the table scan is skipped.」「validation acquires only a SHARE UPDATE EXCLUSIVE lock on the table being altered.」（[ALTER TABLE](https://www.postgresql.org/docs/16/sql-altertable.html)）
- 〔實測〕300 萬列的表：直接 `SET NOT NULL` 要 333 ms，期間持有 ACCESS EXCLUSIVE。分步做時，`ADD ... NOT VALID` 10 ms、`VALIDATE` 362 ms（不擋讀寫）、`SET NOT NULL` 4 ms。

要拆成兩支 migration（同一個 PR 即可）。鎖會一直持有到 transaction 結束，而 Laravel 每支 migration 是一個 transaction（§5.1）。如果寫在同一支，`ADD CONSTRAINT` 拿到的 ACCESS EXCLUSIVE 會一直佔到 `VALIDATE` 掃完〔推論〕。下面兩支在 PostgreSQL 與 SQLite 上都跑過 `up`／`down`〔實測〕：

```php
// ..._add_status_not_null_check_to_documentations_table.php
public function up(): void
{
    if (DB::getDriverName() !== 'pgsql') {
        return;
    }

    DB::statement("SET LOCAL lock_timeout = '5s'");
    DB::statement('ALTER TABLE documentations ADD CONSTRAINT documentations_status_not_null CHECK (status IS NOT NULL) NOT VALID');
}

// ..._validate_status_not_null_on_documentations_table.php
public function up(): void
{
    if (DB::getDriverName() !== 'pgsql') {
        // SQLite（本地／CI）資料量小，直接改
        Schema::table('documentations', function (Blueprint $table) {
            $table->integer('status')->default(1)->comment('狀態')->nullable(false)->change();
        });

        return;
    }

    DB::statement("SET LOCAL lock_timeout = '5s'");
    DB::statement('ALTER TABLE documentations VALIDATE CONSTRAINT documentations_status_not_null');
    DB::statement('ALTER TABLE documentations ALTER COLUMN status SET NOT NULL');
    DB::statement('ALTER TABLE documentations DROP CONSTRAINT documentations_status_not_null');
}
```

---

## 4. PostgreSQL 注意事項

### 4.1 鎖等級速查

〔引用〕（[Explicit Locking](https://www.postgresql.org/docs/16/explicit-locking.html)）

- ACCESS SHARE：`SELECT` 拿的。「Conflicts with the ACCESS EXCLUSIVE lock mode only.」
- ROW EXCLUSIVE：`INSERT`／`UPDATE`／`DELETE` 拿的。
- SHARE UPDATE EXCLUSIVE：`CREATE INDEX CONCURRENTLY`、`VALIDATE CONSTRAINT` 拿的。不擋讀寫，但擋其他結構變更。
- SHARE：「Acquired by CREATE INDEX (without CONCURRENTLY).」跟 ROW EXCLUSIVE 衝突，也就是會擋寫入。
- ACCESS EXCLUSIVE：大部分 `ALTER TABLE` 拿的。「This mode guarantees that the holder is the only transaction accessing the table in any way.」

### 4.2 鎖排隊與 `lock_timeout`

ACCESS EXCLUSIVE 就算只要持有幾毫秒，也得先等表上所有現有的鎖放掉。排隊等的這段時間，**後面進來的普通查詢會排在它後面**。

- 〔引用〕GoCardless：「When a lock can't be acquired because of a lock held by another transaction, it goes into a queue. Any locks that conflict with the queued lock will queue up behind it. As AccessExclusive locks conflict with every other type of lock, having one sat in the queue blocks all other operations on that table.」（原文 operations 後面有註腳編號，這裡省略）「Set lock_timeout in your migration scripts to a pause your app can tolerate. It's better to abort a deploy than take your application down.」（[Zero-downtime Postgres migrations - the hard parts](https://gocardless.com/blog/zero-downtime-postgres-migrations-the-hard-parts/)）
- 〔實測〕本機重現：A 開 transaction 讀表並停 6 秒；B 執行 `ALTER TABLE ... ADD COLUMN`；C 執行 `SELECT ... WHERE id = 1`。
  - 沒設 `lock_timeout`：C 卡了 5.07 秒，等 A 結束、B 做完才拿到結果。
  - B 先 `SET lock_timeout = '2s'`：B 在 2.04 秒報 `canceling statement due to lock timeout`，C 1.54 秒就完成（C 比 B 晚 0.5 秒開始）。
- 〔引用〕「Abort any statement that waits longer than the specified amount of time while attempting to acquire a lock on a table, index, row, or other database object. ... A value of zero (the default) disables the timeout.」（[Client Connection Defaults](https://www.postgresql.org/docs/16/runtime-config-client.html)）

**做法**：會拿 ACCESS EXCLUSIVE 的 migration，開頭加：

```php
if (DB::getDriverName() === 'pgsql') {
    DB::statement("SET LOCAL lock_timeout = '5s'");
}
```

- `SET LOCAL` 只在這支 migration 的 transaction 裡有效，不會影響後面的 migration。〔引用〕「The effects of SET LOCAL last only till the end of the current transaction, whether committed or not.」（[SET](https://www.postgresql.org/docs/16/sql-set.html)）。〔實測〕在 migration 裡查 `show lock_timeout` 得到 `3s`（測試時設 3 秒），transaction 層級 1。
- `$withinTransaction = false` 的 migration 不能用 `SET LOCAL`：「Issuing this outside of a transaction block emits a warning and otherwise has no effect.」（同上）〔引用〕
- 包 `pgsql` 判斷，是因為 CI 也跑 SQLite（§5.3）。
- 逾時之後：migration 回滾，Job 失敗，workflow 停在部署之前，正式環境還是舊結構＋舊程式。等一下再從 Actions 重跑（`workflow_dispatch`）就好〔推論〕。`gcloud run jobs execute --wait` 在執行失敗時會不會回傳非 0，讓 workflow 停下來〔未驗證〕：`gcloud` 說明只寫「Wait until the execution has completed running before exiting」。
- 每天 03:17（Asia/Taipei）的 GitHub 同步會寫 `implementations`、`techniques` 跟 pivot 表（`docs/deployment-gcp.md` 第 10 步）。改這幾張表的 migration 避開這個時段合併〔推論〕。

### 4.3 建索引：`->online()`（CREATE INDEX CONCURRENTLY）

- 〔引用〕「PostgreSQL will build the index without taking any locks that prevent concurrent inserts, updates, or deletes on the table; whereas a standard index build locks out writes (but not reads) on the table until it's done.」「a regular CREATE INDEX command can be performed within a transaction block, but CREATE INDEX CONCURRENTLY cannot.」（[CREATE INDEX](https://www.postgresql.org/docs/16/sql-createindex.html)）
- 〔引用〕Laravel 13 有 `online()`：「When using PostgreSQL, this adds the `CONCURRENTLY` option to the index creation statement.」（[Laravel 13.x Migrations — Online Index Creation](https://laravel.com/docs/13.x/migrations#online-index-creation)）。Laravel 文件沒提到要關掉 transaction。
- 〔實測〕只加 `->online()`、沒關 transaction：`SQLSTATE[25001] ... CREATE INDEX CONCURRENTLY cannot run inside a transaction block`。加上 `$withinTransaction = false` 才成功，`pg_index.indisvalid = t`。SQLite 上 `online()` 會被忽略，同一支 migration 照樣跑得過。

```php
return new class extends Migration
{
    // CREATE INDEX CONCURRENTLY 不能在 transaction 裡跑
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('implementations', function (Blueprint $table) {
            $table->index('git_repo_id')->online();
        });
    }

    public function down(): void
    {
        Schema::table('implementations', function (Blueprint $table) {
            $table->dropIndex(['git_repo_id']);
        });
    }
};
```

- **一支 migration 只放一個 `online()` 索引，不要放其他東西**。沒有 transaction 包著，中途失敗就會停在一半（§5.1）。
- 失敗會留下 INVALID 索引。〔引用〕「the CREATE INDEX command will fail but leave behind an “invalid” index. This index will be ignored for querying purposes because it might be incomplete; however it will still consume update overhead.」「The recommended recovery method in such cases is to drop the index and try again to perform CREATE INDEX CONCURRENTLY.」（[CREATE INDEX](https://www.postgresql.org/docs/16/sql-createindex.html)）。重跑之前先把它刪掉。**不要用 `IF NOT EXISTS` 跳過**：「there is no guarantee that the existing index is anything like the one that would have been created.」（同上）〔引用〕

### 4.4 加有預設值的欄位

〔引用〕「When a column is added with ADD COLUMN and a non-volatile DEFAULT is specified, the default is evaluated at the time of the statement and the result stored in the table's metadata. ... In neither case is a rewrite of the table required.」（[ALTER TABLE](https://www.postgresql.org/docs/16/sql-altertable.html)）

`->default(true)`、`->default('00')` 這類常數不會重寫整張表〔實測，§2〕。預設值是 `clock_timestamp()`、`gen_random_uuid()` 這種每列算出不同值的 volatile 函式，就會重寫〔實測：`clock_timestamp()`〕。這種情況改成先加可為 null 的欄位，再回填。

---

## 5. Laravel 注意事項

### 5.1 migration 的 transaction 行為

〔實測：讀 Laravel v13.30.1 原始碼〕

- `Migration::$withinTransaction` 預設 `true`，註解是「Enables, if supported, wrapping the migration within a transaction.」（[Migration.php](https://github.com/laravel/framework/blob/v13.30.1/src/Illuminate/Database/Migrations/Migration.php)）
- `Migrator::runMigration()` 用 `supportsSchemaTransactions() && $migration->withinTransaction ? $connection->transaction($callback) : $callback()`，`PostgresGrammar::$transactions = true`（[Migrator.php](https://github.com/laravel/framework/blob/v13.30.1/src/Illuminate/Database/Migrations/Migrator.php)、[PostgresGrammar.php](https://github.com/laravel/framework/blob/v13.30.1/src/Illuminate/Database/Schema/Grammars/PostgresGrammar.php)）。也就是在 Postgres 上，**每一支 migration 各自一個 transaction**，不是整批一個。
- `runUp()` 等 `runMigration()` 結束、transaction commit 之後，才把這支寫進 `migrations` 表。

所以：

- 一次部署有三支 migration，第二支失敗時，第一支已經 commit，第二支整個回滾，第三支沒執行。下次 `migrate` 會從第二支重來〔推論，來自上面的程式碼〕。
- DDL 在 Postgres 上可以回滾。〔引用〕「This design supports backing out even large changes to DDL, such as table creation.」（[PostgreSQL wiki: Transactional DDL](https://wiki.postgresql.org/wiki/Transactional_DDL_in_PostgreSQL:_A_Competitive_Analysis)）。所以 §3.4 那支 migration 失敗時不會只改一半〔實測〕。
- `$withinTransaction = false` 的 migration 失敗時，已經執行的語句不會回滾，`migrations` 表也不會有這支的紀錄，下次會整支重跑〔推論〕。這種 migration 要寫成重跑也安全，或者只放一個語句。

### 5.2 `change()` 會拿掉沒寫出來的屬性

〔引用〕「When modifying a column, you must explicitly include all the modifiers you want to keep on the column definition - any missing attribute will be dropped.」（[Laravel 13.x Migrations — Modifying Columns](https://laravel.com/docs/13.x/migrations#modifying-columns)）

〔實測：讀 `PostgresGrammar::compileChange()`〕在 Postgres 上，`change()` 會產生**一條** `ALTER TABLE`，裡面一定有 `alter column ... type ...`，再加上 null、預設值等設定。所以它一定拿 ACCESS EXCLUSIVE。型別真的變了會重寫整張表，加 NOT NULL 會掃整張表（§2）。§3.4 的範例跑完後查過，預設值 `1` 和註解 `狀態` 都還在〔實測〕。

### 5.3 CI 同時跑 SQLite 和 PostgreSQL

`tests.yml` 兩種資料庫都會從頭跑一遍所有 migration〔實測：讀 workflow〕。

- 原生 SQL（`SET LOCAL`、`NOT VALID`、`VALIDATE`）用 `DB::getDriverName() === 'pgsql'` 包起來，SQLite 走 Schema builder 或直接跳過（§3.4 範例）。
- `->online()` 在 SQLite 上會被忽略〔實測〕。
- CI 的 Postgres 是 18，正式環境是 16（本文開頭）。CI 綠燈不保證 16 的行為一樣；本文的實測都在 16.14 上做〔實測〕。

### 5.4 `down()` 的實際用途

〔引用〕「The `up` method is used to add new tables, columns, or indexes to your database, while the `down` method should reverse the operations performed by the `up` method.」（[Laravel 13.x Migrations](https://laravel.com/docs/13.x/migrations)）

在這個專案，`down()` 實際上只會在這些地方用到：本地 `migrate:rollback`、`migrate:refresh`，還有寫 migration 時自己驗證能不能來回跑。正式環境的部署流程只會跑 `migrate --force`，不會跑 rollback〔實測：讀 workflow〕。

正式環境出問題時，**用新的 migration 往前修，不跑 `down()`**〔推論〕：

- 刪欄位、刪資料的 `down()` 只能把結構加回來，資料回不來（§3.2）。
- `down()` 本身也是一次結構變更，正在跑的那一版程式一樣要能跑在 `down()` 之後的結構上。照 §3 做的話，上一版的結構通常比現在的少東西，現在這版程式反而跑不動。
- 要在正式環境跑 `down()`，得另外手動執行 Job，不在 workflow 裡，沒有審查紀錄。

`down()` 還是要寫，而且要能跑，因為本地開發和 CI 會用到。真的沒辦法還原的（例如刪資料的回填），就在 `down()` 裡寫註解說明，不要假裝能還原。

---

## 6. 回滾

### 6.1 程式退版（Cloud Run revision）

```bash
gcloud run revisions list --service=my-dev-grid-api --region=asia-east1
gcloud run services update-traffic my-dev-grid-api --region=asia-east1 --to-revisions=<上一個 revision>=100
```

- 照 §3 分次部署的話，每一步的結構都跟上一版程式相容，所以程式退版是安全的〔推論〕。這也是分次部署的主要目的：**程式可以退版，結構不用跟著退**。
- **退版之後，後續部署都不會拿到流量，要手動切回來**。〔引用〕「If you split traffic between multiple revisions or assigned traffic to a previous revision, all subsequent deployments use that traffic split pattern going forward. To return to just using the latest revision without traffic splitting, send all traffic to the latest revision.」（[Rollbacks, gradual rollouts, and traffic migration](https://cloud.google.com/run/docs/rollouts-rollbacks-traffic-migration)）。workflow 的 `deploy-cloudrun` 沒設 `revision_traffic`，所以不會自動改回去〔推論〕。修好之後要跑：

  ```bash
  gcloud run services update-traffic my-dev-grid-api --region=asia-east1 --to-latest
  ```

  還沒切回 latest 之前合併的 PR，migration 照樣會執行，但接流量的還是那個被固定住的舊 revision。這時候 §1 的「舊程式跑在新結構上」會一直持續〔推論〕。

### 6.2 結構退版：往前修

照 §5.4 的理由，寫一支新的 migration 修正，走一般 PR＋部署流程。

### 6.3 會刪資料的 migration：先備份

- `docs/deployment-gcp.md` 建 Cloud SQL 時帶了 `--no-backup`，現在正式環境有沒有開自動備份〔未驗證〕。
- 刪欄位、刪資料、大量改寫的 migration 合併之前，先手動做一次備份：

  ```bash
  gcloud sql backups create --instance=my-dev-grid-db --description="before <PR 編號>"
  ```

  〔引用〕「On-demand backups are backups that can be created at any time. These are useful if you are about to perform a risky operation on your database」「You can create on-demand backups for any instance, whether the instance has automatic backups enabled or not.」（[Cloud SQL for PostgreSQL: About backups](https://cloud.google.com/sql/docs/postgres/backup-recovery/backups)）

---

## 7. 合併前自我檢查

改到 `database/migrations/` 的 PR，合併前逐項確認：

- [ ] **上一版程式**跑在這次的新結構上還正常嗎？（§1：沒刪、沒改名它還在用的欄位；沒加它不會填的 NOT NULL 欄位）
- [ ] 這次的程式跑在**上一版的結構**上也正常嗎？（退版、切流量的時候會發生）
- [ ] 刪除、改名、改型別、加 NOT NULL 都已經照 §3 拆開，這個 PR 只做其中一步？
- [ ] 上一步的部署已經綠燈、流量 100% 在新 revision 上？（§1 `concurrency`）
- [ ] 會拿 ACCESS EXCLUSIVE 的語句加了 `SET LOCAL lock_timeout`？（§4.2）
- [ ] 新索引用了 `->online()`，而且那支 migration 設了 `$withinTransaction = false`、只放這一個索引？（§4.3）
- [ ] `change()` 把要保留的 `default`、`comment`、`nullable` 都寫出來了？（§5.2）
- [ ] 原生 SQL 有用 `pgsql` 判斷包起來，CI 的 SQLite 和 PostgreSQL 都綠？（§5.3）
- [ ] 回填的值保留了原本的意思？（§3.4：null 代表草稿，不能填 1）PR 寫了執行前後的筆數？
- [ ] 會刪資料的話，先做了 on-demand 備份？（§6.3）
- [ ] PR 說明寫了回滾指令（`CLAUDE.md` 高風險改動），包括切回 `--to-latest`？（§6.1）
- [ ] 改到 `implementations`、`techniques` 或 pivot 表的話，避開 03:17 的同步？（§4.2）

---

## 8. 來源

引用都在 2026-10-07 抓取並核對過原文。

- PostgreSQL 16：[ALTER TABLE](https://www.postgresql.org/docs/16/sql-altertable.html)、[Explicit Locking](https://www.postgresql.org/docs/16/explicit-locking.html)、[CREATE INDEX](https://www.postgresql.org/docs/16/sql-createindex.html)、[Client Connection Defaults（lock_timeout）](https://www.postgresql.org/docs/16/runtime-config-client.html)、[SET](https://www.postgresql.org/docs/16/sql-set.html)
- [PostgreSQL wiki: Transactional DDL in PostgreSQL](https://wiki.postgresql.org/wiki/Transactional_DDL_in_PostgreSQL:_A_Competitive_Analysis)
- [Laravel 13.x: Database Migrations](https://laravel.com/docs/13.x/migrations)
- Laravel v13.30.1 原始碼：[Migration.php](https://github.com/laravel/framework/blob/v13.30.1/src/Illuminate/Database/Migrations/Migration.php)、[Migrator.php](https://github.com/laravel/framework/blob/v13.30.1/src/Illuminate/Database/Migrations/Migrator.php)、[PostgresGrammar.php](https://github.com/laravel/framework/blob/v13.30.1/src/Illuminate/Database/Schema/Grammars/PostgresGrammar.php)
- Cloud Run：[Rollbacks, gradual rollouts, and traffic migration](https://cloud.google.com/run/docs/rollouts-rollbacks-traffic-migration)、[Container runtime contract](https://cloud.google.com/run/docs/container-contract)
- [Cloud SQL for PostgreSQL: About backups](https://cloud.google.com/sql/docs/postgres/backup-recovery/backups)
- [google-github-actions/deploy-cloudrun v2 README](https://github.com/google-github-actions/deploy-cloudrun/blob/v2/README.md)
- [GitHub Actions: Control the concurrency of workflows and jobs](https://docs.github.com/en/actions/how-tos/write-workflows/choose-when-workflows-run/control-workflow-concurrency)
- Martin Fowler（Danilo Sato）：[ParallelChange](https://martinfowler.com/bliki/ParallelChange.html)
- GoCardless：[Zero-downtime Postgres migrations - the hard parts](https://gocardless.com/blog/zero-downtime-postgres-migrations-the-hard-parts/)
