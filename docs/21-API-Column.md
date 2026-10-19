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
| `modal(string $title, $content)` | 点击弹窗，弹窗内容服务端逐行生成（Table / Card / Html / 闭包 / 类名 / 模板） |
| `expand($content, string $title = '')` | 点击行内展开/收起（内容写法同 `modal`） |
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

// ②' 闭包返回 HTML 但没写 ->html()：自动检测（默认开启，见下方说明）
$grid->column('ip_status', __('IP状态'))
    ->display(function () {
        return $this->getOther()['proxy_cache'] ?? ''
            ? "<el-tag type='info'>池</el-tag>"
            : "<el-tag type='danger'>兜底</el-tag>";
    });   // 返回值里出现真实标签 → 自动按 HTML 渲染

// ③ 字符串模板：自动按 HTML 渲染，{prop} 取行字段、{value} 取原始值
$grid->column('name', __('Name'))
    ->display('<a href="/admin/user/{id}" target="_blank">{name}</a>');

// ④ 前后缀（与 display 可叠加：先 display 再拼前后缀）
$grid->column('price', __('Price'))->prefix('￥')->suffix(' 元');
```

> 闭包内 `$this` 绑定为**当前行**，也可用第二个参数 `$row`。

**HTML 自动检测（`display_auto_html`，默认开启）**

服务端逐行判断闭包返回值：命中「`<字母…>` 真实标签」就按 HTML 渲染，纯文本照常转义。判定规则：

| 返回值 | 是否按 HTML 渲染 |
|---|---|
| `<span class="label">池</span>` / `<el-tag type="info">池</el-tag>` | ✅ 是 |
| `价格 <span>12</span> 元` | ✅ 是（含真实标签） |
| `纯文本` / `5 < 10` / `a < b 且 b > c` / `<>` | ❌ 否（`<` 后不是标签名） |

优先级：`->html()` 强制 HTML → `->html(false)` 强制文本 → 都没写才走自动检测。
全局关闭：宿主 `config/curd-admin.php` 加 `'display_auto_html' => false`（之后闭包返回 HTML 必须显式 `->html()`）。

> ⚠️ 自动检测开启后，返回值不再自动转义：拼接外部数据（如代理 IP、用户昵称）请自行 `htmlspecialchars()`。
> 该能力需要新前端产物（`display_html_{prop}` 标记），升级插件后**必须重建/同步前端**。

> ⚠️ **`v-html` 不编译 Vue 组件**：`return '<el-tag type="info">池</el-tag>'` 只会得到一个无样式的未知标签
> （Element Plus 的样式挂在 `.el-tag` **类名**上，不是标签名上）。徽标请用内联样式，最稳且不依赖分包 CSS：
>
> ```php
> // 灰（info）徽标；success/warning/danger 换 bg/color/border 三色即可
> return '<span style="display:inline-flex;align-items:center;height:22px;padding:0 8px;'
>      . 'border-radius:4px;font-size:12px;line-height:1;border:1px solid #e9e9eb;'
>      . 'background:#f4f4f5;color:#909399">池</span>';
> ```
>
> 也可写 `<span class="el-tag el-tag--info">池</span>`（依赖该页已加载 el-tag 的样式分包）；
> 想用真组件渲染则走 `->map()` + `->label()`（文案由字典决定，不能再拼额外数据）。

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

## 9. 弹窗与行内展开

给单元格挂一个交互：点开**弹窗**（`modal`）或就地**展开**（`expand`）。
内容在**服务端逐行生成**，随列表接口一起返回（`_modal_{prop}` / `_expand_{prop}`），
前端直接渲染，不用写 Vue。

```php
use plugin\curd\app\dsl\component\Card;
use plugin\curd\app\dsl\component\Table;

// 行内展开：点一下展开，再点收起
$grid->column('money', __('Money'))->expand(function ($value, $row) {
    return new Table(['xxx' => '标题'], [
        ['xxx' => '111'],
        ['xxx' => '1222'],
    ]);
});

// 弹窗：标题 + 内容
$grid->column('order_no', __('Order no'))->modal('订单详情', function ($value, $row) {
    return new Card(['订单号' => $value, '金额' => $row->amount], '基本信息');
});
```

### 9.1 内容的四种写法

| 写法 | 示例 | 说明 |
|---|---|---|
| 组件实例 | `new Table(...)` / `new Card(...)` / `new Html(...)` | 推荐，最直观 |
| 闭包 | `fn($value, $row) => new Card([...])` | `$this` 绑定当前行，可 `$this->id` 取字段 |
| 类名 | `OrderDetailModal::class` | 实例化后依次找 `render($row)` / `schema($row)` / `__invoke($row)` |
| HTML 模板 | `'<b>{order_no}</b> 金额 {amount}'` | `{prop}` 取行字段，`{value}` 取本列值 |

### 9.2 内置组件

```php
new Table(['id' => 'ID', 'name' => '名称'], $rows, ['stripe' => true, 'maxHeight' => 320]);
new Card(['订单号' => 'A001', '状态' => '已支付'], '订单详情', ['column' => 2]);
new Html('<div class="tip">任意 <b>HTML</b></div>');
```

- `Table` 的列也可以细配：`[['prop' => 'id', 'label' => 'ID', 'width' => 80, 'align' => 'center']]`，
  `options` 直接透传 el-table（`border` / `stripe` / `maxHeight` / `size` / `emptyText`）；
- `Card` 的 items 支持 `['标签' => '值']`，或 `[['label' => '..', 'value' => '..', 'html' => true]]`；
- `Html` 按 v-html 渲染，只放自己拼的可信内容。

> 弹窗默认宽 720px；展开面板在单元格内，列窄时内容横向滚动 —— 宽表格建议用 `modal`。

### 9.3 与 display() / copy() 的优先级

`modal` > `expand` > `copy` > `display` > 其它类型。
即同一列既 `->display()` 又 `->modal()` 时：单元格显示 display 的值，点击弹窗。

---

## 10. 兜底配置

```php
$grid->column('extra', __('Extra'))->config([
    'type' => 'tag',
    'width' => 120,
]);
```

直接合并进列配置对象，用于前端新增能力先行试用。

---

## 11. 完整示例

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
