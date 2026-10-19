# API · Grid 方法大全

> `Grid` 是 CURD 页面的根对象：列、搜索、表单、详情、操作、汇总、样式与能力开关都挂在它上面。
> 所有方法都返回 `$grid`（或子对象），支持链式调用。
> 代码位置 `plugin/curd/app/dsl/Grid.php`；列方法见[Column](21-API-Column.md)，表单/字段见[Form/Field](22-API-Form-Field.md)，搜索/操作见[Filter/Action](23-API-Filter-Action.md)。

---

## 0. 速查表

| 方法 | 说明 |
|---|---|
| `new Grid(Model $model, ?string $title = null)` | 构造。title 不传则自动读控制器 `$title` |
| `getModel(): Builder` | 取查询构造器（排序 / 附加条件） |
| `getModelInstance(): Model` | 取模型实例 |
| `column(string $prop, ?string $label = null): Column` | 加列表列，返回 Column |
| `filter(callable $cb): self` | 搜索项（回调参数 `Filter`） |
| `form(callable $cb): self` | 新增+编辑共用表单（回调参数 `Form`） |
| `addForm(callable $cb): self` | 仅「新增」表单（覆盖 form 的同名字段） |
| `editForm(callable $cb): self` | 仅「编辑」表单 |
| `action(string $name, string $label): Action` | 加操作按钮，返回 Action |
| `addAction(Action $action): self` | 加入已构造好的 Action |
| `setDetail(array $groups): self` | 查看详情分组 `[['title'=>..,'fields'=>[..]]]` |
| `setFormTabs(array $tabs): self` | 表单选项卡 `[['label'=>..,'fields'=>[..]]]` |
| `setFormSteps(array $steps): self` | 表单分步 `[['label'=>..,'fields'=>[..]]]` |
| `setDialogWidth(string $width): self` | 弹窗宽度，如 `'700px'` |
| `setOptions(array $options): self` | 透传页面级配置（高级） |
| `setRowStyle(callable $cb): self` | 行内联样式回调 |
| `setRowClass(callable $cb): self` | 行 class 回调 |
| `setRules(array $rules): self` | 提交前校验规则（后端格式） |
| `summary(callable $cb): self` | 列表头汇总条 |
| `setCustomCss(string $css): self` | 注入页面 CSS |
| `setCustomJs(string $js): self` | 注入页面 JS（顶层函数挂全局） |
| `disableCreate/Edit/Delete/View/Export/BatchDelete(): self` | 关闭对应能力 |
| `title(): string` / `columns()` / `search()` / `formFields()` / `detail()` / `actions()` | 读取内部结构（框架用，少直接用） |

---

## 1. 构造与标题

```php
protected function grid(): Grid
{
    // 推荐：不传 title，自动读控制器 $title
    $grid = new Grid(new MyTest());
    $grid->getModel()->orderByDesc('id');
    return $grid;
}
```

| 写法 | 结果 |
|---|---|
| `new Grid(new MyTest())` | 标题取 `$this->title` |
| `new Grid(new MyTest(), '订单管理')` | 显式覆盖标题 |

> 模型类不用再声明 `$modelClass`：框架从 Grid 的模型实例自动提取回填。

**排序 / 附加条件**（`getModel()` 返回 `illuminate` 查询构造器）：

```php
$grid->getModel()->orderByDesc('id')->where('deleted', 0);
```

---

## 2. 列 `column()`

```php
$grid->column('id', __('ID'))->width(80)->align('center');
$grid->column('name', __('Name'));
$grid->column('created_at', __('Created at'))->datetime()->width(180);
```

返回 `Column`，可继续链式设置。**完整列方法见 [API · Column](21-API-Column.md)。**

---

## 3. 搜索 `filter()`

```php
$grid->filter(function (Filter $filter) {
    $filter->like('name', __('Name'));
    $filter->select('status', __('Status'))->options([0 => '禁用', 1 => '启用']);
    $filter->dateRange('created_at', __('Created at'));
});
```

**完整搜索方法见 [API · Filter](23-API-Filter-Action.md#1-filter-搜索)。**

---

## 4. 表单 `form()` / `addForm()` / `editForm()`

```php
$grid->form(function (Form $form) {
    $form->labelWidth(120)->contentWidth('520px');
    $form->text('name', __('Name'))->required();
    $form->select('status', __('Status'))->options([0 => '禁用', 1 => '启用'])->default(1);
});

// 仅新增时追加字段（如初始密码）
$grid->addForm(function (Form $form) {
    $form->password('password', __('Password'))->required();
});

// 仅编辑时追加字段
$grid->editForm(function (Form $form) {
    $form->textarea('remark', __('Remark'));
});
```

- `form()` 同时作用于新增与编辑；
- `addForm()/editForm()` 里的字段按 `prop` 覆盖 `form()` 的同名字段；
- 字段类型与通用方法见 [API · Form/Field](22-API-Form-Field.md)。

### 4.1 表单选项卡 / 分步

两者结构相同：`[['label' => 标题, 'fields' => [字段 prop...]]]`。

```php
$grid->setFormTabs([
    ['label' => '基础信息', 'fields' => ['name', 'status']],
    ['label' => '接口配置', 'fields' => ['config.api_url', 'config.api_token', 'config.timeout']],
    ['label' => '其它', 'fields' => ['remark', 'extra']],
]);
```

```php
$grid->setFormSteps([
    ['label' => '第 1 步 · 基础', 'fields' => ['name', 'status']],
    ['label' => '第 2 步 · 配置', 'fields' => ['config.api_url', 'config.api_token']],
]);
```

> 未列进任何分组的字段会自动归入「其他」，不会丢失。选项卡与分步同时设置时以分步为准。

### 4.2 提交二次确认

```php
$grid->form(function (Form $form) {
    $form->confirm('确认保存吗？', 'create');   // 新增时确认
    $form->confirm('修改将立即生效，继续？', 'edit'); // 编辑时确认
});
```

### 4.3 场景字段（新增/编辑各显示一部分）

```php
$grid->form(function (Form $form) {
    $form->text('name', __('Name'))->isCreate();       // 仅新增显示
    $form->text('code', __('Code'))->isEdit();         // 仅编辑显示
});
```

---

## 5. 查看详情 `setDetail()`

不设置时默认 = 「列表列 ∪ 表单字段」。显式设置后**只显示声明的字段**：

```php
$grid->setDetail([
    ['title' => '基础信息', 'fields' => ['id', 'name', 'status', 'created_at']],
    ['title' => '接口配置', 'fields' => ['config.api_url', 'config.api_token', 'config.timeout']],
]);
```

- `fields` 用列表列 / 表单字段的 `prop`（点式字段同样支持）；
- 详情是只读视图：`switch` 显示「开/关」、`select` 显示选项文案、`image` 显示缩略图。

---

## 6. 操作按钮 `action()`

```php
$grid->action('sync', __('Sync'))
    ->icon('Refresh')
    ->confirm('确定同步该条数据吗？')
    ->handler('sync')          // → POST /api/curd/model/{Model}/action/sync
    ->show(fn($row) => $row->status == 1);
```

返回 `Action`，可继续链式设置（`form()` 弹窗表单 / `batch()` 批量 / `global()` 全局 / `jump()` 跳转 / `api()` 自定义接口…）。
**完整操作能力见 [API · Action](23-API-Filter-Action.md#2-action-操作按钮)。**

---

## 7. 汇总条 `summary()`

```php
$grid->summary(function ($query) {
    $q = clone $query; // 当前筛选后的查询，随搜索自动刷新
    $total = (float)(clone $q)->sum('money');
    $ok = (float)(clone $q)->where('status', 2)->sum('money');
    return '<span>合计：<b>￥' . number_format($total, 2) . '</b>'
        . ' · 成功：<b>￥' . number_format($ok, 2) . '</b></span>';
});
```

返回 HTML，直接渲染在表格头部。

---

## 8. 行样式与校验

```php
// 行内联样式（返回数组）
$grid->setRowStyle(function ($row) {
    return $row->status == 0 ? ['background' => '#fff7ed'] : [];
});

// 行 class（返回字符串）
$grid->setRowClass(fn($row) => $row->status == 0 ? 'row-disabled' : '');

// 提交前校验（后端格式，字段 => 规则）
$grid->setRules([
    'name' => 'required|max:50',
    'config.timeout' => 'integer|between:1,600',
]);
```

---

## 9. 能力开关

```php
$grid->disableCreate();        // 隐藏「新增」
$grid->disableEdit();          // 隐藏「编辑」
$grid->disableDelete();        // 隐藏「删除」
$grid->disableView();          // 隐藏「查看」
$grid->disableExport();        // 隐藏「导出」
$grid->disableBatchDelete();   // 隐藏「批量删除」
```

导出行为（导出当前页 / 选中 / 全部、导出列收窄）见 [DSL · CURD 页面 §8](11-DSL-CURD页面.md)。

---

## 10. 自定义 CSS / JS

```php
$grid->setCustomCss('.row-disabled { color: #9ca3af; }');

$grid->setCustomJs(<<<'JS'
// 顶层函数会挂到全局，help 里的 @click 可直接调用
function hello() {
  curd.message.success('hello')
  console.log(curd.getFormData())
}
JS);
```

`window.curd` 公共 API（表单 / 列表 / 弹窗 / 提示 / 请求）见 [DSL · CURD 页面 §10](11-DSL-CURD页面.md)。

---

## 11. 其它

```php
$grid->setDialogWidth('760px');   // 新增/编辑弹窗宽度
$grid->setOptions([...]);         // 页面级配置透传（高级用法，见前端 config）
```

---

## 12. 一个完整可运行示例

```php
<?php
namespace app\controller\admin\api;

use app\model\HfGoods;
use plugin\curd\app\controller\base\BaseCurdController;
use plugin\curd\app\dsl\Filter;
use plugin\curd\app\dsl\Form;
use plugin\curd\app\dsl\Grid;

class HfGoodsController extends BaseCurdController
{
    protected string $title = '商品管理';

    private const STATUS = [0 => '下架', 1 => '上架'];

    protected function grid(): Grid
    {
        $grid = new Grid(new HfGoods());
        $grid->getModel()->orderByDesc('id');

        $grid->column('id', __('ID'))->width(70)->align('center');
        $grid->column('name', __('Name'))->copy();
        $grid->column('price', __('Price'))->prefix('￥');
        $grid->column('status', __('Status'))->map(self::STATUS)->label([0 => 'info', 1 => 'success']);
        $grid->column('created_at', __('Created at'))->datetime()->width(180);

        $grid->filter(function (Filter $filter) {
            $filter->like('name', __('Name'));
            $filter->select('status', __('Status'))->options(self::STATUS);
            $filter->dateRange('created_at', __('Created at'));
        });

        $grid->form(function (Form $form) {
            $form->text('name', __('Name'))->required();
            $form->number('price', __('Price'))->min(0)->step('0.01');
            $form->radio('status', __('Status'), self::STATUS)->default(1);
        });

        $grid->action('sync', __('Sync'))
            ->icon('Refresh')
            ->confirm('确定同步该商品吗？')
            ->handler('sync');

        $grid->summary(function ($query) {
            return '<span>商品数：<b>' . (clone $query)->count() . '</b></span>';
        });

        return $grid;
    }
}
```

路由（宿主 `config/route.php`）：

```php
RouteControllerRegistry::registerMany([
    '/hf-goods' => \app\controller\admin\api\HfGoodsController::class,
]);
```

控制器里再实现操作处理（`handler('sync')` 对应 `actionSync()`；不写 `handler()` 时按 action 名推导 `action{Sync}()`）：

```php
// 约定：action + 帕斯卡命名的操作名 + (Request $request)
public function actionSync(\support\Request $request)
{
    $id = (int)$request->post('id');
    // ... 业务逻辑
    return json(['code' => 0, 'msg' => 'ok']);
}
```

> 更推荐把业务写进 `Action` 子类的 `handle(Request $request)`（可用 `$this->model()` 取当前行），
> 此时控制器无需任何方法。约定见 [API · Action](23-API-Filter-Action.md#24-自定义处理-handler--params)。
