# 生产部署指南（nginx + supervisor + 备份）

适用于 `huafei/webman-crud` 接入后的新项目上线。

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

> 不要用 `php start.php start -d` 裸跑；也不要用 `restart`（会杀会话进程组，见历史坑）。

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
        fastcgi_pass 127.0.0.1:8787;   # 与 config/server.php listen 一致
    }

    # 插件内置前端（SPA history fallback 由 PageController 处理，无需 nginx 兜底）
    location /app/crud/ {
        try_files $uri $uri/ /index.php?$query_string;
    }
}
```

> 真实 IP：webman 通过 `connection->getRemoteIp()` 取客户端 IP（反代场景依赖 Nginx 透传，必要时在 worker 启动前解析 `X-Forwarded-For`）。

## 3. .env 隔离（环境分级）

- 开发 / 测试 / 生产各用**独立** `.env`，绝不共用库
- 生产 `.env` 至少：
  - `CRUD_ADMIN_REQUIRE_PERMISSION=true`（开启 /api/admin/* 的 casbin 鉴权）
  - 登录后**立即修改** admin 默认密码（种子账号 admin/admin123 仅初始）
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
