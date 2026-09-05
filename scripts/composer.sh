#!/usr/bin/env bash
# ============================================================
# webman-crud · composer 包装脚本（自动追加 --no-audit）
# ============================================================
#
# 为什么需要这个？
#   `composer require` / `composer update` 默认会在末尾跑一次
#   `composer audit`，它硬编码访问 packagist.org 的 security-advisories
#   端点（不走国内镜像）。国内/内网环境 packagist.org 不可达，导致
#   末尾报：
#       Failed to audit installed packages.
#   这个告警 EXIT CODE = 0（不影响安装），但视觉上很刺眼。
#
# composer 本身没有「全局禁用 audit」的 config；只能在每次调用时加
# --no-audit。这个脚本就是「每次都加 --no-audit」的包装。
#
# 用法：
#   ./scripts/composer.sh require huafei/webman-crud:^1.0
#   ./scripts/composer.sh update
#   ./scripts/composer.sh install
#
# 或在你的 ~/.zshrc / ~/.bashrc 加一行（更省事）：
#   alias composer='</绝对路径>/scripts/composer.sh'
#
# 说明：脚本内用 exec 替换进程（无额外 shell 开销），透传所有参数。
# ============================================================

set -e

if ! command -v composer >/dev/null 2>&1; then
    echo "[composer.sh] 错误：系统未找到 composer 命令" >&2
    echo "              请先安装：https://getcomposer.org/download/" >&2
    exit 127
fi

# 透传全部参数并强制追加 --no-audit
# 若用户已显式传入 --no-audit / --audit，则尊重用户选择
has_audit_flag=0
for arg in "$@"; do
    case "$arg" in
        --no-audit|--audit)
            has_audit_flag=1
            break
            ;;
    esac
done

if [ "$has_audit_flag" -eq 0 ]; then
    exec composer "$@" --no-audit
else
    exec composer "$@"
fi