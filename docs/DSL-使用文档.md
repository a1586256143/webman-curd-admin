# webman-huafei-platform 后台 DSL 使用文档

> 适用版本：2026-09-06（Schema 页面引擎已迁入 plugin/crud 插件；插件自带「测试管理」后台示例
> my_test / MyTestController，安装时自动建表 + 幂等补菜单，可作为新控制器最小参考）
>
> 本文面向「用 DSL 在话费平台后台造页面」的开发者。后端 DSL 分两类：
>
> | DSL | 用途 | 形态 | 代码位置 |
> |---|---|---|---|
> | **CRUD DSL** | 列表管理页（表格/搜索/表单/自定义操作/导出/汇总条） | `Grid/Form/Filter/Column/Action` 流式声明 | `plugin/crud/app/dsl/` |
> | **Schema 页面 DSL** | 展示型页面（首页/看板，组件套组件 + 24 栏栅格 + 数据注入） | `PageSchema + blocks` 编译成 JSON 组件树 | `plugin/crud/app/schema/` |

---

## 1. 分层与文件归属（先读这一段）

自研 CRUD 插件 `plugin/crud` 提供**通用引擎**，业务项目（本项目 `webman-huafei-platform`）在 **app 层**消费它。两者严格分工：

| 内容 | 归属 | 说明 |
|---|---|---|
| Schema 引擎（SchemaNode / PageSchema / PageRegistry / blocks 全部节点类） | 插件 `plugin/crud/app/schema/` | 命名空间 `plugin\crud\app\schema\*`，与业务无关 |
| 自定义页面分发控制器 `CustomPageController`（`.vue` + Schema 两种分发） | 插件 `plugin/crud/app/controller/CustomPageController.php` | 路由在插件 `plugin/crud/config/route.php` |
| Schema / .vue 页面 **内容接口** 路由 `GET /api/custom/pages`、`GET /api/custom/page` | 插件 | 随插件自动加载 |
| **业务 Schema 页面类**（如首页 `HomePage`） | 业务 `app/controller/admin/api/HomePage.php` | 用户级文件，自己 `use` 插件 DSL |
| Schema 页面**数据接口**（dataApi 指向的业务统计） | 业务 `app/controller/admin/api/SchemaPageDataController.php` | 查业务表，属业务逻辑 |
| Schema 页面**注册**（`PageRegistry::register`） | 业务 `config/route.php` 顶部 | 业务对插件扩展点的声明 |
| `.vue` 自定义页面文件 | 业务 `app/custom/pages/*.vue` | 插件只读取分发，不存放 |
| CRUD 业务控制器（Model + Grid） | 业务 `app/controller/admin/api/*Controller.php` | extends `BaseAdminController` |
| CRUD 路由注册 `RouteControllerRegistry::registerMany` | 业务 `config/route.php` | |

**页面访问入口约定**（前端统一承载，菜单 path 填它）：
- CRUD 页：`/mobile-recharge-orders` 这类（即 registerMany 的 key）
- Schema / .vue 自定义页：`/custom-page/<页面名>`（前端 catch-all 路由命中）

---

## 2. Schema 页面 DSL（重点）

### 2.1 一句话原理

一个页面 = 后端 PHP DSL 流式声明的一棵**组件树 JSON**，前端 `SchemaRenderer` 递归把它渲染成真实 Element Plus 页面。树节点统一为：

```json
{ "type": "card", "props": { "header": "今日概览" }, "children": [ … ] }
```

`type` 在**前后端注册表**里登记（后端 `plugin/crud/app/schema/blocks/` 的类，前端 `frontend/src/schema/componentRegistry.js`），`props` 走白名单透传。**Schema 是纯数据、无脚本**，比自定义 .vue 更可控。

### 2.2 新建一个页面（三步）

**① 写页面类**（用户级文件放 `app/controller/admin/api/`，命名如 `XxxPage.php`）：

```php
<?php
namespace app\controller\admin\api;

use plugin\crud\app\schema\PageSchema;
use plugin\crud\app\schema\blocks\Col;
use plugin\crud\app\schema\blocks\Card;
use plugin\crud\app\schema\blocks\Row;

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
| `->row/col/card/collapse/timeline/descriptions/divider/alert/statistic/image/text/node` | 顶层挂载（来自 `HasBlocks` trait，下面节点通用） |
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
| `text` | `Text` | `->value(str)` | 纯文本（渲染 span，非 EP 组件），说明文字用 |

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

1. **后端** `plugin/crud/app/schema/blocks/Button.php`：`class Button extends SchemaNode { __construct(){ $this->type='button'; } public function text($v){ return $this->set('text',$v); } }`（并可在 `HasBlocks::button()` 加便捷入口）；
2. **前端** `componentRegistry.js` 登记一行：`button: { component: ElButton, props: ['text','type','link', ...COMMON] }`。

渲染器零改动。当前已注册 type 全表 = 第 2.4~2.6 节列出的 17 个。

---

## 3. CRUD 业务页 DSL

### 3.1 新增一个 CRUD 页（五步）

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

表单级：`$form->columns(int)`（栅格列数）、`$form->width/height`、`confirm`；`Grid::addForm / editForm` 可拆分新增/编辑差异、`setFormTabs / setFormSteps` 分 Tab/分步。

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

---

## 4. 菜单 / 权限 / 生效

- **菜单命令**：`php webman make:permission --name 页面名 --slug 唯一slug --type 1 --path <path> --icon <el-icon名> --sort n`；`--type 1` 为菜单页。删除/更新菜单在后台「菜单管理」或 `php webman make:permission` 交互内处理。
- **path 取值**：CRUD 页 = registerMany key（`/mobile-recharge-orders`）；Schema/.vue 页 = `/custom-page/<name>`。
- **鉴权中间件**：CRUD 与 custom 路由都挂在统一 `CorsMiddleware + AuthCheck + PermissionCheck` 上。白名单段（仅需登录、不校验权限节点）：`/api/auth/`、`/api/menu`、`/api/crud/config`、`/api/admin/`、`/api/custom/`、`/api/schema/`（业务 Schema 数据接口段，白名单在 `app/middleware/PermissionCheck.php`）。
- **接口加密**：请求/响应默认走 RSA+AES 信封（`ApiCrypto` 全局中间件，`X-Encrypt-Data` / `{data:...}`），前端 `request` 拦截器透明处理——**DSL 层无感知**。数据接口直接返回 `json(['code'=>200,'msg'=>'ok','data'=>...])` 即可。
- **生效方式**：
  - 新增/修改 PHP 类、路由、注册 → `php start.php restart`（改 config 与常驻内存类需重启；纯路由/页面内容 reload 亦可）；
  - Schema 页面类改内容、`.vue` 文件改内容 → 仅后端改文件，**前端无需重新构建**（协议运行时拉取）；
  - 前端组件注册表/渲染器改动 → 走前端 dev（9528）或重新 `npm run build`。

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

---

*文档配套可运行示例：Schema → `app/controller/admin/api/HomePage.php` + `SchemaPageDataController`；CRUD → `app/controller/admin/api/MROrdersController.php` + `app/controller/admin/api/actions/`；最小参考 → 插件内置「测试管理」`MyTestController`。插件安装 / 宿主内升级 / 生产部署见本目录《插件安装升级与生产部署》。*
