# webman-huafei-platform 后台 DSL 使用文档

> 适用版本：2026-09-18（Schema 页面引擎已迁入 plugin/curd 插件；插件自带「测试管理」后台示例
> my_test / MyTestController——安装器把示例代码落位到宿主根 `app/controller/admin/api/MyTestController.php`
> + `app/model/MyTest.php`（源在包 `examples/`），安装时自动建表 + 幂等补菜单，可作为新控制器最小参考）
>
> 本次新增：§2.11 `.vue` 自定义页面（`custom/pages` 怎么放、怎么访问、运行时白名单）、
> §4.1 权限被拒不泄露内部结构 + 服务端日志、§4.2 自定义登录覆盖（`login_handler` / `LoginIssuer`）、
> §4.3 新建菜单一键生成权限、§4.4 菜单行内快速创建权限。
>
> 本文面向「用 DSL 在话费平台后台造页面」的开发者。后端 DSL 分两类：
>
> | DSL | 用途 | 形态 | 代码位置 |
> |---|---|---|---|
> | **CURD DSL** | 列表管理页（表格/搜索/表单/自定义操作/导出/汇总条） | `Grid/Form/Filter/Column/Action` 流式声明 | `plugin/curd/app/dsl/` |
> | **Schema 页面 DSL** | 展示型页面（首页/看板，组件套组件 + 24 栏栅格 + 数据注入） | `PageSchema + blocks` 编译成 JSON 组件树 | `plugin/curd/app/schema/` |

---

## 1. 分层与文件归属（先读这一段）

自研 CURD 插件 `plugin/curd` 提供**通用引擎**，业务项目（本项目 `webman-huafei-platform`）在 **app 层**消费它。两者严格分工：

| 内容 | 归属 | 说明 |
|---|---|---|
| Schema 引擎（SchemaNode / PageSchema / PageRegistry / blocks 全部节点类） | 插件 `plugin/curd/app/schema/` | 命名空间 `plugin\curd\app\schema\*`，与业务无关 |
| 自定义页面分发控制器 `CustomPageController`（`.vue` + Schema 两种分发） | 插件 `plugin/curd/app/controller/CustomPageController.php` | 路由在插件 `plugin/curd/config/route.php` |
| Schema / .vue 页面 **内容接口** 路由 `GET /api/custom/pages`、`GET /api/custom/page` | 插件 | 随插件自动加载 |
| **业务 Schema 页面类**（如首页 `HomePage`） | 业务 `app/controller/admin/api/HomePage.php` | 用户级文件，自己 `use` 插件 DSL |
| Schema 页面**数据接口**（dataApi 指向的业务统计） | 业务 `app/controller/admin/api/SchemaPageDataController.php` | 查业务表，属业务逻辑 |
| Schema 页面**注册**（`PageRegistry::register`） | 业务 `config/route.php` 顶部 | 业务对插件扩展点的声明 |
| `.vue` 自定义页面文件 | 业务 `app/custom/pages/*.vue` | 插件只读取分发，不存放 |
| CURD 业务控制器（Model + Grid） | 业务 `app/controller/admin/api/*Controller.php` | extends `BaseAdminController` |
| CURD 路由注册 `RouteControllerRegistry::registerMany` | 业务 `config/route.php` | |

**页面访问入口约定**（前端统一承载，菜单 path 填它）：
- CURD 页：`/mobile-recharge-orders` 这类（即 registerMany 的 key）
- Schema / .vue 自定义页：`/custom-page/<页面名>`（前端 catch-all 路由命中）

---

## 2. Schema 页面 DSL（重点）

### 2.1 一句话原理

一个页面 = 后端 PHP DSL 流式声明的一棵**组件树 JSON**，前端 `SchemaRenderer` 递归把它渲染成真实 Element Plus 页面。树节点统一为：

```json
{ "type": "card", "props": { "header": "今日概览" }, "children": [ … ] }
```

`type` 在**前后端注册表**里登记（后端 `plugin/curd/app/schema/blocks/` 的类，前端 `frontend/src/schema/componentRegistry.js`），`props` 走白名单透传。**Schema 是纯数据、无脚本**，比自定义 .vue 更可控。

### 2.2 新建一个页面（三步）

**① 写页面类**（用户级文件放 `app/controller/admin/api/`，命名如 `XxxPage.php`）：

```php
<?php
namespace app\controller\admin\api;

use plugin\curd\app\schema\PageSchema;
use plugin\curd\app\schema\blocks\Col;
use plugin\curd\app\schema\blocks\Card;
use plugin\curd\app\schema\blocks\Row;

class DashPage
{
    public static function schema(): PageSchema
    {
        $page = new PageSchema('概览看板');
        $page->api('stats', '/api/dash/stats');            // ① 数据接口（可多个）
        $page->row(function (Row $row) {                   // ② 布局：24 栏栅格
            $row->col(12, function (Col $col) {
                $col->card('今日', function (Card $card) {
                    $card->statistic('充值额')->value('{{stats.amount}}')->prefix('¥');
                });
            });
            $row->col(12, function (Col $col) {
                $col->card('提示', function (Card $card) {
                    $card->alert('正常', '一切正常')->type('success')->closable(false);
                });
            });
        });
        return $page;
    }
}
```

**② 注册**（业务 `config/route.php` 顶部，一行）：

```php
PageRegistry::register('dash', [\app\controller\admin\api\DashPage::class, 'schema']);
```

**③ 建菜单**，`path` 填 `/custom-page/dash`：

```bash
php webman make:permission --name 概览看板 --slug dash-page --type 1 --path /custom-page/dash --icon DataBoard --sort 3
```

改完 `php start.php restart`（新类 + 注册生效），刷新即可看到页面。**已注册的参考实现**：`app/controller/admin/api/HomePage.php`（首页，name=`home`）。

> `.vue` 自定义页面是同一入口体系的另一形态：文件放 `app/custom/pages/demo.vue`，菜单 path `/custom-page/demo`，前端运行时编译渲染（复杂交互页用）。同名时 **Schema 注册表优先**于 .vue 文件。

### 2.3 页面根 PageSchema

| 方法 | 说明 |
|---|---|
| `new PageSchema(?string $title)` | 页面根节点（type=`page`，不参与渲染，只承载 title/dataApi/body） |
| `->title(string)` | 页面标题 |
| `->api(string $key, string $url, string $method = 'get', array $params = [])` | 声明数据接口。渲染前前端并发请求，结果放进 `pageData[$key]`，供 `{{key.xxx}}` 注入 |
| `->row/col/card/collapse/timeline/descriptions/divider/alert/statistic/image/text/copyText/node` | 顶层挂载（来自 `HasBlocks` trait，下面节点通用） |
| `->toArray()` | 输出 `{ title, dataApi, body }`，由 CustomPageController 下发 |

### 2.4 布局块（可任意嵌套，大布局套小布局）

| type | 类 | 便捷方法 | 透传 prop（白名单） | 取值 |
|---|---|---|---|---|
| `row` | `Row` | `->gutter(int)` `->justify(str)` `->align(str)` | gutter/justify/align/wrap | justify: start/end/center/space-around/space-between/space-evenly；align: top/middle/bottom |
| `col` | `Col` | `->span(int 1-24)` `->offset(int)` `->moveRight(int)`(push) `->moveLeft(int)`(pull) `->responsive('md', 8)` | span/offset/push/pull/xs/sm/md/lg/xl | 响应式断点同 el-col |

子节点规则：`row` 装 `col`；`col` 纵向堆叠任意块。想缩进偏移用 `offset`，想调整顺序用 `moveRight/moveLeft`。

### 2.5 容器块（能继续装子节点）

| type | 类 | 便捷方法 | 子节点规则 |
|---|---|---|---|
| `card` | `Card` | `->header(string)`（渲染到 EP 卡片 header 插槽） `->shadow('always'\|'hover'\|'never')` | children 进卡片正文 |
| `collapse` | `Collapse` | `->accordion(bool)` `->active(array $names)` | 只能 `->item(title, cb)` |
| `collapse-item` | `CollapseItem` | `->title(string)` `->name(string)` `->disabled(bool)` | 内容放 children（可再嵌 row/card/描述…） |
| `timeline` | `Timeline` | — | 只能 `->item(ts, cb)` |
| `timeline-item` | `TimelineItem` | `->timestamp(string)` `->placement('top'\|'bottom')` `->type('primary'\|'success'\|'warning'\|'danger'\|'info')` `->color(string)` `->hollow(bool)` | 内容放 children |
| `descriptions` | `Descriptions` | `->title(str)` `->column(int)` `->border(bool)` `->direction('horizontal'\|'vertical')` `->colon(bool)` `->size('large'\|'default'\|'small')` | 只能 `->item(label, content)` |
| `descriptions-item` | `DescriptionsItem` | `->label(str)` `->span(int)` `->width(mixed)` | 值内容放 children；`item()` 传字符串自动转 Text 子节点 |
| `divider` | `Divider` | `->content(string)`（中央文字） `->contentPosition('left'\|'center'\|'right')` `->direction('horizontal'\|'vertical')` `->borderStyle(str)` | 中央文字用 `content()`，子节点作默认插槽 |

条目类（`*-item`）**只能**作为对应容器（collapse/timeline/descriptions）的直接子节点，不可隔层——渲染器用单组件 `h()` 递归保证它们是父容器的直接相邻 VNode。

### 2.6 展示块（叶子，靠 props 驱动）

| type | 类 | 便捷方法 | 说明 |
|---|---|---|---|
| `alert` | `Alert` | `->title(str)` `->description(str)` `->type('success'\|'info'\|'warning'\|'error')` `->closable(bool)` `->showIcon(bool)` `->center(bool)` `->effect('light'\|'dark')` | 提示条 |
| `statistic` | `Statistic` | `->title(str)` `->value($v)` `->prefix(str)` `->suffix(str)` `->precision(int)` `->groupSeparator(str)` `->valueStyle(array)` | 数值卡；value 整串 `{{}}` 且为数字时自动转 number |
| `image` | `Image` | `->src(str)` `->fit('fill'\|'contain'\|'cover'\|'none'\|'scale-down')` `->alt(str)` `->lazy(bool)` `->previewSrcList(array)` `->previewTeleported(bool)` | 点击预览大图等 |
| `copy-text` | `CopyText` | `->value(str)` `->label(str)` `->mono(bool=true)` `->mask(bool=false)` `->maskHead(int=4)` `->maskTail(int=4)` | **可复制文本**：等宽展示 + 一键复制按钮，用于 api_key / 密钥 / 回调地址等长串；`->mask(true)` 默认打码，配眼睛按钮切换明文 |
| `text` | `Text` | `->value(str)`（也可 `new Text('内容')`） | 纯文本（渲染 span，非 EP 组件），说明文字用 |

### 2.7 数据绑定：`{{key.path}}` 注入

1. 页面 `->api('stats', '/api/xxx')` 声明数据源；数据接口就是一个普通控制器方法，返回统一响应 `{code, msg, data}`，渲染前前端并发请求并取 **`data`** 作为 `pageData['stats']`。
2. 任一节点 props 的字符串里可用 `{{stats.today_amount}}` 占位：
   - **整串就是占位符**（如 `value: "{{stats.amount}}"`）：数据是数字 → 注入数字（statistic 需要）；是对象/数组 → 原样注入；取不到 → **保留原文**（避免渲染出 undefined）；
   - 占位符**嵌在长文本**里（如 `"¥{{stats.amount}} 元"`）→ 文本替换，取不到替换为空串。
3. **只做取值替换，不执行任何 JS**。单个 dataApi 失败不阻塞整页（该键数据为空，占位保留便于定位）。

> 参考数据接口：`SchemaPageDataController::home`（GET `/api/schema/home/stats`），返回 `today_amount / today_count / month_amount / pending_count / account_total / account_count / accounts[]`，与订单列表头部汇总条同口径。

### 2.8 props 白名单与安全

后端 block 的 `->方法()` 本质是往 `props` 里塞键（`SchemaNode::set`）。**前端渲染器只透传注册表白名单内的键**（`componentRegistry.js`），其余一律丢弃——避免脏属性打到 EP 组件上、也避免任意数据注入造成意外行为。三个兜底入口：

```php
$card->raw(['bodyStyle' => ['padding' => '12px']]); // SchemaNode::raw：一次合并多个 props
$img->set('initialIndex', 2);                        // SchemaNode::set：单个 prop（白名单外前端会忽略）
$node->node($anyNode);                               // 挂载任意已构造好的节点
```

### 2.9 渲染链路（改后端即生效，无需构建前端）

```
菜单 /custom-page/<name>
  → GET /api/custom/page?name=<name>      (插件 CustomPageController)
  → PageRegistry::find(name) 有 → {format:'schema', schema:{…}}
      | 无 → app/custom/pages/<name>.vue 有 → {format:'vue', content, mtime}
  → 前端 CustomPageHost 按 format 分派：
      schema → 并发请求 dataApi → interpolateSchema 注入 → SchemaRenderer 递归渲染
      vue    → dynamicSfc 运行时编译 SFC 渲染
```

**渲染器三条特殊规则**（`SchemaRenderer.vue`）：
1. `card` 的 `header` 不是 prop，是 EP 命名插槽 → 渲染器把 `props.header` 转成 header 插槽；
2. `collapse` 的展开状态（v-model）由渲染器 WeakMap 自托管，初始取 `props.activeNames`，页面无需写交互代码；
3. 所有容器一律递归 children 到**默认插槽**，保证 `*-item` 是父容器的直接子 VNode（EP 结构要求）。

### 2.10 扩展新组件（前后端各一步）

以加一个「链接按钮」为例：

1. **后端** `plugin/curd/app/schema/blocks/Button.php`：`class Button extends SchemaNode { __construct(){ $this->type='button'; } public function text($v){ return $this->set('text',$v); } }`（并可在 `HasBlocks::button()` 加便捷入口）；
2. **前端** `componentRegistry.js` 登记一行：`button: { component: ElButton, props: ['text','type','link', ...COMMON] }`。

渲染器零改动。当前已注册 type 全表 = 第 2.4~2.6 节列出的 18 个。

**component 也可以指向自定义 Vue 组件**（不限于 EP 组件）：`copy-text` 就是先例 ——
`componentRegistry.js` 里 `'copy-text': { component: CopyTextNode, props: [...] }`，
`CopyTextNode.vue` 自己负责渲染与交互（渲染器仍然只按 `h(component, props, slots)` 调用，
props 依旧走白名单）。复制这类交互因此可以纯前端实现，后端 block 只负责摆位置与传值。

### 2.11 `.vue` 自定义页面（`app/custom/pages/`）—— 怎么用、怎么访问

Schema 只适合「纯展示」。一旦要写复杂交互（自定义表格逻辑、图表联动、多步表单、第三方组件），就用 `.vue` 页面：

**① 放文件**：`<宿主根>/app/custom/pages/<name>.vue`。**文件名即页面名，无需注册**；支持子目录（`sub/foo.vue` → name=`sub/foo`）。目录可用 `config/plugin/curd/curd.php` 的 `vue_pages_dir` 改。

**② 建菜单**：`path` 填 `/custom-page/<name>`（与 Schema 页同一个入口体系）。

**③ 怎么访问**（三种都行）：

| 途径 | 地址 |
|---|---|
| 后台点菜单 | 前端 catch-all 路由命中 `/custom-page/<name>` |
| 直接开 URL | `http://<宿主域名>/app/curd/custom-page/<name>`（插件内置前端挂 `/app/curd/` 前缀，history 模式） |
| 调试接口 | `GET /api/custom/page?name=<name>` → `{format:'vue', content, mtime}`；`GET /api/custom/pages` 列全部页面 |

**运行时约束**（内容由浏览器端 `@vue/compiler-sfc` 现场编译，绕开了 Vite，因此有白名单）：

| 项 | 规则 |
|---|---|
| 结构 | `<template>` + `<script setup>`（或 `<script>`，**二选一，不能同时写**）+ `<style>` |
| 可 import 的模块 | **仅 3 个**：`vue`、`element-plus`、`@element-plus/icons-vue`。其它任意 specifier（含相对路径 `./x.js`、`axios`、`lodash`）编译期直接报「不支持导入模块」 |
| 发请求 | `const request = inject('request')` 拿统一加密请求服务（自动带 token + RSA/AES 信封，签名同 axios：`request.get/post`）；`inject('router')` / `inject('route')` 同理 |
| 组件 | `element-plus` 全量注册到应用，模板里 `<el-xxx>` 直接写，**不用 import 注册** |
| 样式 | **只支持纯 CSS**（`lang="scss"` 会报错）；`<style scoped>` 的 scoped 语义降级为**全局注入**（不做属性转写）→ 页面 class 请带唯一前缀防串样式 |
| 生效 | 改 `.vue` 文件**不用重新构建前端**，刷新页面即取最新（后端按 `mtime` 下发内容） |

参考实现：`app/custom/pages/demo.vue`（计数器 + `inject('request')` 调 `/api/config/site` + el-table）。

**两条路线怎么选**：

| | Schema 页面 | `.vue` 页面 |
|---|---|---|
| 写在哪 | 后端 PHP（`app/controller/admin/api/*Page.php`） | 前端文件（`app/custom/pages/*.vue`） |
| 能力 | 组件树 + 24 栏栅格 + `{{key.path}}` 数据注入，**无脚本** | 完整 Vue 3：响应式/异步/循环/自定义逻辑 |
| 谁维护 | 后端自己能加页面，零前端资产 | 前端同学 |
| 安全边界 | props 白名单，最可控 | 能跑任意页面逻辑（仍是登录后可访问） |

同名时 **Schema 注册表优先** 于同名 `.vue` 文件。

### 2.12 完整示例：做一个「我的信息」页（Api Key + 可用余额）

需求：任何账号登录后只看到**自己**的 Api Key 与可用余额。**全程只写后端 PHP，前端零改动。**

**① 页面类** `<宿主>/app/controller/admin/api/MyInfoPage.php`

```php
class MyInfoPage
{
    public static function schema(): PageSchema
    {
        $page = new PageSchema('我的信息');
        $page->api('info', '/api/schema/my-info');        // 数据接口见 ②

        $page->row(function (Row $row) {                  // 24 栏栅格
            $row->gutter(16);
            $row->col(8, function (Col $col) {            // 左：余额
                $col->card('可用余额', function (Card $card) {
                    $card->statistic('当前可用余额')
                        ->value('{{info.amount}}')->prefix('¥')->precision(2);
                    $card->divider();
                    $card->descriptions(function (Descriptions $d) {
                        $d->column(1);
                        $d->item('登录账号', '{{info.username}}');
                        $d->item('关联账户', '{{info.account}}');
                    });
                });
            });
            $row->col(16, function (Col $col) {           // 右：账户资料
                $col->card('账户资料', function (Card $card) {
                    $card->descriptions(function (Descriptions $d) {
                        $d->border(true)->column(2);
                        $d->item('账户名称', '{{info.name}}');
                        $d->item('接入渠道', '{{info.channel_text}}');
                    });
                });
            });
        });

        $page->row(function (Row $row) {                  // 第二行：接口凭证
            $row->col(24, function (Col $col) {
                $col->card('接口凭证', function (Card $card) {
                    $card->copyText('{{info.api_key}}', 'Api Key');   // 等宽 + 一键复制
                    $card->alert(null, '{{info.notice}}')
                        ->type('{{info.notice_type}}')->closable(false);
                });
            });
        });

        return $page;
    }
}
```

**② 数据接口** `GET /api/schema/my-info`

```php
public function myInfo(Request $request)
{
    $user = $request->user;                               // AuthCheck 注入的登录用户
    $row  = HfPlatformAccount::query()
        ->where('account', (string)$user->username)       // 归属口径：account = 登录用户名
        ->where('status', 1)
        ->first();

    return json(['code' => 200, 'msg' => 'ok', 'data' => $row ? [
        'username'    => (string)$user->username,
        'account'     => (string)$row->account,
        'name'        => (string)$row->name,
        'api_key'     => (string)$row->api_key,
        'amount'      => round((float)$row->amount, 2),   // statistic 要数字
        'channel_text'=> '中核英科',
        'notice_type' => 'info',
        'notice'      => 'Api Key 是接口调用的唯一凭证，请勿泄露。',
    ] : [
        'username'    => (string)$user->username,
        'account'     => '—', 'name' => '—', 'api_key' => '—', 'amount' => 0,
        'channel_text'=> '—',
        'notice_type' => 'warning',
        'notice'      => '未找到与登录账号「' . $user->username . '」关联的平台账户，请联系管理员开通。',
    ]]);
}
```

**③ 注册两处**（宿主 `config/route.php`）

```php
// 顶部：页面声明（页面引擎在插件，业务侧只声明）
PageRegistry::register('my-info', [\app\controller\admin\api\page\MyInfoPage::class, 'schema']);

// /api 分组内：数据接口（自动带 AuthCheck + PermissionCheck）
Route::get('/schema/my-info', [\app\controller\admin\api\SchemaPageDataController::class, 'myInfo']);
```

**④ 建菜单**：菜单管理页新增一条，`path = /custom-page/my-info`（`php start.php restart` 后生效）。

**⑤ 访问**：`http://<宿主域名>/app/curd/custom-page/my-info`，或直接点侧边栏菜单。

两个设计要点：

- **Schema 没有逻辑分支**：页面结构固定，"有没有账户"由数据接口返回占位值 + `notice/notice_type` 表达。
  连 `alert` 的 `type` 都能用占位符（`->type('{{info.notice_type}}')`），所以同一套结构既能显示 info 提示，
  也能显示 warning 警告，页面代码完全不用改；
- **权限天然够用**：`/api/custom/` 与 `/api/schema/` 在 `PermissionCheck` 白名单内（仅要求登录），
  因此任何账号都能打开这一页、看到自己那条数据，**不需要额外配权限节点**。

---

## 3. CURD 业务页 DSL

### 3.1 新增一个 CURD 页（五步）

1. **Model**（业务 `app/model/`）：extends 平台 BaseModel、设 `$table`，必要时加常量字典；
2. **Controller**（业务 `app/controller/admin/api/`）：extends `BaseAdminController`，声明 `$modelClass / $title`，实现 `grid()`；
3. **路由**（业务 `config/route.php`）：`RouteControllerRegistry::registerMany(['/xxx' => XxxController::class])`；
4. **菜单**：`php webman make:permission --name … --slug … --type 1 --path /xxx --icon … --sort n`；
5. **功能声明**：全部在 `grid()` 里流式拼装（列/搜索/表单/操作/汇总/导出开关）。

参考完整实现：`app/controller/admin/api/MROrdersController.php`（话费订单）。

### 3.2 控制器骨架

```php
class MROrdersController extends BaseAdminController
{
    protected string $modelClass = MobileRechargeOrder::class;  // ① 模型（grid 支持传 model，可不写 model()）
    protected string $title = '话费订单';

    protected function grid(): Grid
    {
        $grid = new Grid(new MobileRechargeOrder(), $this->title);
        $grid->getModel()->orderByDesc('id');                   // ② 列表默认排序

        // ③ 列 / 搜索 / 表单 / 操作 / 汇总 ……
        return $grid;
    }
}
```

### 3.3 常用列 `grid()->column($prop, $label)`

| 用法 | 说明 |
|---|---|
| `->width(int)` / `->minWidth(int)` / `->align('center')` / `->fixed('right')` | 布局 |
| `->map([0=>'否',1=>'是'])` | 值→文案 |
| `->label([0=>'danger',1=>'success'])` | 值→el-tag 颜色（配合 map 成徽标） |
| `->display(fn() => …)` | 自定义渲染：回调内 `$this` 即当前行，可访问 `$this->status` 等 |
| `->prefix('￥')` | 前缀文案（金额） |
| `->datetime()` / `->date()` | 时间格式化显示 |
| `->image(int $w, int $h)` / `->staticHost()` / `->open('_blank')` | 图片列 / 静态资源 host / 点击新窗口 |
| `->href(...)` / `->href(callable)` | 单元格链接 |
| `->switch()` | 行内开关 |
| `->remote($table, $valueKey='id', $labelKey='name')` | 关联表翻译（跨库由统一接口代替时自行 display） |
| `->limit(int)`（截断）、`->hidden()`（默认隐藏可配置显示）、`->sortable()` | 其它 |
| `->editable($type='input', $options)` | 单元格编辑 |

### 3.4 搜索 `grid()->filter(callable)`

```php
$grid->filter(function (Filter $filter) {
    $filter->like('remark', '备注');                       // 模糊
    $filter->equal('mobile', '手机号');                    // 精确
    $filter->select('status', '状态')->options(self::STATUS); // 下拉
    $filter->dateRange('created_at', '创建时间');          // 日期区间
    $filter->between('money', '充值金额');                 // 数值区间
    $filter->where(function ($q, $v) { … }, '自定义', 'prop'); // 任意条件（prop 传空为只读标签项）
});
```

### 3.5 表单 `grid()->form(callable)`（新增+编辑共用）

```php
$grid->form(function (Form $form) {
    $form->text('mobile', '手机号')->required()->rules('required|regex:/^\d+$/|min:11');
    $form->select('platform', '充值平台')->options(self::PLATFORM)->default(2);
    $form->number('money', '充值金额')->default(0)->min(0);
    $form->textarea('remark', '备注');
    $form->datetime('created_at', '时间');
    $form->switch('new_system', '新系统');
    $form->remote('business_id', '业务', 'a_businesses', 'id', 'name'); // 远程下拉
});
```

字段通用链式能力（`Field`）：`required / placeholder / help / default / addDefault / editDefault / disabled / addDisabled / editDisabled / hidden / addHidden / addOnly / editOnly / options(map) / multiple / filterable / span / full / row / rules / min / max / step / config(extra)`，支持**动态显隐**：`->when(父值, cb)` / `->addVisibleWhen([...])`。
其中 `addOnly()` = 仅新增场景展示并提交、`editOnly()` = 仅编辑场景（也可以用 `if ($form->isCreate())` 包裹字段，见 3.5.1，效果等价且更好读）。

表单级：`$form->columns(int)`（栅格列数）、`$form->width/height`、`confirm`、`isCreate()` / `isEdit()`（**场景判断，写在 `if` 里**，见 3.5.1）；`Grid::addForm / editForm` 可拆分新增/编辑差异、`setFormTabs / setFormSteps` 分 Tab/分步。

#### 3.5.1 场景字段控制：`if ($form->isCreate())` / `if ($form->isEdit())`

**推荐写法**：把字段注册包在 `if` 里，按场景条件注册。**场景块内声明的字段自动变成「仅该场景」**（等价于在字段上挂 `->addOnly()` / `->editOnly()`）：

```php
$grid->form(function (Form $form) {
    $form->text('account', '账户')->required();   // 块外：两个场景都有
    $form->text('name', '名称')->required();

    if ($form->isCreate()) {
        $form->text('password', '密码')->required(); // 仅新增弹窗
    }
    if ($form->isEdit()) {
        $form->text('amount', '金额')->required();   // 仅编辑弹窗
    }
});
```

效果：

| 弹窗 | 渲染 / 提交的字段 |
|---|---|
| 新增 | account、name、password |
| 编辑 | account、name、amount |

- 块外字段两个场景共用，**不需要任何白名单**：以后新增字段也不会"漏字段"（这正是白名单写法最容易踩的坑）。
- 前端不渲染、不回显、不提交；后端 `add()/update()` 按同一份配置剔除，校验规则同步收敛 —— 抓包往 `update` 强塞 `password=xxx` 也写不进库。
- `if / else` 同样支持（`else` 块里的字段自动成为另一个场景专属）。
- 未指定场景时（如 `Action::form()` 的行级弹窗只有一份表单）两个判断都返回 `true`，两段都注册。
- 回调里**没用**场景判断时只执行一次回调，行为与开销和以前完全一致（用到时才会按另一个场景多执行一次再合并）。

**兼容旧写法（场景白名单）**：`isCreate(...)` / `isEdit(...)` 传字段名时仍是白名单语义 —— 该场景**只**保留列出的字段，支持可变参数、数组、回调动态控制：

```php
$grid->form(function (Form $form) {
    $form->text('mobile', '手机号');
    $form->text('price', '价格');
    $form->hidden('uid', 'UID');

    $form->isCreate('mobile');                 // 创建时：仅展示/提交 mobile（price 不存在）
    $form->isEdit('mobile', 'price');          // 编辑时：展示/提交 mobile + price
    // $form->isCreate(['mobile']);            // 数组写法等价
    // $form->isCreate(fn($ctx) => ['mobile']);// 回调动态控制，$ctx = ['mode' => 'create'|'edit']
});
```

未声明的场景不限制（全部字段可用）；不传字段时按 `if` 判断语义（返回 `true/false`）。

**严格提交（前端 + 后端双端生效）：**

1. **场景专属字段 + 白名单外字段即提交范围**：不渲染、不初始化、不提交；即使恶意客户端多提交，后端 `add()/update()` 保存前也会按同一份配置剔除，校验规则同步剔除（不会因被剔除的 required 字段而报错）。
2. **disabled 不提交**：`->disabled()` / `->addDisabled()` / `->editDisabled()` 生效的场景中，该字段 UI 禁用且**不参与提交**（前端 `sanitizeFormData` 剔除 + 后端白名单剔除）。只读展示需求照常满足，且杜绝"前端禁用、抓包改值"绕过。
3. **例外**：`$form->hidden('prop', ...)` 声明的隐藏提交字段（顶层 `hidden=true`）不受白名单/禁用影响，始终随表单提交；但它若声明在场景块内，仍只在所属场景提交。
4. 条件字段（`->when()`）如需在受限场景使用，应把字段名写进白名单（或用 `if` 写法包裹）；其可见性仍由父字段值在前端实时控制。
5. 数组写法的继承式控制器（`actions()` 同级的 `formFields()` 数组）可在返回的 config 里自带 `sceneShow: {create: [...], edit: [...]}` 键，前端同样生效。

> ⚠️ **三种写法的区别，别搞混**：
>
> | 需求 | 正确写法 | 说明 |
> |---|---|---|
> | 按场景条件注册字段（**推荐**） | `if ($form->isCreate()) { $form->text('password', '密码')->required(); }` | **Form 级判断**：块内字段自动「仅该场景」，块外字段共用 |
> | 某场景**只**要这几个字段 | `$form->isCreate('a', 'b')` | **Form 级白名单**：列出的才展示/提交，没列的一律不展示不提交（**列多了反而漏字段**） |
> | **某一个字段**只在新增 / 只在编辑出现 | `$form->text('password', '密码')->addOnly()` | **字段级**：`addOnly()` = 仅新增，`editOnly()` = 仅编辑（与 `if` 写法等价，适合不想改结构的场景） |
>
> 常见误写：`$form->text('password', '密码')->isCreate()` —— `isCreate()/isEdit()` 只存在于 **Form** 上，
> 挂在 Field 上会抛 `Call to undefined method plugin\curd\app\dsl\Field::isCreate()`。
> 另外白名单语义下「编辑时不含 password」不能写 `$form->isEdit('password')`（那等于编辑只剩 password）。

前端行为见 `GenericCurd/index.vue` 的 `resolveFieldForMode`（场景专属字段靠 `modeOnly`）/ `allFormFields`（白名单过滤 `sceneShow`）/ `sanitizeFormData`；后端见 `CurdActionsTrait` 的 `fieldWritableInMode / writableFields / filterWritableFields`。

### 3.6 自定义操作 Action（行级/批量/全局/弹窗）

```php
$grid->addAction(new MrOrderRetryAction());          // 行级（默认）
$grid->addAction(new MrOrderBatchRetryAction());     // 批量：Action 内 ->batch(true)
// $grid->addAction(…->global(true))                 // 全局按钮：工具栏右侧
```

Action 类（放 `app/controller/admin/api/actions/`）用 `init()` 声明外观、`handle()` 写业务：

```php
class MrOrderQueryStatusAction extends Action
{
    protected function init()
    {
        $this->name('query-status')->label('查询充值状态')->type('primary')
            ->confirm('确定要查询吗？将实时请求充值平台接口')
            ->show(function ($row) {                    // 按行控制是否显示
                return in_array((int)$row->status, [MobileRechargeOrder::STATUS_DOING, MobileRechargeOrder::STATUS_FAILED], true);
            });
    }

    public function handle(Request $request)
    {
        $row = $this->model();                          // 框架注入的当前行模型（行级 action）
        if (!$row) return $this->error('订单不存在', 404);
        // …… 业务逻辑（调平台 SDK 等）……
        return $this->ok('操作成功');                   // 成功/失败统一用基类辅助方法返回
    }
}
```

外观链式方法：`name/label/icon/type/confirm/form/modal/jump/api(url,method)/handler/params/field/show/successMsg/dialogTitle/dialogWidth/inline/batch/global/roles/permission`。`batch(true)` 的按钮出现在批量工具栏（需勾选行），handle 内取勾选的 id：

```php
// 批量 action 的 handle（勾选的 id 在请求里）
public function handle(Request $request)
{
    $ids = $request->post('ids', []);
    $models = MobileRechargeOrder::query()->whereIn('id', $ids)->get();
    // …… 逐条处理 ……
    return $this->ok('批量重试成功，处理条数' . $count);
}
```

要点：`$this->model()` 是框架按请求 id 注入的**当前行**模型（未命中为 null），行级用；批量用 `$request->post('ids')`；`$this->row('字段')` 只在 `init()` 的闭包（show 等）里取当前渲染行数据；基类 `handle()` 未覆盖时会回退到控制器上的 `action{Name}(Request $request)`。

#### 3.6.1 全局/批量按钮 + 表单弹窗（`global()/batch()` × `form()`）

行级、批量、全局按钮都支持 `->form(fn($form){...})` 弹窗表单：点击按钮 → 弹出任意 Form DSL 字段的弹窗 → 用户填写/选文件 → 点确认 → 请求进 `handle(Request $request)`，表单值在 `$request->post()` 里。典型场景：列表工具栏「导入」按钮，点开弹窗选 Excel 文件，确认后处理。

```php
// ① 全局导入按钮（工具栏，与新增/导出同级）
$grid->addAction(new ImportOrdersAction());

class ImportOrdersAction extends Action
{
    protected function init()
    {
        $this->name('import')->label('导入订单')->icon('Upload')->type('primary')
            ->global(true)                                   // 工具栏全局按钮；批量按钮则用 ->batch(true)
            ->dialogTitle('导入订单')
            ->dialogWidth('520px')
            ->form(function ($form) {                        // 弹窗内任意 Form DSL 字段
                $form->file('import_file', '选择文件')
                    ->rules('required');                     // 必填校验复用表单规则
                $form->switch('skip_exists', '跳过已存在');
            });
    }

    public function handle(Request $request)
    {
        $file = $request->post('import_file');   // 已通过 /api/upload 上传后的文件相对路径
        $skipExists = (bool)$request->post('skip_exists');
        // 全局按钮附带当前搜索条件：$request->post('query', [])
        // 批量按钮附带勾选主键：$request->post('ids', [])
        // …… 解析文件、逐行入库 ……
        return $this->ok('导入成功，共处理 ' . $count . ' 条');
    }
}
```

行为说明：
- 表单字段支持全部 Form DSL 能力（file/image/remote/when/isCreate 等）；文件类字段先走 `/api/upload` 上传拿到路径，`handle` 里拿到的就是路径字符串。
- `global(true)` 提交时自动附带 `{ query: 当前搜索条件 }`；`batch(true)` 自动附带 `{ ids: 勾选主键 }`；行级自动附带 `{ id: 当前行主键 }`（`->field()` 可改字段名）。
- 无 `->api()` 时自动路由到 `/api/curd/model/{model}/action/{name}`；弹窗提交失败（接口返回错误）时弹窗保持打开，便于修改后重试。

#### 3.6.2 Excel 导入字段 `$form->excel()`（multipart 直传 + 自动解析）

Excel 导入推荐用专用 `excel` 字段：**不走 `/api/upload`**，本地选文件后以 multipart 表单把二进制直接提交到 action 接口，服务端自动解析，`handle` 里直接拿解析好的数据行：

```php
$grid->addAction(new ImportOrdersAction());

class ImportOrdersAction extends Action
{
    protected function init()
    {
        $this->name('import')->label('导入订单')->global(true)
            ->form(fn($form) => $form->excel('import_file', '选择文件')      // 默认 accept .xlsx/.csv，必选
                                     ->options(['maxRows' => 10000, 'sheet' => 1])
            );
    }

    public function handle(Request $request)
    {
        $rows = $this->excelRows('import_file');  // 首行作表头：[['手机号' => '138...', '金额' => '10'], ...]
        $info = $this->excelInfo('import_file');  // ['count' => 2, 'file_name' => 'xx.xlsx', 'sheets' => 1]
        // …… 逐行业务处理 ……
        return $this->ok('导入成功，共解析 ' . $info['count'] . ' 行');
    }
}
```

`excel($prop, $label, $options)` 的 `$options`：

| 键 | 默认 | 说明 |
|---|---|---|
| `required` | `true` | 前端必选校验 |
| `accept` | `.xlsx,.csv` | 可选文件格式 |
| `maxRows` | `5000` | 最大解析行数（不含表头，超出抛异常） |
| `sheet` | `1` | 解析第几个工作表 |

**解析规则**（内置轻量解析器 `ExcelParser`，零 composer 依赖，需 PHP `zip` + `SimpleXML` 扩展）：
- `.xlsx`：Office Open XML，覆盖 sharedStrings / inlineStr / 数字 / 公式结果字符串；`.csv`：自动识别 UTF-8 BOM 与 GBK 编码
- 首行为表头（空表头自动用 A/B/C 兜底），每行输出为 `['表头' => 值]` 关联数组，整行为空自动跳过
- 日期单元格读出的是 Excel 序列数字（如 `45210.5`），需要日期请用文本格式存储或业务侧自行换算

**取用方式（双写法同一 API）**：
- Action 类写法：`handle()` 内 `$this->excelRows('prop')` / `$this->excelInfo('prop')`
- 数组式继承控制器（`actions(): array` 里写 `'type' => 'excel'` 字段）：`actionXxx()` 内同样 `$this->excelRows('prop')` / `$this->excelInfo('prop')`（trait 提供）
- 未上传文件时 `excelRows()` 返回空数组，业务自行判断报错

### 3.7 列表头汇总条 `grid()->summary(callable)`

```php
$grid->summary(function ($query) {
    $q = clone $query;                                  // $query = 当前筛选后的列表查询，随筛选自动刷新
    $money = (float)(clone $q)->where('status', 2)->sum('money');
    return '<span>成功单合计：<b>￥' . number_format($money, 2) . '</b></span>'; // 返回 HTML
});
```

### 3.8 其它常用开关

```php
$grid->disableCreate() / disableEdit() / disableDelete() / disableView()
     / disableExport() / disableBatchDelete();      // 按需关能力
$grid->setDialogWidth('700px');                     // 弹窗宽
$grid->setCustomCss($css) / setCustomJs($js);       // 页面注入 css/js
$grid->setRowStyle(fn($row) => [...]) / setRowClass(...); // 行样式
$grid->setRules([...]);                             // 提交前校验（参考后端 rules 格式）
```

**导出**：工具栏「导出」下拉（当前页 / 当前选择 / 全部）为框架同步导出，随当前筛选条件导出 CSV，无需额外配置（不实现异步导出任务）。

**导出哪几列**（只收窄、不放宽，最终列 = 三者交集，顺序始终按 grid 定义）：

| 口径 | 写法 | 说明 |
|---|---|---|
| 跟随用户列设置（默认） | 无需配置 | 前端把「列配置」当前勾选的 prop 以 `_columns=prop1,prop2` 随导出请求下发，后端取交集。`->hidden()` 列默认不在勾选集合里，**所以不再进 CSV**；用户手动在「列配置」里勾上，它就跟着导出 |
| 固定白名单（逐页硬约束） | 控制器里 `protected function exportColumns(): array { return ['id','money','channel']; }` | 开发者口径，优先级最高；与用户勾选取交集，未勾选的不会出现 |
| 不做限制 | 以上都不涉及 | 接口直连（不带 `_columns`）或参数解析为空时按 grid 全列导出（含 `hidden`），保持历史行为 |

无 grid 配置的动态 CURD 页会回退到「表字段」，该回退路径同样受 `_columns` 约束。

**导出文件名**：默认 `{页面标题}_{YmdHis}.csv`，标题与 `/api/curd/config` 返回的 `data.title` **同源**
（控制器 `$title` → 落库配置 `curd_configs.title` → 表名兜底）。响应同时给两个头：

```
Content-Disposition: attachment; filename="hf_accoount_waters_20260918205603.csv";
                     filename*=UTF-8''%E8%AF%9D%E8%B4%B9%E6%B5%81%E6%B0%B4_20260918205603.csv
```

- 现代浏览器读 `filename*`，拿到中文标题（如 `话费流水_….csv`）；`filename` 是给老客户端（不认 `filename*`）的 ASCII 兜底。
- 中文标题剥掉非 ASCII 后为空 → ASCII 兜底**退回表名**（`hf_accoount_waters`），不会退化成一串下划线。
- 前端 blob 下载名（`link.download`）由前端自己拼一份同名文件（浏览器不读 `Content-Disposition`），两边规则一致。

**单次导出上限**：超过则**直接报错**（`{"code":400,"msg":"本次将导出 N 条，超过单次导出上限 M 条…"}`），不做静默截断。

| 位置 | 键 | 说明 |
|---|---|---|
| 宿主 `config/admin.php`（推荐） | `'export_max_rows' => 100000` | 与 `upload_path` / `site` 同一个宿主配置文件，运维改这一个即可 |
| 插件 `config/curd.php` | `'export_max_rows' => 100000` | 宿主 `config/curd.php` 顶层同名键可覆盖；宿主未配 `config/admin.php` 时走这里 |

- `<= 0` = 不限制。
- 校验按**实际命中条数**：导出当前页按 `min(总数, size)`，导出选中按选中条数，导出全部按筛选后总数。
- 前端会嗅探响应类型：拿到 JSON 就当错误提示，不会把报错内容当 CSV 下载下来（`dynamicCurd/index.vue` 的 `export`）。

---

## 4. 菜单 / 权限 / 生效

- **菜单命令**：`php webman make:permission --name 页面名 --slug 唯一slug --type 1 --path <path> --icon <el-icon名> --sort n`；`--type 1` 为菜单页。删除/更新菜单在后台「菜单管理」或 `php webman make:permission` 交互内处理。
- **path 取值**：CURD 页 = registerMany key（`/mobile-recharge-orders`）；Schema/.vue 页 = `/custom-page/<name>`。
- **鉴权中间件**：CURD 与 custom 路由都挂在统一 `CorsMiddleware + AuthCheck + PermissionCheck` 上。白名单段（仅需登录、不校验权限节点）：`/api/auth/`、`/api/menu`、`/api/curd/config`、`/api/admin/`、`/api/custom/`、`/api/schema/`（业务 Schema 数据接口段，白名单在 `app/middleware/PermissionCheck.php`）。
- **接口加密**：请求/响应默认走 RSA+AES 信封（`ApiCrypto` 全局中间件，`X-Encrypt-Data` / `{data:...}`），前端 `request` 拦截器透明处理——**DSL 层无感知**。数据接口直接返回 `json(['code'=>200,'msg'=>'ok','data'=>...])` 即可。
- **生效方式**：
  - 新增/修改 PHP 类、路由、注册 → `php start.php restart`（改 config 与常驻内存类需重启；纯路由/页面内容 reload 亦可）；
  - Schema 页面类改内容、`.vue` 文件改内容 → 仅后端改文件，**前端无需重新构建**（协议运行时拉取）；
  - 前端组件注册表/渲染器改动 → 走前端 dev（9528）或重新 `npm run build`。

### 4.1 权限被拒时不泄露内部结构（安全约定）

`PermissionCheck` 命中拒绝后**统一只回一句**，不回显 obj / act / 路径 / 需要哪个权限节点，避免给探测者画出权限地图：

```json
{ "code": 403, "msg": "没有权限操作，请联系管理员（编号 1739601a）" }
```

细节全部写服务端日志（webman 原生 `support\Log` → `runtime/logs/webman-YYYY-MM-DD.log`）：

```
[curd] 权限校验未通过 {...}
  reason: rbac-denied            # 有权限节点但不匹配
  user_id: 39  username: xxx
  method: POST  path: /api/curd/model/HfGoods/add
  need_perm: curd.HfGoods.add:*  ip: 1.2.3.4  trace: 1739601a
```

`reason=unresolved-permission` 表示路径没能推导出 `obj:act`（控制器没注册进 `RouteControllerRegistry` / path 与注册 key 不一致）——那是**配置问题**，不是用户没权限。排查时拿页面上显示的「编号」去日志里 grep 即可。

### 4.2 自定义登录（覆盖内置 login）

三种力度，按需选：

| 方式 | 配置项 | 改什么 | 适用 |
|---|---|---|---|
| ① 只换凭据校验 | `auth_provider` | 校验逻辑（换表 / 换算法 / 对接外部账号） | 仍是「用户名密码 → 内置响应结构」 |
| ② 连响应一起接管 | `login_handler` | 整个 `POST /api/auth/login` | 要加风控 / 单点登录 / 额外返回字段（**验证码插件已内置**，见 §4.7，无需自己写；且校验发生在委派之前，自定义类不必再实现） |
| ③ 自己注册路由 | 宿主 `config/route.php` 注册 `POST /api/auth/login` | 路由 + 逻辑 | 登录协议与内置完全不同（插件检测到路由已被注册即跳过自带那条） |

②最常用，写法（token 体系仍与插件一致）：

```php
// 宿主 config/plugin/curd/curd.php
'login_handler' => \app\admin\MyLogin::class,
```

```php
namespace app\admin;

use support\Request;
use plugin\curd\app\auth\LoginIssuer;

class MyLogin
{
    /** 与内置 login() 同签名：收 Request，返 Response */
    public function login(Request $request)
    {
        $username = (string)$request->post('username');
        // …你自己的校验：验证码、风控、外部单点接口…
        if (!$ok) {
            return json(['code' => 401, 'msg' => '自定义登录：用户名或密码错误']);
        }

        // 签发 token + 拼标准响应（前端拿到的结构完全不变）
        return json(LoginIssuer::issue([
            'id' => $uid, 'username' => $username, 'name' => $name, 'status' => 1,
        ]));
    }
}
```

要点：
- `login_handler` 默认 `''` → 走内置逻辑；**类名写错/类不存在时也自动回退内置**（排查先看类名是否真的被加载，注意 webman 常驻内存要 `restart`）；
- `LoginIssuer::issue($identity, $ttl = 604800)` 负责写 `admin_tokens` 表并返回 `{code,msg,data:{token,...}}`，**与内置登录完全同构** → `AuthCheck`、`/api/auth/me`、前端拦截器都不用改；
- 只要 token 字符串（自己拼响应）用 `LoginIssuer::issueToken($identity, $ttl)`。

### 4.3 新建菜单「一键生成权限」

后台「菜单管理 → 新增」里勾 **「同时生成操作权限（一键生成）」**，会在该菜单下批量创建 type=3 权限节点（不出现在侧边栏），省掉逐条手点。

- **生成哪些**：`list 列表 / add 新增 / update 编辑 / delete 删除 / batch-delete 批量删除 / export 导出`，可勾选子集；
- **前缀怎么来**：① 表单里的「权限标识」 → ② 按 `path` 自动推导 `curd.{Model}`（选完 path 前端会实时预览「将生成的权限」）；
- **推导不出来**（path 不是注册过的 CURD 页）→ **不生成**，接口返回 `warning` 提示手填权限标识后重试；
- **接口**：`POST /api/menu`（`add` / `update` 都支持），额外字段 `gen_permissions=1`、`permission_ops=list,add,update`（不传 = 全部）；
- **幂等**：已存在的 permission 直接跳过 → 老菜单可以「编辑时再勾一次」补生成；
- **预览接口**：`GET /api/menu/permission/suggest?path=/hf-goods` → `{base:'curd.HfGoods', ops:[{op,title,permission}]}`；
  行内快速创建传 `?parent_id=<菜单id>`，走的是与写入**同一套**前缀推导，保证「预览 = 实际写入」。

### 4.4 菜单行内「快速创建权限」

菜单/容器行末尾的 🔑 按钮（type=3 行不显示）：**只输权限名**就能建一个 type=3 权限节点，比走「新增菜单」快得多。

- 弹窗填「权限名」（如 `审核`、`audit`），可选「标识后缀」（留空按权限名生成：ASCII 转小写连字符、中文原样保留），并实时预览最终 `permission`；
- **前缀自动取**：父菜单自身的 `permission`（如 `curd.HfGoods`）→ 否则取父菜单已有子权限的公共前缀 → 否则按父菜单 path 推导（预览值由 `GET /api/menu/permission/suggest?parent_id=` 下发，与写入规则一致）；
- **重名**返回 400：`权限标识已存在：curd.HfGoods.audit`；
- **接口**：`POST /api/menu/permission/quick`，参数 `parent_id`（必填，不能是 type=3 节点）、`name`（必填）、`slug`（可选）。

### 4.5 URL 里只能传「已注册模型名」，猜不到别的表

`/api/curd/model/{X}` 的 `{X}` **不是表名**，是 `ModelRegistry` 里的模型短类名（如 `HfGoods` → `app\model\HfGoods`，表名写在模型类里）。
换个名字试探别的表的路子被三层堵死：

| 传什么 | 结果 |
|---|---|
| 已注册模型名（`HfAccoountWater`） | 200，正常返回（权限不足则 403） |
| app/model 下**存在**的模型类（扫描/注册过，如 `AdminUser`） | 可解析，**这是设计内**；能不能出数据看权限（管理员 200 / 业务账号 403） |
| 业务库真实存在、但没有模型类也没登记的表（`admin_roles`、`hf_accoount_waters`） | `{"code":404,"msg":"模型 xxx 未注册"}` |
| 纯手写的名字（`NotExistThing`） | 同上 404 |
| **传表名**（`admin_roles`） | 同上 404（表名一律不认） |

控制点在 `ModelRegistry::$allowDynamicResolve`（**默认 false**，收紧口径）：关着时只有
①注册表里的模型 ②`app/model` 下真实存在的模型类 ③`dynamicAllowedTables`（启动时从 `curd_configs` 已登记的表灌入）能被解析；
开了才会对未登记的表做「模型名 → 表名」反推 + eval 生成类。也就是说：
**页面能访问哪张表，取决于开发者建了多少模型/配了多少条 `curd_configs`，与数据库里有什么表无关。**

即便模型名猜对了，仍要过 `PermissionCheck` 的 `curd.{模型}.{动作}` 校验（见 4.1），权限不足只回一句
`{"code":403,"msg":"没有权限操作，请联系管理员（编号 xxxxxx）"}`。实测（huafei）：

| 请求（账号 guangzhouzeqin，非 admin） | 结果 |
|---|---|
| `HfGoods` / `HfAccoountWater`（已授权） | 200，且**按账号维度隔离**（`account_id` 过滤，返回 0 条也正常） |
| `AdminUser` / `AdminMenu` / `HfPlatformAccount`（未授权） | 403 一句带过 |
| 无 token | 401 未登录 |

> **注意**：`app/model` 下被扫描注册的模型都是「可访问的候选」，能不能进看权限。像 `AdminUser` 这类表
> 管理员有 `p,admin,*,*` 全通权限，列表会**原样带出 `password` 哈希**——如果不想暴露，应在模型 `$hidden`
> 里加 `password`，或在 `PermissionCheck` 侧不给普通角色该模型的权限。

### 4.6 默认落地页 `home_page`（登录后 / 刷新根路径打开哪一页）

后台根路径（`/app/curd/`、`/app/curd/?...`）以及**每次登录成功**，打开的页面都由宿主配置唯一决定（`?redirect=` 不参与，见下方口径表）：

```php
// config/admin.php
'home_page' => '/custom-page/home',   // 相对路径，不带 /app/curd 前缀
```

| 项 | 说明 |
|---|---|
| 取值来源（优先级） | 宿主 `config/admin.php` 的 `home_page` → 同文件 `site.home_page` → 插件 `config/curd.php` 的 `home_page` → `/dashboard` |
| 写法 | `/custom-page/home`、`custom-page/home` 都行（自动补前导斜杠、折叠重复斜杠）；也支持写完整 `http(s)://...`，此时前端整页跳转 |
| 生效方式 | 前端每次启动拉 `/api/config/site`（含 `home_page`），**改配置 + `restart` 即可，无需重新构建前端** |
| 页面不存在时 | 不阻断：该路径由通配路由交给 `CustomPageHost`，页面缺失时显示「页面加载失败」而不是白屏；配置项本身也不会回退成别的页 |
| 与菜单/权限的关系 | **只决定打开哪个页面**，不要求用户菜单里有这一项；菜单里没有也能直接打开（这正是「哪怕菜单里没有也要打开默认页」的用法） |
| 前端缓存 | 落地页会存一份 `localStorage.admin_home_page`，冷启动（刷新时接口还没回来）也能落对页；接口成功返回后会覆盖/清理 |

也会影响这些地方（都用同一个 `getHomePath()`）：已登录访问 `/login`、标签栏「关闭所有 / 关闭最后一个标签」时的兜底跳转。

> 顺带修掉一个老问题：前端 `utils/site.js` 原先按**同名键**拷贝后端字段，而后端是 `logo_type`、前端是 `logoType`，
> 导致 `config/admin.php` 里配 `logo_type` 一直不生效（永远按默认 icon 渲染）。现在加了 snake→camel 映射（`logo_type → logoType`、`home_page → homePage`）。

**唯一口径（v1.12.0 起）：登录成功一律打开 `home_page`，`?redirect=` 彻底不参与。**

| 场景 | 行为 |
|---|---|
| 登录成功（无论从哪来） | `router.push(home_page)`，**完全不看** `?redirect=` |
| 未登录访问根路径 `/app/curd/` | 落到 `/app/curd/login`（**不带任何参数**）；登录后按当时配置的 `home_page` |
| 未登录访问子页 `/app/curd/hf-goods` | 同样落到 `/app/curd/login`（不再记录「原页」） |
| token 失效（401 拦截） | 清 token 后跳 `/app/curd/login`（不带参数）；重新登录落 `home_page` |
| 已登录访问 `/login` | 回落 `home_page` |
| 手动敲 `/app/curd/dashboard` 等具体页 | 照常直达，不受影响 |

> 为什么砍掉 `?redirect=`：把「要去的页」写进 URL，它就会长期存活在书签/浏览器历史里。
> 之后把 `home_page` 从 `/custom-page/home` 改成 `/dashboard`，旧 URL 仍按旧值跳 —— 表现成「改了配置没生效」，
> 排查成本极高（真实踩过）。代价是**登录过期后重新登录会落到 `home_page`，而不是被打断的那一页**，这是刻意的取舍。

**另外两点必须知道**：

1. **未登录访问根路径不会带参数**：根路径分支先 `await ensureSiteConfig()`（`/api/config/site` 免鉴权），
   未登录直接 `next({ path: '/login', replace: true })`，登录后按「那一刻」的配置算落地页。
2. **侧边栏「首页」菜单与 `home_page` 无关。** 那是菜单表里的数据（huafei 里 `menus.id=1`，`path=/dashboard`），
   点它永远去 `/dashboard`。要让侧边栏「首页」也指向自定义页，改菜单那一行的 `path` 即可（纯数据改动，不用动代码）。

### 4.7 登录图形验证码（webman/captcha）与「记住账号」

登录页默认带图形验证码，开箱即用，**无需改前端**（前端产物已内置）：

```bash
composer require webman/captcha      # 宿主安装一次即可（ext-gd / ext-mbstring 必装）
```

```php
// 宿主 config/admin.php（推荐；没有 config/admin.php 的宿主写 config/curd.php 同名顶层键）
'captcha_enabled' => true,   // false = 登录页完全不显示验证码，登录接口也不再校验
'captcha_ttl'     => 300,    // 有效期（秒）
'captcha_length'  => 4,      // 位数（3~6，越界回落 4）
```

**接口契约**（前端已实现，自建页面时可直接复用）：

| 接口 | 请求 | 响应 |
|---|---|---|
| `GET /api/auth/captcha` | 无（免鉴权） | `{"code":200,"data":{"key":"<32位hex>","image":"data:image/jpeg;base64,..."}}` |
| `POST /api/auth/login` | `{username, password, captcha_key, captcha_code}` | 验证码不过：`{"code":400,"msg":"验证码错误","data":{"captcha":true}}` |

| 关键点 | 说明 |
|---|---|
| 存哪 | Redis `curd_captcha:{key}` = 验证码明文（小写），TTL = `captcha_ttl`。**不用 session**（插件是 Bearer token 架构，不依赖 cookie，跨域开发也不用 credentials） |
| 一次性 | `Captcha::verify()` **无论对错都删 key**，杜绝「同一张图反复试探」。所以登录失败后前端会**自动换一张新图**（`loadCaptcha()`） |
| 大小写 | 不敏感（两边都 `strtolower`）；字符集已剔除易混的 `i / l / o / 0 / 1` |
| 校验位置 | 在 `AuthController::login()` 的**最前面**，早于 `login_handler` 委派 → 自定义登录类**不用自己实现验证码**；确实不需要就在 `login_handler` 里关掉 `captcha_enabled` |
| 前端显隐 | 由 `/api/config/site` 的 `captcha_enabled`（布尔）下发；为 `false` 时输入框与图片整块不渲染。**配置请求失败时前端兜底**：先不显示，一旦后端返回含「验证码」的错误 → 自动展开并拉图 |
| 点图刷新 | 点验证码图片即换一张（`loadCaptcha()`），同时清空已输入内容 |
| 关掉/应急 | `captcha_enabled => false` + `restart`。**Redis 不可用时登录会被拒**（验证码服务暂不可用）→ 这就是应急开关 |
| 生效方式 | 改配置 → `php start.php restart`；**前端不用重建** |

**「记住账号」**：登录成功后把用户名写进 `localStorage.admin_login_username`（见前端 `utils/remember.js`），
下次打开登录页自动回填，并把光标落在密码框。**只记账号不记密码**；换账号登录会覆盖；退出登录**不清除**（否则就失去意义）。
想彻底忘掉：清浏览器数据，或在 `src/views/login/index.vue` 里调 `clearRememberedUsername()`。

> 注意：`localStorage` 按**源（协议+域名+端口）**隔离。`127.0.0.1:18082` 与 `127.0.0.1:8787` 是两个源，
> 验证时别把「另一个端口没回填」当成 bug。
>
> 若宿主自己实现了 `login_handler` 且**不希望**有验证码：把 `captcha_enabled` 设为 `false`，
> 否则因为校验在委派之前，所有登录都会先被验证码拦下（表现为「明明密码对，一直提示验证码错误」）。

---

## 5. 踩坑速查

| 现象 | 原因/解法 |
|---|---|
| 新页面 404 | `PageRegistry::register` 没写（或没 restart）；页面名与 name 不一致 |
| props 不生效 | 键不在前端注册表白名单 → 被丢弃；查 `componentRegistry.js` 对应 type 的 props |
| 页面显示 `[未注册节点: xxx]` | type 拼错或前端漏登记 |
| collapse 不展开/展开错乱 | 展开态渲染器托管：用 `->active([...])` 设初始 name，item 需有 `->name()` 才能精确命中 |
| `*-item` 没显示 | 条目必须直接挂在对应容器 children，不能夹在中间组件里 |
| statistic 显示 NaN/空 | 整串占位取不到值时保留原文；确认数据接口返回数字、key 路径写对（`{{stats.amount}}` 是 `data.amount`） |
| Action 按钮不出现 | `->show(fn)` 返回 false；或 `->batch(true)` 的按钮在批量工具栏出现（需勾选行） |
| 想改全局变量/常量后无效 | webman 常驻内存，改 PHP 需 `restart` 而非仅 reload |
| 登录后仍进 `/dashboard`（没走配置的 `home_page`） | 按顺序查：①`config/admin.php` 的 `home_page` 改了但没 `restart`；②前端是旧产物（登录跳转在前端，需重新构建部署）；③浏览器里是**旧书签/历史**（`login?redirect=/custom-page/home` 这类旧值已被忽略，见 4.6 口径表）；④从侧边栏点了「首页」菜单（那是菜单数据，与 `home_page` 无关）；⑤`localStorage.admin_home_page` 是旧值且 `/api/config/site` 请求失败（看 Network） |
| 登录后落点跟「上次被拦住的那一页」不一致 | v1.12.0 起刻意如此：登录一律跳 `home_page`，不再回跳原页（`?redirect=` 已彻底不参与），见 4.6 |
| 403 只看到「编号」看不到原因 | 设计如此（不泄露权限结构）：拿编号 grep `runtime/logs/webman-*.log` 看 `reason` |
| 403 日志里 `reason=unresolved-permission` | 不是没权限，是路径推导不出 `obj:act`：控制器没进 `RouteControllerRegistry`，或菜单 path 与注册 key 不一致 |
| 勾了「一键生成权限」却没生成 | `path` 不是已注册的 CURD 页 → 推导不出 `curd.{Model}`；接口响应 `data.warning` 会提示，手填「权限标识」后重试 |
| 「快速创建权限」报重名 400 | 该 `permission` 已存在（含之前一键生成过的）；换个后缀名，或去菜单树里找已有节点 |
| 自定义登录没生效 | `login_handler` 类名写错会静默回退内置；改 config 后需 `restart`，并确认类真的能被自动加载（PSR-4 路径/命名空间） |
| `.vue` 页面报「不支持导入模块 xxx」 | 运行时编译只放行 `vue` / `element-plus` / `@element-plus/icons-vue`；发请求改用 `inject('request')` |
| `.vue` 页面 `<style lang="scss">` 报错 | 浏览器端无预处理器，只能用纯 CSS（`scoped` 也会降级为全局，class 加唯一前缀） |
| 登录一直提示「验证码错误」「请输入验证码」 | ①宿主要求验证码但自己的登录页没送 `captcha_key/captcha_code`（用了自定义 `login_handler` 且不想用验证码 → `captcha_enabled => false` 后 restart）；②前端产物是旧的（不含验证码字段）→ 重新构建部署；③`/api/auth/captcha` 返回 500「验证码生成失败：Class "Webman\Captcha\..." not found」→ 宿主没 `composer require webman/captcha`（见 4.7） |
| 验证码位置只有一行「点击获取」，没有图片 | `/api/auth/captcha` 没返回有效 key，看响应 `msg`：ext-gd 未装 / 字体缺失 / Redis 不可用；点那一行可重试 |
| 验证码输入框整块不显示（后端却仍要验证码） | `captcha_enabled=false` 生效了，或 `/api/config/site` 请求失败（前端兜底不显示）——后端返回的错误里带 `data.captcha=true`，前端会**自动展开并拉图**，重试一次即可 |
| 换了端口打开登录页，账号没自动回填 | `localStorage` 按源隔离（`127.0.0.1:18082` ≠ `127.0.0.1:8787`），属正常；换端口就是另一个站点的数据 |

---

*文档配套可运行示例：Schema → `app/controller/admin/api/HomePage.php` + `SchemaPageDataController`；CURD → `app/controller/admin/api/MROrdersController.php` + `app/controller/admin/api/actions/`；最小参考 → 安装器落位到宿主根的示例 `app/controller/admin/api/MyTestController.php`（源在 `examples/`，含完整写法注释）。插件安装 / 宿主内升级 / 生产部署见本目录《插件安装升级与生产部署》。*
