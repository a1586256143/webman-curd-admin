# 后台 DSL · CURD 页面

> 列表管理页：表格 / 搜索 / 表单 / 自定义操作 / 汇总条 / 导出，用 `Grid/Form/Filter/Column/Action` 流式声明。
> 代码位置 `plugin/curd/app/dsl/`；配套示例见 `app/controller/admin/api/MROrdersController.php`。
> 展示型页面见[DSL · Schema 页面](10-DSL-Schema页面.md)，菜单 / 权限 / 生效见[菜单与权限](12-菜单与权限.md)。

---

## 1. 五步建一个 CURD 页

1. **Model**（业务 `app/model/`）：extends 平台 BaseModel、设 `$table`，必要时加常量字典；
2. **Controller**（业务 `app/controller/api/` 或 `admin/api/`）：extends `plugin\curd\app\controller\base\BaseCurdController`，声明 `$title`，实现 `grid()`；
3. **路由**（`config/admin-route.php` 的 `register` 闭包，或业务 `config/route.php`）：`RouteControllerRegistry::registerMany(['/xxx' => XxxController::class])`；
4. **菜单**：后台「菜单管理」新增（`path` 填上一步的路径）；宿主若带 `make:permission` 命令（如 huafei）也可用命令建；
5. **功能声明**：全部在 `grid()` 里流式拼装（列 / 搜索 / 表单 / 操作 / 汇总 / 导出开关）。

参考完整实现：`examples/MyTestController.php`（示例「测试管理」，覆盖点式字段、`display()` HTML、
`help()` 里写 `@click`、`setCustomJs()` 与 `window.curd`）。

> **本篇 vs [API 参考](20-API-Grid.md)**：本篇按任务讲「怎么拼成一个能跑的页面」，示例偏整体；
> 逐个方法的参数与边界条件看 API 参考（与源码逐行对齐），两处冲突时以 API 参考为准。

## 2. 控制器骨架

```php
use plugin\curd\app\controller\base\BaseCurdController;
use plugin\curd\app\dsl\Grid;

class MROrdersController extends BaseCurdController
{
    protected string $title = '话费订单';

    protected function grid(): Grid
    {
        // ① 推荐写法：不声明 $modelClass —— 框架自动从 Grid 的模型实例提取回填
        //    （Grid 里 new 的 Model 与 $modelClass 本就是同一个类，无需写两遍）
        // ② 推荐写法：Grid 不传 title —— 自动读控制器的 $this->title
        $grid = new Grid(new MobileRechargeOrder());
        $grid->getModel()->orderByDesc('id');                   // ③ 列表默认排序

        // ④ 列 / 搜索 / 表单 / 操作 / 汇总 ……
        return $grid;
    }
}
```

> 旧写法（声明 `$modelClass` + `new Grid(new Model(), $this->title)`）依然兼容；
> 新写法少两行、且改模型类时只改一处。字段标题请统一用 `__()` 翻译，见 §9。

## 3. 常用列 `grid()->column($prop, $label)`

| 用法 | 说明 |
|---|---|
| `->width(int)` / `->minWidth(int)` / `->align('center')` / `->fixed('right')` | 布局 |
| `->map([0=>'否',1=>'是'])` | 值→文案 |
| `->label([0=>'danger',1=>'success'])` | 值→el-tag 颜色（配合 map 成徽标） |
| `->display(fn() => …)` | 自定义渲染：回调内 `$this` 即当前行，可访问 `$this->status` 等 |
| `->display('<b>{name}</b>')` | **字符串模板 = HTML**：直接按 HTML 渲染（v-html）；`{prop}` 取当前行字段、`{value}` 取该列原始值，如 `<a href="/u/{id}">{name}</a>` |
| `->display(fn($v) => '<i>'.$v.'</i>')` | 闭包返回 HTML：**默认自动检测**（返回值含真实标签就按 HTML 渲染）；`->html()` 强制 HTML、`->html(false)` 强制文本（详见 [Column §3](21-API-Column.md#3-显示值与-html)） |
| `->prefix('￥')` | 前缀文案（金额） |
| `->datetime()` / `->date()` | 时间格式化显示 |
| `->image(int $w, int $h)` / `->staticHost()` / `->open('_blank')` | 图片列 / 静态资源 host / 点击新窗口 |
| `->href(...)` / `->href(callable)` | 单元格链接 |
| `->switch()` | 行内开关 |
| `column('config.api_url', '接口地址')` | **点式列**：根字段是 JSON 文本时自动解码取子键；`->map()/->datetime()` 等列方法全部可用（见 §5.2） |
| `column('user.name', '用户')` | **点式列走关联**：`user` 不是真实字段时当作关联关系（`belongsTo/hasOne`），自动 `with('user')` 预加载；**字段优先，字段没有才走关联** |
| `->modal('标题', $content)` | **点击弹窗**：内容服务端逐行生成，支持 `Table/Card/Html` 组件、闭包、类名、HTML 模板（见 §3.1） |
| `->expand($content, '标题')` | **整行展开**：点行任意位置展开/收起，内容通栏显示（无行首箭头）；写法同 `modal` |
| `->remote($table, $valueKey='id', $labelKey='name')` | 关联表翻译（跨库由统一接口代替时自行 display） |
| `->limit(int)`（截断）、`->hidden()`（默认隐藏可配置显示）、`->sortable()` | 其它 |
| `->copy()` | **可复制列**：单元格带复制按钮，一键复制该列文本（复制的是 `display()` 之后的最终展示值；与 tag/switch/image 等共存时 copy 优先） |
| `->select([1=>'安卓',2=>'iOS'])` | **行内下拉编辑**（`= editable('select', …)` 的便捷写法）：参数为 `[值 => 文案]` 关联数组，或 `[['value'=>1,'label'=>'安卓'],…]` 二维列表，两种写法等价；选中即提交保存，字段自动加入可写字段白名单 |
| `->editable($type='input', $options)` | 单元格编辑（`select` 的底层方法） |

### 3.1 点击弹窗 / 行内展开（`modal()` / `expand()`）

列值旁挂一个交互，内容由**服务端逐行生成**（随列表接口返回，前端零代码）：

```php
use plugin\curd\app\dsl\component\Card;
use plugin\curd\app\dsl\component\Table;

// 行内展开：点一下展开，再点收起
$grid->column('money', '明细')->expand(function ($value, $row) {
    return new Table(['xxx' => '标题'], [['xxx' => '111'], ['xxx' => '1222']]);
});

// 弹窗：标题 + 内容
$grid->column('order_no', '订单号')->modal('订单详情', function ($value, $row) {
    return new Card(['订单号' => $value, '金额' => $row->amount], '基本信息');
});

// 类名写法：实例化后调 render($row)
$grid->column('id', 'ID')->modal('详情', OrderDetailModal::class);
```

内置组件：`new Table(列, 行, 选项)`、`new Card(['标签' => '值'], '标题')`、`new Html('<b>..</b>')`。
内容也可以直接给 HTML 模板字符串（`'<b>{order_no}</b>'`，`{prop}` 取行字段、`{value}` 取本列值）。

优先级：`modal` > `expand` > `copy` > `display` > 其它类型。详见 [Column §9](21-API-Column.md#9-弹窗与行内展开)。

## 4. 搜索 `grid()->filter(callable)`

```php
$grid->filter(function (Filter $filter) {
    $filter->like('remark', '备注');                       // 模糊
    $filter->equal('mobile', '手机号');                    // 精确
    $filter->select('status', '状态')->options(self::STATUS); // 下拉
    $filter->dateRange('created_at', '创建时间');          // 日期区间
    $filter->between('created_at', '创建时间');            // 日期时间区间（默认 datetimerange）
    $filter->where(function ($q, $v) { … }, '自定义', 'prop'); // 任意条件（prop 传空为只读标签项）
});
```

### 4.1 搜索项默认值 `->default()`

给搜索项配默认值，**首屏加载就带上它**（搜索框已填好），点「重置」回到默认值而不是清空：

```php
$grid->filter(function (Filter $filter) {
    // 日期区间：相对表达式在输出配置时按「当前时间」求值
    $filter->dateRange('created_at', '创建时间')->default('-7d');            // 近 7 天（7 天前 ~ 今天）
    $filter->dateRange('created_at', '创建时间')->default(['month', 'today']); // 本月 1 号 ~ 今天
    $filter->dateRange('created_at', '创建时间')->default(['2026-01-01', '2026-01-31']); // 固定区间
    $filter->between('updated_at', '更新时间')->datetime()->default(['-1d', 'now']);
    // 其它类型：原样
    $filter->select('status', '状态')->options(self::STATUS)->default(1);
    $filter->singleDate('begin_time', '开始时间')->default('today');
});
```

区间类（`daterange` / `datetimerange`）取值规则：

| 写法 | 结果 |
|---|---|
| `['-7d', 'today']` | 两端各自求值 |
| `['-3d', null]`（另一端给 `null` / `''`） | 缺失端补「现在」 |
| `'-7d'`（单个**相对表达式**） | 起点 = 表达式，终点 = 现在 |
| `'2026-01-01'`（单个**具体日期**） | 两端同值（就是那一天） |

日期字符串支持相对写法（输出配置时按当前时间展开）：

| 写法 | 含义 |
|---|---|
| `today` / `now` | 此刻 |
| `yesterday` | 昨天 |
| `week` / `month` / `year` | 本周一 / 本月 1 号 / 本年 1 月 1 号 |
| `'-7d'` `'+3days'` `'-2weeks'` `'+1month'` `'-1year'` | 相对偏移（`±N` + `d/day(s)/w/week(s)/month(s)/year(s)`） |
| `'2026-01-01'` / `'2026-01-01 08:00:00'` | 原样透传 |

> - 前端协议字段是 `default`（`defaultValue` 为等价别名），由 `GenericCurd` 在挂载时写入 `searchForm`，
>   所以**首次列表请求就带默认值**；`default` 只影响首屏与「重置」，用户改动后不会被覆盖。
> - 配置里输出的是**求值后的具体日期**（页面每次加载重新计算），因此「近 7 天」永远跟着当天走。
> - `->where(...)` 自定义条件同样支持 `->default('x')`：默认值会填进输入框并参与首次查询（闭包按有值执行）。
> - 前端硬编码 config（不走 DSL）时，直接在 `search` 项上写 `default` / `defaultValue` 即可。

## 5. 表单 `grid()->form(callable)`（新增+编辑共用）

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

**字段通用链式（`Field`）**：`required / placeholder / help / default / addDefault / editDefault / disabled / addDisabled / editDisabled / hidden / addHidden / addOnly / editOnly / options(map) / multiple / filterable / span / full / row / rules / min / max / step / config(extra)`；
动态显隐 `->when(父值, cb)` / `->addVisibleWhen([...])`。
`addOnly()` = 仅新增场景、`editOnly()` = 仅编辑场景（也可用 `if ($form->isCreate())` 包裹，见 §5.1，更好读）。

**表单级**：`$form->columns(int)` 栅格列数、`$form->width/height`、`confirm`、`isCreate()` / `isEdit()`；
`Grid::addForm / editForm` 拆分新增/编辑差异、`setFormTabs / setFormSteps` 分 Tab/分步；
`$form->labelWidth(int)` 标签宽（默认 100）、`labelPosition('right'|'left'|'top')`、
`contentWidth(string)` 内容区整体最大宽度（元素区 ≈ contentWidth − labelWidth，不设则撑满弹窗）。

### 5.1 场景字段控制：`if ($form->isCreate())` / `if ($form->isEdit())`

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

- 块外字段两个场景共用，**不需要任何白名单**（以后加字段也不会「漏字段」—— 白名单写法最常踩的坑）。
- 前端不渲染 / 不回显 / 不提交；后端 `add()/update()` 按同一份配置剔除，校验规则同步收敛。
- `if / else` 同样支持（`else` 块里的字段自动成为另一场景专属）。
- 未指定场景时（如 `Action::form()` 的行级弹窗只有一份表单）两个判断都返回 `true`，两段都注册。
- 回调里**没用**场景判断时只执行一次，行为与开销与以前一致。

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

1. **场景专属字段 + 白名单外字段即提交范围**：不渲染、不初始化、不提交；恶意客户端多提交，后端保存前同样剔除，校验规则同步剔除（不会因被剔除的 required 字段报错）。
2. **disabled 不提交**：`->disabled()` / `->addDisabled()` / `->editDisabled()` 生效的场景中，字段 UI 禁用且**不参与提交**，杜绝「前端禁用、抓包改值」绕过。
3. **例外**：`$form->hidden('prop', ...)` 声明的隐藏提交字段始终随表单提交；但它若声明在场景块内，仍只在所属场景提交。
4. 条件字段（`->when()`）要在受限场景使用，应把字段名写进白名单（或用 `if` 包裹）；可见性仍由父字段值在前端实时控制。
5. 数组写法的继承式控制器（`formFields()` 数组）可在返回的 config 里自带 `sceneShow: {create: [...], edit: [...]}`，前端同样生效。

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

### 5.2 点式字段：读写 JSON 列里的子键（`config.api_url`）

表单字段名带 `.` 时，按「根字段是 JSON 列」读写：**取数**解根字段 JSON 取子键，**保存**合并回根字段以 JSON 存库。典型场景是一张表上用一列 `config`（text/json）存一组配置，而不必拆几十个列。

```php
$grid->column('config.api_url', '接口地址');   // 列表列同样支持点式（根字段自动解码）

$grid->form(function (Form $form) {
    $form->text('name', '名称')->required();
    $form->text('config.api_url', '接口地址')->required()->placeholder('https://api.example.com');
    $form->text('config.api_token', '接口令牌');
    $form->number('config.timeout', '超时(秒)')->min(1)->max(600);
});
```

**点式写法对任意字段方法都成立**（`text` / `textarea` / `number` / `select` / `radio` / `switch` / `date` / `datetime` / `tags` / `remote` / `password` ……）——点式只改「prop 的名字」，与字段类型无关，各类型的取值/回显/校验规则完全保持原行为：

```php
$form->select('config.platform', '接入平台')->options([1 => '微信', 2 => '支付宝', 3 => '银联'])->default(2);
$form->switch('config.enabled', '启用开关');                 // 布尔值入库
$form->datetime('config.sync_at', '同步时间');               // 'Y-m-d H:i:s' 字符串入库
$form->tags('config.scopes', '权限范围');                    // 数组入库（JSON 数组）
```

列方法同理：`$grid->column('config.platform', '接入平台')->map([...])`、`->datetime()`、`->switch()` 等全部可用。

行为细则：

| 环节 | 行为 |
|---|---|
| 列表 / 回显 | 后端把根字段（`config`）JSON 解码后，写字面键 `config.api_url` 的扁平值；前端 el-table 与表单都能按字面 key 取到 |
| 新增 | 只提交了点式字段时，以「编辑读库现值 → 空数组」为基础值合并，未提交的子键原样保留 |
| 编辑 | 同上：合并而非覆盖，**没出现在表单里的子键不会丢**；显式提交空串 = 真的写空值（清空语义） |
| 校验 | 前端**同时**写字面键与嵌套路径（`setFieldValue` 双写 + `initFormData`/`handleEdit` 末尾 `syncDotNested` 镜像），el-form-item 取值 (`lodash.get(formData,'config.x')`) 天然命中字面键，`required` 等规则正常生效。⚠️ **不要给校验规则挂 async-validator 的 `transform`**：4.2.5 下带 `transform` 的规则会让 `required` 误报「不能为空」，即便字段有值（详见 [16 排错 §4](16-排错速查与FAQ.md#4-页面--dsl)） |
| 导出 | 导出值按路径从行数据取，与列表展示一致 |

- 前端提交的就是**字面键**（`formData['config.api_url']`，JS 对象键允许带点）；合并、解码全在后端（`CurdActionsTrait::mergeDotFields` / `CurdListTrait::expandDotColumns`）。
- 根字段建议在表里建成 `text`（或 json）列；模型上有 `json`/`array` cast 时合并结果交回数组由 Eloquent 编码，否则后端 `json_encode(..., JSON_UNESCAPED_UNICODE)`。
- 路径中的**数字段**（如 `config.list.0`）按数组下标处理，保证 JSON 输出是数组而非 `{"0":...}`；各字段类型的值按前端原样入库（`switch` 存布尔、`datetime` 存字符串、`select` 存选项 value）。
- **展开范围**：grid 点式列 ∪ 表单点式字段（add/edit）∪ 详情里显式声明的点式字段 ∪ 动态配置（curd_configs）里的点式 prop —— 任一处声明过，列表 / 编辑 / 详情都能取到值。
- 搜索（`$filter`）**不**支持点式：JSON 列内的子键检索请用 `->where()` 自定义闭包。

### 5.3 JSON 组件：`$form->json()` 键值对编辑器

新增 `json` 表单组件：用户自由增删键值对，保存时生成 JSON 字符串存入数据库（列类型 text/json）。

```php
$grid->form(function (Form $form) {
    $form->text('name', '名称')->required();
    $form->json('extra', '扩展配置')->help('键值对形式保存到 extra 列（JSON），值按字符串存储');
});
```

- 前端是行式编辑器（键 / 值 / 删除 + 「+ 添加一项」），空键行不提交；**空结果提交 `'{}'`**（显式清空，区别于「不提交该字段」）。
- 编辑回显：字符串值 `JSON.parse` 成行；解析失败时保留原文作为单行值（不丢数据）。
- 与点式字段同表单互不干扰：JSON 组件提交的是**根字段**（`extra`），点式字段提交的是**字面键**（`config.api_url`），后端先合并点式再走各自落库逻辑。
- 值一律按字符串存储；需要数字/布尔请在业务端消费时转换（或改用点式字段 + `number`）。

### 5.4 查看详情（`$grid->setDetail()`）：默认「列表列 ∪ 表单字段」

行操作里的「查看」打开只读详情弹窗（按钮由 `options(['view' => true])` 控制，默认开）。

**不写任何配置时，详情字段 = 列表列 ∪ 表单字段**（按 `prop` 去重，列在前）——所以表单里声明的点式字段（`config.api_url` / `config.timeout` …）在详情里同样可见，不会出现「表单能编辑、详情看不到」。

```php
$grid->form(function (Form $form) {
    $form->text('config.api_url', '接口地址');
    $form->switch('config.enabled', '启用开关');
});

// 想自定义字段顺序 / 分组时再声明；一旦声明就以此为准，不再并入表单字段
$grid->setDetail([
    ['group' => '基础信息', 'fields' => [
        ['prop' => 'name', 'label' => '名称'],
        ['prop' => 'config.api_url', 'label' => '接口地址'],
    ]],
    ['group' => '运行配置', 'fields' => [
        ['prop' => 'config.timeout', 'label' => '超时(秒)'],
        ['prop' => 'config.enabled', 'label' => '启用开关'],
    ]],
]);
```

要点：

| 项 | 说明 |
|---|---|
| 默认字段 | 列表列（`showInView !== false`）在前，表单字段在后；跳过 `hidden` 字段与「仅新增」字段（`modeOnly === 'add'`，如创建时才填的密码） |
| 点式字段 | 与列表同机制：后端把根字段解 JSON 后写字面键扁平值，详情直接读 `row['config.api_url']`；**显式 `setDetail()` 里声明的点式 prop 也会纳入展开清单**，不会取不到值 |
| 只读渲染 | 复用列表单元格渲染器，但转成只读形态：`switch` → 「开 / 关」文字（不再渲染可点开关）；`select` / `radio` 的 `options` 自动转 `map` → 显示文案而非裸值；`image` / `date` / `datetime` 保留专用单元格（缩略图 / 日期格式化） |
| 关闭详情 | `options(['view' => false])`（旧写法用 `features`） |
| 旧数组写法 | 同一个 `detail()` 方法返回字段数组（不分组），语义与 Grid DSL 一致 |

## 6. 自定义操作 Action（行级/批量/全局/弹窗）

```php
$grid->addAction(new MrOrderRetryAction());          // 行级（默认）
$grid->addAction(new MrOrderBatchRetryAction());     // 批量：Action 内 ->batch(true)
// $grid->addAction(…->global(true))                 // 全局按钮：工具栏右侧
```

**不建 Action 类时的三个快捷方法**（逻辑写在控制器里，够用且最省事）：

```php
// 行级按钮 → 控制器 actionAudit(Request $request)
$grid->action('audit', '审核')->icon('Check')->confirm('确定审核该记录？')->handler('audit');

// 批量按钮（勾选行后可用）＝ action($name,$label)->batch(true)
$grid->batAction('batch-on', '批量上架')->icon('Top');
// 控制器 actionBatchOn(Request $request) 里读 $request->post('ids')

// 全局 Excel 导入按钮 ＝ action($name,$label)->icon('Upload')->type('warning')
//                            ->global(true)->form(fn($f) => $f->excel('file','Excel 文件'))
$grid->importAction();                          // 默认 name=import / label=导入
$grid->importAction('import', '导入订单');        // 也可自定义
// 控制器 actionImport(Request $request) 里读 $this->excelRows('file')
```

`->handler('audit')` 表示「不实现 Action 子类，交给控制器的 `actionAudit()`」；方法名由按钮名推导
（`batch-on` → `actionBatchOn`）。**只在 Action 子类自己实现了 `handle()` 时才走子类**，
链式 `action()` 一律回退到控制器方法（否则会抛「Action xxx 未实现 handle」）。

用 `make:curd --action / --bat-action / --import-action` 可以连按钮配置和这三个方法骨架一起生成，
见 [快速开始 §6](02-快速开始.md#6-命令行生成-makecurd)。

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

### 6.1 全局/批量按钮 + 表单弹窗（`global()/batch()` × `form()`）

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
- **图片 `image` 字段只收 jpg / jpeg / png**：前端选择器 `accept=".jpg,.jpeg,.png"` + 上传前按扩展名/MIME 硬校验，服务端 `/api/upload/image` 也按真实 MIME 只放行 jpeg/png（gif/webp/bmp 会被拒）。需要别的格式请改用通用 `file` 字段（`/api/upload`，仅限 5MB 内不限类型）。
- `global(true)` 提交时自动附带 `{ query: 当前搜索条件 }`；`batch(true)` 自动附带 `{ ids: 勾选主键 }`；行级自动附带 `{ id: 当前行主键 }`（`->field()` 可改字段名）。
- 无 `->api()` 时自动路由到 `/api/curd/model/{model}/action/{name}`；弹窗提交失败（接口返回错误）时弹窗保持打开，便于修改后重试。

### 6.2 Excel 导入字段 `$form->excel()`（multipart 直传 + 自动解析）

Excel 导入推荐用专用 `excel` 字段：**不走 `/api/upload`**，本地选文件后以 multipart 表单把二进制直接提交到 action 接口，服务端自动解析，`handle` 里直接拿解析好的数据行。

> 只要「导入」这一个按钮时不用建 Action 类：`$grid->importAction()` 一行即可（见 §6 快捷方法），
> 逻辑写在控制器 `actionImport()` 里。

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

## 7. 列表头汇总条 `grid()->summary(callable)`

```php
$grid->summary(function ($query) {
    $q = clone $query;                                  // $query = 当前筛选后的列表查询，随筛选自动刷新
    $money = (float)(clone $q)->where('status', 2)->sum('money');
    return '<span>成功单合计：<b>￥' . number_format($money, 2) . '</b></span>'; // 返回 HTML
});
```

## 8. 其它常用开关（含导出）

```php
$grid->disableCreate() / disableEdit() / disableDelete() / disableView()
     / disableExport() / disableBatchDelete();      // 按需关能力
$grid->setDialogWidth('700px');                     // 弹窗宽
$grid->setCustomCss($css) / setCustomJs($js);       // 页面注入 css/js
$grid->setRowStyle(fn($row) => [...]) / setRowClass(...); // 行样式
$grid->setRules([...]);                             // 提交前校验（参考后端 rules 格式）
```

**导出**：工具栏「导出」下拉（当前页 / 当前选择 / 全部）为框架同步导出，随当前筛选条件导出 CSV，无需额外配置（不实现异步导出任务）。

**搜索条件与列表同口径**，唯一差异在范围条件的传参形态：列表请求经信封加密传真数组
（`created_at=[起, 止]` → 后端 `whereBetween`）；导出是 GET 文件流，前端把数组拆成
`created_at` / `created_at_end` 两个 query 参数，后端配对还原后同样 `whereBetween`。
历史版本未做配对，`created_at` 单值落到 like 分支，datetime 列
`LIKE '%2026-10-20 00:00:00%'` 几乎匹配不到行——表现为「页面上有数据、导出只有表头」，
`dateRange(...)->datetime()` 场景必现，现已修复。

**导出哪几列**（只收窄、不放宽，最终列 = 三者交集，顺序始终按 grid 定义）：

| 口径 | 写法 | 说明 |
|---|---|---|
| 跟随用户列设置（默认） | 无需配置 | 前端把「列配置」当前勾选的 prop 以 `_columns=prop1,prop2` 随导出请求下发，后端取交集。`->hidden()` 列默认不在勾选集合里，**所以不再进 CSV**；用户手动在「列配置」里勾上，它就跟着导出 |
| 固定白名单（逐页硬约束） | 控制器里 `protected function exportColumns(): array { return ['id','money','channel']; }` | 开发者口径，优先级最高；与用户勾选取交集，未勾选的不会出现 |
| 不做限制 | 以上都不涉及 | 接口直连（不带 `_columns`）或参数解析为空时按 grid 全列导出（含 `hidden`），保持历史行为 |

无 grid 配置的动态 CURD 页会回退到「表字段」，该回退路径同样受 `_columns` 约束。

**导出文件名**：默认 `{页面标题}_{YmdHis}.csv`，标题与 `/api/curd/config` 的 `data.title` 同源
（控制器 `$title` → 落库 `curd_configs.title` → 表名兜底）。响应同时给 `filename`（ASCII 兜底，
中文剥空后退回表名）与 `filename*`（UTF-8，现代浏览器优先）两个头，前端 blob 下载名按同一规则拼。

**单次导出上限**：`export_max_rows`（默认 `100000`，`<= 0` 不限制），超过**直接报错**不做静默截断：
`{"code":400,"msg":"本次将导出 N 条，超过单次导出上限 M 条…"}`。按实际命中条数校验
（当前页 `min(总数, size)` / 选中条数 / 筛选后总数）；前端嗅探响应类型，拿到 JSON 就当错误提示而非下载。
配置项见 [配置参考](03-配置参考.md)。

## 9. 字段标题国际化 `__()`（symfony/translation）

字段标题统一走翻译函数 `__()`（`trans($id, [], 'fields')` 的别名），key 写英文短语，中文在语言文件里维护：

```php
$grid->column('created_at', __('Created at'));   // 显示「创建时间」
$grid->column('id', __('ID'));                   // 显示「ID」
```

| 项 | 说明 |
|---|---|
| 别名 | `__($id, $replace = [], $locale = null)` ≡ `trans($id, $replace, 'fields', $locale)`（`plugin/curd/app/functions.php`） |
| domain | 固定 `fields`（即语言文件名 `fields.php`） |
| 插件内置 | `plugin/curd/resource/translations/zh_CN/fields.php` —— 常用英文短语→中文（`'Created at' => '创建时间'` 等），安装时由 `Install::ensureTranslations()` 复制到宿主（已存在不覆盖） |
| 宿主覆盖/补充 | `resource/translations/zh_CN/fields.php`（宿主根），同名 key 优先于插件内置 |
| 加载顺序 | 插件 `config/translation.php` 配两段 path：先插件内置、后宿主，宿主缺 key 自动落到插件默认 |
| 未命中 | 原样返回英文短语（如 `'Created at'`），不会报错、不会打崩页面 |
| 生成器 | `make:curd` 生成的控制器标题已统一 `__()`（`created_at` → `__('Created at')`），见[快速开始 §6](02-快速开始.md#6-命令行生成-makecurd) |

> 注意：`__()` 依赖「当前请求所属插件」= curd 的翻译配置（webman 按 `request()->plugin` 取
> `config('plugin.curd.translation')`）。宿主如果没有该配置文件，插件请求会因缺 `locale` 报错——
> 插件已内置 `plugin/curd/config/translation.php`，无需宿主再配。

## 10. 自定义 JS `setCustomJs()` 与公共 API `window.curd`

页面级注入一段 JS，用来写本页专属逻辑；**不需要再重复实现**取表单、弹窗、请求这些常见操作。

```php
$grid->setCustomJs(<<<'JS'
function hello() {
  curd.message.success('hello')
}
JS);
$grid->setCustomCss('.my-tip { color: #e6a23c; }');
```

### 10.1 执行方式（为什么 help 里的 `@click` 能调到函数）

customJs 以**经典 `<script>`** 注入（不是包在函数里），因此：

- 顶层 `function hello(){}` / `var x` 会挂到**全局**，`help()` 里的 `@click="hello()"` 可直接调用；
- 也可以显式 `window.hello = () => {}`；
- 只在该段代码**内容变化**时重新注入，避免 `const` 重复声明报错（建议用 `function` / `var` / `window.x=`）；
- 注入前会先挂好 `window.curd`，脚本里可直接用。

### 10.2 `help()` 里写事件

`Field::help()` 的内容用 `v-html` 渲染，**不会被 Vue 编译**，所以 `@click` 由前端事件委托执行：

```php
$form->text('config.api_token', __('Api token'))
    ->help('留空沿用旧值 · <span @click="fillDemoToken()" style="cursor:pointer;color:var(--el-color-primary)">填入示例</span>');
```

支持的写法：

| 写法 | 说明 |
|---|---|
| `@click="fn()"` / `@click="fn($event)"` | 调全局函数，`$event` 为原生事件；`this` 为被点元素 |
| `@click.stop` / `.prevent` / `.self` / `.once` | 常用修饰符均支持（写在属性名上，与 Vue 一致） |
| `v-on:click="fn()"` | 等价写法 |
| `onclick="fn()"` | 原生内联事件，同样可用（全局函数已存在） |
| `<span class="content-click">文本</span>` | 原有能力：点击把文本回填到当前字段 |

### 10.3 公共 API `window.curd`

| 分组 | 方法 / 属性 |
|---|---|
| 状态（只读） | `curd.config`、`curd.loading`、`curd.tableData`、`curd.pagination`、`curd.searchForm`、`curd.selectedRows`、`curd.dialogVisible`、`curd.dialogMode`、`curd.formData` |
| 表单 | `getFormData()`（深拷贝）、`setFormData(patch)`、`resetForm()`、`validateForm()`、`submitForm()`、`getFormRef()` |
| 弹窗 | `openAdd()`、`openEdit(row)`、`openView(row)`、`closeDialog()`、`closeView()` |
| 列表 | `refresh()` / `reload()`、`search(patch?)`、`resetSearch()`、`getSelection()`、`clearSelection()`、`toggleRowSelection(row, selected)`、`clearColWidthCache(all?)`（清列宽自适应缓存，默认当前页、`true` 清全部） |
| 提示 | `curd.message.success/error/warning/info(msg)`、`curd.confirm(msg, title?)`、`curd.alert(...)`、`curd.prompt(...)` |
| 请求 | `curd.get(url, params)`、`curd.post(url, data)`、`curd.put(url, data)`、`curd.del(url, params)`、`curd.request`（原实例） |
| 工具 | `curd.openUrl(url)`、`curd.copyText(text)`、`curd.$nextTick(fn)` |

请求走**与内部同一套 axios 实例**：自动带 token、走接口加密、统一错误提示，无需自己处理。

```js
// 例：表单里改一个值 + 提示
curd.setFormData({ 'config.api_token': 'demo-' + Date.now() })
curd.message.success('已填入')

// 例：确认后调接口并刷新列表
const ok = await curd.confirm('确定同步吗？').catch(() => false)
if (ok) {
  await curd.post('/api/curd/model/MyTest/sync', { id: curd.getFormData().id })
  curd.refresh()
}
```

> 兼容：旧名 `window.__curd` 仍然可用（与 `window.curd` 是同一个对象）；旧版的 `__curd.loadData/handleAdd/handleSearch/handleReset` 也已保留为 `curd.refresh/openAdd/search/resetSearch` 的等价方法。
