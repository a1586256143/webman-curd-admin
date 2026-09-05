# webman-crud（webman 应用插件 · composer 分发版）

自包含的 **CRUD / DSL / Schema 页面引擎 + 登录 / RBAC 中后台底座 + 内置前端** 插件。
插件源码位于本包 `plugin/crud/`，宿主 `composer require` 后经
`support\Plugin::install` 自动拷贝到 `{项目}/plugin/crud`，webman 启动即加载。

> 命名空间 `plugin\crud\app\*`；配置读取 `config('plugin.crud.*')`；
> 路由 `/api/*`（后端 API）与 `/app/crud/*`（内置前端页面，SPA），随插件自动生效。

## 目录结构

```
webman-crud/
├── composer.json          # 包定义（name: huafei/webman-crud）
├── plugin/crud/           # 应用插件源码（自包含，不依赖宿主 app 层）
│   ├── app/
│   │   ├── controller/    # Auth / Menu / Crud / CustomPage / Upload / Options / Page
│   │   │   ├── PageController.php   # 内置前端静态托管 + SPA history fallback
│   │   │   └── base/      # BaseCrudController + Traits + DynamicCrudController
│   │   ├── dsl/           # Grid / Form / Field / Column / Filter / Action
│   │   ├── schema/        # PageSchema / PageRegistry / SchemaNode / blocks/*
│   │   ├── middleware/    # Cors / AuthCheck / PermissionCheck / ApiCrypto（内置）
│   │   ├── rbac/          # casbin Rbac + DatabaseAdapter（内置）
│   │   ├── model/         # CrudConfigs / BaseModel（内置）
│   │   ├── CrudDb.php     # 库连接助手（admin_connection / business_connection）
│   │   ├── CrudConfigRegistry.php
│   │   ├── ModelRegistry.php
│   │   ├── RouteControllerRegistry.php
│   │   ├── bootstrap.php  # 初始化引导（模型/控制器扫描等）
│   │   └── functions.php  # 全局 helper（function_exists 防重）
│   ├── api/Install.php    # 安装器（建表/种子/RSA 密钥，webman 官方 zip 安装钩子兼容）
│   ├── config/            # app.php / route.php / autoload.php / crypto.php
│   │                       # crud.php（宿主接入调参）/ casbin.conf（随插件分发）
│   ├── install.sql        # 8 张核心表 DDL（种子由 Install.php 幂等插入）
│   ├── install-business.sql    # 业务库建表示例（项目自带，**不进**本通用包）
│   ├── install.php        # CLI 引导：php plugin/crud/install.php（一键建表+种子+密钥）
│   │                       # 支持 --business-sql=<path> --business-connection=<name>
│   └── public/            # 内置前端构建产物（vite base=/app/crud/），由 PageController 承载
├── scripts/
│   ├── composer.sh          # composer wrapper：仅对 require/update/install 等触发 audit 的子命令自动 --no-audit
│   ├── release.sh         # 发布脚本：宿主 plugin/crud → 本包 plugin/crud（排除 keys/business sql）
│   ├── build-frontend.sh  # 构建内置前端并回灌 plugin/crud/public/
│   ├── release-zip.sh     # 打 zip 发布包（dist/webman-crud-vX.Y.Z.zip）
│   ├── sync-plugin.sh     # 升级同步：备份宿主 plugin/crud 后从 vendor 重拷最新插件文件
│   └── check-plugin-overrides.sh  # 升级前 diff：检测宿主 plugin/crud 本地改动
├── src/Install.php        # composer 安装器（webman 2.x 官方机制：拷贝 plugin/crud + 生成 config）
├── docs/
│   ├── new-project-checklist.html  # 新项目接入流程表（P0–P8 + 高频坑位速查，当前为 v1.0.8）
│   └── deploy.md          # 生产部署指南（首次安装 / supervisor / Nginx / 备份 / 回滚）
```

## 在宿主 webman 项目中安装

前置：宿主 `composer.json` scripts 需含（webman 官方骨架自带，缺失则补）：

```json
"scripts": {
  "post-package-install": ["support\\Plugin::install"],
  "post-package-update":  ["support\\Plugin::install"],
  "pre-package-uninstall":["support\\Plugin::uninstall"]
}
```

以及 `support/Plugin.php`（官方骨架文件；本项目 webman-huafei-platform 已补齐）。

```bash
# 方式 A：本地 path（开发期，发布后去掉 repositories 配置）
composer config repositories.crud path "../webman-crud"
composer require huafei/webman-crud:@dev

# 方式 B：私有 Git（推荐上线使用，已 git init + tag v1.0.x）
#   仓库托管于 Gitee：https://gitee.com/colingit/webman-curd-admin.git
composer config repositories.crud vcs "https://gitee.com/colingit/webman-curd-admin.git"
composer require huafei/webman-crud:^1.0 --no-audit   # 走 tag 版本，避免 @dev 漂移
#   ⚠️ audit 红字（国内访问 packagist.org 失败）只是尾部告警、exit 0 不影响安装；
#   不想每次手打 --no-audit：alias composer='<webman-crud>/scripts/composer.sh'（见底部 FAQ）

# 方式 C：私有 Satis / Packagist（团队统一，无需每个项目配 repositories）
#   本仓库已附 satis.json 模板（指向本 Gitee 仓库），放到 Satis 服务器执行
#   `satis build satis.json web/` 后部署；团队统一配一次源即可直接 require：
#     composer config repositories.crud-satis composer "https://satis.your-company.com"
#     composer require huafei/webman-crud
#   记得把 satis.json 里的 homepage / Gitee URL 改成你的真实地址。
```

> 本包已初始化 git 仓库并打 `v1.0.0+` 标签；上线建议走 **方式 B**（VCS + 版本约束），
> 升级时用 `composer update huafei/webman-crud` 拉新 tag。
> ⚠️ 升级后 `plugin/crud` 文件本体不会自动更新（src/Install.php 仅在目录不存在时拷贝，
> 以保护本地改动）——需要同步最新插件文件时执行 `bash scripts/sync-plugin.sh`
> （先备份旧 plugin/crud 再整体重拷），详见下方「升级安全」。

> 若 `{项目}/plugin/crud` 已存在（本地开发版），安装器会跳过拷贝、保留本地版本，
> 由 `scripts/release.sh` 负责把本地改动回灌到本包。

## 新项目开箱跑通（完整步骤）

> 前置：webman 骨架 + PHP ≥8.1 + MySQL。默认**单库模式**：认证库与业务库放同一数据库。
> 安装后 config/database.php 提供 mysql（认证）与 mysql_business（业务）两个连接名，
> 单库模式下二者指向同一库（业务库名取值链：config/crud.php business_db → .env DB_BUSINESS_NAME → 认证库名）。

```bash
# 1) 装插件（composer require 自动完成：① 拉齐依赖 webman/database + webman/redis + casbin/casbin
#    + vlucas/phpdotenv；② webman 官方插件机制自动拷贝 plugin/crud 到宿主；
#    ③ 宿主缺 config/database.php 自动生成；④ 宿主缺 config/crud.php 自动生成集中配置入口）
#
#    ⚠️ composer audit 报红是已知问题：audit 硬编码访问 packagist.org（不走镜像、国内常不可达），
#    报 "Failed to audit installed packages." 仅是尾部告警——命令本身成功（exit 0）。
#    消除红字：用本包提供的 wrapper `scripts/composer.sh`（仅对会触发 audit 的
#    require/update/install 等子命令自动追加 --no-audit），或在本包目录执行后在你的 shell 加
#    `alias composer='<webman-crud>/scripts/composer.sh'`。详见底部「audit 红字彻底消除」一节。
composer require huafei/webman-crud:^1.0
# 验证：ls plugin/crud config/database.php config/crud.php
#   兜底（极少见自动拷贝未触发）：composer dump-autoload && composer update huafei/webman-crud
#   或用本包 wrapper：./scripts/composer.sh update huafei/webman-crud
#   或 cp -r vendor/huafei/webman-crud/plugin/crud plugin/crud

# 2) 启动服务（先启动，再装库 —— Web 向导模式需要服务在线）
#    端口默认 8787，位置 config/process.php 的 'listen' 行
php start.php start

# 3) 方式一【推荐】Web 安装向导：浏览器打开
#    http://host:port/app/crud-installer
#    填：数据库连接（主机/端口/库名/账号/密码；库不存在自动建）
#        管理员账号密码（初始管理员，非固定 admin/admin123）
#    自动完成：DB 信息写入 .env（按键合并，绝不覆盖 .env 其它内容）+
#             同步写入 config/crud.php 的 database 段（修复 worker 启动后 .env 无法刷新
#             导致的 1045 Access denied）→ 子进程执行 install.php（自动建库/建表/种子/密钥）
#             → 页面实时显示执行进度
#    ⚠️ 完成后会提示执行：php start.php restart
#       —— worker 配置在启动时固化，必须重启才加载新 DB 配置（不重启会报 1045）
#    安装成功生成 config/crud-installed.lock，向导自动失效（重复访问提示已安装）；
#    如需重装：删锁 + 清库后刷新页面。
#
#    方式二【命令行】（Web 向导的等价手动流程）：
#    # 2a) 配置数据库（DB 写 .env 或 config/crud.php database 段，二选一）
#    #     单库模式只需一个库名；分库再填 business_db / DB_BUSINESS_NAME
#    # 2b) 一键安装（幂等；自动建库；自定义管理员；进度可落文件）
#    #     默认只种【超级管理员】角色，其它角色登录后台按需新增
#    php plugin/crud/install.php --admin-user=admin --admin-pass=你的密码
#    #    可选：--progress-file=runtime/i.log（JSONL 进度）、--business-sql=xxx

# 4) 打开后台（插件自带前端，无需再部署任何静态资源）
#    http://host:port/app/crud/        → 登录页（用第 3 步填的管理员账号）
#    登录后：用户/角色/菜单管理、配置生成器（DSL 拖表单生成 CRUD）、
#            自定义页面引擎全部可用
```

之后写业务：

```php
// 1) 模型 app/model/APackage.php（自动扫描进 ModelRegistry）
// 2) 控制器（可选专属，extends 插件基类即获得整套 CRUD 能力）
namespace app\controller\api;
use plugin\crud\app\controller\base\BaseCrudController;
class PackageController extends BaseCrudController {
    protected string $modelClass = \app\model\APackage::class;
    protected string $title = '套餐管理';
}
// 3) 菜单/权限在管理后台登记（menus + casbin_rule），前端即可见可用
```

### 业务库表结构（可选）

`install.sql` 默认只建认证/管理面 8 张表（admin_users / admin_tokens / roles /
admin_role_user / role_permission / casbin_rule / crud_configs / menus）。
业务表（如 `hf_goods` / `mobile_recharge_orders` 等项目专有表）由项目自带：

1. 项目根目录放 `install-business.sql`，语句间 `--SPLIT--` 分隔，`CREATE TABLE
   IF NOT EXISTS` 保证幂等。**不**打包进 `huafei/webman-crud` 通用包。
2. 跑 `install.php` 时通过 `--business-sql` 传参：

```bash
# 单个文件
php plugin/crud/install.php --business-sql=install-business.sql

# 多个文件（可重复或逗号分隔）
php plugin/crud/install.php \
  --business-sql=sql/admin.sql \
  --business-sql=sql/business.sql

# 临时切换业务库连接（默认 config('plugin.crud.crud.business_connection')，默认 mysql_business）
php plugin/crud/install.php \
  --business-sql=install-business.sql \
  --business-connection=mysql_business_test
```

执行机制：`Install::install(false, $extraSqls)` → `Install::runSqlFile($content, $db, true)`
（先把内容读入再传，避免路径被当 SQL 语句）。每条额外 SQL 用各自指定的 DB 连接
（默认走 `business_connection`），与认证库完全隔离。

幂等：所有表用 `IF NOT EXISTS`，重复执行全部 SKIP；种子按表空判定，重复执行全部 SKIP；
RSA 密钥已存在跳过生成。

### 业务种子模板（菜单 / 权限自动登记）

新环境「装完即带业务菜单」可把菜单/权限 INSERT 直接追加进业务 SQL。本包提供
`plugin/crud/install-business.example.sql` 作模板（建表 + `menus` / `casbin_rule`
种子示例，全 `INSERT IGNORE`），复制为项目自有 `install-business.sql` 后改表名/菜单即可。

### 增量 migration（表结构演进）

业务表结构变更用**只追加**的迁移文件，由 `plugin/crud/migrate.php` 驱动：

```bash
# 文件命名：plugin/crud/migrations/V{版本}__{描述}.sql，版本号升序，--SPLIT-- 分隔多语句
php plugin/crud/migrate.php                       # 跑全部未执行迁移
php plugin/crud/migrate.php --connection=mysql   # 指定连接
php plugin/crud/migrate.php --dry-run            # 预检
```

- 已执行版本记录进 `{连接库}._crud_migrations`（唯一约束，幂等）
- 已发布版本**只追加不修改**；改结构请新建更高版本号文件
- 详见 `plugin/crud/migrations/README.md`

### 配置模板

- **新项目（推荐）**：首次 `composer require` 自动生成宿主 `config/crud.php`
  （`database` 段 + 插件调参），所有配置写在此文件即可，**不动宿主 `.env`**。
- **传统 .env 方式**：`plugin/crud/env.example` 列有全部键名与示例值（`DB_*` /
  `CRUD_*` / 前端构建期变量），可整段拷进宿主 `.env`（勿整文件覆盖）。

## 内置前端说明

- 构建：`frontend` 子工程以 `VITE_BASE_PATH=/app/crud VITE_API_BASE_URL= VITE_API_ENCRYPT=false npm run build`
  产物整体放入 `plugin/crud/public/`（当前包内已内置一版开箱即用）。
- 承载：`plugin/crud/app/controller/PageController.php` 把 `/app/crud/*` 真实文件按静态
  返回，其余路径（vue-router history 路由）回退 `index.html`；`PageController` 做了路径
  穿越防护。
- 前缀可整体改：`CRUD_PAGE_BASE` (.env) 或 `crud.page_base`（默认 `/app/crud`），
  改后需用相同 `VITE_BASE_PATH` 重新构建前端。
- 存量 OSS/CDN 部署不受影响：不设置 `VITE_BASE_PATH` 时 vite base 与旧版一致（`/` 或 OSS URL）。

## 接口加密（可选）

- 默认关闭（内置前端 `VITE_API_ENCRYPT=false` 构建，明文直连；后端对明文请求透传）。
- 开启：`plugin/crud/install.php` 已生成 `config/keys/` 密钥对；把 `api_rsa_public.pem`
  内容替换进前端 `src/utils/apiCrypto.js` 的 `RSA_PUBLIC_KEY`，以 `VITE_API_ENCRYPT=true`
  重新构建，并在 `.env` 设 `API_ENCRYPT=true`（前后端需一致）。

## 宿主接入调参（.env 优先）

> ⚠️ **配置覆盖方式**：本插件以「根目录应用插件」形式分发（`{项目}/plugin/crud`），
> 而 webman 的配置加载顺序是 `config/` 先、`plugin/*/config` 后
> （见 `support/App::loadAllConfig`）。**插件自带配置会覆盖 `config/plugin/crud/crud.php`**，
> 所以调参请走 **`.env`**（下表标注 (.env) 的项）或直接改 `plugin/crud/config/crud.php`
> （升级插件会被覆盖，不推荐）。

| 配置键 | 默认 | 说明 |
| --- | --- | --- |
| `CRUD_ADMIN_CONNECTION` (.env) | `mysql` | 认证/菜单/RBAC 库连接名（admin_users/menus/crud_configs/casbin_rule） |
| `CRUD_BUSINESS_CONNECTION` (.env) | `mysql_business` | 业务模型 CRUD 默认库连接名 |
| `crud.model_dir` | `{根}/app/model` | 宿主业务模型扫描目录 |
| `crud.model_namespace` | `app\model` | 模型命名空间 |
| `crud.controller_dirs` | `app/controller/api` + `app/controller/admin/api` | CRUD 业务控制器扫描目录（继承 BaseCrudController 自动关联模型） |
| `crud.vue_pages_dir` | `{根}/app/custom/pages` | 宿主业务 `.vue` 页面目录 |
| `crud.base_model_class` | `plugin\crud\app\model\BaseModel` | 动态生成模型的基类（默认插件内置，宿主可改回自己的增强版） |
| `crud.page_base` | `/app/crud` | 内置前端 URL 前缀（与前端 VITE_BASE_PATH 一致） |
| `crud.public_dir` | `plugin/crud/public` | 内置前端产物目录 |
| `crud.casbin_model_path` | 插件自带 `config/casbin.conf` | casbin 模型文件 |
| `crud.allow_unresolved` | `true` | 推导不出权限标识的路径：true=仅登录放行 / false=403 |
| `CRUD_ADMIN_REQUIRE_PERMISSION` (.env) | `false` | `/api/admin/*`（用户/角色/个人中心）是否强制 RBAC 校验，见下 |

### 权限收紧（新项目建议开启）

`/api/admin/*`（用户增删改、角色授权）默认**仅要求登录**即可访问（与历史行为一致）。
新项目可在 `.env` 加一行收紧：

```bash
CRUD_ADMIN_REQUIRE_PERMISSION = true
```

开启后 `/api/admin/*` 走 casbin 校验，权限标识由路径推导
（`/api/admin/users` → `obj=admin`、`act=users`）：

- `admin` 角色有 `p,admin,*,*` 通配策略 → 不受影响
- 其余角色默认 403（响应体 `{"code":403,...}`，HTTP 仍 200，与项目既有风格一致）
- 授权方式：在「角色管理」勾选，或直接写 casbin_rule
  `INSERT INTO casbin_rule (ptype,v0,v1,v2) VALUES ('p','yunying','admin','users');`
- `/api/auth/*`、`/api/menu`、`/api/crud/config`、`/api/custom/*` 仍在白名单（仅登录）

## 路由「宿主优先」机制

webman 的 `Route` 对「同 method + 同 path」重复注册会**直接抛异常**
（`Route conflict`，见 `Route::addRoute`）。插件因此统一用 `crud_route()`
注册路由：注册前检测该路径是否已被注册，已注册则**跳过**，保留宿主实现。

- 路由加载顺序：`宿主 config/route.php` → `plugin/{name}/config/route.php`
  （`support/bootstrap.php`）
- 存量项目（自己注册过 `/api/admin/*`、`/api/config/site` 等）：装插件不冲突、行为不变，
  继续走宿主控制器
- 新项目：无同名路由，插件路由全部生效（`/api/auth/*`、`/api/admin/*`、`/api/menu/*`、
  `/api/crud/*`、`/api/custom/*`、`/api/options/*`、`/api/upload`、`/app/crud/*`）

## 发布（zip / GitHub Release）

```bash
# 本地打 zip（排除 keys / install-business.sql / release-zip.sh 自身）
./scripts/release-zip.sh 1.0.0
# → dist/webman-crud-v1.0.0.zip（约 1.1M，130 文件，解压即用）

# 发布到 GitHub：
git tag v1.0.0 && git push origin v1.0.0
# → .github/workflows/release.yml 自动跑：
#   composer validate --strict → 全量 php -l → 前端 dist 完整性校验
#   → 关键文件存在性校验 → release-zip.sh → GitHub Release 附 zip
```

> CI 不跑 npm：内置前端由开发者本地 `./scripts/build-frontend.sh` 构建后提交 dist，
> CI 只校验产物完整性（`index.html` + `assets/` + 文件数 > 10）。

### 升级安全（避免本地改动被静默覆盖）

安装器策略：`plugin/crud` 已存在 → **跳过拷贝**（保护本地改动），因此
`composer update huafei/webman-crud` 只会更新 `vendor/` 里的包，不会动宿主
`plugin/crud` 与 `config/` 下的文件。需要把新版插件文件同步到宿主时：

```bash
# 1) 升级前先检查宿主 plugin/crud 是否有本地改动
./scripts/check-plugin-overrides.sh <项目根>   # 0=无本地改动 1=发现改动

# 2) 确认无本地改动（或已备份）后，同步最新插件文件：
bash <项目根>/scripts/sync-plugin.sh
#    = 备份 plugin/crud → plugin/crud.bak-<时间戳>，再从 vendor 整体重拷
#    宿主 config/crud.php 与 config/database.php 已存在则不会被覆盖（安装器跳过已有文件）
```

发现改动时，先 `git stash` / 提交 / 把改动迁回插件配置化，再升级。
若你只改前端，用 `./scripts/build-frontend.sh` 重建内置 dist，不必改 `plugin/crud` 源码。

### 生产部署

见 `docs/deploy.md`：supervisor / systemd 守护、Nginx 反代（`/app/crud/` 同域免 CORS）、
`.env` 环境隔离、`config/keys/` 备份策略、升级回滚预案。

## 与本项目 webman-huafei-platform 的关系

本包由 `webman-huafei-platform/plugin/crud` 发布而来：

- **M1**（自包含下沉）：middleware/rbac/model/helpers/casbin.conf 均内置，插件不再依赖
  宿主 `app\middleware`、`app\rbac`、`app\functions.php`、`app\model\CrudConfigs`；
  宿主业务代码零改动，行为不变。
- **M2**（开箱即用）：`install.sql` + `api/Install.php` + `install.php` 一键建表/种子/密钥；
  前端 dist 内置 `public/`，`/app/crud/` 直接打开完整后台。
- **M3**（管理面内置 + 独立部署验证）：用户/角色/个人中心 `AdminController` 下沉进插件并
  注册路由（宿主同名路由优先，零行为变化）；新增 `admin_require_permission` 收紧开关；
  路由注册改走 `crud_route()` 冲突检测（修掉"重复注册抛 Route conflict"的崩溃点）；
  在全新空 webman 项目上完成端到端验证。

### 端到端验证记录（M3）

空 webman 骨架 + 本插件，全新库，`php plugin/crud/install.php` 后启动：

| 项 | 结果 |
| --- | --- |
| 建表 / 种子 / RSA 密钥 / 幂等重跑 | 8 表 + roles(3) + menus(6) + admin 账号 + 密钥，二次执行全 SKIP |
| `/app/crud/`、`/app/crud/assets/*.js`、`/app/crud/dashboard`(SPA 深链) | 200，资源前缀 `/app/crud/` |
| 登录 `admin/admin123` → token + `roles:[admin]` + `permissions:[{*,*}]` | 200 |
| `/api/auth/me` `/api/admin/profile` `/api/admin/users` `/api/admin/roles` `/api/admin/roles/options` | 200 |
| `/api/menu` `/api/menu/all` `/api/crud/models` `/api/crud/tables` `/api/crud/config/all` `/api/custom/pages` | 200 |
| 新增用户 → 自动写 `admin_role_user` + casbin `g`；新账号登录受权限约束 | 200 |
| 开启 `CRUD_ADMIN_REQUIRE_PERMISSION=true` 后普通角色访问 `/api/admin/users` | body 403；授权后恢复 200 |
| 宿主项目（webman-huafei-platform）回归 | `/api/admin/*`、`/api/config/site` 仍指向宿主控制器，无冲突异常 |
| M4 业务库建表（`--business-sql=plugin/crud/install-business.sql`） | 13 张表（8 认证 + 5 业务 hf_goods / mobile_recharge_orders / hf_order_notifies / hf_platform_accounts / hf_accoount_waters），3 次重跑全 OK + SKIP；`--business-connection=mysql` 覆盖生效；不带 `--business-sql` 时默认只装 8 张认证表（行为不变） |

### 待办（M4+）— 已全部闭合

- [x] **M5 宿主旧副本下线** → `app/middleware/*`、`app/rbac/*`、`app/functions.php` 已切到
      插件内置版并删除（`StaticFile.php` 宿主自有保留）；行为等价验证通过（见 M5 验证记录）
- [x] **发布渠道** → 包已 `git init` + tag（v1.0.8），支持私有 Git(VCS) / Satis / 本地 path 三种引入方式
- [x] **升级覆盖防护** → `scripts/check-plugin-overrides.sh` 升级前检测项目内本地改动；
      `scripts/sync-plugin.sh` 备份后从 vendor 重拷最新插件文件（composer update 不再覆盖 plugin/crud）
- [x] **前端构建发布一体化** → `scripts/build-and-release.sh`（build-frontend + release-zip）
- [x] **业务种子模板** → `plugin/crud/install-business.example.sql`（菜单/权限自动登记示例）
- [x] **增量 migration** → `plugin/crud/migrate.php` + `migrations/`（`_crud_migrations` 跟踪，幂等）
- [x] **配置模板** → 宿主 `config/crud.php`（composer require 自动生成，数据库与插件调参集中入口，不覆盖 .env）；
      传统 .env 键名见 `plugin/crud/env.example`
- [x] **部署与备份** → `docs/deploy.md`（supervisor + Nginx + 备份策略 + 回滚预案）
- [x] **update 钩子演练** → `Install::update()` 全链路 + 幂等复跑已验证（8 表 + 种子 + 密钥，重跑全 SKIP）

> 历史坑沉淀：① webman `app/functions.php` 是框架约定加载点，删除须同步改 `config/autoload.php`
> ② 测试脚本**绝不可写宿主 `.env`**（曾误删真实 .env 导致 PDO 空连接）；③ `php start.php restart`
> 杀会话进程组须用 `start`；④ cwd 在脚本间不持久，凡涉及路径操作一律显式 `cd` 绝对路径。

---

## 常见问题（FAQ）

### `composer audit` 红字（Failed to audit installed packages）能否彻底消除？

**结论**：包内无法彻底消除（composer 没有全局 config 关闭 audit），但提供**零侵入的 wrapper**。

- **原理**：`composer require/update` 末尾会跑 `composer audit`，硬编码访问 `packagist.org` 的
  `security-advisories` 端点（不走国内镜像）。国内/内网 packagist.org 不可达时就会报这条红字；
  命令本身 **exit code = 0**，不影响安装。
- **根治**：让 `packagist.org` 可达（配置全局代理或 hosts）。
- **包内方案**（推荐）：使用本包 `scripts/composer.sh` 替代系统 `composer`：

```bash
# 一次性：临时用一次
./scripts/composer.sh require huafei/webman-crud:^1.0
./scripts/composer.sh update

# 永久：把系统的 composer alias 成 wrapper（推荐）
echo "alias composer='$(pwd)/scripts/composer.sh'" >> ~/.zshrc
source ~/.zshrc
# 之后 require/update/install 等会自动追加 --no-audit（show/info 等不受影响），红字消失
```

### 为什么还保留 `mysql_business` 连接（默认指向同一个库）？

- **历史原因**：插件代码按「认证库 / 业务库」分离设计，`CRUD_BUSINESS_CONNECTION=mysql_business` 是默认
  业务模型 CRUD 用的连接名。改这一处会动大量业务代码、破坏向后兼容。
- **单库模式**（默认）：`mysql_business.database` 回退到认证库名 → 两个连接**指向同一库**，零开销。
- **想分库**：在 `config/crud.php` 的 `database.business_db` 填不同库名，向导重装或手动改即可生效。
- **不要它**：在 `config/database.php` 里删掉 `'mysql_business' => [...]` 整块代码即可（业务代码里
  出现 `Db::connection('mysql_business')->...` 时改成 `mysql`，或在每个模型里 `protected $connection = 'mysql';`）。

### 登录后台报 `Class "support\Redis" not found`

本包已 require `webman/redis`（v1.0.8+），`composer require huafei/webman-crud:^1.0` 会自动拉齐。
若你用的是旧版（v1.0.7 及以前），手动补一行：

```bash
composer require webman/redis
```

Redis 不可用时 `AuthController / Rbac / AuthCheck / AdminController` 已全部 `try/catch` 降级（直查 DB），
不影响登录鉴权。

### 向导完成后后台报 `SQLSTATE[HY000] [1045] Access denied for user '...' (using password: NO)`

**根因**：webman worker 启动时 `config/database.php` 已 `require` 加载（`.env` 同时 `putenv` 固化）。
**wizard 写文件后，运行期内存里的 `env('DB_PASSWORD')` 仍是旧值**（通常空），导致心跳 `select 1` 用空密码重连失败。

**修法**（v1.0.8+）：向导已把 DB 信息**同时写入 `config/crud.php` 的 `database` 段**（不走 .env），
但**仍需重启 worker** 让配置生效。向导完成后会提示执行：

```bash
php start.php restart
```

之后心跳连接用 `config/crud.php` 里的真密码，`1045` 消失。
