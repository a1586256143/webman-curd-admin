# 生产部署指南（nginx + supervisor + 备份）

适用于 `amcolin/webman-curd-admin` 接入后的新项目上线。

## 0. 首次安装（新项目，部署前完成一次）

```bash
# ① 装包（自动：拷贝 plugin/crud + 拉齐依赖 webman/database/redis/casbin + 生成 config）
composer require amcolin/webman-curd-admin:^1.0
#    audit 红字（访问 packagist.org 失败）仅尾部告警、exit 0；不想看到就 alias 到
#    scripts/composer.sh（详见主 README FAQ）

# ② 启动服务（Web 向导需要服务在线）
php start.php start     # 端口见 config/process.php 'listen'（默认 8787）

# ③ 浏览器打开安装向导，填数据库 + 管理员账号，自动建库/建表/种子/密钥：
#    http://host:port/app/crud-installer
#    - 默认单库模式：认证表与业务表放同一库（想分库填 business_db）
#    - 只预置【超级管理员】角色，其它角色登录后台按需新增
# ④ ⚠️ 完成后必须重启 worker（向导界面会提示），否则运行期报 1045 Access denied：
php start.php restart

# ⑤ 登录后台（账号=第 ③ 步填的管理员）
#    http://host:port/app/crud/
```

重装/二次安装：删除 `config/crud-installed.lock` + 清空数据库后刷新向导页即可
（生产环境重装会重建全部表，务必先备份，见第 4 节）。

## 1. 守护进程（supervisor）

webman 自身是常驻进程，但生产环境须由进程管理器托管，避免异常退出后无人拉起。

`/etc/supervisor/conf.d/my-app.conf`：

```ini
[program:my-app]
command=php /var/www/my-app/start.php start
directory=/var/www/my-app
numprocs=1
autostart=true
autorestart=true
startretries=3
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/my-app/worker.log
```

```bash
supervisorctl reread && supervisorctl update && supervisorctl start my-app
```

> 不要用 `php start.php start -d` 裸跑。
> ⚠️ 已在 supervisor 托管后，请用 `supervisorctl restart my-app` 重启（手动 `php start.php restart`
> 会杀会话进程组波及同组进程，见历史坑）。未托管的前台/首次安装场景可直接 `php start.php restart`。

## 2. Nginx 反代

SPA 与 API 同域部署，免 CORS。前端挂载前缀默认 `/app/crud/`。

```nginx
server {
    listen 80;
    server_name admin.example.com;
    root /var/www/my-app/public;

    # 静态资源（含插件内置前端 plugin/crud/public）
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # webman 入口
    location /index.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_pass 127.0.0.1:8787;   # 与 config/process.php 'listen' 一致（webman 2.x 端口在此文件）
    }

    # 插件内置前端（SPA history fallback 由 PageController 处理，无需 nginx 兜底）
    location /app/crud/ {
        try_files $uri $uri/ /index.php?$query_string;
    }
}
```

> 真实 IP：webman 通过 `connection->getRemoteIp()` 取客户端 IP（反代场景依赖 Nginx 透传，必要时在 worker 启动前解析 `X-Forwarded-For`）。

## 3. 环境配置隔离（config/crud.php + .env 分级）

- 开发 / 测试 / 生产各用**独立**数据库，绝不共用库。配置写宿主 `config/crud.php`
  （`database` 段按环境改库名/凭据），生产建议 `config/crud.php` 不入 git、由部署流水线生成
  （`src/Install.php` 已保证宿主缺失时自动生成默认模板）。
- 传统 .env 方式同样支持（`DB_*` / `CRUD_*`，优先级低于 `config/crud.php`）。生产至少：
  - `admin_require_permission=true`（或 .env `CRUD_ADMIN_REQUIRE_PERMISSION=true`），
    开启 /api/admin/* 的 casbin 鉴权
  - 登录后**立即修改**管理员密码（v1.0.8+ 初始管理员由安装向导自定义，不再预置固定
    admin/admin123；向导/安装器只种【超级管理员】角色）
  - 不在 `.env` 写密钥：`config/keys/` 由 install.php 生成，独立挂载，不进 git / 不进发布 zip

## 4. 备份策略

| 对象 | 频率 | 方式 |
| --- | --- | --- |
| 认证库（admin_users / roles / admin_role_user / role_permission / casbin_rule / menus） | 每日 | `mysqldump` 全量 + 保留 7 天 |
| 业务库 | 每日 | 同上，按业务 RPO 调整 |
| `config/keys/`（RSA 私钥） | 生成即备份一次 | 单独加密归档，**切勿丢失**（丢后需重新生成并重建前端公钥） |
| 代码 / 前端 dist | 每次发版 | git tag + CI 产物存档 |

> ⚠️ 认证库承载全部权限与账号，缺失无法恢复登录与 RBAC，优先级最高。

## 5. 升级回滚预案

升级前先跑 `scripts/check-plugin-overrides.sh <项目根>`，确认无本地改动；
回滚：`git checkout <上一版本 tag>` 或 composer 锁版本 + 重新 `install.php`（幂等补种子）。
