#!/usr/bin/env bash
#
# 本機容器 smoke test：用 repo 自己的 Dockerfile 建出跟正式環境同一份映像檔，
# 接上用完即丟的 PostgreSQL，用 APP_ENV=production 跑起來，再打一輪 HTTP
# 探測，最後把容器日誌也檢查一遍。目的是在 push 之前先抓到「CI 的 PHPUnit
# 不會踩到、但上了 Cloud Run 才會壞」的問題：Dockerfile／nginx／php-fpm 設定、
# 環境變數、日誌格式、正式環境的錯誤頁面（APP_DEBUG=false）等。
#
# 這個腳本不會改動你目前的工作目錄：被測的 ref 會用 `git worktree add`
# 放到暫存目錄再建置，結束（含失敗、Ctrl-C）時一律清掉容器、暫存資料庫、
# worktree 與暫存映像檔標籤。
#
# 什麼時候要跑（見 CLAUDE.md「高風險改動與資料庫結構變更」→ 驗證範圍）：
# 動到部署管線（Dockerfile、docker/、workflow、環境變數）、日誌／middleware／
# bootstrap/app.php／config/、或 composer.lock 相依套件的 PR，合併前跑一次，
# 把最後印出的 Markdown 摘要表貼到 PR。
#
# Usage:
#   ./scripts/smoke-test.sh [git-ref]
#
#   git-ref   要測的 commit／分支／tag，預設是目前的 HEAD
#             （例如 origin/main、origin/feat/some-branch）
#
# 需要：docker、git、curl、python3；沒有 docker 但有 dockerd 會自己啟動。
#
# Optional env vars:
#   SMOKE_DB_MODE          auto（預設）| docker | local
#                          auto 先試 `docker run postgres:18-alpine`，拉不到
#                          映像檔才退回本機 PostgreSQL cluster（pg_createcluster）
#   SMOKE_SUMMARY_FILE     另外把摘要表寫進這個檔案
#   SMOKE_KEEP=1           結束時不清理（容器、worktree、暫存目錄都留著，除錯用）
#   SMOKE_EXPECTED_REMOTE  sandbox 模式結束後，`git remote -v` 必須含有這段字串，
#                          預設 jerryyehself/my-dev-grid；設成空字串就不檢查（fork 用）
#
# Cloud sandbox 模式：只在 /root/.ccr/ca-bundle.crt 存在時啟用（Claude Code 的
# 雲端容器）。那裡的 HTTPS 出口 proxy 會重簽憑證，容器內的 TLS 驗證與
# GitHub zipball 下載都會壞，所以這個模式會：
#   - 用暫存的 Dockerfile 副本（不動 repo 的 Dockerfile），每個 FROM 後面放進
#     合併過的 CA bundle 並設好 SSL_CERT_FILE／CURL_CA_BUNDLE／NODE_EXTRA_CA_CERTS
#   - 把 `RUN composer install ...` 換成 no-op，改在 host 上裝 vendor 再 COPY 進去
# 跟正式環境的差別只有「CA」與「vendor 在哪裡安裝」，其餘層完全相同。

set -euo pipefail

REF="${1:-HEAD}"
SMOKE_DB_MODE="${SMOKE_DB_MODE:-auto}"
SMOKE_KEEP="${SMOKE_KEEP:-}"
SMOKE_SUMMARY_FILE="${SMOKE_SUMMARY_FILE:-}"
SMOKE_EXPECTED_REMOTE="${SMOKE_EXPECTED_REMOTE-jerryyehself/my-dev-grid}"
CCR_CA="/root/.ccr/ca-bundle.crt"
PG_IMAGE="postgres:18-alpine"

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && git rev-parse --show-toplevel)"

# --- 執行期狀態（cleanup 會用到，所以先宣告空值）---
TMP=""
WT=""
IMAGE=""
APP_CONTAINER=""
PG_CONTAINER=""
PG_CLUSTER=""
PG_VERSION=""
DB_KIND=""
APP_PORT=""
PG_PORT=""
SANDBOX=0
SUMMARY_PRINTED=1   # 前置檢查通過之前不印摘要表（見下方「前置檢查」）
FAILS=0
WARNS=0
REMOTE_BEFORE=""
RESOLVED_SHA=""
LOG_CHANNEL_USED=""
R_STATUS=()
R_NAME=()
R_DETAIL=()

# ---------------------------------------------------------------------------
# 小工具
# ---------------------------------------------------------------------------

# record <pass|fail|skip|warn> <名稱> [備註]：印一行並記進摘要表
record() {
  local status="$1" name="$2" detail="${3:-}" icon
  case "$status" in
    pass) icon="✅" ;;
    fail) icon="❌"; FAILS=$((FAILS + 1)) ;;
    skip) icon="⏭ " ;;
    warn) icon="⚠️ "; WARNS=$((WARNS + 1)) ;;
  esac
  R_STATUS+=("$status")
  R_NAME+=("$name")
  R_DETAIL+=("$detail")
  if [ -n "$detail" ]; then
    echo "$icon $name — $detail"
  else
    echo "$icon $name"
  fi
}

# 致命錯誤：記一筆失敗然後結束（EXIT trap 會清理並印摘要）
die() {
  record fail "$1" "${2:-}"
  exit 1
}

# 找一個空的 TCP port
free_port() {
  python3 -c 'import socket; s = socket.socket(); s.bind(("127.0.0.1", 0)); print(s.getsockname()[1]); s.close()'
}

# 產生 n 個位元組的隨機十六進位字串
rand_hex() {
  python3 -c "import os; print(os.urandom($1).hex())"
}

# http_req <名稱> <curl 參數...>
# 結果：狀態碼在 $HTTP_CODE，標頭在 $TMP/h_<名稱>，內容在 $TMP/b_<名稱>
http_req() {
  local n="$1"
  shift
  HTTP_CODE="$(curl -sS --noproxy '*' --max-time 30 -D "$TMP/h_$n" -o "$TMP/b_$n" -w '%{http_code}' "$@" 2>"$TMP/e_$n")" || HTTP_CODE="000"
}

# header_val <名稱> <標頭名>：取回應標頭的值（不分大小寫、去掉 CR）
header_val() {
  { grep -i "^$2:" "$TMP/h_$1" 2>/dev/null || true; } | tail -1 | sed -E 's/^[^:]*:[[:space:]]*//; s/\r$//'
}

# ---------------------------------------------------------------------------
# 資料庫（docker 或本機 cluster 兩種，下面四個函式對外一致）
# ---------------------------------------------------------------------------

PG_USER="smoke"
PG_PASS="smoke-pass"
PG_DB="smoke"

pg_ready() {
  if [ "$DB_KIND" = docker ]; then
    docker exec "$PG_CONTAINER" pg_isready -U "$PG_USER" -d "$PG_DB" >/dev/null 2>&1
  else
    pg_isready -h 127.0.0.1 -p "$PG_PORT" >/dev/null 2>&1
  fi
}

wait_pg() {
  local i
  for i in $(seq 1 40); do
    if pg_ready; then return 0; fi
    sleep 1
  done
  return 1
}

db_stop() {
  if [ "$DB_KIND" = docker ]; then
    docker stop -t 2 "$PG_CONTAINER" >/dev/null
  else
    pg_ctlcluster "$PG_VERSION" "$PG_CLUSTER" stop
  fi
}

db_start() {
  if [ "$DB_KIND" = docker ]; then
    docker start "$PG_CONTAINER" >/dev/null
  else
    pg_ctlcluster "$PG_VERSION" "$PG_CLUSTER" start
  fi
}

# 以 postgres 系統使用者執行指令（本機 cluster 模式用）
as_postgres() {
  if [ "$(id -u)" = 0 ]; then
    su postgres -c "$*"
  else
    sudo -u postgres bash -c "$*"
  fi
}

start_db_docker() {
  docker run -d --name "$PG_CONTAINER" \
    -e POSTGRES_USER="$PG_USER" -e POSTGRES_PASSWORD="$PG_PASS" -e POSTGRES_DB="$PG_DB" \
    -p "127.0.0.1:$PG_PORT:5432" "$PG_IMAGE" >/dev/null
  DB_KIND=docker
}

start_db_local() {
  command -v pg_createcluster >/dev/null 2>&1 || return 1
  PG_VERSION="$(ls /usr/lib/postgresql 2>/dev/null | sort -V | tail -1)"
  [ -n "$PG_VERSION" ] || return 1
  PG_CLUSTER="smoke$$"
  pg_createcluster "$PG_VERSION" "$PG_CLUSTER" --port "$PG_PORT" >/dev/null
  DB_KIND=local
  pg_ctlcluster "$PG_VERSION" "$PG_CLUSTER" start
  wait_pg || return 1
  as_postgres "psql -p $PG_PORT -qc \"CREATE ROLE $PG_USER LOGIN PASSWORD '$PG_PASS'\"" >/dev/null
  as_postgres "createdb -p $PG_PORT -O $PG_USER $PG_DB"
}

# ---------------------------------------------------------------------------
# 清理 + 摘要（EXIT trap：不論成功、失敗、Ctrl-C 都會跑）
# ---------------------------------------------------------------------------

print_summary() {
  [ "$SUMMARY_PRINTED" = 1 ] && return 0
  SUMMARY_PRINTED=1
  local out i icon detail
  out="$(
    echo "## 容器 smoke test 結果"
    echo
    echo "- ref：\`$REF\`（\`${RESOLVED_SHA:-?}\`）"
    echo "- 映像檔：\`${IMAGE:-未建置}\`，$([ "$SANDBOX" = 1 ] && echo "cloud sandbox 模式" || echo "repo 的 Dockerfile 原樣建置")"
    echo "- 日誌 channel：\`${LOG_CHANNEL_USED:-?}\`，APP_ENV=production、APP_DEBUG=false"
    echo
    echo "| 檢查 | 結果 | 備註 |"
    echo "| --- | --- | --- |"
    for i in "${!R_NAME[@]}"; do
      case "${R_STATUS[$i]}" in
        pass) icon="✅" ;;
        fail) icon="❌" ;;
        skip) icon="⏭" ;;
        warn) icon="⚠️" ;;
      esac
      detail="${R_DETAIL[$i]//|/\\|}"
      echo "| ${R_NAME[$i]//|/\\|} | $icon | $detail |"
    done
    echo
    if [ "$FAILS" -gt 0 ]; then
      echo "**結果：${FAILS} 項失敗**$([ "$WARNS" -gt 0 ] && echo "，${WARNS} 項警告")"
    else
      echo "**結果：全部通過**$([ "$WARNS" -gt 0 ] && echo "（${WARNS} 項警告）")"
    fi
    if [ "$SANDBOX" = 1 ]; then
      echo
      echo "> sandbox 模式與正式環境的差異只有兩項：映像檔內多放了出口 proxy 的 CA，以及 vendor/ 是在 host 上安裝後再 COPY 進映像檔。"
    fi
  )"
  echo
  echo "$out"
  if [ -n "$SMOKE_SUMMARY_FILE" ]; then
    echo "$out" >"$SMOKE_SUMMARY_FILE"
  fi
}

cleanup() {
  local rc=$?
  set +e
  trap - EXIT INT TERM

  # 失敗時先把容器日誌的尾巴印出來，清掉容器就看不到了
  if [ "$FAILS" -gt 0 ] || [ "$rc" -ne 0 ]; then
    if [ -n "$APP_CONTAINER" ] && docker inspect "$APP_CONTAINER" >/dev/null 2>&1; then
      echo
      echo "--- 應用程式容器日誌（最後 20 行，每行最多 300 字元）---"
      docker logs --tail 20 "$APP_CONTAINER" 2>&1 | cut -c1-300
      echo "--- 以上 ---"
    fi
  fi

  if [ -n "$SMOKE_KEEP" ]; then
    echo "SMOKE_KEEP 已設定，不清理：TMP=$TMP worktree=$WT container=$APP_CONTAINER db=${PG_CONTAINER:-$PG_CLUSTER}"
  else
    [ -n "$APP_CONTAINER" ] && docker rm -f "$APP_CONTAINER" >/dev/null 2>&1
    [ -n "$PG_CONTAINER" ] && docker rm -f -v "$PG_CONTAINER" >/dev/null 2>&1
    if [ -n "$PG_CLUSTER" ]; then
      pg_dropcluster --stop "$PG_VERSION" "$PG_CLUSTER" >/dev/null 2>&1
    fi
    [ -n "$IMAGE" ] && docker rmi "$IMAGE" >/dev/null 2>&1
    if [ -n "$WT" ] && [ -e "$WT" ]; then
      git -C "$REPO_ROOT" worktree remove --force "$WT" >/dev/null 2>&1
    fi
    git -C "$REPO_ROOT" worktree prune >/dev/null 2>&1
    [ -n "$TMP" ] && rm -rf "$TMP"
  fi

  print_summary
  if [ "$rc" -eq 0 ] && [ "$FAILS" -gt 0 ]; then rc=1; fi
  exit "$rc"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

# ---------------------------------------------------------------------------
# 前置檢查
# ---------------------------------------------------------------------------

for cmd in git curl python3; do
  command -v "$cmd" >/dev/null 2>&1 || { echo "缺少必要指令：$cmd" >&2; exit 2; }
done
command -v docker >/dev/null 2>&1 || { echo "缺少必要指令：docker" >&2; exit 2; }

RESOLVED_SHA="$(git -C "$REPO_ROOT" rev-parse --verify --short=10 "$REF^{commit}" 2>/dev/null)" \
  || { echo "找不到 git ref：$REF" >&2; exit 2; }

SUMMARY_PRINTED=0
TMP="$(mktemp -d "${TMPDIR:-/tmp}/smoke-test.XXXXXX")"
[ -f "$CCR_CA" ] && SANDBOX=1

echo "==> smoke test：$REF ($RESOLVED_SHA)$([ "$SANDBOX" = 1 ] && echo '  [cloud sandbox 模式]')"

# ---------------------------------------------------------------------------
echo "==> 1. Docker daemon"
# ---------------------------------------------------------------------------
if ! docker info >/dev/null 2>&1; then
  if command -v dockerd >/dev/null 2>&1; then
    echo "Docker daemon 沒有回應，嘗試在背景啟動 dockerd（最多等 20 秒）…"
    nohup dockerd >"$TMP/dockerd.log" 2>&1 &
    for _ in $(seq 1 20); do
      docker info >/dev/null 2>&1 && break
      sleep 1
    done
  fi
fi
docker info >/dev/null 2>&1 || die "Docker daemon" "連不上，也無法啟動 dockerd（日誌：$TMP/dockerd.log）"
record pass "Docker daemon" "可連線（結束後不會關閉）"

# ---------------------------------------------------------------------------
echo "==> 2. 取出 $REF 到暫存 worktree"
# ---------------------------------------------------------------------------
WT="$TMP/src"
git -C "$REPO_ROOT" worktree add --detach "$WT" "$REF" >/dev/null 2>&1 \
  || die "git worktree" "無法取出 $REF"
record pass "git worktree" "$REF → $RESOLVED_SHA（你目前的工作目錄不受影響）"

# 這個 ref 支援哪些功能，後面的探測靠這些旗標決定要不要跳過
HAS_REQUEST_ID=0
[ -f "$WT/app/Http/Middleware/AssignRequestId.php" ] && HAS_REQUEST_ID=1
HAS_PROBLEM_JSON=0
grep -rqF 'application/problem+json' "$WT/app" 2>/dev/null && HAS_PROBLEM_JSON=1
if grep -qF "'cloud_run'" "$WT/config/logging.php" 2>/dev/null; then
  LOG_CHANNEL_USED="cloud_run"
else
  LOG_CHANNEL_USED="stderr"
fi

# ---------------------------------------------------------------------------
echo "==> 3. 建置映像檔"
# ---------------------------------------------------------------------------
IMAGE="mdg-smoke:${RESOLVED_SHA}-$$"
BUILD_LOG="$TMP/build.log"
BUILD_ARGS=()

if [ "$SANDBOX" = 1 ]; then
  echo "Cloud sandbox 模式：暫存 Dockerfile 副本 + host 端安裝 vendor（repo 的 Dockerfile 不會被改）"

  # 合併 host 系統 CA 與出口 proxy 的 CA，放進 build context 讓 COPY 抓得到
  {
    [ -f /etc/ssl/certs/ca-certificates.crt ] && cat /etc/ssl/certs/ca-certificates.crt
    cat "$CCR_CA"
  } >"$WT/.smoke-ca.crt"

  # 產生暫存 Dockerfile：每個 FROM 之後放進 CA；composer install 區塊換成 no-op
  python3 - "$WT/Dockerfile" "$TMP/Dockerfile.smoke" <<'PY' || die "產生暫存 Dockerfile" "Dockerfile 結構與預期不符，sandbox 模式找不到 FROM／composer install 區塊"
import re
import sys

src, dst = sys.argv[1:3]
ca_lines = [
    "COPY .smoke-ca.crt /usr/local/share/smoke-ca.crt",
    "ENV SSL_CERT_FILE=/usr/local/share/smoke-ca.crt \\",
    "    CURL_CA_BUNDLE=/usr/local/share/smoke-ca.crt \\",
    "    NODE_EXTRA_CA_CERTS=/usr/local/share/smoke-ca.crt",
    "RUN mkdir -p /etc/ssl/certs && cat /usr/local/share/smoke-ca.crt >> /etc/ssl/certs/ca-certificates.crt",
]
lines = open(src, encoding="utf-8").read().split("\n")
out = []
froms = 0
replaced = 0
i = 0
while i < len(lines):
    line = lines[i]
    if re.match(r"^\s*FROM\s", line):
        out.append(line)
        out.extend(ca_lines)
        froms += 1
    elif re.match(r"^\s*RUN\s+composer\s+install\b", line):
        # 吃掉整個以反斜線續行的 RUN 區塊
        while lines[i].rstrip().endswith("\\"):
            i += 1
        out.append("# smoke-test sandbox 模式：vendor 已由 host 端的 composer install 放進 build context")
        out.append("RUN true")
        replaced += 1
    else:
        out.append(line)
    i += 1
if froms == 0 or replaced != 1:
    sys.exit(1)
open(dst, "w", encoding="utf-8").write("\n".join(out))
PY

  # Dockerfile 專屬的 ignore 檔：跟 repo 的 .dockerignore 一樣，只是不排除 vendor
  grep -vxE 'vendor/?' "$WT/.dockerignore" >"$TMP/Dockerfile.smoke.dockerignore"

  # vendor 在 host 端安裝（容器內裝會因為 proxy 重簽憑證而下載失敗）
  REMOTE_BEFORE="$(git -C "$REPO_ROOT" remote -v)"
  echo "host 端 composer install（--no-dev，沿用 Dockerfile 的參數）…"
  if ! (cd "$WT" && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --no-scripts --no-interaction --no-progress --optimize-autoloader --ignore-platform-reqs) >"$TMP/composer.log" 2>&1; then
    tail -20 "$TMP/composer.log" | cut -c1-300
    die "host composer install" "失敗（完整輸出：$TMP/composer.log）"
  fi
  record pass "host composer install" "vendor 安裝在 worktree（--no-dev --ignore-platform-reqs）"

  # 已知陷阱：以 root 跑 composer 之後 repo 的 git remote 可能被改掉
  REMOTE_AFTER="$(git -C "$REPO_ROOT" remote -v)"
  if [ "$REMOTE_AFTER" != "$REMOTE_BEFORE" ]; then
    die "git remote 檢查" "composer 之後 git remote -v 變了！之前：$REMOTE_BEFORE／之後：$REMOTE_AFTER"
  fi
  if [ -n "$SMOKE_EXPECTED_REMOTE" ] && ! grep -qF "$SMOKE_EXPECTED_REMOTE" <<<"$REMOTE_AFTER"; then
    die "git remote 檢查" "git remote -v 沒有指向 $SMOKE_EXPECTED_REMOTE：$REMOTE_AFTER"
  fi
  record pass "git remote 檢查" "composer 之後 remote 仍為 ${SMOKE_EXPECTED_REMOTE:-（未檢查）}"

  BUILD_ARGS=(--network host -f "$TMP/Dockerfile.smoke")
  for v in HTTPS_PROXY https_proxy HTTP_PROXY http_proxy; do
    if [ -n "${!v:-}" ]; then BUILD_ARGS+=(--build-arg "$v=${!v}"); fi
  done
else
  BUILD_ARGS=(-f "$WT/Dockerfile")
fi

echo "docker build 中（輸出寫到 $BUILD_LOG；第一次要幾分鐘）…"
if ! docker build --progress=plain "${BUILD_ARGS[@]}" -t "$IMAGE" "$WT" >"$BUILD_LOG" 2>&1; then
  tail -40 "$BUILD_LOG" | cut -c1-300
  die "docker build" "失敗（完整輸出：$BUILD_LOG）"
fi
record pass "docker build" "$IMAGE$([ "$SANDBOX" = 1 ] && echo '（sandbox 模式）')"

# ---------------------------------------------------------------------------
echo "==> 4. 資料庫、migrate、啟動容器"
# ---------------------------------------------------------------------------
PG_PORT="$(free_port)"
APP_PORT="$(free_port)"
while [ "$APP_PORT" = "$PG_PORT" ]; do APP_PORT="$(free_port)"; done
PG_CONTAINER="smoke-pg-$$"
APP_CONTAINER="smoke-app-$$"

case "$SMOKE_DB_MODE" in
  docker)
    docker pull -q "$PG_IMAGE" >/dev/null 2>&1 || die "暫存資料庫" "SMOKE_DB_MODE=docker 但拉不到 $PG_IMAGE"
    start_db_docker
    ;;
  local)
    PG_CONTAINER=""
    start_db_local || die "暫存資料庫" "SMOKE_DB_MODE=local 但本機 PostgreSQL cluster 建立失敗"
    ;;
  auto)
    if docker image inspect "$PG_IMAGE" >/dev/null 2>&1 || docker pull -q "$PG_IMAGE" >/dev/null 2>&1; then
      start_db_docker
    else
      echo "拉不到 $PG_IMAGE，退回本機 PostgreSQL cluster"
      PG_CONTAINER=""
      start_db_local || die "暫存資料庫" "拉不到 $PG_IMAGE，本機也沒有可用的 PostgreSQL"
    fi
    ;;
  *) die "暫存資料庫" "SMOKE_DB_MODE 只能是 auto｜docker｜local" ;;
esac
wait_pg || die "暫存資料庫" "40 秒內沒有就緒"
record pass "暫存 PostgreSQL" "$([ "$DB_KIND" = docker ] && echo "docker $PG_IMAGE" || echo "本機 cluster $PG_VERSION/$PG_CLUSTER")，port $PG_PORT"

APP_KEY="base64:$(python3 -c 'import base64, os; print(base64.b64encode(os.urandom(32)).decode())')"
ENV_ARGS=(
  -e APP_ENV=production
  -e APP_DEBUG=false
  -e "APP_KEY=$APP_KEY"
  -e "LOG_CHANNEL=$LOG_CHANNEL_USED"
  -e GOOGLE_CLOUD_PROJECT=local-smoke
  -e DB_CONNECTION=pgsql
  -e DB_HOST=127.0.0.1
  -e "DB_PORT=$PG_PORT"
  -e "DB_DATABASE=$PG_DB"
  -e "DB_USERNAME=$PG_USER"
  -e "DB_PASSWORD=$PG_PASS"
  -e SESSION_DRIVER=database
  -e CACHE_STORE=database
  -e QUEUE_CONNECTION=sync
  -e "PORT=$APP_PORT"
  -e FRONTEND_URL=http://localhost:5173
)

# 容器的 entrypoint 收到參數就直接 exec，所以這跟 Cloud Run Job 跑 migration 是同一條路
if ! docker run --rm --network host "${ENV_ARGS[@]}" "$IMAGE" php artisan migrate --force >"$TMP/migrate.log" 2>&1; then
  tail -20 "$TMP/migrate.log" | cut -c1-300
  die "php artisan migrate --force" "失敗（透過映像檔執行）"
fi
record pass "php artisan migrate --force" "透過映像檔執行成功"

docker run -d --name "$APP_CONTAINER" --network host "${ENV_ARGS[@]}" "$IMAGE" >/dev/null \
  || die "啟動容器" "docker run 失敗"
BASE="http://127.0.0.1:$APP_PORT"
READY=0
for _ in $(seq 1 60); do
  if [ "$(docker inspect -f '{{.State.Running}}' "$APP_CONTAINER" 2>/dev/null)" != "true" ]; then break; fi
  code="$(curl -s --noproxy '*' --max-time 3 -o /dev/null -w '%{http_code}' "$BASE/api/scopes" 2>/dev/null || true)"
  if [ -n "$code" ] && [ "$code" != "000" ]; then READY=1; break; fi
  sleep 1
done
[ "$READY" = 1 ] || die "啟動容器" "60 秒內沒有回應 HTTP（容器可能已退出）"
record pass "啟動容器並回應 HTTP" "$BASE（--network host）"

# ---------------------------------------------------------------------------
echo "==> 5. HTTP 探測"
# ---------------------------------------------------------------------------
SENTINEL="smoke-secret-$(rand_hex 6)"

http_req scopes "$BASE/api/scopes?smoke_secret=$SENTINEL"
if [ "$HTTP_CODE" = 200 ]; then
  record pass "GET /api/scopes → 200"
else
  record fail "GET /api/scopes → 200" "實際 $HTTP_CODE"
fi

if [ "$HAS_REQUEST_ID" = 1 ]; then
  rid="$(header_val scopes X-Request-Id)"
  if [ -n "$rid" ]; then
    record pass "回應帶 X-Request-Id" "$rid"
  else
    record fail "回應帶 X-Request-Id" "沒有這個標頭"
  fi

  mine="smoke-echo-$(rand_hex 4)"
  http_req echo -H "X-Request-Id: $mine" "$BASE/api/scopes"
  got="$(header_val echo X-Request-Id)"
  if [ "$got" = "$mine" ]; then
    record pass "合法的 X-Request-Id 會原樣回傳" "$mine"
  else
    record fail "合法的 X-Request-Id 會原樣回傳" "送 $mine，收到 '${got}'"
  fi

  trace="$(rand_hex 16)"
  http_req trace -H "X-Cloud-Trace-Context: $trace/1;o=1" "$BASE/api/scopes"
  got="$(header_val trace X-Request-Id)"
  if [ "$got" = "$trace" ]; then
    record pass "X-Cloud-Trace-Context 的 32 位 trace id 成為 request id"
  else
    record fail "X-Cloud-Trace-Context 的 32 位 trace id 成為 request id" "送 $trace，收到 '${got}'"
  fi
else
  record skip "X-Request-Id 三項檢查" "此 ref 沒有 AssignRequestId middleware"
fi

http_req notfound "$BASE/api/smoke-nonexistent-$(rand_hex 3)"
if [ "$HTTP_CODE" = 404 ]; then
  record pass "GET /api/<不存在的路徑> → 404"
else
  record fail "GET /api/<不存在的路徑> → 404" "實際 $HTTP_CODE"
fi
if [ "$HAS_PROBLEM_JSON" = 1 ]; then
  ctype="$(header_val notfound Content-Type)"
  if [[ "$ctype" == application/problem+json* ]]; then
    record pass "404 的 Content-Type 是 application/problem+json"
  else
    record fail "404 的 Content-Type 是 application/problem+json" "實際 '${ctype}'"
  fi
else
  record skip "404 的 Content-Type 是 application/problem+json" "app/ 內沒有使用 problem+json"
fi

# 不帶 Accept 的未登入寫入：要 401，不能因為找不到 login 路由變成 500
http_req unauth -X POST -H 'Accept:' -H 'Content-Type: application/json' -d '{}' "$BASE/api/scopes"
if [ "$HTTP_CODE" = 401 ]; then
  record pass "未登入 POST /api/scopes（不帶 Accept）→ 401"
else
  record fail "未登入 POST /api/scopes（不帶 Accept）→ 401" "實際 $HTTP_CODE"
fi

# 資料庫停掉：要回 500，而且不能把堆疊或查詢細節洩漏給用戶端
DOWN_RID="smoke-down-$(rand_hex 4)"
db_stop
sleep 1
if [ "$HAS_REQUEST_ID" = 1 ]; then
  http_req dbdown -H "X-Request-Id: $DOWN_RID" "$BASE/api/scopes?smoke_secret=$SENTINEL"
else
  http_req dbdown "$BASE/api/scopes?smoke_secret=$SENTINEL"
fi
if [ "$HTTP_CODE" = 500 ]; then
  record pass "資料庫停掉時 GET /api/scopes → 500"
else
  record fail "資料庫停掉時 GET /api/scopes → 500" "實際 $HTTP_CODE"
fi
if grep -qiE 'Stack trace|#[0-9]+ /|\.php:[0-9]+|/var/www|vendor/|SQLSTATE|PDOException|Illuminate\\' "$TMP/b_dbdown"; then
  record fail "500 回應內容沒有堆疊追蹤" "內容含有堆疊／路徑／SQL 字樣"
else
  record pass "500 回應內容沒有堆疊追蹤"
fi
db_start
wait_pg || record fail "資料庫重新啟動" "40 秒內沒有就緒"

# /docs/api.json（Scramble）
if docker run --rm "$IMAGE" test -d vendor/dedoc/scramble >/dev/null 2>&1; then
  http_req docs "$BASE/docs/api.json"
  if [ "$HTTP_CODE" = 200 ]; then
    record pass "GET /docs/api.json → 200"
  else
    record fail "GET /docs/api.json → 200" "實際 $HTTP_CODE"
  fi
else
  record skip "GET /docs/api.json → 200" "此 ref 沒有安裝 Scramble"
fi

# ---------------------------------------------------------------------------
echo "==> 6. 容器日誌"
# ---------------------------------------------------------------------------
sleep 1
docker logs "$APP_CONTAINER" >"$TMP/app.log" 2>&1 || true

read -r JSON_LINES JSON_BAD < <(python3 - "$TMP/app.log" <<'PY'
import json
import sys

total = bad = 0
for line in open(sys.argv[1], encoding="utf-8", errors="replace"):
    if line.startswith("{"):
        total += 1
        try:
            json.loads(line)
        except ValueError:
            bad += 1
print(total, bad)
PY
)
if [ "$JSON_BAD" = 0 ]; then
  record pass "以 { 開頭的日誌行都是合法 JSON" "$JSON_LINES 行 JSON"
else
  record fail "以 { 開頭的日誌行都是合法 JSON" "$JSON_BAD／$JSON_LINES 行無法解析（可能被 php-fpm 換行拆開）"
fi

if [ "$LOG_CHANNEL_USED" = cloud_run ] && [ "$HAS_REQUEST_ID" = 1 ]; then
  read -r HIT_COUNT HIT_OK HIT_LEN < <(python3 - "$TMP/app.log" "$DOWN_RID" <<'PY'
import json
import sys

path, rid = sys.argv[1:3]
hits = []
for line in open(path, encoding="utf-8", errors="replace"):
    line = line.rstrip("\n")
    if not line.startswith("{"):
        continue
    try:
        entry = json.loads(line)
    except ValueError:
        continue
    if entry.get("severity") == "ERROR" and rid in line:
        hits.append((entry, line))
count = len(hits)
ok = 0
length = 0
if count == 1:
    entry, line = hits[0]
    ok = 1 if str(entry.get("message", "")).startswith("PHP Fatal error:  Uncaught") else 0
    length = len(line.encode("utf-8"))
print(count, ok, length)
PY
)
  if [ "$HIT_COUNT" = 1 ] && [ "$HIT_OK" = 1 ]; then
    record pass "500 恰好產生一行 ERROR JSON（message 以 PHP Fatal error:  Uncaught 開頭，含 request id）" "該行 $HIT_LEN bytes，沒被 php-fpm log_limit 拆行"
  elif [ "$HIT_COUNT" = 1 ]; then
    record fail "500 恰好產生一行 ERROR JSON（message 以 PHP Fatal error:  Uncaught 開頭，含 request id）" "找到一行但 message 開頭不對"
  else
    record fail "500 恰好產生一行 ERROR JSON（message 以 PHP Fatal error:  Uncaught 開頭，含 request id）" "含 request id 的 ERROR JSON 行有 $HIT_COUNT 行（應為 1）"
  fi
else
  record skip "500 的 ERROR JSON 行（Error Reporting 格式）" "此 ref 的日誌 channel 不是 cloud_run 或沒有 request id"
fi

# 查詢字串裡的機敏值不應進結構化日誌；nginx／php-fpm 的文字 access 行已知會帶（issue #103），只警告
JSON_LEAK="$(python3 - "$TMP/app.log" "$SENTINEL" <<'PY'
import sys

path, needle = sys.argv[1:3]
n = 0
for line in open(path, encoding="utf-8", errors="replace"):
    if line.startswith("{") and needle in line:
        n += 1
print(n)
PY
)"
if [ "$JSON_LEAK" = 0 ]; then
  record pass "查詢字串（?smoke_secret=…）沒有出現在任何 JSON 日誌行"
else
  record fail "查詢字串（?smoke_secret=…）沒有出現在任何 JSON 日誌行" "$JSON_LEAK 行 JSON 含有"
fi
TEXT_LEAK="$(grep -v '^{' "$TMP/app.log" | grep -cF "$SENTINEL" || true)"
if [ "$TEXT_LEAK" -gt 0 ]; then
  record warn "nginx／php-fpm 文字 access 行帶有查詢字串" "$TEXT_LEAK 行；已知問題，追蹤於 issue #103，不算失敗"
fi

echo
echo "==> 完成：$REF ($RESOLVED_SHA)，$FAILS 項失敗、$WARNS 項警告"
