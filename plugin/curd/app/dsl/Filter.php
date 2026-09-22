<?php
namespace plugin\curd\app\dsl;

/**
 * 搜索筛选配置（链式）
 *
 * 用法：
 *   $grid->filter(function ($filter) {
 *       $filter->like('title', '标题');
 *       $filter->select('status', '状态')->options([1 => '开启', 0 => '关闭']);
 *       $filter->between('created_at', '创建时间');
 *       $filter->remote('qid', '渠道', 'a_channels', 'id', 'title');
 *   });
 */
class Filter extends BaseDsl
{
    protected array $filters = [];

    /**
     * 当前用户输入内容（服务端执行 where 闭包前注入，闭包内用 $this->input 访问）
     */
    public $input = '';

    /**
     * 自定义 SQL 条件回调：[prop => callable($query)]
     * 闭包在 where() 内定义，$this 自动绑定本 Filter 实例，可访问 $this->input
     */
    protected array $whereCallbacks = [];

    /**
     * 模糊搜索（input）
     */
    public function like(string $prop, string $label, array $options = []): self
    {
        $this->filters[] = array_merge([
            'prop' => $prop,
            'label' => $label,
            'type' => 'input',
        ], $options);
        return $this;
    }

    /**
     * 精确搜索（默认 input，可 ->select() 变下拉）
     */
    public function equal(string $prop, string $label, array $options = []): self
    {
        $this->filters[] = array_merge([
            'prop' => $prop,
            'label' => $label,
            'type' => 'input',
        ], $options);
        return $this;
    }

    /**
     * 自定义 SQL 搜索条件
     *
     *   $filter->where(function ($query) {
     *       // $this->input = 当前用户输入内容
     *       $query->whereRaw("find_in_set('{$this->input}', project)");
     *   }, __('新系统项目'))->select($newProds);   // ->select($map) 可把输入框变下拉
     *
     * 参数说明：
     *   - $callback：接收 Eloquent Query Builder 的闭包，可 whereRaw/where 等任意条件；
     *     闭包内可用 $this->input 读取用户当前输入（框架在查询前注入，空输入不执行）
     *   - $label：搜索项名称（前端显示）
     *   - $prop：搜索项标识（可选，缺省自动生成 where_0/where_1...）
     *
     * @param callable $callback
     * @param string   $label
     * @param string   $prop
     */
    public function where(callable $callback, string $label, string $prop = ''): self
    {
        $prop = $prop !== '' ? $prop : 'where_' . count($this->filters);
        $this->filters[] = [
            'prop' => $prop,
            'label' => $label,
            'type' => 'input',
            'where' => true, // 标记：自定义 SQL 条件（仅服务端执行，不进 JSON）
        ];
        // 关键：闭包定义在控制器方法里，$this 默认绑定控制器实例；
        // 这里重绑到本 Filter 实例，闭包内 $this->input 才能读到用户输入
        if ($callback instanceof \Closure) {
            $callback = $callback->bindTo($this);
        }
        $this->whereCallbacks[$prop] = $callback;
        return $this;
    }

    /**
     * 设置当前用户输入（查询前由框架注入，供 where 闭包内 $this->input 使用）
     */
    public function setInput($input): self
    {
        $this->input = is_array($input) ? implode(',', $input) : (string)$input;
        return $this;
    }

    /**
     * 自定义 SQL 条件回调表（服务端专用，不进 JSON）
     */
    public function whereCallbacks(): array
    {
        return $this->whereCallbacks;
    }

    /**
     * 下拉搜索（select + options）
     * 选项通过 ->options() 链式设置
     */
    public function select($prop, $label = null): self
    {
        // where(...)->select($map)：把最近一个搜索项（如自定义 where）变下拉，$map 为选项
        if (is_array($prop)) {
            $idx = count($this->filters) - 1;
            if ($idx >= 0) {
                $this->filters[$idx]['type'] = 'select';
                $opts = [['value' => '', 'label' => '全部']];
                foreach ($prop as $value => $text) {
                    $opts[] = ['value' => $value, 'label' => $text];
                }
                $this->filters[$idx]['options'] = $opts;
            }
            return $this;
        }
        $this->filters[] = [
            'prop' => $prop,
            'label' => $label ?? '',
            'type' => 'select',
            'options' => [],
        ];
        return $this;
    }

    /**
     * 设置最近一个下拉项（select）的选项与额外配置
     * $map:    [value => '文案']（原 select 第 3 参）
     * $config: 额外配置如 ['filterable' => true]（原 select 第 4 参）
     * 必须在 select() 之后链式调用
     */
    public function options(array $map = [], array $config = []): self
    {
        $idx = count($this->filters) - 1;
        if ($idx < 0) {
            return $this;
        }
        $opts = [['value' => '', 'label' => '全部']];
        foreach ($map as $value => $text) {
            $opts[] = ['value' => $value, 'label' => $text];
        }
        $this->filters[$idx]['options'] = $opts;
        if ($config) {
            $this->filters[$idx] = array_merge($this->filters[$idx], $config);
        }
        return $this;
    }

    /**
     * 范围搜索（daterange）
     */
    public function between(string $prop, string $label, array $options = []): self
    {
        $this->filters[] = array_merge([
            'prop' => $prop,
            'label' => $label,
            'type' => 'daterange',
        ], $options);
        return $this;
    }

    /**
     * 把最近一个范围搜索改为「仅日期」（daterange，默认即是）
     * 用法：$filter->between('created_at', '创建时间')->date();
     */
    public function date(): self
    {
        return $this->setLastType('daterange');
    }

    /**
     * 把最近一个范围搜索改为「日期 + 时间」（datetimerange）
     * 用法：$filter->between('created_at', '创建时间')->datetime();
     */
    public function datetime(): self
    {
        return $this->setLastType('datetimerange');
    }

    /**
     * 修改最近一个搜索项的类型（链式辅助）
     */
    protected function setLastType(string $type): self
    {
        $idx = count($this->filters) - 1;
        if ($idx >= 0) {
            $this->filters[$idx]['type'] = $type;
        }
        return $this;
    }

    /**
     * 日期范围搜索（别名，同 between）
     */
    public function dateRange(string $prop, string $label, array $options = []): self
    {
        return $this->between($prop, $label, $options);
    }

    /**
     * 单日期搜索（选某一天，后端用 whereDate，对 date/datetime 列均适用）
     * 用法：$filter->singleDate('begin_time', '开始时间');
     */
    public function singleDate(string $prop, string $label, array $options = []): self
    {
        $this->filters[] = array_merge([
            'prop' => $prop,
            'label' => $label,
            'type' => 'date',
        ], $options);
        return $this;
    }

    /**
     * 远程下拉搜索（数据源 /api/options/{table}）
     */
    public function remote(string $prop, string $label, string $table, string $valueKey = 'id', string $labelKey = 'name', array $options = []): self
    {
        $this->filters[] = array_merge([
            'prop' => $prop,
            'label' => $label,
            'type' => 'select',
            'remote' => true,
            'remoteTable' => self::processTable($table),
            'valueKey' => $valueKey,
            'labelKey' => $labelKey,
        ], $options);
        return $this;
    }

    /**
     * 自定义搜索项（兜底，直接塞原始配置）
     */
    public function add(array $item): self
    {
        $this->filters[] = $item;
        return $this;
    }

    /**
     * 给最近一个搜索项设置默认值（前端首屏加载与「重置」时自动填入搜索框）
     *
     *   $filter->dateRange('created_at', '创建时间')->default('-7d');            // 近 7 天（7 天前 ~ 今天）
     *   $filter->dateRange('created_at', '创建时间')->default(['month', 'today']); // 本月 1 号 ~ 今天
     *   $filter->dateRange('created_at', '创建时间')->default(['2026-01-01', '2026-01-31']);
     *   $filter->between('created_at', '创建时间')->datetime()->default(['-1d', 'now']);
     *   $filter->select('status', '状态')->options(self::STATUS)->default(1);
     *   $filter->singleDate('begin_time', '开始时间')->default('today');
     *
     * 区间类（daterange / datetimerange）值的解析规则：
     *   - 数组 [起点, 终点]：两端各自求值；终点给 null / '' 时补「现在」
     *   - 单个相对表达式（'-7d'）→ 起点 = 表达式、终点 = 现在（即「近 7 天」）
     *   - 单个具体日期（'2026-01-01'）→ 两端同值（就是那一天）
     * 日期字符串支持相对写法（在输出配置时按**当前时间**求值）：
     *   today / now · yesterday · week（本周一）· month（本月 1 号）· year（本年 1 月 1 号）
     *   ±N d | day(s) | week(s) | month(s) | year(s)（如 '-7d' '+3days' '-2weeks'）
     * 具体日期串（'2026-01-01' / '2026-01-01 08:00:00'）原样透传。
     */
    public function default($value): self
    {
        $idx = count($this->filters) - 1;
        if ($idx >= 0) {
            $this->filters[$idx]['default'] = $value;
        }
        return $this;
    }

    /**
     * 日期范围默认值的相对表达式求值（区间/单日期搜索项，toArray 时按当前时间展开）
     */
    protected function resolveDefaultDate($value, bool $withTime = false): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        // 非字符串（时间戳 / DateTime）交给调用方语义，原样输出
        if ($value instanceof \DateTimeInterface) {
            return $value->format($withTime ? 'Y-m-d H:i:s' : 'Y-m-d');
        }
        if (!is_string($value)) {
            return (string)$value;
        }
        $format = $withTime ? 'Y-m-d H:i:s' : 'Y-m-d';
        $now = time();
        switch (strtolower(trim($value))) {
            case 'today':
            case 'now':
                return date($format, $now);
            case 'yesterday':
                return date($format, strtotime('-1 day', $now));
            case 'week':
                return date($format, strtotime('monday this week', $now));
            case 'month':
                return date($format, strtotime(date('Y-m-01', $now)));
            case 'year':
                return date($format, strtotime(date('Y-01-01', $now)));
        }
        // '-7d' / '+3days' / '-2weeks' 这类相对偏移；纯日期串不匹配，原样返回
        if (preg_match('/^([+-]\d+)\s*(d|day|days|w|week|weeks|month|months|year|years)$/i', trim($value), $m)) {
            $unit = strtolower($m[2]);
            $unit = in_array($unit, ['d', 'day', 'days'], true) ? 'days' : $unit;
            $unit = in_array($unit, ['w', 'week', 'weeks'], true) ? 'weeks' : $unit;
            return date($format, strtotime($m[1] . ' ' . $unit, $now));
        }
        return $value;
    }

    /**
     * 判断是否为「相对日期表达式」（today / yesterday / week / month / year / ±N d|w|month|year）
     */
    protected function isRelativeDateToken($value): bool
    {
        if (!is_string($value)) {
            return false;
        }
        $v = strtolower(trim($value));
        if (in_array($v, ['today', 'now', 'yesterday', 'week', 'month', 'year'], true)) {
            return true;
        }
        return (bool)preg_match('/^[+-]\d+\s*(d|day|days|w|week|weeks|month|months|year|years)$/i', $v);
    }

    /**
     * 展开搜索项的 default：日期/区间类走相对表达式求值，其余类型原样
     */
    protected function resolveDefault(array $filter): array
    {
        if (!array_key_exists('default', $filter) || $filter['default'] === null) {
            return $filter;
        }
        $type = $filter['type'] ?? '';
        if ($type === 'daterange' || $type === 'datetimerange') {
            $withTime = ($type === 'datetimerange');
            $raw = $filter['default'];
            if (!is_array($raw)) {
                // 区间给了单个值：
                //   相对表达式（'-7d'）→ 起点=表达式、终点=当前时间（「近 7 天」的直觉语义）
                //   具体日期（'2026-01-01'）→ 两端同值（就是那一天）
                $raw = $this->isRelativeDateToken($raw)
                    ? [$raw, $withTime ? 'now' : 'today']
                    : [$raw, $raw];
            } else {
                $raw = array_values($raw);
                // 注意：不能用 ?? 取终点 —— null 表示「没给」，?? 会退回起点
                $start = $raw[0] ?? '';
                $end = array_key_exists(1, $raw) ? $raw[1] : null;
                // 只给一个日期（另一端给 null / 空串）时，另一端按「现在」补齐
                if ($end === '' || $end === null) {
                    $end = $this->isRelativeDateToken($start) ? ($withTime ? 'now' : 'today') : $start;
                }
                if ($start === '' || $start === null) {
                    $start = $end;
                }
                $raw = [$start, $end];
            }
            $filter['default'] = [
                $this->resolveDefaultDate($raw[0], $withTime),
                $this->resolveDefaultDate($raw[1], $withTime),
            ];
            return $filter;
        }
        if ($type === 'date') {
            $filter['default'] = $this->resolveDefaultDate($filter['default'], false);
        }
        return $filter;
    }

    /**
     * 输出前端配置
     */
    public function toArray(): array
    {
        return array_map(fn(array $f) => $this->resolveDefault($f), $this->filters);
    }
}
