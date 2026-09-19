<?php
namespace plugin\curd\app\dsl;

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
     * 当前构建场景：'create'（新增弹窗）| 'edit'（编辑弹窗）| null（未指定，如 Action 行级弹窗表单）
     * 由 Grid::form() 场景感知构建时传入，供 isCreate()/isEdit() 判断。
     */
    protected ?string $scene = null;

    /**
     * 回调中是否调用了无参形式的 isCreate()/isEdit()（场景判断）
     * Grid::form() 据此决定是否需要按另一个场景再执行一次回调（只有用到才多跑一遍，保持原开销）
     */
    protected bool $sceneGuardUsed = false;

    public function __construct(?string $scene = null)
    {
        $this->scene = $scene;
    }

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

    /**
     * 场景字段白名单（isCreate('a','b') / isEdit('a','b') 传参形式，兼容保留）：
     *  - 'create'：创建场景展示并提交的字段清单（null = 未限制，全部展示）
     *  - 'edit'  ：编辑场景展示并提交的字段清单
     * 支持回调（服务端配置输出时求值一次，如 fn($ctx) => ['name','remark']）
     *
     * 无参形式 isCreate()/isEdit() 是场景判断（用 if 条件注册字段），走 modeOnly 机制，两者互不影响。
     */
    protected array $sceneFields = [
        'create' => null,
        'edit' => null,
    ];

    /**
     * 当前是否「新增」场景（可直接写在 if 里，按场景条件注册字段）
     *
     *   if ($form->isCreate()) {
     *       $form->password('password', '密码')->required();   // 只在新增弹窗出现
     *   }
     *   if ($form->isEdit()) {
     *       $form->text('amount', '金额')->required();          // 只在编辑弹窗出现
     *   }
     *
     * 场景块内声明的字段会被**自动**标记为「仅该场景」（等价于在字段上挂 ->addOnly() / ->editOnly()）：
     *  - 前端：另一场景的弹窗不渲染、不回显、不提交该字段
     *  - 后端：add()/update() 按场景剔除该字段，校验规则同步收敛（抓包强塞也写不进去）
     * 场景块外声明的字段两个场景都有，不需要任何白名单。
     *
     * 未指定场景时（如 Action::form() 行级弹窗表单只有一份）两个判断都返回 true，两段都会注册。
     *
     * 兼容旧写法：传字段名时仍按「场景白名单」处理（$form->isCreate('name', 'remark') = 新增只留这些字段），
     * 白名单语义下"列多了反而漏字段"，通常不如上面的 if 写法直观。
     *
     * @param string|array ...$fields 旧写法：该场景仅保留的字段清单
     * @return bool|static
     */
    public function isCreate(...$fields)
    {
        if (!empty($fields)) {
            return $this->setSceneFields('create', $fields);
        }
        $this->sceneGuardUsed = true;
        return $this->scene === null || $this->scene === 'create';
    }

    /**
     * 当前是否「编辑」场景（用法同 isCreate()）
     *
     *   if ($form->isEdit()) { $form->text('amount', '金额')->required(); }
     *
     * 兼容旧写法：$form->isEdit('name', 'audit_by') 仍按编辑场景白名单处理。
     *
     * @param string|array ...$fields 旧写法：该场景仅保留的字段清单
     * @return bool|static
     */
    public function isEdit(...$fields)
    {
        if (!empty($fields)) {
            return $this->setSceneFields('edit', $fields);
        }
        $this->sceneGuardUsed = true;
        return $this->scene === null || $this->scene === 'edit';
    }

    /**
     * 当前构建场景：'create' | 'edit' | null
     */
    public function scene(): ?string
    {
        return $this->scene;
    }

    /**
     * 回调是否用过场景判断（isCreate()/isEdit() 无参形式）
     */
    public function sceneGuardUsed(): bool
    {
        return $this->sceneGuardUsed;
    }

    /**
     * 合并另一个场景的执行结果（Grid::form() 场景感知构建用）
     *
     * 以本次执行为「当前场景」：
     *  - 只在本场景出现的字段 → 自动标记「仅本场景」（modeOnly）
     *  - 只有另一场景才有的字段 → 追加进字段列表并标记为「仅另一场景」
     *  - 两个场景都有的字段（写在场景块外）→ 保持原样，两场景共用
     */
    public function mergeScene(self $other): void
    {
        if ($this->scene === null || $other->scene === null || $this->scene === $other->scene) {
            return;
        }
        $mine = $this->scene === 'create' ? 'add' : 'edit';
        $theirs = $other->scene === 'create' ? 'add' : 'edit';

        // 本场景独有 → 仅本场景
        $otherProps = $other->fieldProps();
        foreach ($this->fields as $field) {
            if (!in_array($field->prop(), $otherProps, true)) {
                $field->config(['modeOnly' => $mine]);
            }
        }
        // 另一场景独有 → 追加并标记
        $myProps = $this->fieldProps();
        foreach ($other->getFields() as $field) {
            if (in_array($field->prop(), $myProps, true)) {
                continue;
            }
            $field->config(['modeOnly' => $theirs]);
            $field->setForm($this);
            $this->fields[] = $field;
        }
    }

    /**
     * 当前已注册字段的 prop 列表
     */
    protected function fieldProps(): array
    {
        $props = [];
        foreach ($this->fields as $field) {
            $props[] = $field->prop();
        }
        return $props;
    }

    protected function setSceneFields(string $scene, array $fields): self
    {
        // 展开一层嵌套数组：('a', ['b','c']) → ['a','b','c']
        $flat = [];
        foreach ($fields as $f) {
            if ($f instanceof \Closure) {
                $this->sceneFields[$scene] = $f;
                return $this;
            }
            if (is_array($f)) {
                $flat = array_merge($flat, $f);
            } elseif (is_string($f) && $f !== '') {
                $flat[] = $f;
            }
        }
        $this->sceneFields[$scene] = array_values(array_unique($flat));
        return $this;
    }

    /**
     * 场景白名单求值（供 toArray/后端过滤用）：['create' => string[]|null, 'edit' => string[]|null]
     * 回调在求值时执行一次，返回字段数组
     */
    public function sceneShowConfig(): array
    {
        $out = [];
        foreach (['create', 'edit'] as $scene) {
            $v = $this->sceneFields[$scene];
            if ($v instanceof \Closure) {
                $v = array_values(array_unique((array)$v(['mode' => $scene])));
            }
            // 空清单视为未限制（isCreate() 不传字段时保持默认行为）
            $out[$scene] = (is_array($v) && empty($v)) ? null : $v;
        }
        return $out;
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
            'staticHost' => config('curd-admin.image_server', ''),
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
            'staticHost' => config('curd-admin.image_server', ''),
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
            'staticHost' => config('curd-admin.image_server', ''),
            'multiple' => true,
        ], $options));
    }

    public function hidden(string $prop, string $label = '', array $options = []): Field
    {
        return $this->add('input', $prop, $label, array_merge(['hidden' => true, 'disabled' => true], $options));
    }

    /**
     * Excel 导入字段（action form 弹窗用）：本地选择文件后**不走独立上传**，
     * 确认提交时文件二进制随表单 multipart 直传，服务端 Action::handle() 内直接取解析好的数据
     *
     *   $form->excel('import_file', '选择文件');
     *   $form->excel('import_file', '选择文件', ['maxRows' => 10000]);
     *
     * options：
     *  - maxRows  最大解析行数（不含表头，默认 5000，超出抛异常）
     *  - sheet    解析第几个工作表（默认 1）
     *  - required 是否必选（默认 true，前端 el-upload 校验）
     *
     * handle 中取数据：
     *   $rows = $this->excelRows('import_file');   // [[ '手机号' => '138...', '金额' => '10' ], ...]（首行作表头）
     *   $info = $this->excelInfo('import_file');   // ['count' => n, 'file_name' => 'xx.xlsx', ...]
     *
     * 支持 .xlsx / .csv；日期单元格读出为 Excel 序列数字，需业务自行换算。
     */
    public function excel(string $prop, string $label, array $options = []): Field
    {
        return $this->add('excel', $prop, $label, array_merge([
            'required' => true,
            'maxRows' => 5000,
            'sheet' => 1,
            'accept' => '.xlsx,.csv',
        ], $options));
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
