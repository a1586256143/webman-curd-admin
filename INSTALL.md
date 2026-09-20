# webman-curd-admin 安装说明

自包含的 **CURD / DSL / Schema 页面引擎 + 登录 / RBAC 中后台底座 + 内置前端**。
宿主只需 `composer require`，插件自动拷到 `{项目}/plugin/curd`，启动即用，**无需部署任何前端静态资源**。

- 包名：`amcolin/webman-curd-admin`
- 仓库：GitHub `git@github.com:a1586256143/webman-curd-admin.git` · Gitee 镜像 `https://gitee.com/colingit/webman-curd-admin.git`
- 插件目录 `plugin/curd` · 命名空间 `plugin\curd\app\*` · 前端前缀 `/app/curd` · 配置表 `curd_configs`

> 本文只讲「装起来」。用法、配置全量表、排错见 `docs/index.html`（浏览器直接打开）
> 或 `docs/` 下的各篇 Markdown。

---

## 1. 环境要求

| 项 | 要求 |
|---|---|
| PHP | ≥ 8.1 |
| 框架 | webman（`workerman/webman-framework` ^2.1，官方骨架即可） |
| 数据库 | MySQL —— **单库架构**：认证表与业务表放同一个库 |
| PHP 扩展 | `ext-gd` + `ext-mbstring`（登录图形验证码用；不需要可 `'captcha_enabled' => false`） |
| 依赖 | `composer require` 自动拉齐 `webman/database` `webman/redis` `casbin/casbin` `vlucas/phpdotenv` `webman/captcha` |

宿主 `composer.json` 需含 webman 官方插件的三个 scripts（官方骨架自带，缺失则补）：

```json
"scripts": {
  "post-package-install":  ["support\\Plugin::install"],
  "post-package-update":   ["support\\Plugin::install"],
  "pre-package-uninstall": ["support\\Plugin::uninstall"]
}
```

并确认存在 `support/Plugin.php`（官方骨架文件）。

---

## 2. 引入插件（三选一）

```bash
# 方式 A：本地 path（开发期联调；改包源码即时生效）
composer config repositories.curd path "../webman-curd-admin"
composer require amcolin/webman-curd-admin:@dev

# 方式 B：私有 Git VCS（推荐上线，走 tag 版本）
composer config repositories.curd vcs "git@github.com:a1586256143/webman-curd-admin.git"
#   国内更快可走 Gitee 镜像：https://gitee.com/colingit/webman-curd-admin.git
composer require amcolin/webman-curd-admin:^1.0 --no-audit

# 方式 C：私有 Satis / Packagist（团队统一源；仓库已附 satis.json 模板）
composer config repositories.curd-satis composer "https://satis.your-company.com"
composer require amcolin/webman-curd-admin
```

> ⚠️ `composer audit` 红字（`Failed to audit installed packages`）是**已知尾部告警**：
> audit 硬编码访问 `packagist.org`、不走国内镜像，命令本身 `exit 0`、不影响安装。
> 不想每次手打 `--no-audit`：用本包自带 wrapper `scripts/composer.sh`，或
> `alias composer='<包目录>/scripts/composer.sh'`。

**安装器自动完成**（`src/Install.php`，幂等）：

1. 拷贝 `plugin/curd` 到宿主（**已存在则跳过**，保护本地开发版）；
2. 示例落位到宿主根：`app/controller/admin/api/MyTestController.php` + `app/model/MyTest.php`；
3. 生成集中配置 `config/curd-admin.php`（已存在不覆盖）；
4. `config/database.php` 缺失或是占位模板时，自动生成 env 驱动的 `mysql` + `mysql_business` 双连接模板。

```bash
# 验证
ls plugin/curd config/curd-admin.php config/database.php
# 兜底（极少见自动拷贝未触发）
composer dump-autoload && composer update amcolin/webman-curd-admin
cp -r vendor/amcolin/webman-curd-admin/plugin/curd plugin/curd
```

---

## 3. 初始化（建表 + 种子 + 密钥）

**Web 向导（推荐）**：

```bash
php start.php start            # 端口默认 8787（config/process.php 的 'listen'）
# 浏览器打开 http://host:port/app/curd-installer
#   填 数据库连接（主机/端口/库名/账号/密码；库不存在自动建）
#   填 管理员账号密码（不是固定的 admin/admin123）
```

提交后自动：DB 信息按键合并写入 `.env` + `config/curd-admin.php` → 子进程执行安装
（建库 / 建表 / 种子 / RSA 密钥）→ 页面实时进度 → **成功即发 `SIGUSR1` 平滑 reload，免手动重启**
（Windows / supervisor 等信号不可用场景页面会提示手动 `php start.php restart`）。
成功生成 `runtime/curd-installed.lock`，向导失效；重装 = 删锁 + 清库后刷新页面。

**CLI 等价流程**：

```bash
# 先配好 .env 的 DB_*（键清单见 plugin/curd/env.example，单库只需一份 DB_NAME）
php plugin/curd/install.php --admin-user=admin --admin-pass='你的密码'
#   可选：--business-sql=install-business.sql（可重复/逗号分隔）
#         --business-connection=mysql_business
#         --progress-file=runtime/i.log
```

**装了什么**（幂等，重复执行全 SKIP）：8 张认证/管理面表（`admin_users` `admin_tokens`
`roles` `admin_role_user` `role_permission` `casbin_rule` `curd_configs` `menus`）
+ 示例表 `my_test`；种子（超级管理员角色 / 6 条基础菜单 / 管理员账号 / `/my-test` 示例页面
+ 「测试管理」菜单）；RSA 密钥 `config/keys/`。

---

## 4. 必做：全局中间件与异常处理器

**① 全局中间件**（插件的接口加解密必须注册在 `'@'` 组，`''` 只作用于默认 app、插件 `/api/*` 会绕过）：

```php
// config/middleware.php
return [
    '@' => [
        \app\middleware\StaticFile::class,
        // 即使关闭加密也要保留，否则前端加密请求无人解
        \plugin\curd\app\middleware\ApiCrypto::class,
    ],
];
```

**② 异常处理器**（未捕获异常的兜底出口）：

- 插件路由**开箱生效**（插件自带 `plugin/curd/config/exception.php`）；
- 宿主自己的 `/api` 控制器要覆盖到，把宿主 `config/exception.php` 的 `''` 键指过来：

```php
// config/exception.php
return ['' => \plugin\curd\app\exception\CurdExceptionHandler::class];
```

> ⚠️ 只写 `'@'` 键无效（本 app 已有 `''` 键时框架会忽略 `'@'`）。
> ⚠️ 别用中间件做这件事：webman 在每层中间件内层已先 try/catch，外层拿不到异常。

线上务必在 `.env` 设 `APP_DEBUG=false`，否则异常堆栈会直接抛给前端。

---

## 5. 启动验证

```bash
php start.php restart      # CLI 安装后重启一次；Web 向导场景已自动 reload，可跳过
```

| 验证点 | 期望 |
|---|---|
| `http://host:port/app/curd/` | 登录页（用第 3 步的管理员账号） |
| 登录成功 | 用户 / 角色 / 菜单管理、配置生成器、自定义页面全部可用 |
| `http://host:port/app/curd/dashboard` | 200（SPA history 路由回退 `index.html`） |
| `/api/curd/models`、`/api/curd/tables` | 200 |

> 端口默认 8787，与存量服务冲突就改 `config/process.php` 的 `listen` 再重启。

---

## 附：下一步

| 你想做的事 | 看哪里 |
|---|---|
| 写第一个业务 CURD / 用配置生成器零代码出页面 | `docs/01-快速开始.md` |
| 查某个配置项、或「配置改了没反应」 | `docs/02-配置参考.md` |
| DSL 造 Schema / CURD 页面 | `docs/03-DSL-Schema页面.md`、`docs/04-DSL-CURD页面.md` |
| 菜单与权限、默认落地页 | `docs/05-菜单与权限.md` |
| 换登录方式、账号状态判定 | `docs/06-认证与登录.md` |
| 接口加密、线上异常兜底 | `docs/07-接口加密与异常兜底.md` |
| 升级 / supervisor / Nginx / 备份回滚 | `docs/08-升级与生产部署.md` |
| 报错了 | `docs/09-排错速查与FAQ.md` |

浏览器直接打开 **`docs/index.html`** 可读完整文档站（自包含单页，无需服务器）。
