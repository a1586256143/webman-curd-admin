# API · Filter 搜索 与 Action 操作

> `Filter` 用于 `$grid->filter(fn(Filter $f) => ...)`；`Action` 用于 `$grid->action(name, label)`。
> 代码位置 `plugin/curd/app/dsl/Filter.php`、`Action.php`。

---

## 1. Filter 搜索

### 1.1 速查表

| 方法 | 说明 |
|---|---|
| `like($prop, $label, array $options = [])` | 模糊匹配（`LIKE %kw%`） |
| `equal($prop, $label, array $options = [])` | 精确匹配 |
| `where(callable $cb, $label, $prop = '')` | 自定义查询条件（闭包内 `$this->input` 取输入） |
| `select($prop, $label)` | 下拉筛选（配合 `->options()`） |
| `options(array $map, array $config = [])` | 设置选项 |
| `between($prop, $label)` | 日期**时间**区间（`datetimerange`，默认带时分秒；`->date()` 可退回纯日期） |
| `dateRange($prop, $label)` | 日期区间（`daterange`，等价 `between(...)->date()`） |
| `singleDate($prop, $label)` | 单个日期 |
| `remote($prop, $label, $table, $valueKey, $labelKey)` | 关联表下拉 |
| `default($value)` | 默认值（构建时求值，传具体值或相对表达式） |
| `defaultToday()` | 默认「今天」：今天 00:00:00 ~ 明天 00:00:00 |
| `defaultWeek()` | 默认「最近一周」：7 天前此刻 ~ 现在 |
| `defaultMonth()` | 默认「最近一月」：1 个月前此刻 ~ 现在 |
| `defaultThreeMonth()` | 默认「最近三月」：3 个月前此刻 ~ 现在 |
| `add(array $item)` | 兜底：直接塞一个搜索项配置 |

### 1.2 基础用法

```php
$grid->filter(function (Filter $filter) {
    $filter->like('name', __('Name'));
    $filter->equal('code', __('Code'));
    $filter->select('status', __('Status'))->options([0 => '禁用', 1 => '启用']);
    $filter->dateRange('created_at', __('Created at'));
});
```

### 1.3 时间区间 / 单日

```php
$grid->filter(function (Filter $filter) {
    // 默认「日期 + 时间」（datetimerange）；只要日期用 ->date()
    $filter->between('created_at', __('Created at'));
    $filter->between('created_at', __('Created at'))->date();   // 纯日期区间

    $filter->singleDate('publish_date', __('Publish date'));
});
```

### 1.4 默认值

```php
$grid->filter(function (Filter $filter) {
    // 固定值
    $filter->select('status', __('Status'))->options([0 => '禁用', 1 => '启用'])->default(1);

    // 相对日期：最近 7 天（构建时求值成具体日期区间）
    $filter->dateRange('created_at', __('Created at'))->default('-7d');

    // 常用快捷区间（构建时按当前时间求值）
    $filter->between('created_at', __('Created at'))->defaultToday();       // 今天 00:00:00 ~ 明天 00:00:00
    $filter->between('created_at', __('Created at'))->defaultWeek();        // 近 7 天 ~ 现在
    $filter->between('created_at', __('Created at'))->defaultMonth();       // 近 1 月 ~ 现在
    $filter->between('created_at', __('Created at'))->defaultThreeMonth();  // 近 3 月 ~ 现在
});
```

> `default` 必须写在 `between` / `dateRange` **之后**（它作用于「最近一个搜索项」）；
> 区间类默认值最终会被解析成 `[起点, 终点]`，`datetimerange` 带时分秒、`daterange` 只到日。

### 1.5 自定义条件

```php
$grid->filter(function (Filter $filter) {
    // 闭包内 $this->input 是用户输入；返回查询条件
    $filter->where(function ($query) {
        $kw = trim((string)$this->input);
        if ($kw !== '') {
            $query->where(function ($q) use ($kw) {
                $q->where('name', 'like', "%{$kw}%")
                  ->orWhere('code', 'like', "%{$kw}%");
            });
        }
    }, __('关键字（名称/编码）'), 'keyword');

    // 把上一个自定义条件变成下拉
    $filter->where(function ($query) {
        if ($this->input === '1') $query->whereNull('deleted_at');
    }, __('删除状态'), 'deleted')->select([1 => '仅未删除']);
});
```

### 1.6 关联表下拉

```php
$grid->filter(function (Filter $filter) {
    $filter->remote('user_id', __('User'), 'admin_users', 'id', 'username');
});
```

---

## 2. Action 操作按钮

### 2.1 速查表

| 方法 | 说明 |
|---|---|
| `name($name)` / `label($label)` | 标识 / 文案 |
| `icon($icon)` | 按钮图标（Element Plus 图标名） |
| `type($type)` | 按钮类型 `primary/success/warning/danger/info` |
| `confirm($msg, $type = 'warning')` | 点击前二次确认 |
| `form(callable $cb)` | 弹窗表单（回调参数 `Form`） |
| `modal(string $html)` | 自定义 HTML 弹窗 |
| `jump($url, $target = '_self')` | 跳转链接 |
| `api($url, $method = 'POST')` | 调用自定义接口 |
| `handler($name)` | 指定后端处理方法名 |
| `params(array\|Closure)` | 附加请求参数（闭包可按行取值） |
| `field($field)` | 行主键字段名（默认 `id`） |
| `show(callable $cb)` | 按行控制是否显示 |
| `successMsg($msg)` | 成功提示文案 |
| `dialogTitle($title)` / `dialogWidth($width)` | 弹窗标题 / 宽度 |
| `inline(bool)` | 行内按钮（默认） |
| `batch(bool)` | 批量工具栏按钮 |
| `global(bool)` | 表格右上角全局按钮 |
| `roles(array)` | 限定角色 |
| `permission($object, $action = 'execute')` | 权限点 |
| `excelRows($prop)` | 读取 `$form->excel()` 解析后的数据行 |

### 2.2 行级操作（默认）

```php
$grid->action('sync', __('Sync'))
    ->icon('Refresh')
    ->type('primary')
    ->confirm('确定同步该条数据吗？')
    ->successMsg('同步成功')
    ->show(fn($row) => $row->status == 1)
    ->handler('sync');   // → POST /api/curd/model/{Model}/action/sync
```

后端两种接法（任选其一）：

```php
// 接法 A（推荐）：Action 子类里写业务，控制器无需任何方法
class SyncAction extends \plugin\curd\app\dsl\Action
{
    public function handle(\support\Request $request)
    {
        $row = $this->model();          // 当前行模型实例（按 id 自动注入）
        // ... 业务
        return json(['code' => 0, 'msg' => 'ok']);
    }
}

// 注册
$grid->addAction(
    (new SyncAction('sync', __('Sync')))->confirm('确定同步？')
);

// 接法 B：控制器方法，命名 action + 帕斯卡(name 或 handler)
public function actionSync(\support\Request $request)
{
    $id = (int)$request->post('id');
    return json(['code' => 0, 'msg' => 'ok']);
}
```

> **分发规则**：只有 Action **子类自己实现了 `handle()`** 才走子类；链式 `$grid->action(...)` 创建的实例
> 一律回退到控制器 `action{Name}()`（方法名取 `handler()` 优先，否则取按钮名转帕斯卡）。
> 子类没实现 `handle()` 时不要 `addAction`，否则会抛「Action xxx 未实现 handle」。

### 2.3 带表单弹窗

```php
$grid->action('audit', __('Audit'))
    ->icon('Edit')
    ->form(function (Form $form) {
        $form->radio('pass', __('Result'), [1 => '通过', 0 => '驳回'])->required();
        $form->textarea('reason', __('Reason'))->rows(3);
    })
    ->handler('audit');
```

提交时会带上 `id` + 表单字段。后端：

```php
public function actionAudit(\support\Request $request)
{
    $id     = (int)$request->post('id');
    $pass   = (int)$request->post('pass');
    $reason = (string)$request->post('reason');
    return json(['code' => 0, 'msg' => 'ok']);
}
```

### 2.4 自定义处理 `handler` + `params`

```php
$grid->action('notify', __('Notify'))
    ->params(fn($row) => ['order_id' => $row->id, 'channel' => 'sms'])
    ->handler('notify');
```

- `params` 传数组：固定参数；
- 传闭包：按当前行生成参数；
- URL 使用 `handler`（未设置则用 action 名）：`POST /api/curd/model/{Model}/action/{handler}`。

### 2.5 批量操作

`$grid->action($name, $label)->batch(true)`，等价简写 **`$grid->batAction($name, $label)`**：

```php
$grid->action('batchSync', __('批量同步'))
    ->batch(true)
    ->confirm('确定同步选中的记录吗？')
    ->handler('batch-sync');

// 简写
$grid->batAction('batch-on', __('批量上架'))->icon('Top');
```

后端从 `ids` 取选中行：

```php
public function actionBatchSync(\support\Request $request)
{
    $ids = (array)$request->post('ids', []);
    return json(['code' => 0, 'msg' => '已处理 ' . count($ids) . ' 条']);
}
```

### 2.6 全局按钮

```php
$grid->action('exportAll', __('导出全部'))
    ->global(true)
    ->type('success')
    ->jump('/api/curd/model/HfGoods/export');
```

### 2.7 跳转 / 调接口

```php
// 新窗口打开
$grid->action('detail', __('详情'))->jump('/app/curd/#/order-detail?id={id}', '_blank');

// 直接调接口（不写后端方法）
$grid->action('refreshCache', __('刷新缓存'))
    ->api('/api/curd/model/HfGoods/action/refresh-cache', 'POST');
```

> `jump` 的 URL 支持 `{id}` 等行字段占位（按当前行替换）。

### 2.8 权限与角色

```php
$grid->action('delete', __('删除'))
    ->type('danger')
    ->permission('curd.HfGoods.delete', 'execute')  // 权限点
    ->roles(['admin'])                              // 限定角色
    ->handler('delete');
```

### 2.9 Excel 导入操作

**一行版**（推荐）：`$grid->importAction()` = `action('import', '导入')` + `icon('Upload')` +
`type('warning')` + `global(true)` + `form(fn($f) => $f->excel('file', 'Excel 文件'))`：

```php
$grid->importAction();                       // 默认 name=import / label=导入
$grid->importAction('import', __('导入订单')); // 自定义
```

**展开写法**（需要多个字段或自定义样式时）：

```php
$grid->action('import', __('批量导入'))
    ->global(true)
    ->form(function (Form $form) {
        $form->excel('file', __('Excel'))->required();
    })
    ->handler('import');
```

后端读取解析结果：

```php
public function actionImport(\support\Request $request)
{
    $rows = $this->excelRows('file');   // 已解析的二维数组（首行表头）
    foreach ($rows as $row) {
        // $row['列名'] => 值
    }
    return json(['code' => 0, 'msg' => '导入 ' . count($rows) . ' 条']);
}
```

---

## 3. 完整可运行示例

```php
protected function grid(): Grid
{
    $grid = new Grid(new HfGoods());

    $grid->column('id', __('ID'))->width(70);
    $grid->column('name', __('Name'));
    $grid->column('status', __('Status'))->map([0 => '下架', 1 => '上架']);

    // 搜索
    $grid->filter(function (Filter $filter) {
        $filter->like('name', __('Name'));
        $filter->select('status', __('Status'))->options([0 => '下架', 1 => '上架']);
        $filter->dateRange('created_at', __('Created at'))->default('-7d');
    });

    // 行级：带表单
    $grid->action('audit', __('审核'))
        ->icon('Edit')
        ->form(function (Form $form) {
            $form->radio('pass', __('Result'), [1 => '通过', 0 => '驳回'])->required();
            $form->textarea('reason', __('Reason'))->rows(3);
        })
        ->handler('audit');

    // 批量
    $grid->action('batchOn', __('批量上架'))
        ->batch(true)
        ->confirm('确定上架选中商品吗？')
        ->handler('batch-on');

    // 全局：导入
    $grid->action('import', __('导入'))
        ->global(true)
        ->form(fn(Form $form) => $form->excel('file', __('Excel'))->required())
        ->handler('import');

    return $grid;
}
```

对应后端方法：

```php
public function actionAudit(\support\Request $request) { /* ... */ return json(['code' => 0]); }
public function actionBatchOn(\support\Request $request) { /* ... */ return json(['code' => 0]); }
public function actionImport(\support\Request $request) { /* $this->excelRows('file') */ return json(['code' => 0]); }
```

路由与控制器骨架见 [API · Grid §12](20-API-Grid.md#12-一个完整可运行示例)。
