#!/usr/bin/env bash
#
# 构建插件内置前端并回灌到 plugin/curd/public/
#
# 用法：
#   ./scripts/build-frontend.sh [前端源码目录]
#
# 前端源码目录默认取环境变量 CURD_FRONTEND_DIR，未设置则报出提示退出。
# 产物以 VITE_BASE_PATH=/app/curd/ 构建（与 plugin/curd/config/curd.php 的
# page_base 保持一致），API 走同域相对路径。
#
# 环境变量：
#   CURD_PUBLIC_DIR         回灌目标，默认 {插件仓库}/plugin/curd/public
#                           （为单个宿主构建时指向该宿主的 plugin/curd/public）
#   VITE_API_ENCRYPT        'false'（默认，明文）| 其他值=开启加密
#                           ⚠️ 开启加密时公钥自动从「回灌目标宿主」的
#                           config/keys/api_rsa_public.pem 读取，读不到直接报错退出；
#                           也可用 VITE_API_RSA_PUBLIC_KEY 显式传 PEM 单行文本（\n 分隔）
#   CURD_PAGE_BASE          前端挂载前缀，默认 /app/curd/
#
# 例（只给 huafei 构建加密产物，插件仓库默认产物不受影响）：
#   VITE_API_ENCRYPT=true \
#   CURD_PUBLIC_DIR=~/Public/www/company/webman-huafei-platform/plugin/curd/public \
#     ./scripts/build-frontend.sh ~/Public/www/company/webman-curd-admin-frontend
#
# 注意：本机若被注入 NODE_OPTIONS fs shim（部分沙箱/IDE 环境），npm 会报
# "Brokered host mkdir ..."，此时用 `env -u NODE_OPTIONS npm ...` 绕开。

set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# 产物回灌目标。默认=插件仓库自带的 public/（对外发布的「明文」默认产物）；
# 要给某个宿主单独构建（如为 huafei 开加密），用 CURD_PUBLIC_DIR 指向该宿主的
# plugin/curd/public —— 这样插件仓库的默认产物保持明文、不被污染。
PUBLIC_DIR="${CURD_PUBLIC_DIR:-$PLUGIN_DIR/plugin/curd/public}"
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

# ---------- 加密开关与公钥（开启加密就绝不能拿错公钥，否则全线 400） ----------
API_ENCRYPT="${VITE_API_ENCRYPT:-false}"
RSA_PUB_INJECT="${VITE_API_RSA_PUBLIC_KEY:-}"

if [ "$API_ENCRYPT" != "false" ]; then
  if [ -z "$RSA_PUB_INJECT" ]; then
    # 未显式给公钥：从「本次回灌目标宿主」的 config/keys 自动取
    HOST_KEY="$(dirname "$PUBLIC_DIR")/config/keys/api_rsa_public.pem"
    if [ -f "$HOST_KEY" ]; then
      # 按行读入 PEM 原样注入（前端 apiCrypto.js 同时兼容真实换行与字面 \n 两种写法）
      RSA_PUB_INJECT="$(awk 'NF{printf "%s\\n", $0}' "$HOST_KEY")"
      echo "==> 加密已开启，公钥取自: $HOST_KEY"
    else
      echo "错误: VITE_API_ENCRYPT=$API_ENCRYPT 但找不到宿主公钥：$HOST_KEY"
      echo "      每个宿主的密钥对独立，插件源码内嵌的公钥谁都对不上。"
      echo "      请确认 CURD_PUBLIC_DIR 指向目标宿主的 plugin/curd/public，"
      echo "      或用 VITE_API_RSA_PUBLIC_KEY 显式传入 PEM 单行文本。"
      exit 1
    fi
  fi
else
  RSA_PUB_INJECT=""
fi

echo "==> 构建前端: $FRONTEND_DIR (base=$PAGE_BASE, 加密=$API_ENCRYPT)"
(
  cd "$FRONTEND_DIR"
  VITE_BASE_PATH="$PAGE_BASE" \
  VITE_API_BASE_URL="${VITE_API_BASE_URL:-}" \
  VITE_API_ENCRYPT="$API_ENCRYPT" \
  VITE_API_RSA_PUBLIC_KEY="$RSA_PUB_INJECT" \
  env -u NODE_OPTIONS npm run build
)

# 产物自检：开启加密时，入口 bundle 必须真的带上加密分支与目标公钥
if [ "$API_ENCRYPT" != "false" ]; then
  # 公钥指纹：注入值可能是多行 PEM 或 \n 单行，先归一化再取 base64 体前 40 字符
  KEY_MARK="$(printf '%s' "$RSA_PUB_INJECT" | sed 's/\\n//g' | tr -d '[:space:]' \
              | sed 's/^-----BEGINPUBLICKEY-----//; s/-----ENDPUBLICKEY-----$//')"
  KEY_MARK="${KEY_MARK:0:40}"
  ENTRY="$(grep -o 'assets/index-[A-Za-z0-9_-]*\.js' "$FRONTEND_DIR/dist/index.html" | head -1)"
  if [ -z "$KEY_MARK" ]; then
    echo "错误: 公钥指纹解析为空，拒绝回灌（注入值格式不对？）"
    exit 1
  fi
  if grep -q 'X-Encrypt-Data' "$FRONTEND_DIR/dist/$ENTRY" \
     && grep -q "$KEY_MARK" "$FRONTEND_DIR/dist/$ENTRY"; then
    echo "    自检通过: 加密分支已在产物中，且公钥指纹匹配（${KEY_MARK:0:24}…）"
  else
    echo "错误: 产物里没有加密分支或公钥不匹配（entry=$ENTRY, mark=$KEY_MARK）—— 拒绝回灌！"
    exit 1
  fi
fi

echo "==> 回灌产物到 $PUBLIC_DIR"
# installer/ 是插件自带的 Web 安装向导页（InstallerController::PAGE_FILE =
# /installer/index.html，路由 GET /app/curd-installer），**不属于前端构建产物**。
# 清空 public 前先备份，回灌后放回；否则向导页会 404（且该文件会被 git 记为删除）。
INSTALLER_BAK=""
if [ -d "$PUBLIC_DIR/installer" ]; then
  INSTALLER_BAK="$(mktemp -d)"
  cp -R "$PUBLIC_DIR/installer" "$INSTALLER_BAK/installer"
fi

rm -rf "$PUBLIC_DIR"
mkdir -p "$PUBLIC_DIR"
cp -R "$FRONTEND_DIR/dist/." "$PUBLIC_DIR/"

if [ -n "$INSTALLER_BAK" ]; then
  cp -R "$INSTALLER_BAK/installer" "$PUBLIC_DIR/installer"
  rm -rf "$INSTALLER_BAK"
  echo "    已保留 installer/（Web 安装向导页）"
else
  echo "    警告: 未找到 installer/，Web 安装向导页会 404"
  echo "          缺失时用 git 恢复: git checkout -- plugin/curd/public/installer/index.html"
fi

find "$PUBLIC_DIR" -name '.DS_Store' -delete 2>/dev/null || true

echo "==> 完成，产物文件数: $(find "$PUBLIC_DIR" -type f | wc -l | tr -d ' ')，体积: $(du -sh "$PUBLIC_DIR" | cut -f1)"
echo "    资源前缀校验: $(grep -o 'src=\"[^\"]*assets/[^\"]*\"' "$PUBLIC_DIR/index.html" | head -1)"
