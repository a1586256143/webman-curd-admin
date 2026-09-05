<?php
namespace plugin\crud\app\dsl;

/**
 * 列配置（链式）
 *
 * 用法：
 *   $grid->column('status', '状态')->map([1 => '开启', 0 => '关闭'])->label([1 => 'success', 0 => 'danger']);
 *   $grid->column('id', 'ID')->width(80)->sortable()->align('center');
 *   $grid->column('qid', '渠道')->remote('a_channels', 'id', 'title');
 *   $grid->column('created_at', '创建时间')->datetime();
 *   $grid->column('a', 'A')->width(80)->column('b', 'B');     // 链式下一列
 *   $grid->column('a', 'A')->filter(fn(...))->form(fn(...));   // 一气呵成（filter/form 代理到 Grid）
 *
 * @mixin Grid
 */
class Column extends BaseDsl
{
    protected string $prop;
    protected string $label;
    protected array $config = [];

    /**
     * 所属 Grid（用于链式 ->column() 代理）
     */
    protected ?Grid $grid = null;

    /**
     * display 处理器（PHP 闭包，服务端执行，不进前端 JSON）
     */
    protected $displayHandler = null;

    /**
     * href 处理器（PHP 闭包，服务端执行生成动态链接，不进前端 JSON）
     */
    protected $hrefHandler = null;

    /**
     * 显示值前缀（如 '$'），由服务端拼接进 display_{prop}，与 display() 可叠加
     */
    protected ?string $prefix = null;

    /**
     * 显示值后缀（如 ' 元'），由服务端拼接进 display_{prop}，与 display() 可叠加
     */
    protected ?string $suffix = null;

    public function __construct(string $prop, ?string $label = null)
    {
        $this->prop = $prop;
        $this->label = $label ?? $prop;
    }

    /**
     * 绑定所属 Grid（Grid::column 时自动注入）
     */
    public function setGrid(Grid $grid): self
    {
        $this->grid = $grid;
        return $this;
    }

    /**
     * 链式继续添加下一列（代理到 Grid）
     */
    public function column(string $prop, ?string $label = null): Column
    {
        if (!$this->grid) {
            throw new \RuntimeException('Column::column() 需要在 Grid 链式上下文中使用');
        }
        return $this->grid->column($prop, $label);
    }

    /**
     * 链式代理：filter()/form()/setDetail()/setOptions() 等 Grid 方法
     * 支持 $grid->column(...)->filter(fn)->form(fn) 一气呵成
     */
    public function __call(string $method, array $arguments)
    {
        if (!$this->grid) {
            throw new \RuntimeException("Column::{$method}() 需要在 Grid 链式上下文中使用");
        }
        if (!method_exists($this->grid, $method)) {
            throw new \RuntimeException("Grid 没有方法 {$method}");
        }
        // filter/form 返回 Grid（保持链式继续），其余按 Grid 方法返回
        return $this->grid->{$method}(...$arguments);
    }

    public function width(int $width): self
    {
        $this->config['width'] = $width;
        return $this;
    }

    public function minWidth(int $minWidth): self
    {
        $this->config['minWidth'] = $minWidth;
        return $this;
    }

    /**
     * 文本截断显示（对标 laravel-admin 的 limit）
     * 列表内容长度超过 $limit 字符时截断显示 + 箭头图标，点击查看完整内容
     * 例：->limit(20)
     */
    public function limit(int $limit): self
    {
        $this->config['limit'] = $limit;
        return $this;
    }

    public function sortable(bool $sortable = true): self
    {
        $this->config['sortable'] = $sortable;
        return $this;
    }

    public function align(string $align): self
    {
        $this->config['align'] = $align;
        return $this;
    }

    public function fixed(string $fixed): self
    {
        $this->config['fixed'] = $fixed;
        return $this;
    }

    /**
     * 时间列（含时分秒）
     */
    public function datetime(): self
    {
        $this->config['type'] = 'datetime';
        return $this;
    }

    /**
     * 日期列（仅年月日）
     */
    public function date(): self
    {
        $this->config['type'] = 'date';
        return $this;
    }

    /**
     * 图片列（带宽高参数，控制单元格内图片显示尺寸）
     * 默认 40×40；可传 width、height 自定义
     *
     * 自动带上 config('admin.image_server') 作为拼装前缀（写入 staticHost）：
     * 列值为相对路径（file/xxx.png）时，前端会拼成 https://xxxx.com/file/xxx.png；
     * 配置为空字符串时不做任何拼装，行为与原先一致。
     */
    public function image(int $width = 40, int $height = 40): self
    {
        $this->config['type'] = 'image';
        $this->config['imageWidth'] = $width;
        $this->config['imageHeight'] = $height;
        $this->config['staticHost'] = config('admin.image_server', '');
        return $this;
    }

    /**
     * 静态资源域名前缀（通用列，与 image() 的域名拼装共用一套配置）
     *
     * 设置后该列数据前面自动拼接 config('admin.image_server') 域名：
     * 列值为相对路径（file/xxx.png）时，显示文本/跳转链接会拼成 https://xxxx.com/file/xxx.png；
     * 列值已是完整 URL（http:// 或 https:// 开头）时不重复拼接。
     *
     * 用法：
     *   ->staticHost()                        // 默认用 config('admin.image_server')，如 https://xxxx.com
     *   ->staticHost('https://cdn.xxx.com')   // 显式覆盖域名
     *
     * @param string|null $host 静态资源域名；为 null 时回退到 config('admin.image_server')
     */
    public function staticHost(?string $host = null): self
    {
        $this->config['staticHost'] = $host ?? config('admin.image_server', '');
        return $this;
    }

    /**
     * 链接列：显示固定文案"打开"，点击跳转，hover 显示完整 URL
     * 列值支持单个 URL 字符串，也支持数组（如 JSON cast 的多附件字段）：
     * 数组时前端会逐项生成多个"打开"按钮，空值项自动跳过。
     *
     * 例：
     *   ->open()                       // 单个 URL，默认 _blank
     *   ->open('_self')                // 当前窗口打开
     *
     * @param string $target '_self' | '_blank' | '_parent' | '_top'（默认 '_blank'）
     */
    public function open(string $target = '_blank'): self
    {
        $this->config['type'] = 'open';
        $this->config['linkTarget'] = $target;
        return $this;
    }

    /**
     * 链接列（保留原展示内容）：文本显示列值本身，点击跳转，hover 显示完整 URL
     * 与 open() 的区别仅在于展示文本：open 显示"打开"，href 显示原内容
     *
     * $target 传字符串 = 链接 target（'_self' 默认）；
     * $target 传闭包 = 动态生成链接地址（PHP 服务端执行）：
     *   闭包中 $this 绑定为当前行数据对象（可用 $this->id 等取字段），返回链接 URL 字符串
     *   例：->href(fn() => '/contract/' . $this->id)  或  ->href(fn() => '/contract/' . $this->id, '_blank')
     */
    public function href(string|callable $target = '_self', string $linkTarget = '_self'): self
    {
        $this->config['type'] = 'href';
        if (is_callable($target)) {
            $this->hrefHandler = $target;
            $this->config['linkTarget'] = $linkTarget;
            $this->config['hrefDynamic'] = true;
        } else {
            $this->config['linkTarget'] = $target;
        }
        return $this;
    }

    /**
     * 获取 href 处理器（PHP 侧使用，不进 JSON）
     */
    public function getHrefHandler(): ?callable
    {
        return $this->hrefHandler;
    }

    /**
     * 开关列（行内切换）
     */
    public function switch(int $activeValue = 1, int $inactiveValue = 0): self
    {
        $this->config['type'] = 'switch';
        $this->config['activeValue'] = $activeValue;
        $this->config['inactiveValue'] = $inactiveValue;
        return $this;
    }

    /**
     * 标签列（tag 颜色映射，仅颜色）
     * 仅设置颜色：->label([0 => 'danger', 1 => 'success'])
     * 文案一般配合 map() 使用：->map([0 => '未付款', 1 => '已付款'])->label([0 => 'danger', 1 => 'success'])
     * （兼容旧写法 ->label([0 => ['未付款','danger']])，自动拆为文案+颜色）
     *
     * 颜色映射写入 config['tagType']（{ value: 'type' }），前端 getTagType 原生支持，
     * 避免与列标题 config['label'] 冲突。
     */
    public function label(array $map): self
    {
        $this->config['type'] = 'tag';
        $this->config['tagType'] = $this->normalizeTagTypes($map);
        return $this;
    }

    /**
     * 字典映射（值 → 显示文案，不强制 tag 样式）
     * 例：->map([0 => '未付款', 1 => '已付款'])
     * 用途 1：tag 文案（配合 label() 设置颜色）
     * 用途 2：普通列 id → 名称 文本替换（如 ->map($nameTypes)）
     */
    public function map(array $map): self
    {
        $this->config['map'] = $this->normalizeMap($map);
        return $this;
    }

    /**
     * 远程列：ID → 名字（数据源 /api/options/{table}）
     */
    public function remote(string $table, string $valueKey = 'id', string $labelKey = 'name'): self
    {
        $this->config['remote'] = true;
        $this->config['remoteTable'] = self::processTable($table);
        $this->config['valueKey'] = $valueKey;
        $this->config['labelKey'] = $labelKey;
        return $this;
    }

    /**
     * 行内可编辑
     * $type: input / select / switch / number
     */
    public function editable(string $type = 'input', array $options = []): self
    {
        $this->config['editable'] = true;
        $this->config['editType'] = $type;
        if ($type === 'select' && !empty($options['options'])) {
            $this->config['options'] = $options['options'];
        }
        return $this;
    }

    /**
     * 隐藏列（detail 中显示但列表不显示，或反之）
     */
    public function hidden(bool $hidden = true): self
    {
        $this->config['hidden'] = $hidden;
        return $this;
    }

    /**
     * 列表不显示（仅在详情/表单中）
     */
    public function showInList(bool $show = true): self
    {
        $this->config['showInList'] = $show;
        return $this;
    }

    /**
     * display 显示处理器（对标 laravel-admin 的 display()）
     * 闭包在【服务端列表接口】执行：fn($value, $row) => 显示文本
     * 结果以 display_{prop} 字段附加到每行数据，前端优先显示；原始值保留供编辑回显
     *
     *   $grid->column('status', '状态')->display(function ($value, $row) {
     *       return $value == 1 ? '开启' : '关闭';
     *   });
     */
    public function display(callable $handler): self
    {
        $this->displayHandler = $handler;
        $this->config['display'] = true;
        return $this;
    }

    /**
     * 在显示值前面添加固定前缀
     * 例：->prefix('$')  原数 100 → $100
     * 可与 display() 组合：先经 display 闭包处理，再拼接前缀
     * 注意：一旦设置 prefix/suffix/display，结果以服务端 display_{prop} 返回，
     *       前端会优先使用，不再对原始值套用列上的 map/label。
     */
    public function prefix(string $prefix): self
    {
        $this->prefix = $prefix;
        return $this;
    }

    /**
     * 在显示值后面添加固定后缀
     * 例：->suffix(' 元')  原数 100 → 100 元
     * 可与 display() 组合：先经 display 闭包处理，再拼接后缀
     */
    public function suffix(string $suffix): self
    {
        $this->suffix = $suffix;
        return $this;
    }

    /**
     * 获取 display 处理器（PHP 侧使用，不进 JSON）
     * 组合规则：若设置了自定义 display() 闭包，先执行它；再叠加 prefix/suffix。
     * 三者都未设置时返回 null（不进入服务端 display 处理流程）。
     */
    public function getDisplayHandler(): ?callable
    {
        $base = $this->displayHandler;
        $pre = $this->prefix;
        $suf = $this->suffix;
        if ($base === null && $pre === null && $suf === null) {
            return null;
        }
        return function ($value, $row) use ($base, $pre, $suf) {
            // 关键：用 ->call($this, ...) 把用户闭包（及其内嵌闭包）的 $this 也绑定到
            // 当前行上下文（此处 $this 已被 applyDisplayHandlers 通过 Closure::bind 绑到 $rowCtx）。
            // 否则闭包 $this 会沿用「定义时」的控制器作用域，导致 $this->字段 取不到行数据。
            $v = $base !== null ? $base->call($this, $value, $row) : $value;
            if ($v === null) {
                $v = '';
            }
            return ($pre ?? '') . $v . ($suf ?? '');
        };
    }

    /**
     * 自定义列配置（兜底，直接塞原始配置项）
     */
    public function config(array $extra): self
    {
        $this->config = array_merge($this->config, $extra);
        return $this;
    }

    /**
     * 输出前端配置
     */
    public function toArray(): array
    {
        return array_merge([
            'prop' => $this->prop,
            'label' => $this->label,
        ], $this->config);
    }

    /**
     * 归一化文本字典 map：{ value: '文案' }
     *
     * 返回 stdClass（而非数组）：PHP 数组键为连续整数（如 0/1）时 json_encode 会序列化成
     * JSON 数组并丢失键值，前端 col.map[val] 将查不到。强转 stdClass 保证输出 JSON 对象。
     * 兼容旧写法 [value => ['文案', 'type']]（取 [0] 作为文案）。
     */
    protected function normalizeMap(array $map): \stdClass
    {
        $result = [];
        foreach ($map as $value => $conf) {
            $result[(string)$value] = is_array($conf) ? (string)($conf[0] ?? $value) : (string)$conf;
        }
        return (object)$result;
    }

    /**
     * 归一化颜色字典 map：{ value: 'type' }
     * 兼容旧写法 [value => ['文案', 'type']]（取 [1] 作为颜色）。
     */
    protected function normalizeTagTypes(array $map): \stdClass
    {
        $result = [];
        foreach ($map as $value => $conf) {
            if (is_array($conf)) {
                $result[(string)$value] = (string)($conf[1] ?? 'primary');
            } else {
                $result[(string)$value] = (string)$conf;
            }
        }
        return (object)$result;
    }
}
