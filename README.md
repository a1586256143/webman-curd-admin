# webman-curd-admin（webman 应用插件 · composer 分发版）

自包含的 **CURD / DSL / Schema 页面引擎 + 登录 / RBAC 中后台底座 + 内置前端**。
宿主一条 `composer require`，插件经 webman 官方机制自动拷到 `{项目}/plugin/curd`，启动即用 ——
**不需要单独部署前端静态资源，也不需要手写 List / Form / API 代码**。

> 命名空间 `plugin\curd\app\*`；配置读取 `config('plugin.curd.*')`；
> 路由 `/api/*`（后端 API）与 `/app/curd/*`（内置前端 SPA），随插件自动生效。
>
> 仓库：GitHub `a1586256143/webman-curd-admin` · Gitee 镜像 `colingit/webman-curd-admin`
> 包名：`amcolin/webman-curd-admin`

## 文档

📖 **文档站**：浏览器直接打开 **`docs/index.html`**（自包含单页，无需服务器）。

| 篇目 | 内容 |
|---|---|
| [总览与导航](docs/00-总览与导航.md) | 能力清单、按场景索引、**两类 DSL 与文件归属**、目录结构、环境要求、后台界面导览 |
| [快速开始](docs/01-快速开始.md) | 引包三选一 → 初始化（Web 向导 / CLI）→ 全局中间件 → 启动验证 → 第一个 CURD → 业务建表 / 种子 / migration |
| [配置参考](docs/02-配置参考.md) | 配置加载与覆盖规则、数据库连接、**插件调参 + 宿主管家键全量表**、路由「宿主优先」 |
| [DSL · Schema 页面](docs/03-DSL-Schema页面.md) | 数组 DSL 拼展示型页面：布局块 / 容器块 / 统计卡 / 折线图 / 数据绑定 / 自定义 `.vue` |
| [DSL · CURD 页面](docs/04-DSL-CURD页面.md) | `grid()` 五步建列表页：列 / 搜索 / 表单 / 场景字段 / 自定义 Action / Excel 导入 / 汇总条 / 导出 |
| [菜单与权限](docs/05-菜单与权限.md) | 菜单 path 约定、一键生成权限、403 不泄露结构、默认落地页、两个权限开关的差别 |
| [认证与登录](docs/06-认证与登录.md) | `auth_provider` 换登录来源、`login_handler` 接管登录接口、`auth_state_guard` 状态钩子、图形验证码 |
| [接口加密与异常兜底](docs/07-接口加密与异常兜底.md) | RSA+AES 信封开关与构建、公钥每宿主一份、线上 `/api` 统一 500 + 编号、日志一行一条 |
| [升级 · 部署 · 发布](docs/08-升级与生产部署.md) | 升级安全与同步脚本、卸载、supervisor / Nginx / 密钥 / 备份 / 回滚、打 zip 与 CI |
| [排错速查与 FAQ](docs/09-排错速查与FAQ.md) | 按现象查：安装 / 运行 / 权限 / DSL / 登录 / 加密 / 日志 / 升级 + 常见问题 |

> 文档站由 `docs/tools/build_docs.cjs` 生成（需 `marked`）：改完 md 后在 `docs/` 执行
> `node tools/build_docs.cjs` 重新生成 `index.html`。文档之间请用 `[标题](NN-主题.md)`
> 互引 —— 在 GitHub 上是文件链接，在文档站里会被自动接管为**页内跳转**。

安装的极简版见 **[INSTALL.md](INSTALL.md)**。

## 60 秒上手

```bash
# ① 建骨架（已有项目跳过）
composer create-project workerman/webman my-admin && cd my-admin

# ② 引包（换成本包的仓库地址 / 本地路径）
composer config repositories.curd vcs "https://gitee.com/colingit/webman-curd-admin.git"
composer require amcolin/webman-curd-admin:^1.0 --no-audit

# ③ 启动（先启动、再装库 —— Web 向导需要服务在线；端口默认 8787）
php start.php start

# ④ 初始化：浏览器打开安装向导，填数据库连接 + 管理员账号
#    http://127.0.0.1:8787/app/curd-installer
#    （装完自动 SIGUSR1 平滑 reload，新 DB_* 即刻生效、无需手动重启）

# ⑤ 打开后台（内置前端，无需再部署静态资源）
#    http://127.0.0.1:8787/app/curd/
#    http://127.0.0.1:8787/app/curd/curd-generator   ← 配置生成器：选表→配字段→填路径→保存
```

之后写业务：`app/model/APackage.php`（模型）→ 继承 `BaseCurdController` 的控制器 →
`RouteControllerRegistry::registerMany([...])` 注册路由 → 后台「菜单管理」登记菜单与授权。
完整走法见 [快速开始](docs/01-快速开始.md)。

> ⚠️ `composer audit` 红字（`Failed to audit installed packages.`）是已知尾部告警：
> audit 硬编码访问 `packagist.org`，国内常不可达，命令本身 `exit 0`、不影响安装。
> 用包内 wrapper 彻底消除：`alias composer='<包目录>/scripts/composer.sh'`。

## 环境要求

| 项 | 要求 |
|---|---|
| PHP | ≥ 8.1 |
| 框架 | webman（`workerman/webman-framework` ^2.1，官方骨架即可） |
| 数据库 | MySQL —— **单库架构**（认证表与业务表同库） |
| PHP 扩展 | `ext-gd` + `ext-mbstring`（登录图形验证码用；不需要可 `'captcha_enabled' => false`） |
| 依赖 | `composer require` 自动拉齐 `webman/database` `webman/redis` `casbin/casbin` `vlucas/phpdotenv` `webman/captcha` |

宿主 `composer.json` 需含 webman 官方插件三件套（官方骨架自带）：

```json
"scripts": {
  "post-package-install":  ["support\\Plugin::install"],
  "post-package-update":   ["support\\Plugin::install"],
  "pre-package-uninstall": ["support\\Plugin::uninstall"]
}
```

## 目录结构

```
webman-curd-admin/
├── composer.json          # 包定义（name: amcolin/webman-curd-admin）
├── README.md              # 本文件（门面 / 速上手 / 文档索引）
├── INSTALL.md             # 安装说明（离线随包分发）
├── plugin/curd/           # ★ 应用插件源码（自包含，不依赖宿主 app 层）
│   ├── app/
│   │   ├── controller/    # Auth / Menu / Curd / CustomPage / Upload / Options / Page
│   │   │   ├── PageController.php   # 内置前端静态托管 + SPA history fallback
│   │   │   └── base/      # BaseCurdController + Traits + DynamicCurdController
│   │   ├── dsl/           # Grid / Form / Field / Column / Filter / Action
│   │   ├── schema/        # PageSchema / PageRegistry / SchemaNode / blocks/*
│   │   ├── middleware/    # Cors / AuthCheck / PermissionCheck / ApiCrypto（内置）
│   │   ├── exception/     # CurdExceptionHandler（/api 未捕获异常兜底）
│   │   ├── rbac/          # casbin Rbac + DatabaseAdapter（内置）
│   │   ├── model/         # CurdConfigs / BaseModel（内置）
│   │   ├── CurdDb.php     # 库连接助手（admin_connection / business_connection）
│   │   ├── ModelRegistry.php / RouteControllerRegistry.php
│   │   ├── bootstrap.php  # 初始化引导（模型 / 控制器扫描等）
│   │   └── functions.php  # 全局 helper（function_exists 防重）
│   ├── api/Install.php    # 安装器（建表 / 种子 / RSA 密钥）
│   ├── config/            # app.php / route.php / autoload.php / crypto.php / curd.php / casbin.conf
│   ├── install.sql        # 核心表 DDL + my_test 示例表段
│   ├── install.php        # CLI 引导：php plugin/curd/install.php
│   ├── migrate.php        # 增量 migration 引导（migrations/V{n}__*.sql）
│   ├── env.example        # 传统 .env 方式的键清单
│   └── public/            # ★ 内置前端构建产物（vite base=/app/curd/）
├── examples/              # 后台 CURD 示例（MyTestController + MyTest），安装器幂等落位到宿主根
├── scripts/
│   ├── composer.sh                  # composer wrapper（对触发 audit 的子命令自动 --no-audit）
│   ├── release.sh                   # 宿主 plugin/curd → 本包 plugin/curd（回灌）
│   ├── build-frontend.sh            # 构建内置前端并回灌 plugin/curd/public/
│   ├── release-zip.sh               # 打 zip 发布包（dist/webman-curd-admin-vX.Y.Z.zip）
│   ├── sync-plugin.sh               # 升级同步：备份宿主 plugin/curd 后从 vendor 重拷
│   └── check-plugin-overrides.sh    # 升级前 diff：检测宿主 plugin/curd 本地改动
├── src/Install.php        # composer 安装器（拷贝 plugin/curd + 落位 examples + 生成 config）
├── docs/                  # ★ 文档站（index.html 自包含；md 源 + tools/build_docs.cjs）
│   └── _legacy/           # 旧版文档归档（不参与构建）
└── .github/workflows/     # release.yml（打 tag 自动校验 + 出 Release）
```

## 开发与发布

```bash
# 宿主侧改动回灌到包
./scripts/release.sh

# 重建内置前端（VITE_API_ENCRYPT=true 时开接口加密，公钥自动从目标宿主读取并校验）
VITE_API_ENCRYPT=true CURD_PUBLIC_DIR=<宿主>/plugin/curd/public \
  bash scripts/build-frontend.sh <前端源码目录>

# 打 zip 发布包（含 docs/ 文档站与 INSTALL.md）
./scripts/release-zip.sh 1.1.0        # → dist/webman-curd-admin-v1.1.0.zip

# 发版：打 tag 触发 CI（composer validate → php -l → 前端 dist 完整性 → 关键文件校验 → Release）
git tag v1.1.0 && git push origin v1.1.0
```

升级与部署的完整流程（`check-plugin-overrides.sh` / `sync-plugin.sh` / supervisor / Nginx /
备份回滚）见 [升级 · 部署 · 发布](docs/08-升级与生产部署.md)。

## 与本项目 webman-huafei-platform 的关系

本包由 `webman-huafei-platform/plugin/curd` 发布而来，演进分三阶段（**M1** 自包含下沉 →
**M2** 开箱即用 → **M3** 管理面内置 + 空项目端到端验证，另有 **M5** 宿主旧副本下线）。
宿主开发期的两条命令仍是 `scripts/release.sh`（回灌）与 `scripts/build-frontend.sh`（重建前端）。
详细记录见 [升级 · 部署 · 发布](docs/08-升级与生产部署.md) §6。
