#!/usr/bin/env bash
#
# 打 zip 发布包：plugin/curd + composer.json + README + LICENSE（不含 keys/前端源码）
#
# 用法：
#   ./scripts/release-zip.sh [version]    # 默认取当前 git tag 或 git describe
#
# 产物：dist/webman-curd-admin-vX.Y.Z.zip
# 内容结构（zip 解压后）：
#   composer.json
#   README.md
#   plugin/curd/...                 # 应用插件源码（含前端 dist）
#   scripts/release.sh              # 同步脚本（运维用）
#   scripts/build-frontend.sh       # 前端构建脚本（开发者用）
#
# 排除：
#   plugin/curd/config/keys/         # RSA 密钥不打包（项目首次 install 时生成）
#   plugin/curd/install-business.sql # 项目专有业务表 DDL（不通用，由项目自带）
#   scripts/release-zip.sh          # 自身（避免循环引用）
#   .git/ .github/ node_modules/

set -euo pipefail

PKG=$(cd "$(dirname "$0")/.." && pwd)
cd "$PKG"

# 取版本号
if [ -n "${1:-}" ]; then
    VERSION="$1"
elif git describe --tags --abbrev=0 >/dev/null 2>&1; then
    VERSION=$(git describe --tags --abbrev=0 | sed 's/^v//')
else
    VERSION="0.1.0-dev"
fi

DIST="$PKG/dist"
mkdir -p "$DIST"
ZIP="$DIST/webman-curd-admin-v${VERSION}.zip"
rm -f "$ZIP"

# 打包（基于 git ls-files，无 git 时回退 find 排除）
if git rev-parse --git-dir >/dev/null 2>&1; then
    echo "==> 打包 git tracked files"
    git archive --format=zip \
        --output "$ZIP" \
        --prefix=webman-curd-admin/ \
        HEAD \
        $(git ls-files | grep -E '^(composer\.json|README\.md|plugin/curd/|scripts/)' | tr '\n' ' ')
else
    echo "==> 打包全部文件（无 git，按路径排除）"
    zip -r "$ZIP" \
        composer.json README.md plugin/curd scripts \
        -x 'plugin/curd/config/keys/*' \
        -x 'plugin/curd/install-business.sql' \
        -x 'scripts/release-zip.sh'
fi

# 复检 zip 内容
echo "==> zip 内容预览："
unzip -l "$ZIP" | tail -20
echo "==> zip 大小: $(du -h "$ZIP" | cut -f1)"
echo "==> 完成: $ZIP"