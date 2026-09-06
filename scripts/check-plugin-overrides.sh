#!/usr/bin/env bash
#
# 升级前检查：检测「项目内 plugin/curd」相对「插件包原始副本」的本地改动。
#
# 背景：webman 的 support\Plugin::install 会把插件包内的 plugin/curd 覆盖式
#       拷贝到宿主项目的 plugin/curd。若你在项目内改过插件文件，composer update
#       会静默覆盖这些改动且无任何提示 —— 本脚本提前暴露风险。
#
# 用法：
#   ./scripts/check-plugin-overrides.sh <项目根目录>
#   ./scripts/check-plugin-overrides.sh            # 默认当前目录作为项目根
#
# 返回码：0=无改动（可安全升级）  1=发现本地改动（升级前需人工确认）

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PKG_PLUGIN="$SCRIPT_DIR/../plugin/curd"          # 插件包内的权威副本
PROJECT_ROOT="${1:-$(pwd)}"
PROJ_PLUGIN="$PROJECT_ROOT/plugin/curd"

if [ ! -d "$PKG_PLUGIN" ]; then
  echo "错误: 找不到插件包副本: $PKG_PLUGIN" >&2
  exit 2
fi
if [ ! -d "$PROJ_PLUGIN" ]; then
  echo "项目 $PROJECT_ROOT 未安装 plugin/curd（先 composer require）" >&2
  exit 2
fi

echo "== 升级前覆盖检查 =="
echo "  插件包副本 : $PKG_PLUGIN"
echo "  项目副本   : $PROJ_PLUGIN"
echo

# 找不同：以插件包副本为基准，递归比对（忽略 keys/ 与 install-business.sql 等项目专有项）
DIFF_OUT=$(diff -rq \
  --exclude 'config/keys' \
  --exclude 'install-business.sql' \
  --exclude '.DS_Store' \
  "$PKG_PLUGIN" "$PROJ_PLUGIN" 2>&1) || true

# diff -rq 只报「只在某侧存在 / 内容不同」；我们关心的是「项目侧改了/新增了包副本没有的东西」

CHANGED=0
while IFS= read -r line; do
  case "$line" in
    "Only in $PROJ_PLUGIN"*)
      # 项目独有文件（包里没有）→ 升级会被删除
      f="${line#Only in }"
      echo "[删除风险] 项目侧独有: ${f/:/ — }"
      CHANGED=1
      ;;
    "Only in $PKG_PLUGIN"*)
      # 包新增文件 → 升级会补全（正常）
      f="${line#Only in }"
      echo "[将补全] 包内新增: ${f/:/ — }"
      ;;
    *"differ"*)
      # 内容不同 → 升级会覆盖
      path=$(echo "$line" | sed -E "s/^Files (.*) and .* differ/\1/")
      echo "[覆盖风险] 内容被改动: $path"
      CHANGED=1
      ;;
  esac
done <<< "$DIFF_OUT"

echo
if [ "$CHANGED" -eq 0 ]; then
  echo "✅ 未发现本地改动，可安全执行 composer update amcolin/webman-curd-admin"
  exit 0
else
  echo "⚠️  发现本地改动！升级前请处理（git stash / 提交 / 迁回插件配置化）。"
  echo "    处理后可重跑本脚本复查。"
  exit 1
fi
