#!/usr/bin/env bash
# 发布脚本：把宿主开发版 plugin/curd 回灌到本 composer 包后提交
# 用法：sh scripts/release.sh
set -euo pipefail

HOST=/Users/colin/Public/www/company/webman-huafei-platform
PKG=$(cd "$(dirname "$0")/.." && pwd)

if [ ! -d "$HOST/plugin/curd" ]; then
  echo "未找到宿主插件目录: $HOST/plugin/curd" >&2
  exit 1
fi

rsync -a --delete \
  --exclude 'config/keys/' \
  --exclude 'install-business.sql' \
  --exclude '.DS_Store' \
  "$HOST/plugin/curd/" "$PKG/plugin/curd/"

echo "已同步 $HOST/plugin/curd → $PKG/plugin/curd"
echo "（排除: config/keys/、install-business.sql — 后者是项目专有业务表 DDL，不进通用包）"
echo "检查 git 差异后提交即可发布（git add -A && git commit && git tag v1.0.x）"
