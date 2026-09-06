#!/usr/bin/env bash
#
# 双端同步脚本：把当前分支与本地 tags 同时推送到 GitHub(origin) 与 Gitee(gitee)。
#
# 适用场景：
#   - 发布/打 tag 后，一条命令让 GitHub 与 Gitee 完全一致；
#   - 日常 commit 只需推 GitHub 时照常用 git push（本脚本不是每次提交的必选项）。
#
# remote 约定（已在 .git/config 配置）：
#   origin = git@github.com:a1586256143/webman-curd-admin.git   （SSH）
#   gitee  = https://gitee.com/colingit/webman-curd-admin.git   （https，凭据在 ~/.git-credentials）
#
# 用法：
#   sh scripts/git-sync.sh                # 推当前分支 + 所有本地 tags 到双端
#   sh scripts/git-sync.sh main           # 推指定分支 + 所有本地 tags 到双端
#   sh scripts/git-sync.sh main --no-tags # 只推分支，不推 tags
#   sh scripts/git-sync.sh --dry-run      # 只打印将执行的推送，不真正执行
#
# 注意：
#   - 远端同名 tag 指向不同 commit 时不会强推（报错提示），需先手动删除远端旧 tag：
#       git push gitee :refs/tags/<tag>
#   - https 凭据失效时会直接失败而不是卡在交互输入（GIT_TERMINAL_PROMPT=0）。

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PKG_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

# —— 解析参数 ——
BRANCH=""
NO_TAGS=0
DRY_RUN=0
for arg in "$@"; do
  case "$arg" in
    --no-tags) NO_TAGS=1 ;;
    --dry-run) DRY_RUN=1 ;;
    -*) echo "未知参数: $arg" >&2; exit 1 ;;
    *) BRANCH="$arg" ;;
  esac
done

cd "$PKG_DIR"

# 默认推当前分支
if [ -z "$BRANCH" ]; then
  BRANCH="$(git branch --show-current)"
fi
if [ -z "$BRANCH" ]; then
  echo "错误: 无法确定当前分支，请显式传分支名: $0 main" >&2
  exit 1
fi

# 检查双 remote 已配置
for r in origin gitee; do
  if ! git remote | grep -qx "$r"; then
    echo "错误: 缺少 remote '$r'。先执行: git remote add $r <url>" >&2
    exit 1
  fi
done

# https 凭据失效时快速失败，避免卡交互
export GIT_TERMINAL_PROMPT=0

run_push() {
  local remote="$1"; shift
  echo "==> git push $remote $*"
  if [ "$DRY_RUN" = 1 ]; then
    return 0
  fi
  git push "$remote" "$@"
}

echo "==> 同步分支 '$BRANCH' 到双端: origin(GitHub) + gitee(Gitee)"
run_push origin "$BRANCH"
run_push gitee "$BRANCH"

if [ "$NO_TAGS" = 0 ]; then
  echo "==> 同步本地 tags 到双端"
  run_push origin --tags
  run_push gitee --tags
fi

echo
echo "✅ 完成（DRY_RUN=$DRY_RUN）。远端现状："
for r in origin gitee; do
  echo "--- $r ---"
  git ls-remote --heads --tags "$r" | sed "s#^#$r: #" || echo "  (无法访问 $r)"
done
