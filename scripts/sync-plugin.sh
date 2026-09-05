#!/usr/bin/env bash
# ============================================================
# 升级 webman-crud：把 vendor 里最新包的 plugin/crud 重新同步到宿主
#
# 背景：src/Install.php 的拷贝策略是「plugin/crud 已存在则跳过」（保护本地改动），
#       因此 composer update 拉到新版本后，plugin/crud 内的文件（api/Install.php、
#       config/crud.php 等）不会自动更新。本脚本用于升级场景：
#         1) 备份当前 plugin/crud → plugin/crud.bak-<时间戳>（含 config/keys）
#         2) 从 vendor/huafei/webman-crud/plugin/crud 整体重拷
#
# 用法：在宿主项目根执行
#   bash plugin/crud/../../scripts/sync-plugin.sh        # 绝对/相对路径均可
#   # 或项目内已放副本：bash scripts/sync-plugin.sh
#
# 注意：升级前建议先跑 scripts/check-plugin-overrides.sh 检查本地是否改过插件文件，
#       有本地改动请先自行合入备份（plugin/crud.bak-*）再删除备份。
# ============================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# scripts/ 的上一级即宿主项目根（脚本可能在 vendor 包内 scripts/ 或项目 scripts/）
HOST_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
# 若脚本位于 vendor/huafei/webman-crud/scripts → 宿主根是上上级 vendor/huafei/webman-crud/../../..
if [[ "${SCRIPT_DIR}" == *"/vendor/huafei/webman-crud/scripts" ]]; then
    HOST_ROOT="$(cd "${SCRIPT_DIR}/../../.." && pwd)"
fi

SRC="${HOST_ROOT}/vendor/huafei/webman-crud/plugin/crud"
DST="${HOST_ROOT}/plugin/crud"

if [[ ! -d "${SRC}" ]]; then
    echo "[ERROR] vendor 内未找到插件源码: ${SRC}"
    echo "       请确认已 composer require huafei/webman-crud（或先 composer update）"
    exit 1
fi

TS="$(date +%Y%m%d%H%M%S)"
if [[ -d "${DST}" ]]; then
    BAK="${DST}.bak-${TS}"
    mv "${DST}" "${BAK}"
    echo "[OK] 已备份现有 plugin/crud → ${BAK}"
fi

mkdir -p "$(dirname "${DST}")"
cp -R "${SRC}" "${DST}"
rm -rf "${DST}/node_modules" "${DST}/.git" "${DST}/.idea"

echo "[OK] 已从 vendor 同步最新 plugin/crud 到 ${DST}"
echo "     本地改动如需找回，见备份目录 ${BAK}（确认无误后可删除）"
echo "     数据库配置：若之前已生成 config/crud.php，升级不会覆盖它（src/Install.php 跳过已存在文件）"
