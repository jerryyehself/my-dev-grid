#!/usr/bin/env bash
# 依 CHANGELOG.md 建立 git tag 與 GitHub Release（D-107）。
#
# 為什麼由 CI 做、不手動打：雲端 Claude session 推不了 tag、也建不了 Release，
# 而且手動打很容易漏、或打在錯的 commit 上。版本號與日期只認 CHANGELOG.md，
# 這支腳本負責把「已經寫上日期的版本」補成 tag＋Release。
#
# 規則：
# - 只認標題完全符合 `## [X.Y.Z] - YYYY-MM-DD` 的版本；`待上線` 或其他非日期的不算。
# - 已經有 tag `vX.Y.Z` 的版本跳過，所以重跑不會重複建立（冪等）。
# - tag 打在 HEAD 的 first-parent 歷史中「最早新增這一行標題」的 commit，
#   也就是把日期寫進 CHANGELOG 的那個 commit。這樣補打舊版本也會落在正確位置，
#   不會全部擠在最新的 commit 上。
# - Release 內文取該版本在 CHANGELOG.md（HEAD）的段落：標題下一行起，
#   到下一個 `## [` 標題或第一個 `[x.y.z]: ` 連結定義為止。
# - 依版本號由小到大建立；只有 CHANGELOG 裡最高的版本標成 latest。
#
# 本機測試：DRY_RUN=1 bash .github/scripts/release-from-changelog.sh
#   只印出預計建立的 Release，不呼叫 gh、不改任何東西。
#   需要完整歷史與 tag（shallow clone 會直接報錯）。

set -euo pipefail

CHANGELOG="${CHANGELOG:-CHANGELOG.md}"
DRY_RUN="${DRY_RUN:-0}"

cd "$(git rev-parse --show-toplevel)"

if [[ "$(git rev-parse --is-shallow-repository)" == "true" ]]; then
  echo "錯誤：這是 shallow clone，找不到正確的 tag 目標 commit。請用 fetch-depth: 0。" >&2
  exit 1
fi

if [[ ! -f "$CHANGELOG" ]]; then
  echo "錯誤：找不到 $CHANGELOG" >&2
  exit 1
fi

heading_re='^## \[([0-9]+\.[0-9]+\.[0-9]+)\] - ([0-9]{4}-[0-9]{2}-[0-9]{2})[[:space:]]*$'

# 讀出所有已上線版本：版本號 → 日期、版本號 → 原始標題行
declare -A version_date=()
declare -A version_heading=()
while IFS= read -r line || [[ -n "$line" ]]; do
  line="${line%$'\r'}"
  if [[ "$line" =~ $heading_re ]]; then
    v="${BASH_REMATCH[1]}"
    if [[ -n "${version_date[$v]:-}" ]]; then
      echo "錯誤：$CHANGELOG 裡 [$v] 出現不只一次" >&2
      exit 1
    fi
    version_date[$v]="${BASH_REMATCH[2]}"
    version_heading[$v]="$line"
  fi
done < "$CHANGELOG"

if [[ ${#version_date[@]} -eq 0 ]]; then
  echo "CHANGELOG 裡沒有已上線（有日期）的版本，不用建立 Release。"
  exit 0
fi

# X.Y.Z 純數字，sort -V 就是 semver 順序
mapfile -t versions < <(printf '%s\n' "${!version_date[@]}" | sort -V)
highest="${versions[-1]}"

# 把字串轉成 -G 用的 basic regex（跳脫 [ . * ^ $ \）
regex_escape() {
  # 括號裡 `[` 不能緊接 `.`（會變成 POSIX 的 [. .] 排序符號），所以 `$` 放第一個
  printf '%s' "$1" | sed -e 's/[$.*^[\]/\\&/g'
}

# 取某版本在 CHANGELOG 裡的段落，去掉前後空行
extract_notes() {
  local heading="$1"
  awk -v heading="$heading" '
    { sub(/\r$/, "") }
    found && (/^## \[/ || /^\[[0-9]+\.[0-9]+\.[0-9]+\]: /) { exit }
    found { print }
    !found && $0 == heading { found = 1 }
  ' "$CHANGELOG" | sed -e '/./,$!d' | sed -e ':a' -e '/^\n*$/{$d;N;ba' -e '}'
}

tmpdir="$(mktemp -d)"
trap 'rm -rf "$tmpdir"' EXIT

planned=0
for v in "${versions[@]}"; do
  tag="v$v"
  date="${version_date[$v]}"
  heading="${version_heading[$v]}"

  if [[ -n "$(git tag -l "$tag")" ]]; then
    echo "略過 $tag：tag 已存在"
    continue
  fi
  # 正式執行時再問一次 GitHub：tag 可能還沒 fetch 下來，但 Release 已經在了
  if [[ "$DRY_RUN" != "1" ]] && gh release view "$tag" >/dev/null 2>&1; then
    echo "略過 $tag：GitHub Release 已存在"
    continue
  fi

  # 最早一個「新增或刪除了這一行標題」的 first-parent commit，就是寫上日期的那次。
  # 不直接 | head -1：pipefail 下 git log 會被 SIGPIPE 中斷而回傳非 0。
  pattern="^$(regex_escape "$heading")[[:space:]]*\$"
  commits="$(git log --first-parent --diff-merges=first-parent --reverse --format=%H \
    -G "$pattern" HEAD -- "$CHANGELOG")"
  target="${commits%%$'\n'*}"
  if [[ -z "$target" ]]; then
    echo "錯誤：在 HEAD 的 first-parent 歷史裡找不到新增「$heading」的 commit" >&2
    exit 1
  fi

  notes_file="$tmpdir/$tag.md"
  extract_notes "$heading" > "$notes_file"
  if [[ ! -s "$notes_file" ]]; then
    echo "錯誤：$tag 在 CHANGELOG 裡沒有內文" >&2
    exit 1
  fi

  if [[ "$v" == "$highest" ]]; then
    latest_flag="--latest"
  else
    latest_flag="--latest=false"
  fi
  title="$tag（$date）"
  planned=$((planned + 1))

  if [[ "$DRY_RUN" == "1" ]]; then
    echo "預計建立 $tag → $target $latest_flag"
    echo "  標題：$title"
    echo "  內文："
    sed -e 's/^/    | /' "$notes_file"
  else
    echo "建立 $tag → $target $latest_flag"
    gh release create "$tag" \
      --target "$target" \
      --title "$title" \
      --notes-file "$notes_file" \
      "$latest_flag"
  fi
done

if [[ "$planned" -eq 0 ]]; then
  echo "所有已上線版本都有 tag 了，沒有要建立的 Release。"
fi
