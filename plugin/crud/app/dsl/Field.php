<?php
namespace plugin\crud\app\dsl;

/**
 * 表单字段（链式修饰）
 * 由 Form::text()/select() 等方法创建，支持 ->required()->default() 等链式配置
 */
class Field extends BaseDsl
{
    protected array $config;
    protected ?Form $form = null;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function setForm(Form $form): self
    {
        $this->form = $form;
        return $this;
    }

    /**
     * 根据当前字段值动态显示/替换后续字段。
     * 回调中新增的字段会继承当前条件；同名字段可定义多个条件版本。
     */
    public function when($value, callable $callback): self
    {
        if (!$this->form) {
            throw new \RuntimeException('Field::when() 需要在 Form DSL 上下文中使用');
        }
        $this->form->when($this->config['prop'], $value, $callback);
        return $this;
    }

    public function addVisibleWhen(array $condition): self
    {
        if (!empty($this->config['visibleWhen'])) {
            $existing = $this->config['visibleWhen'];
            $this->config['visibleWhen'] = isset($existing['field'])
                ? [$existing, $condition]
                : array_merge($existing, [$condition]);
        } else {
            $this->config['visibleWhen'] = $condition;
        }
        return $this;
    }

    public function required(bool $required = true): self
    {
        $this->config['required'] = $required;
        return $this;
    }

    /**
     * 仅在 add 模式下的默认值（可调用，fn 接收 ['mode' => 'add']）
     */
    public function addDefault($value): self
    {
        $this->config['defaults'] = $this->config['defaults'] ?? [];
        $this->config['defaults']['add'] = $value;
        return $this;
    }

    /**
     * 仅在 edit 模式下的默认值
     */
    public function editDefault($value): self
    {
        $this->config['defaults'] = $this->config['defaults'] ?? [];
        $this->config['defaults']['edit'] = $value;
        return $this;
    }

    /**
     * 默认值：
     *   - 仅传一个 $value：对 add/edit 都生效
     *   - 传第二个 $mode：仅在该模式下生效
     */
    public function default($value, ?string $mode = null): self
    {
        if ($mode !== null) {
            return $this->addDefault($value)->editDefault($value);
        }
        $this->config['defaults'] = $this->config['defaults'] ?? [];
        $this->config['defaults']['common'] = $value;
        return $this;
    }

    public function addDisabled(bool $disabled = true): self
    {
        $this->config['disableds'] = $this->config['disableds'] ?? [];
        $this->config['disableds']['add'] = $disabled;
        return $this;
    }

    public function editDisabled(bool $disabled = true): self
    {
        $this->config['disableds'] = $this->config['disableds'] ?? [];
        $this->config['disableds']['edit'] = $disabled;
        return $this;
    }

    public function disabled(bool $disabled = true, ?string $mode = null): self
    {
        if ($mode !== null) {
            if ($mode === 'add') return $this->addDisabled($disabled);
            if ($mode === 'edit') return $this->editDisabled($disabled);
        }
        $this->config['disableds'] = $this->config['disableds'] ?? [];
        $this->config['disableds']['common'] = $disabled;
        return $this;
    }

    public function addHidden(bool $hidden = true): self
    {
        $this->config['hiddens'] = $this->config['hiddens'] ?? [];
        $this->config['hiddens']['add'] = $hidden;
        return $this;
    }

    public function editHidden(bool $hidden = true): self
    {
        $this->config['hiddens'] = $this->config['hiddens'] ?? [];
        $this->config['hiddens']['edit'] = $hidden;
        return $this;
    }

    /**
     * 仅 add 模式显示（等价于 hiddens.edit = true）
     */
    public function addOnly(bool $only = true): self
    {
        $this->config['modeOnly'] = 'add';
        return $this;
    }

    /**
     * 仅 edit 模式显示（等价于 hiddens.add = true）
     */
    public function editOnly(bool $only = true): self
    {
        $this->config['modeOnly'] = 'edit';
        return $this;
    }

    public function hidden(bool $hidden = true, ?string $mode = null): self
    {
        if ($mode !== null) {
            if ($mode === 'add') return $this->addHidden($hidden);
            if ($mode === 'edit') return $this->editHidden($hidden);
        }
        $this->config['hiddens'] = $this->config['hiddens'] ?? [];
        $this->config['hiddens']['common'] = $hidden;
        return $this;
    }

    public function placeholder(string $placeholder): self
    {
        $this->config['placeholder'] = $placeholder;
        return $this;
    }

    /**
     * 字段帮助提示（支持 HTML，前端 v-html 渲染）
     * 可配合 Grid::setCustomJs() 注入点击联动逻辑（原生 JS + 事件委托，无需 jQuery）
     *
     *   $form->text('content', '开票内容')
     *       ->help('<span class="content-click" style="color:blue;cursor:pointer">生产生活服务*信息服务费</span>');
     */
    public function help(string $html): self
    {
        $this->config['help'] = $html;
        return $this;
    }

    /**
     * 快捷模板下拉（输入框右侧「常用」按钮）：点选模板自动填入本字段
     * 支持两种格式：
     *   - 字符串数组：['信息服务费', '信息技术服务费']（显示即填入值）
     *   - 关联数组：[显示名 => 填入值]（label 与 value 分离）
     *
     *   $form->text('content', '开票内容')->quickOptions([
     *       '生产生活服务*信息服务费',
     *       '生产生活服务*信息技术服务费',
     *   ]);
     */
    public function quickOptions(array $options): self
    {
        $list = [];
        foreach ($options as $label => $value) {
            if (is_int($label)) {
                $list[] = ['label' => (string)$value, 'value' => (string)$value];
            } else {
                $list[] = ['label' => (string)$label, 'value' => (string)$value];
            }
        }
        $this->config['quickOptions'] = $list;
        return $this;
    }

    public function rows(int $rows): self
    {
        $this->config['rows'] = $rows;
        return $this;
    }

    public function min($min): self
    {
        $this->config['min'] = $min;
        return $this;
    }

    public function max($max): self
    {
        $this->config['max'] = $max;
        return $this;
    }

    public function step($step): self
    {
        $this->config['step'] = $step;
        return $this;
    }

    public function multiple(bool $multiple = true): self
    {
        $this->config['multiple'] = $multiple;
        return $this;
    }

    public function filterable(bool $filterable = true): self
    {
        $this->config['filterable'] = $filterable;
        return $this;
    }

    /**
     * 下拉/单选选项
     * $map: [value => '文案'] 或 [value => ['文案','success']]
     * $config: 额外字段配置（如 ['filterable' => true]），merge 进字段顶层
     */
    public function options(array $map, array $config = []): self
    {
        $options = [];
        foreach ($map as $value => $conf) {
            if (is_array($conf)) {
                $options[] = ['value' => $value, 'label' => $conf[0] ?? $value];
            } else {
                $options[] = ['value' => $value, 'label' => (string)$conf];
            }
        }
        $this->config['options'] = $options;
        if ($config) {
            $this->config = array_merge($this->config, $config);
        }
        return $this;
    }

    /**
     * 校验规则字符串（如 'required|max:50'）
     */
    public function rules(string $rules): self
    {
        $this->config['ruleStr'] = $rules;
        return $this;
    }

    /**
     * 占位宽度（1~24，Element Plus 栅格 span）
     * 不设置时按 Form::columns() 默认列数自动均分（如 2 列 → span 12，3 列 → span 8）。
     * 例：一行两列中的某一列想加宽 → ->span(16)
     */
    public function span(int $span): self
    {
        $this->config['span'] = $span;
        return $this;
    }

    /**
     * 强制从新的一行开始排列（配合 Form::columns() 多列布局使用）
     * 例：$form->columns(2) 后，某字段调用 ->row() 会另起一行。
     */
    public function row(bool $row = true): self
    {
        $this->config['row'] = $row;
        return $this;
    }

    /**
     * 独占整行（等价 ->span(24)，语义更清晰）
     * 例：多列布局中让 textarea/富文本/上传等宽字段占满一行。
     */
    public function full(): self
    {
        $this->config['span'] = 24;
        return $this;
    }

    /**
     * 字段联动：本字段被输入时，自动计算并回填目标字段（前端实时执行）
     *
     * @param string $target 目标字段名
     * @param string $expr   计算表达式（JS，可用 val 代表本字段当前值）
     *
     * 例：含税金额 → 自动算不含税（税率 6%）
     *   $form->text('project_money', '含税金额')
     *       ->link('buhan_money', 'val === "" || val === null ? "" : round(Number(val) / 1.06, 2)');
     *
     * 例：两个数字相加
     *   $form->number('a', 'A')->link('total', 'Number(val || 0) + Number(form.b || 0)');
     *   // 表达式内可用 form 访问整个表单对象
     */
    public function link(string $target, string $expr): self
    {
        $this->config['links'] = $this->config['links'] ?? [];
        $this->config['links'][] = ['target' => $target, 'expr' => $expr];
        return $this;
    }

    /**
     * 自定义配置（兜底）
     */
    public function config(array $extra): self
    {
        $this->config = array_merge($this->config, $extra);
        return $this;
    }

    public function toArray(): array
    {
        return $this->config;
    }
}
