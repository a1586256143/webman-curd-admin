# API · Column 列方法大全

> `$grid->column($prop, $label)` 返回 `Column`，所有方法返回自身，可链式组合。
> 代码位置 `plugin/curd/app/dsl/Column.php`。
> 点式列（`config.api_url`）对**任意列方法**都成立，与字段类型无关。

---

## 0. 速查表

| 方法 | 说明 |
|---|---|
| `width(int)` / `minWidth(int)` | 列宽 / 最小宽 |
| `align('center')` | 对齐 `left/center/right` |
| `fixed('right')` | 固定列 `left/right` |
| `sortable(bool)` | 是否可排序 |
| `hidden(bool)` | 默认隐藏（可在「列配置」里打开） |
| `showInList(bool)` | 是否出现在列表 |
| `limit(int)` | 文本截断长度（超出省略） |
| `map(array)` | 值 → 文案 |
| `label(array)` | 值 → el-tag 颜色（配合 map） |
| `switch(int $active, int $inactive)` | 行内开关（显示为开关） |
| `display(string\|callable)` | 自定义显示值；**字符串按 HTML 渲染** |
| `html(bool)` | 显示值按 HTML 渲染（闭包需显式开启） |
| `prefix(string)` / `suffix(string)` | 显示值前后缀 |
| `copy()` | 可复制列（复制按钮，复制显示值） |
| `datetime()` / `date()` | 时间格式化 |
| `image(int $w, int $h)` / `staticHost(?string)` | 图片列 / 静态资源域名 |
| `open('_blank')` | 点击在新窗口打开该列值 |
| `href(string\|callable, string $target)` | 单元格链接 |
| `remote(string $table, $valueKey, $labelKey)` | 关联表翻译 |
| `editable(string $type, array $options)` | 行内编辑 |
| `select(array $options)` | 行内下拉编辑（`editable('select')` 便捷写法） |
| `config(array)` | 塞入任意原始列配置（兜底） |

---

## 1. 布局

```php
$grid->column('id', __('ID'))->width(70)->align('center');
$grid->column('name', __('Name'))->minWidth(160)->sortable();
$grid->column('remark', __('Remark'))->limit(20);       // 超 20 字省略
$grid->column('updated_at', __('Updated at'))->hidden(); // 默认不显示
$grid->column('actions_placeholder', '')->fixed('right');
```

---

## 2. 字典映射与颜色标签

```php
$grid->column('status', __('Status'))
    ->map([0 => '禁用', 1 => '启用'])
    ->label([0 => 'danger', 1 => 'success']);   // el-tag 颜色
```

- `map` 只改显示文案，原始值仍用于编辑回显；
- `label` 让单元格渲染成彩色标签（值必须与 map 的键一致）。

**行内开关**（直接显示 el-switch，切换即保存）：

```php
$grid->column('status', __('Status'))->switch(1, 0);
```

---

## 3. 显示值与 HTML

```php
// ① 闭包：返回文本（默认转义，安全）
$grid->column('status', __('Status'))
    ->display(fn($v) => $v == 1 ? '开启' : '关闭');

// ② 闭包 + ->html()：返回 HTML
$grid->column('name', __('Name'))
    ->display(fn($v, $row) => '<b>' . htmlspecialchars($v) . '</b>')
    ->html();

// ③ 字符串模板：自动按 HTML 渲染，{prop} 取行字段、{value} 取原始值
$grid->column('name', __('Name'))
    ->display('<a href="/admin/user/{id}" target="_blank">{name}</a>');

// ④ 前后缀（与 display 可叠加：先 display 再拼前后缀）
$grid->column('price', __('Price'))->prefix('￥')->suffix(' 元');
```

> 闭包内 `$this` 绑定为**当前行**，也可用第二个参数 `$row`。

**可复制列**：

```php
$grid->column('config.api_url', __('Api url'))->copy();
```

复制的是 `display()` / `map()` / `prefix()` 之后的最终显示值；copy 的渲染优先级最高。

---

## 4. 时间

```php
$grid->column('created_at', __('Created at'))->datetime()->width(180);
$grid->column('birthday', __('Birthday'))->date();
```

---

## 5. 图片与静态资源

```php
$grid->column('avatar', __('Avatar'))->image(40, 40);
$grid->column('cover', __('Cover'))->image()->staticHost('https://cdn.example.com');
```

`staticHost` 不传时取配置 `curd-admin.image_server`。

---

## 6. 链接

```php
// 固定地址，新窗口
$grid->column('url', __('Url'))->open('_blank');

// 动态链接：闭包返回 URL 字符串，或 ['url'=>..,'target'=>..]
$grid->column('order_no', __('Order no'))
    ->href(fn($v, $row) => '/app/curd/#/order-detail?id=' . $row->id);
```

---

## 7. 关联表翻译

```php
$grid->column('user_id', __('User'))
    ->remote('admin_users', 'id', 'username');   // 表, 值字段, 显示字段
```

---

## 8. 行内编辑

```php
// 文本行内编辑
$grid->column('remark', __('Remark'))->editable('input');

// 下拉行内编辑（关联数组：值 => 文案）
$grid->column('status', __('Status'))->select([0 => '禁用', 1 => '启用']);

// 下拉行内编辑（二维列表：value/label）
$grid->column('platform', __('Platform'))->select([
    ['value' => 1, 'label' => '微信'],
    ['value' => 2, 'label' => '支付宝'],
]);
```

- 修改后立即提交到当前控制器的 `update` 接口；
- 行内编辑字段自动加入**可写字段白名单**，不必再写进 `form()`；
- 带 `->select()` 的列优先级低于 `display()`（若同时存在，display 生效）。

---

## 9. 兜底配置

```php
$grid->column('extra', __('Extra'))->config([
    'type' => 'tag',
    'width' => 120,
]);
```

直接合并进列配置对象，用于前端新增能力先行试用。

---

## 10. 完整示例

```php
protected function grid(): Grid
{
    $grid = new Grid(new HfGoods());
    $grid->getModel()->orderByDesc('id');

    $grid->column('id', __('ID'))->width(70)->align('center');
    $grid->column('name', __('Name'))->minWidth(160)->sortable();
    // 名称加粗 + 可复制
    $grid->column('code', __('Code'))
        ->display('<code>{value}</code>')
        ->copy();
    $grid->column('price', __('Price'))->prefix('￥');
    $grid->column('status', __('Status'))
        ->map([0 => '下架', 1 => '上架'])
        ->label([0 => 'info', 1 => 'success'])
        ->select([0 => '下架', 1 => '上架']);
    $grid->column('cover', __('Cover'))->image(48, 48);
    $grid->column('created_at', __('Created at'))->datetime()->width(180);
    $grid->column('updated_at', __('Updated at'))->hidden();

    return $grid;
}
```

对应路由与控制器骨架见 [API · Grid §12](20-API-Grid.md#12-一个完整可运行示例)。
