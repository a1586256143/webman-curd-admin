#!/usr/bin/env bash
#
# 构建内置前端并打包发布 zip（一体化）。
#
# 前置：
#  - 环境变量 CRUD_FRONTEND_DIR 指向前端源码仓库（或首个参数传入）
#  - 前端源码仓库为独立 git 仓库（建议），与插件包解耦
#  - 需要 env -u NODE_OPTIONS npm（沙箱/IDE 注入 fs broker 时）
#
# 流程：build-frontend.sh（按 VITE_BASE_PATH 构建 → 回灌 plugin/crud/public/）
#       → release-zip.sh（打 dist/webman-crud-vX.Y.Z.zip）
#
# 用法：
#   CRUD_FRONTEND_DIR=~/code/crud-frontend ./scripts/build-and-release.sh
#   ./scripts/build-and-release.sh ~/code/crud-frontend

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PKG_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

FRONTEND_DIR="${1:-${CRUD_FRONTEND_DIR:-}}"
if [ -z "$FRONTEND_DIR" ]; then
  echo "用法: $0 <前端源码目录>   （或设置环境变量 CRUD_FRONTEND_DIR）" >&2
  exit 1
fi
if [ ! -f "$FRONTEND_DIR/package.json" ]; then
  echo "错误: $FRONTEND_DIR 下没有 package.json" >&2
  exit 1
fi

echo "==> [1/2] 构建前端: $FRONTEND_DIR"
"$SCRIPT_DIR/build-frontend.sh" "$FRONTEND_DIR"

echo
echo "==> [2/2] 打包发布 zip"
"$SCRIPT_DIR/release-zip.sh"

echo
echo "✅ 完成。zip 位于 $PKG_DIR/dist/"
echo "   发布：git add -A && git commit -m 'build: 前端 vX.Y.Z' && git tag vX.Y.Z && git push --tags"
