<?php
namespace plugin\curd\app\dsl;

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
     * 自动带上 config('curd-admin.image_server') 作为拼装前缀（写入 staticHost）：
     * 列值为相对路径（file/xxx.png）时，前端会拼成 https://xxxx.com/file/xxx.png；
     * 配置为空字符串时不做任何拼装，行为与原先一致。
     */
    public function image(int $width = 40, int $height = 40): self
    {
        $this->config['type'] = 'image';
        $this->config['imageWidth'] = $width;
        $this->config['imageHeight'] = $height;
        $this->config['staticHost'] = config('curd-admin.image_server', '');
        return $this;
    }

    /**
     * 静态资源域名前缀（通用列，与 image() 的域名拼装共用一套配置）
     *
     * 设置后该列数据前面自动拼接 config('curd-admin.image_server') 域名：
     * 列值为相对路径（file/xxx.png）时，显示文本/跳转链接会拼成 https://xxxx.com/file/xxx.png；
     * 列值已是完整 URL（http:// 或 https:// 开头）时不重复拼接。
     *
     * 用法：
     *   ->staticHost()                        // 默认用 config('curd-admin.image_server')，如 https://xxxx.com
     *   ->staticHost('https://cdn.xxx.com')   // 显式覆盖域名
     *
     * @param string|null $host 静态资源域名；为 null 时回退到 config('curd-admin.image_server')
     */
    public function staticHost(?string $host = null): self
    {
        $this->config['staticHost'] = $host ?? config('curd-admin.image_server', '');
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
     * 行内下拉编辑便捷方法（等价 editable('select', ['options' => $options])）
     *
     * 用法：
     *   $grid->column('ip_platform', 'Ip platform')->select($options);
     *
     * ------------------------------------------------------------------
     * $options 支持两种写法（内部自动归一化为前端要的 [{value,label}] 列表）：
     *
     * ① 关联数组【推荐】：键 = 提交/存储值，值 = 显示文案
     *    [1 => '安卓', 2 => 'iOS', 3 => 'PC']
     *    → 归一化为 [['value'=>1,'label'=>'安卓'], ['value'=>2,'label'=>'iOS'], ...]
     *    注意：键必须是标量（int/string），不能是 null；浮点键会被 PHP 截断，勿用
     *
     * ② 二维列表：直接给 value/label 键的数组（前端原生格式，原样透传）
     *    [['value' => 1, 'label' => '安卓'], ['value' => 2, 'label' => 'iOS']]
     *    适合 value 与 label 都是字符串、或需要顺序敏感/重复 label 的场景
     *
     * 说明：
     * - 保存走当前控制器的 update 接口（行内改完即提交 {id, 字段: 新值}）；
     *   该字段会自动加入可写字段白名单，无需再在 form() 里重复声明；
     * - 若要文案翻译：label 处自行包 __()，如 select([1 => __('Android')])；
     * - 想连颜色标签一起配：再链 ->label([1 => 'success', 2 => 'danger'])。
     *
     * @param array $options 选项，格式见上
     */
    public function select(array $options): self
    {
        $this->config['editable'] = true;
        $this->config['editType'] = 'select';
        $normalized = [];
        foreach ($options as $key => $item) {
            if (is_array($item)) {
                // ② 二维列表：取 value/label 键
                $normalized[] = [
                    'value' => $item['value'] ?? $key,
                    'label' => (string)($item['label'] ?? $item['value'] ?? $key),
                ];
            } else {
                // ① 关联数组：键=值，值=文案
                $normalized[] = ['value' => $key, 'label' => (string)$item];
            }
        }
        $this->config['options'] = $normalized;
        return $this;
    }

    /**
     * 可复制列：单元格展示文本旁附复制按钮，点击复制显示值
     *
     * 用法：
     *   $grid->column('url', __('Url'))->copy();
     *
     * 复制内容 = 该列的【显示值】，优先级：
     *   display()/prefix()/suffix() 的服务端结果（display_{prop}）
     *   → 列 map() 映射文案 → 原始值
     * 即 ->display(fn($v) => 'https://xxx.com/'.$v)->copy() 复制的是拼好后的完整链接。
     *
     * 注意：copy() 会优先于 tag/switch/image 等专用单元格（复制语义以文本为准）。
     */
    public function copy(): self
    {
        $this->config['copy'] = true;
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
     * 两种用法：
     *   1) 闭包（动态计算）
     *      $grid->column('status', '状态')->display(fn($value) => $value == 1 ? '开启' : '关闭');
     *   2) 字符串（HTML 模板，自动按 HTML 渲染）
     *      $grid->column('name', '名称')->display('<a href="/user/{id}" target="_blank">{name}</a>');
     *      · {prop} 用当前行的字段值替换，{value} 用该列原始值替换
     *      · 字符串形式会自动开启 ->html()，前端用 v-html 渲染
     *
     * 闭包返回的 HTML 需显式 ->html() 才会按 HTML 渲染（默认按文本转义，避免误渲染）：
     *   $grid->column('status', '状态')->display(fn($v) => "<b>{$v}</b>")->html();
     */
    public function display(string|callable $handler): self
    {
        $this->displayHandler = $handler;
        $this->config['display'] = true;
        // 字符串即开发者手写的 HTML 模板，直接按 HTML 渲染
        if (is_string($handler)) {
            $this->config['html'] = true;
        }
        return $this;
    }

    /**
     * 把该列显示值按 HTML 渲染（v-html）
     * 与 display()/prefix()/suffix() 组合使用；默认关闭（文本转义，防误渲染）
     *
     *   ->display(fn($v) => '<el-tag>'.$v.'</el-tag>')->html()
     *   ->html(false)   // 显式关闭
     */
    public function html(bool $html = true): self
    {
        $this->config['html'] = $html;
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
        // 字符串模板 → 闭包：{prop} 取当前行字段，{value} 取该列原始值
        if (is_string($base)) {
            $template = $base;
            // 注意：不能声明为 static —— 下方 $base->call($this, ...) 需要绑定行上下文
            $base = function ($value, $row) use ($template) {
                return preg_replace_callback('/\{\s*([a-zA-Z0-9_]+)\s*\}/', static function ($m) use ($value, $row) {
                    $key = $m[1];
                    if ($key === 'value') {
                        return is_scalar($value) ? (string)$value : '';
                    }
                    if (is_object($row)) {
                        $v = method_exists($row, 'getAttribute')
                            ? $row->getAttribute($key)
                            : ($row->{$key} ?? null);
                    } else {
                        $v = $row[$key] ?? null;
                    }
                    return is_scalar($v) ? (string)$v : '';
                }, $template);
            };
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
