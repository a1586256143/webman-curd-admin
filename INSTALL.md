# webman-curd-admin 安装使用说明

> **适用版本：v1.1.0**（全量 curd 品牌化后的最新架构）
> 插件目录 `plugin/curd` · 命名空间 `plugin\curd\app\*` · 前端前缀 `/app/curd` · 配置表 `curd_configs`

自包含的 **CURD / DSL / Schema 页面引擎 + 登录 / RBAC 中后台底座 + 内置前端**。
宿主只需 `composer require`，webman 官方插件机制自动把插件拷到 `{项目}/plugin/curd`，启动即用，**无需部署任何前端静态资源**。

- 仓库：GitHub `git@github.com:a1586256143/webman-curd-admin.git`（tag `v1.1.0`）
- Gitee 镜像：`https://gitee.com/colingit/webman-curd-admin.git`
- 包名：`amcolin/webman-curd-admin`

---

## 1. 环境要求

| 项 | 要求 |
|---|---|
| PHP | ≥ 8.1 |
| 框架 | webman（`workerman/webman-framework` ^2.1，官方骨架即可） |
| 数据库 | MySQL（单库架构：认证库与业务库**同一库**，无分库配置项） |
| 其它 | composer require 会自动拉齐 `webman/database` `webman/redis` `casbin/casbin` `vlucas/phpdotenv`，无需手动装 |

宿主 `composer.json` 需含 webman 官方插件的三个 scripts（官方骨架自带，缺失则补）：

```json
"scripts": {
  "post-package-install":  ["support\\Plugin::install"],
  "post-package-update":   ["support\\Plugin::install"],
  "pre-package-uninstall": ["support\\Plugin::uninstall"]
}
```

并确认存在 `support/Plugin.php`（官方骨架文件；缺失时从 webman 骨架仓库补）。

---

## 2. 引入插件（三选一）

### 方式 A：本地 path（开发期联调，发布后移除 repositories）

```bash
composer config repositories.curd path "../webman-curd-admin"
composer require amcolin/webman-curd-admin:@dev
```

### 方式 B：私有 Git VCS（推荐上线使用，走 tag 版本）

```bash
composer config repositories.curd vcs "git@github.com:a1586256143/webman-curd-admin.git"
# 或走 Gitee（国内拉取更快）：
# composer config repositories.curd vcs "https://gitee.com/colingit/webman-curd-admin.git"

composer require amcolin/webman-curd-admin:^1.0 --no-audit
```

> ⚠️ `composer audit` 红字（`Failed to audit installed packages`）是**已知尾部告警**：
> audit 硬编码访问 packagist.org、不走国内镜像，命令本身 exit 0、不影响安装。
> 不想每次手打 `--no-audit`：用本包自带 wrapper `scripts/composer.sh`（只对 require/update/install
> 等子命令自动追加 `--no-audit`），或 `alias composer='<包目录>/scripts/composer.sh'`。

### 方式 C：私有 Satis / Packagist（团队统一源）

仓库已附 `satis.json` 模板（把 homepage / Gitee URL 改成真实地址），`satis build satis.json web/` 部署后：

```bash
composer config repositories.curd-satis composer "https://satis.your-company.com"
composer require amcolin/webman-curd-admin
```

### 安装后自动完成（安装器 `src/Install.php` 幂等执行）

1. `plugin/curd` 拷贝到宿主（**已存在则跳过**，保护本地开发版）；
2. **示例落位**到宿主根（给用户看的示例，可自由改/删）：
   - `app/controller/admin/api/MyTestController.php`（「测试管理」控制器）
   - `app/model/MyTest.php`
3. 生成集中配置 `config/curd.php`（插件调参入口；已存在不覆盖）；
4. 若 `config/database.php` 缺失或是 webman/database 占位模板 → 自动生成 env 驱动的
   `mysql` + `mysql_business` 双连接模板（单库同库）。

验证：

```bash
ls plugin/curd  config/curd.php  config/database.php
# 极少见自动拷贝未触发时的兜底：
composer dump-autoload && composer update amcolin/webman-curd-admin
# 或直接手动拷：cp -r vendor/amcolin/webman-curd-admin/plugin/curd plugin/curd
```

---

## 3. 初始化（建表 + 种子 + 密钥）

### 3.1 Web 安装向导（推荐，新项目）

先启动服务（向导需要服务在线）：

```bash
php start.php start        # 端口默认 8787（config/process.php 的 listen）
```

浏览器打开 **http://host:port/app/curd-installer**，填：

- **数据库连接**：主机 / 端口 / 库名 / 账号 / 密码（库不存在会自动 CREATE）；
- **管理员账号密码**：初始管理员（非固定 admin/admin123）。

向导自动完成：DB 信息写入 `.env`（按键合并，不覆盖其它内容）→ 同步写 `config/curd.php`
database 段 → 子进程执行安装脚本（建表/种子/密钥）→ 页面实时进度。

> ✅ 安装成功**自动向 master 发 SIGUSR1 平滑 reload**（webman-admin 同款）：worker 处理完
> 当前请求后重启并重读 .env/config，新 DB_* 即刻生效，**无需手动 restart**；仅当运行在
> Windows / supervisor 等**信号不可用**场景时，页面会回退提示手动执行 `php start.php restart`
> （worker 配置在启动时固化，不重启会报 `1045 Access denied`）。
> 安装成功生成 `runtime/curd-installed.lock`，向导自动失效（重复访问提示已安装）；
> 重装 = 删锁 + 清库后刷新页面。

### 3.2 命令行安装（向导的等价手动流程）

```bash
# 先配好 .env 的 DB_*（键清单见 plugin/curd/env.example，单库只需一份 DB_NAME）
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=webman_curd        # 不存在会自动建
DB_USER=root
DB_PASSWORD=your_password

# 一键安装（幂等可重复执行；默认只种【超级管理员】角色）
php plugin/curd/install.php --admin-user=admin --admin-pass=你的密码
```

CLI 可选参数：

| 参数 | 说明 |
|---|---|
| `--admin-user=admin` / `--admin-pass=xxx` | 初始管理员凭据（仅 admin_users 空表时生效） |
| `--business-sql=path/a.sql` | 项目自带的业务建表 SQL（相对宿主根；可重复/逗号分隔传多个） |
| `--business-connection=mysql_business` | 覆盖业务 SQL 使用的连接名 |
| `--progress-file=runtime/x.log` | 进度 JSONL 输出（Web 向导内部用） |

### 安装做了什么（幂等，重复执行全部 SKIP）

- **建 9 张表**：`admin_users` `admin_tokens` `roles` `admin_role_user` `role_permission`
  `casbin_rule` `curd_configs` `menus` + 示例业务表 `my_test`；
- **种子**（空表才插）：`roles` admin 超级管理员、`menus` 6 条基础菜单、
  初始 admin 账号、`curd_configs` 的 `/my-test` 示例页面配置 + 「测试管理」菜单 + `my_test` 示例数据；
- **RSA 密钥**：生成 `config/keys/`（已存在跳过；生产备份此目录）。

---

## 4. 启动验证

```bash
# CLI 安装后执行一次重启让新配置/路由生效（Web 向导场景已自动平滑 reload，可跳过）
php start.php restart
```

| 验证点 | 期望 |
|---|---|
| 打开 `http://host:port/app/curd/` | 登录页（用第 3 步管理员账号） |
| 登录成功 | 用户/角色/菜单管理、配置生成器、自定义页面全部可用 |
| SPA 深链 `http://host:port/app/curd/dashboard` | 200（history 路由回退 index.html） |
| `/api/auth/me` 等插件 API | 200 |

> 端口默认 8787；若与存量服务冲突改 `config/process.php` 的 `listen`。

---

## 5. 写第一个业务 CURD

三步 + 菜单登记，无需写 List/Form/API 代码：

```php
// ① 模型 app/model/APackage.php —— 启动时自动扫描进 ModelRegistry，不用手写注册

// ② 控制器 app/controller/api/PackageController.php
namespace app\controller\api;
use plugin\curd\app\controller\base\BaseCurdController;

class PackageController extends BaseCurdController {
    protected string $modelClass = \app\model\APackage::class;
    protected string $title = '套餐管理';
}

// ③ 路由注册：加入宿主 config/route.php 的 registerMany([...]) 数组
//    RouteControllerRegistry::register('/package', \app\controller\api\PackageController::class);
```

④ 在后台「菜单管理」登记菜单 + 角色授权（写 `menus` / `casbin_rule`），前端即可见可用。

### 开箱示例：「测试管理」

装包时示例文件已自动落位宿主根（见第 2 节），启用只需两步：

```php
// a) 宿主 config/route.php 注册（示例文件 MyTestController.php 头部注释含完整写法）
RouteControllerRegistry::registerMany([
    // ...
    \app\controller\admin\api\MyTestController::class,
]);

// b) 安装时已自动种好：my_test 表 + curd_configs /my-test 配置 + 「测试管理」菜单
//    重启后打开后台即出现该菜单，直接可增删改查
```

业务想「装完即带业务菜单」：项目根放 `install-business.sql`（`CREATE TABLE IF NOT EXISTS` +
`INSERT IGNORE` 菜单/权限种子，语句间 `--SPLIT--` 分隔），再跑
`php plugin/curd/install.php --business-sql=install-business.sql`；
模板参考 `plugin/curd/install-business.example.sql`。表结构后续演进用增量 migration：
`php plugin/curd/migrate.php`（版本文件 `plugin/curd/migrations/V{n}__*.sql`，已执行版本记
`_curd_migrations`，只追加不改已发布文件）。

---

## 6. 配置参考

> 插件以「根目录应用插件」分发（`{项目}/plugin/curd`），webman 配置加载顺序是
> `config/` 先、`plugin/*/config` 后——插件自带配置会覆盖 `config/plugin/curd/curd.php`，
> 所以**调参统一走宿主根 `config/curd.php`**（composer require 自动生成模板）或改
> `plugin/curd/config/curd.php`（升级会被覆盖，不推荐）。

| 配置键 | 默认 | 说明 |
|---|---|---|
| `page_base` | `/app/curd` | 前端挂载前缀（改需同步用同 `VITE_BASE_PATH` 重建前端） |
| `admin_require_permission` | `false` | `/api/admin/*` 是否强制 RBAC（生产建议 `true`，admin 角色不受影响） |
| `admin_connection` | `mysql` | 认证/管理面库连接名 |
| `business_connection` | `mysql_business` | 业务模型 CURD 默认连接名（单库下与 mysql 同库） |
| 数据库 | 见 `.env` 的 `DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASSWORD` | 单库架构一份库名即可 |

后端其它调参（model_dir / controller_dirs / vue_pages_dir / casbin_model_path 等）见
`plugin/curd/config/curd.php` 内置默认值，同名顶层键在宿主 `config/curd.php` 覆盖生效。

**接口加密（可选）**：默认关闭（明文直连）。开启需前端 `VITE_API_ENCRYPT=true` 重建 +
`.env` 设 `API_ENCRYPT=true`，密钥用已生成的 `config/keys/api_rsa_public.pem`。

---

## 7. 升级 / 卸载

### 升级（保护本地改动）

安装器策略：`plugin/curd` 已存在就**跳过拷贝**，所以 `composer update` 只更新 vendor、
**不会动**宿主 `plugin/curd` 与 `config/`。同步新版插件文件：

```bash
# 1) 先检查宿主 plugin/curd 是否有本地改动（0=无改动，1=有改动）
./scripts/check-plugin-overrides.sh <宿主项目根>

# 2) 确认无本地改动（或已备份）后整体重拷（先备份 plugin/curd → plugin/curd.bak-<时间戳>）
bash <宿主项目根>/scripts/sync-plugin.sh
#    config/curd.php、config/database.php 已存在则不会被覆盖
```

> 若只改过内置前端，用 `./scripts/build-frontend.sh` 重建即可，不必动 plugin/curd 源码。

### 卸载

```bash
composer remove amcolin/webman-curd-admin
# 安装器卸载钩子不自动删目录（避免误删本地开发版），手动清理：
rm -rf plugin/curd
php start.php restart
```

---

## 8. 生产部署要点

- **进程守护**：supervisor / systemd 守护 `php start.php start`（勿用 restart 类带会话组命令做守护）；详见 `docs/插件安装升级与生产部署.md` 第 6 节（或浏览器打开 `docs/index.html`）。
- **Nginx 反代**：`/app/curd/` 与 `/api/*` 同域反代即可，免 CORS；后端反代用
  `connection->getRemoteIp()` 取真实 IP。
- **密钥备份**：`config/keys/` 随生产环境备份（丢失不影响登录，仅影响已加密接口）。
- **权限收紧**：`.env` / `config/curd.php` 开 `admin_require_permission=true`。
- **DB 备份 / 升级回滚**：升级前 `check-plugin-overrides.sh` + `sync-plugin.sh` 自带备份；
  回滚 = 恢复 `plugin/curd.bak-*` 后 reload。

---

## 9. FAQ / 踩坑

**Q：`/api/curd/config?route_path=/my-test` 返回 404「配置不存在」**
没跑安装向导/install.php（`curd_configs` 种子未插）。执行第 3 节任一步骤即可；
也可在后台「配置生成器」手动生成该页面。

**Q：登录报 `Class "support\Redis" not found`**
包已 require `webman/redis`，^1.0 自动拉齐；确属旧版再 `composer require webman/redis`。
Redis 不可用时 Auth/RBAC 已 try/catch 降级直查 DB，不影响登录。

**Q：装完登录报 1045 Access denied**
Web 向导场景安装成功已**自动 SIGUSR1 平滑 reload**，新 DB 密码即刻生效，一般不会出现；
若运行在 Windows / supervisor 等信号不可用环境，按向导页面提示手动执行 `php start.php restart`
（worker 配置在启动时固化）。CLI 安装后同样重启一次即可。

**Q：与宿主路由冲突怎么办**
插件路由统一走 `curd_route()` 注册（检测到同 method+path 已注册则跳过，宿主优先），
存量项目自注册过 `/api/admin/*` 等不受影响、行为不变。

**Q：为什么配置写在 config/curd.php 而不是 .env**
插件自带 config 会覆盖 `config/plugin/curd/curd.php`；宿主根 `config/curd.php` 由安装器生成、
顶层同名键覆盖内置默认，是唯一推荐调参入口。数据库连接仍走 `.env` 的 `DB_*` 键。

---

## 附：完整文档

- 安装 · 升级 · 生产部署（含文档站版）：`docs/index.html`（浏览器直接打开，含两篇文档）
- 后台 DSL 使用文档：`docs/DSL-使用文档.md`（数组式 / `grid()` 模板、配置生成器、菜单 path 约定）
- 目录结构与包开发（发布 zip / CI / 与宿主关系）：见 `README.md`
