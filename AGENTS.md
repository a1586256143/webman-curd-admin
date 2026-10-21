# AGENTS.md — webman-curd-admin 开发须知

> 本文件是**跨 AI 工具的通用入口**（Codex / Cursor / Claude Code / Gemini CLI 等都会读 `AGENTS.md`）。
> WorkBuddy AI 另有项目级技能，内容一致，见文末。

## 这是什么项目

`amcolin/webman-curd-admin`：面向 webman 的**中后台一体化插件**（CURD / DSL / Schema 页面引擎 + 登录 / RBAC + 内置前端）。
插件体在 `plugin/curd/`，宿主 `composer require` 后自动落地、启动即用。

## 干活前必读：两个目录别搞混

| 角色 | 路径 | 说明 |
|---|---|---|
| **插件仓库（源码 / 真理源）** | 本仓库根 | 写代码、写文档；`plugin/curd/` 是插件体 |
| **真实宿主（运行中的 webman 应用）** | 如 `/Users/colin/Public/www/company/webman-huafei-platform`（端口 18082） | 命令**必须在这里的根目录**跑；`plugin/curd/` 被 git 跟踪 |

**铁律**：`php webman <cmd>` 只能在宿主根目录执行（插件仓库没有 webman console）。改完插件代码后
用 rsync 把 `plugin/curd/` 推到宿主再 `restart`。

## 四条命令（单一职责）

| 命令 | 作用 |
|---|---|
| `make:curd` | 按表结构生成「模型 + 控制器」骨架（`-t 表名` 必填） |
| `make:curd-action` | 给已生成控制器追加行级/批量操作按钮 + `action{Name}()` 骨架（`--name=名:标题`，`--batch`） |
| `make:curd-import` | 给已生成控制器追加 Excel 导入 + `actionImport()` 骨架（`--name` 默认 `import:导入`） |
| `curd:crypto-key` | 查看/生成/轮换 RSA 密钥（`--show` / `--force` / `--bits=4096`） |

`--name` **无短写**，必须写全。改完 PHP 类 / 路由 / config → 宿主 `php webman restart`。

## 同步到真实宿主（改完插件仓库必做）

```bash
# 1) 插件体（务必排除 config/keys，删了加密就崩）
rsync -a --exclude 'config/keys' \
  <插件仓库>/plugin/curd/ <宿主>/plugin/curd/
# 2) 文档（排除 tools 与生成的 index.html）
rsync -a --exclude 'tools' --exclude 'index.html' <插件仓库>/docs/ <宿主>/docs/
# 3) 宿主重启
cd <宿主> && php webman restart
# 4) 两个仓库分别提交（宿主 plugin/curd 是 git 跟踪的）
```

## 必踩坑（高频）

1. 命令在**宿主根目录**跑，不在插件仓库跑。
2. `--name` 无短写；别在宿主 `app/command/` 放同名副本（会顶掉插件版）。
3. rsync 必须 `--exclude 'config/keys'`。
4. 接口加密**默认开**；宿主**自建**的 `/api/*` 控制器要自己在 `config/middleware.php` 挂
   `'@' => [\plugin\curd\app\middleware\ApiCrypto::class]`（插件自带只覆盖 `plugin\curd` 命名空间）。
5. 换密钥后必须 `restart`，否则内存里还是旧私钥（400 / key-mismatch）。
6. `php webman restart` 前台可能卡住 → 用 `php webman start -d`。
7. 宿主 `plugin/curd/` 是 git 跟踪的，同步完要在宿主仓库提交，否则 checkout 会打回旧版。

## 完整手册

- **WorkBuddy AI 项目级技能**：`.workbuddy-ai/skills/webman-curd-admin-dev/SKILL.md`（最全：命令速查 / 命名推导 / Grid DSL 速记 / SOP / 坑）
- **随包分发副本**：`plugin/curd/skills/webman-curd-admin-dev/SKILL.md`（与上面一致，供 composer 安装后发布）
- **发布到宿主项目**：宿主根目录跑 `php webman curd:publish-skill`，把技能复制到 `{项目根}/.workbuddy-ai/skills/` 并维护根 `AGENTS.md`
- **文档站**：`docs/`，编号即栏目；命令细节看 `docs/17-命令行工具.md`，DSL 看 `docs/11-DSL-CURD页面.md`，API 看 `docs/20~23`。
- 构建文档站：`node docs/tools/build_docs.cjs`（需 `NODE_PATH` 指向已装 `marked` 的 node_modules）。
