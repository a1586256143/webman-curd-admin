# API · Form 与 Field 方法大全

> `$grid->form(function (Form $form) { ... })` 里声明表单；`$form->text(...)` 等返回 `Field`，链式设置通用属性。
> 代码位置 `plugin/curd/app/dsl/Form.php`、`Field.php`。
> 点式字段（`config.api_url`）可用于**任意字段类型**：读写 JSON 列里的子键。

---

## 0. 速查表

### 0.1 Form（表单容器 / 布局）

| 方法 | 说明 |
|---|---|
| `columns(int)` | 表单栅格列数（默认 1） |
| `labelWidth(int\|string)` | 标签宽度（px）；`140` / `'140'` / `'140px'` 均可 |
| `labelPosition('right')` | 标签位置 `left/right/top` |
| `contentWidth(string\|int)` | 内容区最大宽度，如 `'480px'`；纯数字 `400` 会自动补成 `400px`（无单位 CSS 无效） |
| `width(string)` / `height(string)` | 弹窗宽 / 高 |
| `confirm(string $msg, string $mode)` | 提交二次确认（`create` / `edit`） |
| `when(string $parent, $value, callable)` | 条件显示字段组 |
| `isCreate(...$fields)` / `isEdit(...$fields)` | 场景白名单（仅新增/仅编辑显示） |

### 0.2 字段类型

| 方法 | 控件 |
|---|---|
| `text($prop, $label)` | 单行文本 |
| `textarea($prop, $label)` | 多行文本 |
| `number($prop, $label)` | 数字 |
| `password($prop, $label)` | 密码 |
| `select($prop, $label)` | 下拉（配合 `->options()`） |
| `multipleSelect($prop, $label)` | 多选下拉 |
| `radio($prop, $label, ?array $map)` | 单选 |
| `switch($prop, $label)` | 开关 |
| `date($prop, $label)` / `datetime($prop, $label)` | 日期 / 日期时间 |
| `image($prop, $label)` | 图片上传（jpg/jpeg/png） |
| `file($prop, $label)` / `multipleFile($prop, $label)` | 单文件 / 多文件 |
| `excel($prop, $label)` | Excel 导入（multipart + 自动解析） |
| `remote($prop, $label, $table, $valueKey, $labelKey)` | 关联表下拉 |
| `json($prop, $label)` | JSON 键值对编辑器 |
| `tags($prop, $label)` | 标签输入（逗号分隔字符串） |
| `hidden($prop, $label = '')` | 隐藏字段 |
| `add(string $type, $prop, $label, array $options)` | 自定义类型（前端已注册的 type） |

### 0.3 Field 通用方法

| 方法 | 说明 |
|---|---|
| `required(bool)` | 必填 |
| `default($value, ?string $mode)` | 默认值（`add`/`edit` 可分开） |
| `addDefault($v)` / `editDefault($v)` | 仅新增 / 仅编辑默认值 |
| `disabled(bool, ?string $mode)` | 禁用（可分开新增/编辑） |
| `addDisabled()` / `editDisabled()` | 同上快捷写法 |
| `hidden(bool, ?string $mode)` | 隐藏（可分开） |
| `addHidden()` / `editHidden()` | 同上快捷写法 |
| `addOnly(bool)` / `editOnly(bool)` | 仅新增 / 仅编辑出现 |
| `placeholder(string)` | 占位提示 |
| `help(string $html)` | 字段下方帮助文字（**支持 HTML 与 `@click`**） |
| `quickOptions(array)` | 快捷填入按钮（`[值]` 或 `['文案'=>'值']`） |
| `rows(int)` | textarea 行数 |
| `min($min)` / `max($max)` / `step($step)` | 数字范围 / 步长 |
| `multiple(bool)` / `filterable(bool)` | 多选 / 可搜索 |
| `options(array $map, array $config = [])` | 选项（关联数组或二维列表） |
| `rules(string)` | 前端校验规则串 |
| `span(int)` / `full()` / `row(bool)` | 栅格占宽 / 独占一行 / 独立成行 |
| `link(string $target, string $expr)` | 输入时联动写入另一字段 |
| `cascadeFrom(string ...$props)` | 级联依赖字段 |
| `cascadeUrl(string)` / `cascadeHandler(string)` | 级联选项来源（接口 / 控制器方法） |
| `when($value, callable)` | 条件显示 |
| `config(array)` | 兜底原始配置 |

---

## 1. 布局

```php
$grid->form(function (Form $form) {
    $form->columns(2)                 // 两列栅格
        ->labelWidth(120)
        ->labelPosition('right')
        ->contentWidth('480px');
});
```

字段占宽：

```php
$form->text('name', __('Name'))->span(12);   // 半行（columns=2 时）
$form->textarea('remark', __('Remark'))->full(); // 独占整行
```

---

## 2. 基础字段

```php
$form->text('name', __('Name'))->required()->placeholder('请输入名称');
$form->textarea('remark', __('Remark'))->rows(4);
$form->number('price', __('Price'))->min(0)->max(99999)->step('0.01');
$form->password('password', __('Password'))->required()->addOnly();
```

---

## 3. 选项类字段

```php
// 关联数组：值 => 文案
$form->select('status', __('Status'))->options([0 => '禁用', 1 => '启用'])->default(1);

// 二维列表：value / label
$form->select('platform', __('Platform'))->options([
    ['value' => 1, 'label' => '微信'],
    ['value' => 2, 'label' => '支付宝'],
]);

$form->multipleSelect('tags', __('Tags'))->options([1 => '新品', 2 => '热销'])->multiple();
$form->radio('status', __('Status'), [0 => '禁用', 1 => '启用'])->default(1);
$form->switch('enabled', __('Enabled'))->default(1);
```

> `select/multipleSelect` 需要显式 `->options()`；`radio` 可直接把 map 作为第三个参数。

---

## 4. 时间

```php
$form->date('publish_date', __('Publish date'));
$form->datetime('sync_at', __('Sync at'))->required();
```

---

## 5. 上传

```php
$form->image('cover', __('Cover'));                 // jpg/jpeg/png
$form->file('attachment', __('Attachment'));        // 通用文件
$form->multipleFile('images', __('Images'));        // 多文件（JSON 数组）
$form->excel('import_file', __('Excel'))
    ->help('上传 xlsx，系统自动解析并导入');
```

---

## 6. 关联表下拉

```php
$form->remote('user_id', __('User'), 'admin_users', 'id', 'username')
    ->filterable();
```

---

## 7. JSON 与标签

```php
// JSON 键值对编辑器（提交时序列化为 JSON 字符串）
$form->json('extra', __('Extra'))->help('键值对形式保存到 extra 列');

// 标签输入：DB 存 "a,b,c" 字符串，前端用数组编辑
$form->tags('tags', __('Tags'));
```

---

## 8. 点式字段（读写 JSON 子键）

```php
// 读写 config 列（JSON）里的 api_url / timeout
$form->text('config.api_url', __('Api url'))->required();
$form->number('config.timeout', __('Timeout seconds'))->min(1)->max(600);
$form->select('config.platform', __('Platform'))->options([1 => '微信', 2 => '支付宝']);
$form->switch('config.enabled', __('Enabled'));
```

保存时后端把散落的点式键合并回根列（JSON），表单里没出现的键原样保留。

---

## 9. 通用属性

```php
$form->text('name', __('Name'))
    ->required()
    ->default('未命名')
    ->placeholder('请输入名称')
    ->rules('min:2|max:50')
    ->help('2~50 个字符');

$form->text('code', __('Code'))
    ->editDisabled()     // 编辑时不可改
    ->addDefault('AUTO');
```

### 9.1 帮助文字里的交互（HTML + `@click`）

```php
$form->text('config.api_token', __('Api token'))
    ->help('留空沿用旧值 · <span @click="fillToken()" style="cursor:pointer;color:var(--el-color-primary)">填入示例</span>');
```

配合 `$grid->setCustomJs()` 定义全局函数：

```php
$grid->setCustomJs(<<<'JS'
function fillToken() {
  curd.setFormData({ 'config.api_token': 'demo-' + Date.now() })
  curd.message.success('已填入')
}
JS);
```

> `help` 内容用 `v-html` 渲染，`@click` 由事件委托执行；函数必须是全局的（customJs 顶层 `function`）。
> 另支持 `<span class="content-click">文本</span>`：点击把文本回填到该字段。

### 9.2 快捷填入

```php
$form->text('remark', __('Remark'))
    ->quickOptions(['已联系客户', '客户要求回电' => '客户要求回电，待跟进']);
```

### 9.3 字段联动

```php
// 输入数量时自动算金额：expr 是 JS 表达式，可用 val / form / round / fn
$form->number('qty', __('Qty'))
    ->link('amount', 'round(val * form.price, 2)');

$form->number('price', __('Price'));
$form->number('amount', __('Amount'))->disabled();
```

### 9.4 级联下拉

```php
$form->select('province_id', __('Province'))->options($provinces);
$form->select('city_id', __('City'))
    ->cascadeFrom('province_id')
    ->cascadeHandler('cities');   // 控制器 public function cascadeCities(Request $request)
```

控制器侧：

```php
public function cascadeCities(\support\Request $request)
{
    $pid = (int)$request->get('province_id');
    return json(['code' => 0, 'data' => City::where('province_id', $pid)->get(['id', 'name'])]);
}
```

---

## 10. 场景控制

```php
$grid->form(function (Form $form) {
    // 方式一：字段级
    $form->text('name', __('Name'))->isCreate();
    $form->text('code', __('Code'))->isEdit();

    // 方式二：条件显示（依赖另一个字段的值）
    $form->select('type', __('Type'))->options([1 => '普通', 2 => '高级']);
    $form->when('type', 2, function (Form $form) {
        $form->number('quota', __('Quota'));
    });
});
```

---

## 11. 完整可运行示例

```php
$grid->form(function (Form $form) {
    $form->columns(2)->labelWidth(130)->contentWidth('520px');

    $form->text('name', __('Name'))->required()->span(12)->rules('max:50');
    $form->select('status', __('Status'))->options([0 => '禁用', 1 => '启用'])->default(1)->span(12);

    $form->number('price', __('Price'))->min(0)->step('0.01')->span(12);
    $form->number('stock', __('Stock'))->min(0)->span(12);

    $form->image('cover', __('Cover'))->span(12);
    $form->tags('tags', __('Tags'))->span(12);

    $form->text('config.api_url', __('Api url'))->required()->full()
        ->help('接口地址，需以 http(s):// 开头');
    $form->text('config.api_token', __('Api token'))->full()
        ->help('留空沿用旧值 · <span @click="fillToken()" style="cursor:pointer;color:var(--el-color-primary)">填入示例</span>');
    $form->switch('config.enabled', __('Enabled'))->default(1)->span(12);
    $form->datetime('config.sync_at', __('Sync at'))->span(12);

    $form->json('extra', __('Extra'))->full();
    $form->textarea('remark', __('Remark'))->rows(3)->full();
});
```

配套 `customJs` 与路由见 [API · Grid §12](20-API-Grid.md#12-一个完整可运行示例)。
