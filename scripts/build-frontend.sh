#!/usr/bin/env bash
#
# 构建插件内置前端并回灌到 plugin/curd/public/
#
# 用法：
#   ./scripts/build-frontend.sh [前端源码目录]
#
# 前端源码目录默认取环境变量 CURD_FRONTEND_DIR，未设置则报出提示退出。
# 产物以 VITE_BASE_PATH=/app/curd/ 构建（与 plugin/curd/config/curd.php 的
# page_base 保持一致），API 走同域相对路径、默认关闭传输加密。
#
# 注意：本机若被注入 NODE_OPTIONS fs shim（部分沙箱/IDE 环境），npm 会报
# "Brokered host mkdir ..."，此时用 `env -u NODE_OPTIONS npm ...` 绕开。

set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PUBLIC_DIR="$PLUGIN_DIR/plugin/curd/public"
FRONTEND_DIR="${1:-${CURD_FRONTEND_DIR:-}}"

if [ -z "$FRONTEND_DIR" ]; then
  echo "用法: $0 <前端源码目录>   （或设置环境变量 CURD_FRONTEND_DIR）"
  exit 1
fi

if [ ! -f "$FRONTEND_DIR/package.json" ]; then
  echo "错误: $FRONTEND_DIR 下没有 package.json"
  exit 1
fi

PAGE_BASE="${CURD_PAGE_BASE:-/app/curd/}"

echo "==> 构建前端: $FRONTEND_DIR (base=$PAGE_BASE)"
(
  cd "$FRONTEND_DIR"
  VITE_BASE_PATH="$PAGE_BASE" \
  VITE_API_BASE_URL="${VITE_API_BASE_URL:-}" \
  VITE_API_ENCRYPT="${VITE_API_ENCRYPT:-false}" \
  env -u NODE_OPTIONS npm run build
)

echo "==> 回灌产物到 $PUBLIC_DIR"
rm -rf "$PUBLIC_DIR"
mkdir -p "$PUBLIC_DIR"
cp -R "$FRONTEND_DIR/dist/." "$PUBLIC_DIR/"

find "$PUBLIC_DIR" -name '.DS_Store' -delete 2>/dev/null || true

echo "==> 完成，产物文件数: $(find "$PUBLIC_DIR" -type f | wc -l | tr -d ' ')，体积: $(du -sh "$PUBLIC_DIR" | cut -f1)"
echo "    资源前缀校验: $(grep -o 'src=\"[^\"]*assets/[^\"]*\"' "$PUBLIC_DIR/index.html" | head -1)"
