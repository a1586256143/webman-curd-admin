<?php
namespace plugin\crud\app\dsl;

/**
 * 表单字段配置（链式）
 *
 * 用法：
 *   $grid->form(function ($form) {
 *       $form->text('title', '标题')->required();
 *       $form->number('price', '定价(分)')->min(0);
 *       $form->select('status', '状态')->options([1 => '开启', 0 => '关闭'])->default(1);
 *       $form->remote('qid', '渠道', 'a_channels', 'id', 'title');
 *       $form->switch('test_mode', '测试模式');
 *       $form->textarea('desc', '描述')->rows(4);
 *   });
 */
class Form extends BaseDsl
{
    protected array $fields = [];

    /**
     * 提交二次确认配置：[mode => message]
     *  - create：新增提交前确认
     *  - edit：编辑提交前确认
     */
    protected array $confirms = [];

    /**
     * 表单提交二次确认
     *
     *   $form->confirm('确定创建吗？', 'create');   // 新增提交前弹确认
     *   $form->confirm('确定更新吗？', 'edit');     // 编辑提交前弹确认
     *
     * @param string $message 确认文案
     * @param string $mode    create | edit
     */
    public function confirm(string $message, string $mode): self
    {
        $this->confirms[$mode] = $message;
        return $this;
    }

    /**
     * 提交二次确认配置（['create' => msg, 'edit' => msg]，供 config 输出）
     */
    public function confirmConfig(): array
    {
        return $this->confirms;
    }

    /**
     * 条件字段：根据父字段值动态追加字段，允许同名字段存在多个条件版本。
     */
    public function when(string $parent, $value, callable $callback): self
    {
        $childForm = new self();
        $callback($childForm);
        foreach ($childForm->getFields() as $field) {
            $field->addVisibleWhen([
                'field' => $parent,
                'value' => $value,
            ]);
            $this->fields[] = $field;
        }
        return $this;
    }

    /*
     * 表单布局配置：
     *  - columns：每行默认列数（1 = 单列整行，2 = 一行两列，3 = 一行三列），默认 1
     *  - width：弹窗宽度（默认 ''，走 Grid::setDialogWidth() 或框架默认 600px）
     *  - height：弹窗高度（默认 '' = 自适应，内容超高时弹窗内部滚动）
     */
    protected array $layout = [
        'columns' => 1,
        'width' => '',
        'height' => '',
    ];

    /**
     * 每行默认列数（1/2/3/4...）
     * 未显式设置 ->span() 的字段按 24/columns 自动均分宽度。
     * 需要特殊宽度时用 ->span(16)；需要独占一行时用 ->full() 或 ->row()->span(24)。
     *
     *   $form->columns(2);            // 一行两列
     *   $form->text('title', '标题')->required();
     *   $form->select('status', '状态')->options([1 => '开启', 0 => '关闭']);
     *   $form->textarea('desc', '描述')->full();   // 独占一行
     */
    public function columns(int $columns): self
    {
        $this->layout['columns'] = max(1, $columns);
        return $this;
    }

    /**
     * 弹窗宽度（默认 '600px'，即框架默认值；不设置则保持框架默认）
     * 例：->width('900px') / ->width('60%')
     */
    public function width(string $width): self
    {
        $this->layout['width'] = $width;
        return $this;
    }

    /**
     * 弹窗高度（默认自适应；设置后弹窗固定高度，表单区内部滚动）
     * 例：->height('70vh') / ->height('600px')
     */
    public function height(string $height): self
    {
        $this->layout['height'] = $height;
        return $this;
    }

    public function layout(): array
    {
        return $this->layout;
    }

    public function text(string $prop, string $label, array $options = []): Field
    {
        return $this->add('input', $prop, $label, $options);
    }

    public function textarea(string $prop, string $label, array $options = []): Field
    {
        return $this->add('textarea', $prop, $label, $options);
    }

    public function number(string $prop, string $label, array $options = []): Field
    {
        return $this->add('number', $prop, $label, $options);
    }

    public function password(string $prop, string $label, array $options = []): Field
    {
        return $this->add('input', $prop, $label, array_merge(['showPassword' => true], $options));
    }

    /**
     * 下拉选择
     * 选项通过 ->options() 链式设置：
     *   $form->select('status', '状态')->options([1 => '开启', 0 => '关闭']);
     *   $form->select('qid', '渠道')->options($map, ['filterable' => true]);
     */
    public function select(string $prop, string $label): Field
    {
        return $this->add('select', $prop, $label);
    }

    /**
     * 多选下拉，兼容 Laravel Admin 的 multipleSelect 写法。
     */
    public function multipleSelect(string $prop, string $label): Field
    {
        return $this->add('select', $prop, $label, [
            'multiple' => true,
            'filterable' => true,
        ]);
    }

    public function radio(string $prop, string $label, ?array $map = null, array $options = []): Field
    {
        $field = $this->add('radio', $prop, $label, $options);
        if ($map !== null) {
            $field->options($map);
        }
        return $field;
    }

    public function switch(string $prop, string $label, array $options = []): Field
    {
        return $this->add('switch', $prop, $label, $options);
    }

    public function date(string $prop, string $label, array $options = []): Field
    {
        return $this->add('date', $prop, $label, $options);
    }

    public function datetime(string $prop, string $label, array $options = []): Field
    {
        return $this->add('datetime', $prop, $label, $options);
    }

    /**
     * 单图上传（存储相对路径，如 uploads/images/xxx.png）
     * 上传接口默认 /api/upload/image（校验图片类型，存 images 目录）
     */
    public function image(string $prop, string $label, array $options = []): Field
    {
        return $this->add('image', $prop, $label, array_merge([
            'action' => '/api/upload/image',
            'staticHost' => config('admin.image_server', ''),
        ], $options));
    }

    /**
     * 单文件上传（存储相对路径，如 uploads/files/xxx.png）
     * 上传接口默认 /api/upload（通用文件，存 files 目录）
     */
    public function file(string $prop, string $label, array $options = []): Field
    {
        return $this->add('file', $prop, $label, array_merge([
            'action' => '/api/upload',
            'staticHost' => config('admin.image_server', ''),
        ], $options));
    }

    /**
     * 多文件上传（存储 JSON 数组字符串，如 ["uploads/files/a.png","uploads/files/b.png"]）
     * 上传接口默认 /api/upload（通用文件，存 files 目录）
     */
    public function multipleFile(string $prop, string $label, array $options = []): Field
    {
        return $this->add('multipleFile', $prop, $label, array_merge([
            'action' => '/api/upload',
            'staticHost' => config('admin.image_server', ''),
            'multiple' => true,
        ], $options));
    }

    public function hidden(string $prop, string $label = '', array $options = []): Field
    {
        return $this->add('input', $prop, $label, array_merge(['hidden' => true, 'disabled' => true], $options));
    }

    /**
     * 远程下拉字段（数据源 /api/options/{table}）
     */
    public function remote(string $prop, string $label, string $table, string $valueKey = 'id', string $labelKey = 'name', array $options = []): Field
    {
        return $this->add('select', $prop, $label, array_merge([
            'remote' => true,
            'remoteTable' => $table,
            'valueKey' => $valueKey,
            'labelKey' => $labelKey,
            'filterable' => true,
        ], $options));
    }

    /**
     * 标签字段（多选 + 可创建新条目）
     * 提交时自动 join(',')；编辑回显时自动 split(',')
     * 前端渲染 el-select: multiple filterable allow-create default-first-option
     * 存储到数据库：varchar/text，逗号分隔，如 "xxx,xxxx,xxx"
     */
    public function tags(string $prop, string $label, array $options = []): Field
    {
        return $this->add('tags', $prop, $label, array_merge([
            'multiple' => true,
            'filterable' => true,
            'allowCreate' => true,
        ], $options));
    }

    /**
     * 自定义字段（兜底）
     */
    public function getFields(): array
    {
        return $this->fields;
    }

    public function add(string $type, string $prop, string $label, array $options = []): Field
    {
        $field = new Field(array_merge([
            'prop' => $prop,
            'label' => $label,
            'type' => $type,
        ], $options));
        $field->setForm($this);
        $this->fields[] = $field;
        return $field;
    }

    /**
     * 输出前端配置
     * 未显式设置 span 的字段按 columns 自动均分（24/columns），保证多列布局开箱即用。
     */
    public function toArray(): array
    {
        $defaultSpan = intdiv(24, max(1, $this->layout['columns']));
        return array_map(function (Field $f) use ($defaultSpan) {
            $arr = $f->toArray();
            if (empty($arr['span'])) {
                $arr['span'] = $defaultSpan;
            }
            return $arr;
        }, $this->fields);
    }
}
