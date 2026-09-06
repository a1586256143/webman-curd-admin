#!/usr/bin/env bash
# ============================================================
# webman-curd-admin · composer 包装脚本（自动追加 --no-audit）
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
#   ./scripts/composer.sh require amcolin/webman-curd-admin:^1.0
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

# 透传全部参数并对会触发 audit 的子命令自动追加 --no-audit
# composer audit 是这几个子命令的尾部内置动作（require/update/install/remove），
# 其它子命令（如 show/info/search）根本不会跑 audit，加 --no-audit 反而会报
# "option does not exist"。通过检查第一个非选项参数判定是否需要追加。
audit_subcommands="require update install remove add create-project"

# 若用户已显式传入 --no-audit / --audit，则尊重用户选择
for arg in "$@"; do
    case "$arg" in
        --no-audit|--audit)
            exec composer "$@"
            ;;
    esac
done

first=""
for arg in "$@"; do
    case "$arg" in
        --*)
            continue
            ;;
        *)
            first="$arg"
            break
            ;;
    esac
done

need_audit_skip=0
for sub in $audit_subcommands; do
    if [ "$first" = "$sub" ]; then
        need_audit_skip=1
        break
    fi
done

if [ "$need_audit_skip" -eq 1 ]; then
    exec composer "$@" --no-audit
else
    exec composer "$@"
fi