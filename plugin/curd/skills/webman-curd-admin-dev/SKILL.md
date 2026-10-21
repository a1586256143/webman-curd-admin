---
name: webman-curd-admin-dev
description: >
  webman-curd-admin 插件的「日常开发」技能。当需要为这个插件做日常开发任务时使用：按表结构生成
  CURD 后台页面骨架（make:curd）、给已生成的控制器追加行级/批量操作按钮（make:curd-action）、
  追加 Excel 导入（make:curd-import）、生成/轮换接口加密密钥（curd:crypto-key）、理解 Grid DSL、
  把改动同步到项目并热重启。
  本技能是给 AI 助手用的「操作手册 + 防坑清单」，完整 API 参考在 docs/ 里（本技能只放高频用法与必踩坑）。
  Trigger phrases: "生成 CURD", "make:curd", "新增操作按钮", "加个导入", "加个 action", "加密密钥",
  "crypto key", "接口加密", "同步到宿主", "部署插件", "webman-curd-admin 开发", "curd 页面",
  "后台页面", "给某表加管理页", "加个审核按钮".
agent_created: true
---

# webman-curd-admin 日常开发技能

> 📦 本文件随 composer 包分发，`php webman curd:publish-skill` 会把它发布到项目根。
> 插件源码仓库里另有同名副本，两处内容保持一致即可。

把插件日常开发的高频命令、命名规则、DSL 速记、同步流程、必踩坑打包成一个即用清单。
**完整 API 参考永远以 `docs/*.md` 为准**（本技能只放干活够用的部分 + 那些会让人卡半天的坑）。

---

## 0. 两个目录的认知（先分清，否则命令全跑错）

| 角色 | 指什么 | 说明 |
|---|---|---|
| **你的 webman 项目（宿主）** | 你实际开发 / 部署的项目根目录 | 装了本插件后 `plugin/curd/` 在它下面；**所有命令都在这里跑** |
| **插件源码仓库（仅开发插件时需要）** | `amcolin/webman-curd-admin` 仓库本身 | 改插件代码时用；改完要同步到你的项目 |

> **铁律**：`php webman <cmd>` 只能在**你的项目根目录**执行（需要项目装了 `webman/console`）。
> 插件源码仓库里没有 webman console，跑不了命令。
>
> **在你的项目里用**：跑一次 `php webman curd:publish-skill`，技能会发布到项目根 `.workbuddy-ai/skills/`
> 并维护根 `AGENTS.md`（详见 docs/18 §7）。升级插件后加 `--force` 重跑同步。

---

## 1. 环境前置

- **PHP ≥ 8.1**：`php -v` 确认。若 `php` 不在 PATH，按你的环境（ServBay / Homebrew / 宝塔 / Docker 等）配好即可。
- **命令入口**：在你的**项目根目录**执行 `php webman <cmd>`。列出全部命令：`php webman`
  （应看到 `make:curd` / `make:curd-action` / `make:curd-import` / `curd:crypto-key` / `curd:publish-skill`）。
- 这些命令由插件 `config/app.php` 的 `'command'` **显式注册**（免疫软链），装好插件即生效，
  **不要在项目 `app/command/` 里再放同名副本**（会顶掉插件版）。

---

## 2. 命令速查（5 条 + 进程命令）

| 命令 | 作用 | 关键选项 |
|---|---|---|
| `make:curd` | 按表结构生成「模型 + 控制器」骨架（**只**骨架，不含操作/导入） | `-t 表名`（必填）、`-c 控制器`、`-m 模型`、`--title`、`-r 路由`、`-d 连接`、`--register`、`-f` |
| `make:curd-action` | 给**已生成**控制器追加行级/批量操作按钮 + `action{Name}()` 骨架（幂等） | `-t 表名` 或 `-c 控制器`、`--name=名:标题`（**必填，无短写**）、`--batch`、`-f` |
| `make:curd-import` | 给**已生成**控制器追加 Excel 导入按钮 + `actionImport()` 骨架（幂等） | `-t 表名` 或 `-c 控制器`、`--name`（默认 `import:导入`）、`-f` |
| `curd:crypto-key` | 查看/生成/轮换 RSA 密钥对 | `--show`、`--force`、`--bits=4096`、`--print-public` |
| `curd:publish-skill` | 把 AI 开发技能发布到你的项目根（`.workbuddy-ai/skills/` + `AGENTS.md`） | `--force`、`--no-agents`、`--dry-run` |

> **`--name` 没有短写**（避免 `-n` 与内置冲突），必须写全 `--name=...`。

webman 进程命令（天天用）：
```bash
php webman start -d     # 后台启动（推荐）
php webman restart      # 改了 PHP 类 / 路由 / config 后必须
php webman reload       # 只重载 .env 等（SIGUSR1）；类/路由改了不生效，要用 restart
php webman stop
```
> 改了 **PHP 类 / 路由 / config** → 必须 `restart`；只改 `.env` → `reload`。

---

## 3. 标准作业流程（SOP）

### 3.1 新增一个 CURD 后台页面
```bash
cd <宿主根目录>
# 最简：表名必填，模型/控制器/路由自动推导
php webman make:curd -t hf_goods
# 指定标题并自动注册路由（写入 config/admin-route.php，写前备份 .bak + php -l 自检）
php webman make:curd -t hf_goods --title=商品管理 --register
# 指定业务库连接 + 覆盖已存在文件
php webman make:curd -t hf_goods -d mysql_business -f
```
做完 → 见 §6 同步宿主 → 宿主 `php webman restart` → 后台页面出现。

### 3.2 给已有页面加操作按钮
```bash
cd <宿主根目录>
# 行级按钮：操作名 audit，标题「审核」
php webman make:curd-action -t hf_goods --name=audit:审核
# 批量按钮（勾选行后可用，请求带 ids）
php webman make:curd-action -t hf_goods --name=batch-on:批量上架 --batch
# 已知类名直接指定
php webman make:curd-action -c HfGoodsController --name=audit:审核
```
命令自动：① 在 `grid()` 插入 `$grid->action(...)` / `$grid->batAction(...)`；② 类末追加 `action{Name}(Request $request)` 骨架（行级取 `post('id')`，批量取 `post('ids')`）。写入前备份 `.bak`，写后 `php -l`，失败回滚。打开骨架补业务逻辑即可。

### 3.3 给已有页面加 Excel 导入
```bash
cd <宿主根目录>
php webman make:curd-import -t hf_goods                 # 默认 import:导入
php webman make:curd-import -t hf_goods --name=import-goods:导入商品
```
命令自动：① 在 `grid()` 插入 `$grid->importAction(...)`；② 类末追加 `actionImport(Request $request)` 骨架（内部 `$this->excelRows('file')` 逐行）。Excel 首行为表头，列名需与字段标签一致。

### 3.4 生成 / 轮换接口加密密钥
```bash
cd <宿主根目录>
php webman curd:crypto-key --show        # 只看指纹，不生成
php webman curd:crypto-key              # 生成（已存在跳过；只有私钥只补公钥）
php webman curd:crypto-key --force      # 换一对新的（旧密文无法解密）
```
> 换密钥后**务必 `php webman restart`**：私钥在进程内存里，不重启还是旧的（会 400 / key-mismatch）。
> 前端不用重新打包（发行产物 `auto` 模式，启动自动握手拿新公钥）。

### 3.5 改完 PHP 后的热重启
宿主根目录 `php webman restart`（见 §2 进程命令说明）。**不要前台 `restart` 卡住不返回**——
若遇到，用 `php webman start -d` 起来（日志见项目 `runtime/logs/`）。

---

## 4. 命名推导规则（`my_test` → `/my-test`）

| 表名 | 模型 | 控制器 | 路由 |
|---|---|---|---|
| `my_test` | `MyTest` | `MyTestController` | `/my-test` |
| `a_packages` | `APackage` | `APackageController` | `/packages` |
| `admin_users` | `AdminUser` | `AdminUserController` | `/admin-users` |

- 操作名 `batch-on` → 方法 `actionBatchOn`（`-` / `_` 后字母大写）。
- `--register` 路由优先级：`config/admin-route.php`（标准）→ 项目根 `admin-route.php`（旧）→ `config/route.php`。

---

## 5. Grid DSL 速记（完整见 docs/20-23）

控制器里 `grid()` 返回 `$grid`：
```php
public function grid()
{
    $grid = new Grid(new MyModel);
    // 列表列
    $grid->column('name', '名称')->width(180);
    $grid->column('status', '状态')->bool()->options(['启用'=>'1','禁用'=>'0']);
    $grid->column('cover', '封面')->image();
    $grid->column('amount', '金额')->money();
    $grid->column('created_at', '创建时间')->datetime();
    // 搜索 / 过滤
    $grid->filterLike('name');            // 模糊
    $grid->filterEqual('status');         // 等于
    $grid->filterIn('type');              // 多选
    $grid->filterBetween('created_at');   // 区间
    // 表单字段
    $grid->formText('name', '名称')->required();
    $grid->formTextarea('remark', '备注');
    $grid->formSelect('status', '状态')->options([...]);
    $grid->formImage('cover', '封面');
    $grid->formSwitch('enable', '启用');
    $grid->formDatetime('start_at', '开始时间');
    // 操作按钮（也可走 make:curd-action 自动加）
    $grid->action('audit', '审核')->handler('audit');
    $grid->batAction('batch-on', '批量上架');
    $grid->importAction('import', '导入');
    return $grid;
}
```
- 操作 `handler('xxx')` 表示交给控制器 `actionXxx()`，不生成 Action 子类。
- `make:curd-action` / `make:curd-import` 会把上面的 `action/batAction/importAction` 行和对应
  `action{Name}()` 方法**自动注入**到已有控制器（幂等 + 备份 + lint）。

---

## 6. 开发插件时：把改动同步到项目（仅插件开发者需要）

如果你是在**改插件源码**（而不是只用插件），改完 `plugin/curd/` 或 `docs/` 后同步到项目并重启：
```bash
# 1) 同步插件体（务必排除 config/keys，那是项目本地密钥目录，删了就崩）
rsync -a --exclude 'config/keys' <插件仓库>/plugin/curd/ <你的项目根>/plugin/curd/

# 2) 同步文档（可选；排除 tools/ 与生成的 index.html）
rsync -a --exclude 'tools' --exclude 'index.html' <插件仓库>/docs/ <你的项目根>/docs/

# 3) 项目重启（改了 PHP 类必做）
cd <你的项目根> && php webman restart

# 4) 两个仓库分别提交（项目的 plugin/curd/ 若被 git 跟踪，不提交下次 checkout 会打回旧版）
```
> 把 `<插件仓库>` / `<你的项目根>` 换成实际路径。**只使用插件、不开发插件的话，本节可跳过。**

---

## 7. 必踩坑（先看这里，省半小时）

1. **命令在宿主根目录跑**，不在插件仓库跑；插件仓库没 webman console。
2. **`--name` 无短写**，必须 `--name=audit:审核`，别写 `-n`。
3. **命令已显式注册**，别在宿主 `app/command/` 放同名副本（Symfony 后者覆盖前者，会顶掉插件版）。
4. **rsync 必须 `--exclude 'config/keys'`**：宿主 `plugin/curd/config/keys/` 是本地密钥，整目录同步会删掉它 → 接口加密失效 / 500。
5. **接口加密默认开**（`api_encrypt` 默认 true，dev/prod 一致）。宿主**自建**的 `/api/*` 控制器要自己挂
   `ApiCrypto`：`config/middleware.php` 里加 `'@' => [\plugin\curd\app\middleware\ApiCrypto::class]`；
   插件自带 `'plugin.curd'` 键只覆盖 `plugin\curd` 命名空间的路由。
6. **换密钥后必须 restart**，否则内存里还是旧私钥（400 / `X-Crypto-Key-Mismatch`）。
7. **`php webman restart` 前台可能卡住不返回** → 改用 `php webman start -d`，看 `runtime/logs/`。
8. **宿主 `plugin/curd/` 是 git 跟踪的**：同步完务必在宿主仓库提交，否则 `git checkout` / IDE「放弃更改」会把插件整目录打回旧版。
9. **明文请求没有密钥 → 响应必然明文**（对称加密硬约束，不是 bug）；加密与否运行时问后端（`GET /api/config/crypto` 免鉴权免加密）。
10. **前端发行产物是 `auto` 模式**：加密与否运行时握手决定，换密钥不用重新打包前端。

---

## 8. 文档索引（完整参考去哪看）

插件仓库 `docs/` 下（编号即左侧栏目分区）：
- `02-快速开始.md` §6 —— 命令行生成入门
- `17-命令行工具.md` —— 四条命令完整选项 + 独立脚本 + 进程命令（本技能的权威来源）
- `10-DSL-Schema页面.md` / `11-DSL-CURD页面.md` —— Schema 与 CURD 页面 DSL
- `20-API-Grid.md` / `21-API-Column.md` / `22-API-Form-Field.md` / `23-API-Filter-Action.md` —— 全量 API
- `14-接口加密与异常兜底.md` —— 加密开/关/生成/确认
- `15-升级与生产部署.md` / `16-排错速查与FAQ.md` —— 部署与排错

文档站构建：`docs/tools/build_docs.cjs` 读 `docs/*.md` 生成自包含 `docs/index.html`，发布走静态站点部署
（`language=static`，`domainPrefix=curd-admin-docs`）。改完 md 后重建并发布即可。

---

## 9. 如何更新这个技能

本技能是给 AI 助手用的操作手册，跟着插件一起演进：
- 新增/修改命令 → 同步更新本文件 §2 / §3 / §7。
- 改了 DSL 用法 → 更新 §5 并交叉核对 `docs/`。
- 换宿主/部署流程变化 → 更新 §0 / §6。
- 改动提交进插件仓库（`.workbuddy-ai/skills/webman-curd-admin-dev/`），推送后团队共用。
