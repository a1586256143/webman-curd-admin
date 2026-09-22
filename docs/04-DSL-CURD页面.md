# 后台 DSL · CURD 页面

> 列表管理页：表格 / 搜索 / 表单 / 自定义操作 / 汇总条 / 导出，用 `Grid/Form/Filter/Column/Action` 流式声明。
> 代码位置 `plugin/curd/app/dsl/`；配套示例见 `app/controller/admin/api/MROrdersController.php`。
> 展示型页面见[DSL · Schema 页面](03-DSL-Schema页面.md)，菜单 / 权限 / 生效见[菜单与权限](05-菜单与权限.md)。

---

## 1. 五步建一个 CURD 页

1. **Model**（业务 `app/model/`）：extends 平台 BaseModel、设 `$table`，必要时加常量字典；
2. **Controller**（业务 `app/controller/admin/api/`）：extends `BaseAdminController`，声明 `$modelClass / $title`，实现 `grid()`；
3. **路由**（业务 `config/route.php`）：`RouteControllerRegistry::registerMany(['/xxx' => XxxController::class])`；
4. **菜单**：`php webman make:permission --name … --slug … --type 1 --path /xxx --icon … --sort n`；
5. **功能声明**：全部在 `grid()` 里流式拼装（列/搜索/表单/操作/汇总/导出开关）。

参考完整实现：`app/controller/admin/api/MROrdersController.php`（话费订单）。

## 2. 控制器骨架

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

## 3. 常用列 `grid()->column($prop, $label)`

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

## 4. 搜索 `grid()->filter(callable)`

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

字段通用链式能力（`Field`）：`required / placeholder / help / default / addDefault / editDefault / disabled / addDisabled / editDisabled / hidden / addHidden / addOnly / editOnly / options(map) / multiple / filterable / span / full / row / rules / min / max / step / config(extra)`，支持**动态显隐**：`->when(父值, cb)` / `->addVisibleWhen([...])`。
其中 `addOnly()` = 仅新增场景展示并提交、`editOnly()` = 仅编辑场景（也可以用 `if ($form->isCreate())` 包裹字段，见 3.5.1，效果等价且更好读）。

表单级：`$form->columns(int)`（栅格列数）、`$form->width/height`、`confirm`、`isCreate()` / `isEdit()`（**场景判断，写在 `if` 里**，见 3.5.1）；`Grid::addForm / editForm` 可拆分新增/编辑差异、`setFormTabs / setFormSteps` 分 Tab/分步。

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

## 6. 自定义操作 Action（行级/批量/全局/弹窗）

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
- `global(true)` 提交时自动附带 `{ query: 当前搜索条件 }`；`batch(true)` 自动附带 `{ ids: 勾选主键 }`；行级自动附带 `{ id: 当前行主键 }`（`->field()` 可改字段名）。
- 无 `->api()` 时自动路由到 `/api/curd/model/{model}/action/{name}`；弹窗提交失败（接口返回错误）时弹窗保持打开，便于修改后重试。

### 6.2 Excel 导入字段 `$form->excel()`（multipart 直传 + 自动解析）

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
| 宿主 `config/curd-admin.php`（推荐） | `'export_max_rows' => 100000` | 与 `upload_path` / `site` 同一个宿主配置文件，运维改这一个即可 |
| 插件 `config/curd.php`（本文件内置默认） | `'export_max_rows' => 100000` | 兜底值；宿主 `config/curd-admin.php` 的同名顶层键会覆盖它 |

- `<= 0` = 不限制。
- 校验按**实际命中条数**：导出当前页按 `min(总数, size)`，导出选中按选中条数，导出全部按筛选后总数。
- 前端会嗅探响应类型：拿到 JSON 就当错误提示，不会把报错内容当 CSV 下载下来（`dynamicCurd/index.vue` 的 `export`）。
