#!/bin/sh
#
# supervisord 用這支腳本啟動 nginx：先等 php-fpm 的 127.0.0.1:9000 接受連線，
# 再 exec 傳進來的指令（nginx）。
#
# 為什麼要等（#91）：supervisord 同時啟動 php-fpm 與 nginx，nginx 不到一秒就開始
# 聽 $PORT，php-fpm 要晚幾秒才開始聽 9000。這段時間進來的請求，nginx 轉給 php-fpm
# 時連不上，回 502（connect() failed (111: Connection refused)）。nginx 等到 php-fpm
# 能連上才開始聽，冷啟動時就不會有「埠開了、但 PHP 還沒準備好」的空窗。
#
# 等多久：最多 PHP_FPM_WAIT_SECONDS 秒（預設 30），時間到還連不上，照樣啟動 nginx，
# 不結束重試。理由：
# - 結束的話 supervisord 會一直重啟這支腳本，nginx 永遠不聽埠；照樣啟動的話，
#   請求拿到的是跟改動前一樣的 502，不會比原本更糟。
# - php-fpm 真的起不來時，擋住流量的是 Cloud Run 的 startup probe（HTTP GET /up，
#   設在 .github/workflows/deploy-cloud-run.yml）：/up 要經過 php-fpm 才會回 200，
#   probe 不過，Cloud Run 不會把請求送進這個 instance，最後換掉它。
#
# 只在 nginx 啟動時等一次。php-fpm 之後被 supervisord 重啟時，nginx 已經在跑，
# 不受這支腳本影響。
#
# 位址要跟 docker/nginx.conf.template 的 fastcgi_pass 一致。php-fpm 的 listen
# 用的是 php 官方映像檔的預設（/usr/local/etc/php-fpm.d/docker.conf：listen = 9000）。
#
# 刻意不用 set -e：這支腳本任何一步出錯都不能讓 nginx 起不來，所以每一步都有退路，
# 最後一定 exec nginx。

host=127.0.0.1
port=9000
max_wait="${PHP_FPM_WAIT_SECONDS:-30}"
case "$max_wait" in
    '' | *[!0-9]*) max_wait=30 ;;
esac

# 現在時間，單位百分之一秒，用整數運算。/proc/uptime 第一欄是開機後的秒數（到小數兩位）；
# busybox 的 date 沒有 %N，讀不到 /proc/uptime 時退回 date +%s（只到秒）。
# 去掉前導 0：ash 會把 0 開頭的數字當八進位，"009" 會算錯。
now_cs() {
    t="$(cut -d' ' -f1 /proc/uptime 2>/dev/null | tr -d .)"
    t="${t#"${t%%[!0]*}"}"
    case "$t" in
        '' | *[!0-9]*) t="$(date +%s)00" ;;
    esac
    echo "$t"
}

# 一行 JSON 寫到 stdout，Cloud Logging 會讀 severity（跟 App\Logging\CloudLoggingFormatter
# 一樣一行一個 JSON 物件）。訊息內容固定，不需要跳脫。
log() {
    printf '{"severity":"%s","message":"%s"}\n' "$1" "$2"
}

start="$(now_cs)"
deadline=$((start + max_wait * 100))

# busybox nc：-z 只測連線不送資料，-w 1 是連線逾時一秒。連得上回 0。
until nc -z -w 1 "$host" "$port" >/dev/null 2>&1; do
    if [ "$(now_cs)" -ge "$deadline" ]; then
        log WARNING "wait-for-php-fpm: ${host}:${port} not accepting connections after ${max_wait}s, starting nginx anyway"
        exec "$@"
    fi
    sleep 0.1
done

waited=$(($(now_cs) - start))
log INFO "wait-for-php-fpm: ${host}:${port} accepting connections after $((waited / 100)).$((waited / 10 % 10))$((waited % 10))s, starting nginx"
exec "$@"
