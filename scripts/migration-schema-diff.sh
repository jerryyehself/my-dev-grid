#!/usr/bin/env bash
#
# 看一支 migration 前後的資料表結構差異（只給本機開發用，用法見
# docs/zero-downtime-migrations.md §5.5）。
#
# 做法：把資料庫清空，只用 Laravel 內建指令跑到「目標前一支」，用 `db:table --json`
# 取前快照；再用 `migrate --pretend` 留下這支會送出的 SQL，實際只跑目標這一支，取後快照，
# 最後比對兩份快照。不裝任何套件，sqlite 與 pgsql 用同一套指令。
#
# 這個腳本會清空它連到的資料庫（`db:wipe`），所以有三道擋：
#   1. APP_ENV 不是 local 或 testing 就不跑（正式環境一律拒絕）。
#   2. sqlite 一律改用輸出目錄裡的暫存檔，不會碰到你平常開發用的 sqlite 檔。
#   3. pgsql 只接受 loopback 主機，而且要明確設 SCHEMA_DIFF_CONFIRM_WIPE=1，
#      表示你連的是用完即丟的資料庫。其他資料庫驅動不支援。
#
# Usage:
#   ./scripts/migration-schema-diff.sh <migration>
#
#   migration  檔名（有沒有 .php 都可以）、檔名開頭到第二個底線後的時間戳記，
#              或 database/migrations 底下的路徑；必須剛好對到一支。
#
# 連哪個資料庫由環境變數決定，跟 artisan 一樣（DB_CONNECTION、DB_HOST …，沒設就讀 .env）。
#
# Optional env vars:
#   PHP_BIN                    指定 PHP 執行檔（專案需要 8.4），預設 php
#   SCHEMA_DIFF_CONFIRM_WIPE   連 pgsql 時必須設成 1
#   SCHEMA_DIFF_OUT            輸出目錄，預設 storage/app/private/schema-diff
#                              （整個資料夾被 .gitignore 擋掉；不要改成會進版控的位置，
#                              結構與差異不放進公開 repo）
#
# 輸出（都在輸出目錄的 <連線>/<migration>/ 底下）：before.json、after.json、
# pretend.sql（這支 migration 實際會送的 SQL）、schema.diff。
# 欄位註解 `db:table` 不會輸出，要比的話看文件 §5.5。
#
# 需要：php 8.4、jq、diff、composer 裝好的 vendor/。

set -euo pipefail

# 檔名比較用位元組順序，跟 Laravel 排序 migration 的方式（PHP 字串比較）一致
export LC_ALL=C

PHP_BIN="${PHP_BIN:-php}"
OUT_ROOT="${SCHEMA_DIFF_OUT:-storage/app/private/schema-diff}"

die() {
    echo "migration-schema-diff: $*" >&2
    exit 1
}

cd "$(dirname "${BASH_SOURCE[0]}")/.."

[[ $# -eq 1 ]] || die "用法：$0 <migration 檔名或時間戳記>"
command -v jq >/dev/null || die "找不到 jq"
command -v "$PHP_BIN" >/dev/null || die "找不到 $PHP_BIN"
[[ -f vendor/autoload.php ]] || die "沒有 vendor/，先跑 composer install"
[[ -f artisan ]] || die "找不到 artisan，要在 repo 根目錄的腳本裡跑"

# 1. 解析目標：只認 database/migrations 底下剛好一支
arg="${1##*/}"
arg="${arg%.php}"
[[ "$arg" =~ ^[0-9A-Za-z_]+$ ]] || die "migration 名稱只能有英數字與底線：$1"
matches=()
for f in database/migrations/"$arg".php database/migrations/"$arg"_*.php; do
    [[ -f "$f" ]] && matches+=("$f")
done
[[ ${#matches[@]} -eq 1 ]] || die "要剛好對到一支 migration，實際對到 ${#matches[@]} 支：$1"
target_file="${matches[0]}"
target_name="$(basename "$target_file" .php)"

# 2. 一律先擋正式環境。讀不到環境名稱也當作不安全，不往下跑
env_name="$("$PHP_BIN" artisan env --no-ansi 2>/dev/null | sed -n 's/.*\[\([^]]*\)\].*/\1/p' | head -n 1)" || true
case "$env_name" in
    local | testing) ;;
    production) die "APP_ENV 是 production，拒絕執行（這個腳本會清空資料庫）" ;;
    "") die "讀不到 APP_ENV，拒絕執行" ;;
    *) die "APP_ENV 是 $env_name，只有 local、testing 可以執行" ;;
esac

# 3. 決定連線，限制到用完即丟的資料庫
connection="$("$PHP_BIN" artisan tinker --no-ansi --execute="echo config('database.default');" 2>/dev/null | tail -n 1)" || true
out_dir="$OUT_ROOT/$connection/$target_name"
case "$connection" in
    sqlite)
        mkdir -p "$out_dir"
        export DB_CONNECTION=sqlite
        export DB_DATABASE="$PWD/$out_dir/scratch.sqlite"
        : >"$DB_DATABASE"
        trap 'rm -f "$DB_DATABASE"' EXIT
        ;;
    pgsql)
        host="$("$PHP_BIN" artisan tinker --no-ansi --execute="echo config('database.connections.pgsql.host');" 2>/dev/null | tail -n 1)" || true
        case "$host" in
            127.0.0.1 | localhost | ::1) ;;
            *) die "pgsql 主機是「$host」，只接受 loopback（127.0.0.1、localhost、::1）" ;;
        esac
        [[ "${SCHEMA_DIFF_CONFIRM_WIPE:-}" == "1" ]] \
            || die "這會清空 pgsql 資料庫；確定它是用完即丟的，再設 SCHEMA_DIFF_CONFIRM_WIPE=1"
        mkdir -p "$out_dir"
        ;;
    *) die "不支援的連線「$connection」，只支援 sqlite、pgsql" ;;
esac

# 4. 排在目標之前的 migration，轉成 --path 參數
prior_paths=()
for f in database/migrations/*.php; do
    name="$(basename "$f" .php)"
    [[ "$name" < "$target_name" ]] && prior_paths+=("--path=$f")
done

# 取快照：每張表一份 db:table JSON，拿掉每次都可能不同的 table.size，欄位與索引的 attributes
# 攤平成陣列（原本是帶著跳號 key 的物件，diff 難讀），合成 {表名: 內容}。
# 不用 db:show --json 的連線資訊，只拿表名（那份輸出含主機、帳號、資料庫名）。
snapshot() {
    local dest="$1" tables t
    tables="$("$PHP_BIN" artisan db:show --json --no-ansi | jq -r '.tables[].table')"
    {
        for t in $tables; do
            "$PHP_BIN" artisan db:table "$t" --json --no-ansi | jq -S 'del(.table.size) | (.columns[]?.attributes, .indexes[]?.attributes) |= [.[]]'
        done
    } | jq -S -s 'map({(.table.name): .}) | add // {}' >"$dest"
}

echo "== 目標：$target_name（連線 $connection，前面有 ${#prior_paths[@]} 支）"
"$PHP_BIN" artisan db:wipe --force --no-ansi >/dev/null
if [[ ${#prior_paths[@]} -gt 0 ]]; then
    "$PHP_BIN" artisan migrate --force --no-ansi "${prior_paths[@]}" >/dev/null
fi
snapshot "$out_dir/before.json"

"$PHP_BIN" artisan migrate --pretend --no-ansi --path="$target_file" >"$out_dir/pretend.sql"
"$PHP_BIN" artisan migrate --force --no-ansi --path="$target_file" >/dev/null
snapshot "$out_dir/after.json"

# 5. 比對。diff 的結束碼 0＝相同、1＝有差異，其他是出錯
echo "== 資料表層級"
jq -r -n --slurpfile b "$out_dir/before.json" --slurpfile a "$out_dir/after.json" '
    def names: if length == 0 then "（無）" else join(", ") end;
    ($b[0] | keys) as $bk | ($a[0] | keys) as $ak |
    "新增的表：\($ak - $bk | names)",
    "移除的表：\($bk - $ak | names)",
    "有變動的表：\([$bk[] | select(. as $t | $a[0][$t] != null and $a[0][$t] != $b[0][$t])] | names)"'

echo "== 這支 migration 會送出的 SQL（$out_dir/pretend.sql）"
cat "$out_dir/pretend.sql"

echo "== 結構差異（$out_dir/schema.diff）"
rc=0
diff -u "$out_dir/before.json" "$out_dir/after.json" >"$out_dir/schema.diff" || rc=$?
[[ $rc -le 1 ]] || die "diff 執行失敗（結束碼 $rc）"
if [[ $rc -eq 0 ]]; then
    echo "前後沒有差異"
else
    cat "$out_dir/schema.diff"
fi
