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
     * 输出前端配置
     */
    public function toArray(): array
    {
        return $this->filters;
    }
}
