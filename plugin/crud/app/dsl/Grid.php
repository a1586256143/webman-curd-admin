<?php
namespace plugin\crud\app\dsl;

use Illuminate\Database\Eloquent\Builder;
use support\Model;

/**
 * Grid 链式构建器（对标 laravel-admin 的 model-grid）
 *
 * 用法：
 *   $grid = new Grid(new \app\model\APackage());
 *   $grid->column('id', 'ID')->width(80);
 *   $grid->column('status', '状态')->map([1 => '开启', 0 => '关闭'])->label([1 => 'success', 0 => 'danger']);
 *   $grid->filter(function ($filter) { $filter->like('title', '标题'); });
 *   $grid->form(function ($form) { $form->text('title', '标题')->required(); });
 *
 * 输出：->columns() / ->search() / ->formFields() / ->options() 供 BaseCrudController::config() 使用
 */
class Grid extends BaseDsl
{
    protected string $title = '';
    protected ?Model $model = null;
    protected ?Builder $modelQuery = null;
    protected array $columns = [];
    protected array $search = [];
    /** @var Filter|null filter() 构建的 Filter 实例（保留闭包 $this 绑定，查询时注入 input 执行 where） */
    protected $searchFilter = null;
    protected array $formFields = [];
    protected array $addFields = [];
    protected array $editFields = [];
    protected array $detail = [];
    protected array $options = [];
    protected array $actions = [];
    protected string $dialogWidth = '600px';
    protected array $rules = [];
    protected array $formTabs = [];
    protected array $formSteps = [];
    protected $rowStyleCallback = null;
    protected $rowClassCallback = null;
    protected string $customCss = '';
    protected string $customJs = '';
    /** @var callable|null 列表头部汇总回调（summary()）：随列表接口返回，可读当前筛选后的 $query */
    protected $summaryCb = null;

    /**
     * 表单布局元数据（来自 Form::columns()/width()/height()）
     *  - columns：每行默认列数（默认 1）
     *  - width：弹窗宽度（默认 ''，回退到 setDialogWidth() 的 600px）
     *  - height：弹窗高度（默认 '' = 自适应）
     */
    protected array $formLayout = ['columns' => 1, 'width' => '', 'height' => ''];

    /**
     * 表单提交二次确认（来自 Form::confirm()）：['create' => msg, 'edit' => msg]
     */
    protected array $formConfirm = [];

    public function __construct(Model $model, ?string $title = null)
    {
        $this->model = $model;
        $this->title = $title ?? '';
    }

    /**
     * 获取 Grid 使用的 Eloquent 查询 Builder。
     *
     * with/orderBy 等链式调用会持续作用于同一个 Builder，调用结果无需重新赋值。
     */
    public function getModel(): Builder
    {
        if ($this->modelQuery === null) {
            $this->modelQuery = $this->model->newQuery();
        }
        return $this->modelQuery;
    }

    /**
     * 获取 Grid 原始模型实例，供框架解析模型类。
     */
    public function getModelInstance(): Model
    {
        return $this->model;
    }

    /**
     * 添加列
     */
    public function column(string $prop, ?string $label = null): Column
    {
        $column = new Column($prop, $label);
        $column->setGrid($this);
        $this->columns[] = $column;
        return $column;
    }

    /**
     * 添加自定义操作按钮（类方式，推荐）：每个 action 一个类，文件间解耦
     *
     *   $grid->addAction(new PaymentAuditAction());       // plugin/crud/actions/PaymentAuditAction.php
     *   $grid->addAction(new PaymentRejectAction());
     *
     * 类写法（plugin/crud/actions/ 下，继承 plugin\crud\app\dsl\Action，在 init() 里配置）：
     *   class PaymentAuditAction extends Action
     *   {
     *       protected function init(): void
     *       {
     *           $this->name('audit')->label('审核通过')
     *               ->icon('Check')->type('success')
     *               ->confirm('确定审核通过该付款申请吗？')
     *               ->handler('audit')
     *               ->show(fn($row) => (int)($row->check_status ?? 0) === 0);
     *       }
     *   }
     */
    public function addAction(Action $action): self
    {
        $this->actions[] = $action;
        return $this;
    }

    /**
     * 添加自定义操作按钮（链式写法，简单场景用）
     *
     * 用法：
     *   $grid->action('audit', '审核通过')      // 名称 + 按钮标题
     *       ->icon('Check')                    // 按钮图标（Element Plus 图标名）
     *       ->type('success')                  // 按钮类型
     *       ->confirm('确定审核通过吗？')        // ① 二次确认
     *       ->handler('audit');                //    确认后调用本控制器 actionAudit()
     *
     *   $grid->action('reject', '审核拒绝')
     *       ->icon('Close')
     *       ->type('danger')
     *       ->form(fn($f) => $f->textarea('refuse_reason', '拒绝理由')->required())  // ② 弹窗表单
     *       ->handler('reject');
     *
     *   $grid->action('view', '查看合同')
     *       ->icon('Document')
     *       ->jump('/contracts?project_name={project_name}', '_blank');  // ③ 跳转页面
     *
     * 详见 plugin/crud/dsl/Action.php
     */
    public function action(string $name, string $label): Action
    {
        $action = new Action($name, $label);
        $this->actions[] = $action;
        return $action;
    }

    /**
     * 设置搜索
     */
    public function filter(callable $callback): self
    {
        $filter = new Filter();
        $callback($filter);
        $this->search = $filter->toArray();
        // 保留 Filter 实例：where 闭包内的 $this 绑定它，查询时注入 input 再执行
        $this->searchFilter = $filter;
        return $this;
    }

    /**
     * 设置表单（共用：add/edit 都会用到）
     */
    public function form(callable $callback): self
    {
        $form = new Form();
        $callback($form);
        $this->formFields = $form->toArray();
        $this->formLayout = $form->layout();
        $this->formConfirm = $form->confirmConfig();
        // 表单字段里的 rules 字符串同步到验证规则
        foreach ($this->formFields as $field) {
            if (!empty($field['required'])) {
                $this->rules[$field['prop']] = 'required';
            }
            if (!empty($field['ruleStr'])) {
                $this->rules[$field['prop']] = $field['ruleStr'];
            }
        }
        return $this;
    }

    /**
     * 仅在「新增」弹窗里追加的字段
     * 用法：
     *   $grid->addForm(function ($form) {
     *       $form->select('assignee', '负责人')
     *           ->options($users)
     *           ->addDefault(fn() => currentUserId());
     *   });
     */
    public function addForm(callable $callback): self
    {
        $form = new Form();
        $callback($form);
        $this->addFields = $form->toArray();
        return $this;
    }

    /**
     * 仅在「编辑」弹窗里追加的字段
     * 编辑时可对字段设置 ->editDefault() / ->editDisabled() / ->editHidden()
     */
    public function editForm(callable $callback): self
    {
        $form = new Form();
        $callback($form);
        $this->editFields = $form->toArray();
        return $this;
    }

    /**
     * 详情页字段（可选，默认取全部列）
     * $groups: [['group' => '分组名', 'fields' => [['prop','label'], ...]], ...]
     */
    public function setDetail(array $groups): self
    {
        $this->detail = $groups;
        return $this;
    }

    /**
     * 功能开关
     */
    public function setOptions(array $options): self
    {
        $this->options = $options;
        return $this;
    }

    public function setDialogWidth(string $width): self
    {
        $this->dialogWidth = $width;
        return $this;
    }

    /**
     * 设置新增/编辑表单的选项卡分组。
     * 每项格式：['label' => '基本信息', 'fields' => ['title', 'number']]
     */
    public function setFormTabs(array $tabs): self
    {
        $this->formTabs = $tabs;
        return $this;
    }

    /**
     * 设置新增/编辑表单的步骤分组。
     * 每项可带 description；前端最后一步统一提交一次。
     */
    public function setFormSteps(array $steps): self
    {
        $this->formSteps = $steps;
        return $this;
    }

    /**
     * 设置行级样式回调（返回 CSS 字符串，如 'color:#fff;background:#f00'）。
     * 回调签名: function ($row) { return 'color:#fff'; }
     */
    public function setRowStyle(callable $callback): self
    {
        $this->rowStyleCallback = $callback;
        return $this;
    }

    /**
     * 设置行级类名回调（返回类名字符串或数组）。
     * 回调签名: function ($row) { return 'my-class'; }
     */
    public function setRowClass(callable $callback): self
    {
        $this->rowClassCallback = $callback;
        return $this;
    }

    /**
     * 自定义验证规则（优先于表单自动推断）
     */
    public function setRules(array $rules): self
    {
        $this->rules = $rules;
        return $this;
    }

    /**
     * 禁用新增
     */
    public function disableCreate(): self
    {
        $this->options['add'] = false;
        return $this;
    }

    /**
     * 禁用编辑
     */
    public function disableEdit(): self
    {
        $this->options['edit'] = false;
        return $this;
    }

    /**
     * 禁用删除
     */
    public function disableDelete(): self
    {
        $this->options['delete'] = false;
        return $this;
    }

    /**
     * 禁用查看
     */
    public function disableView(): self
    {
        $this->options['view'] = false;
        return $this;
    }

    /**
     * 禁用导出
     */
    public function disableExport(): self
    {
        $this->options['export'] = false;
        return $this;
    }

    /**
     * 禁用批量删除
     */
    public function disableBatchDelete(): self
    {
        $this->options['batchDelete'] = false;
        return $this;
    }

    // ===== 输出 =====

    public function title(): string
    {
        return $this->title;
    }

    public function columns(): array
    {
        return array_map(fn(Column $c) => $c->toArray(), $this->columns);
    }

    /**
     * 收集 display 处理器：[prop => callable]（PHP 服务端用，不进 JSON）
     */
    public function displayHandlers(): array
    {
        $handlers = [];
        foreach ($this->columns as $column) {
            $handler = $column->getDisplayHandler();
            if ($handler) {
                $handlers[$column->toArray()['prop']] = $handler;
            }
        }
        return $handlers;
    }

    /**
     * 收集 href 动态链接处理器：[prop => callable]（PHP 服务端用，不进 JSON）
     */
    public function hrefHandlers(): array
    {
        $handlers = [];
        foreach ($this->columns as $column) {
            $handler = $column->getHrefHandler();
            if ($handler) {
                $handlers[$column->toArray()['prop']] = $handler;
            }
        }
        return $handlers;
    }

    public function search(): array
    {
        return $this->search;
    }

    /**
     * filter() 构建的 Filter 实例（含 where 闭包，服务端专用）
     */
    public function searchFilter()
    {
        return $this->searchFilter;
    }

    public function formFields(): array
    {
        return $this->formFields;
    }

    public function addFields(): array
    {
        return $this->addFields;
    }

    public function editFields(): array
    {
        return $this->editFields;
    }

    public function getFormTabs(): array
    {
        return $this->formTabs;
    }

    public function getFormSteps(): array
    {
        return $this->formSteps;
    }

    /**
     * 获取行级样式回调
     */
    public function getRowStyleCallback(): ?callable
    {
        return $this->rowStyleCallback;
    }

    /**
     * 获取行级类名回调
     */
    public function getRowClassCallback(): ?callable
    {
        return $this->rowClassCallback;
    }

    /**
     * 列表头部汇总（对标 laravel-admin 的 grid header）。
     * 回调签名：function (\Illuminate\Database\Eloquent\Builder $query) { ... return $data; }
     *   - 返回 array  → 前端渲染为汇总卡片（data 键值对，前端按 key 展示）
     *   - 返回 string → 前端 v-html 直接渲染（服务端拼好的 HTML，如各平台余额 + 金额汇总条）
     * $query 为「已应用当前筛选」的列表查询，回调内请 clone 后再聚合，避免影响列表分页。
     * 结果随列表接口（index）一起返回 data.summary / data.summaryHtml，筛选变化自动刷新。
     */
    public function summary(callable $callback): self
    {
        $this->summaryCb = $callback;
        return $this;
    }

    /**
     * 获取列表头部汇总回调
     */
    public function summaryCallback(): ?callable
    {
        return $this->summaryCb;
    }

    /**
     * 设置自定义 CSS 代码（将直接注入到页面 <style> 标签中）。
     */
    public function setCustomCss(string $css): self
    {
        $this->customCss = $css;
        return $this;
    }

    /**
     * 获取自定义 CSS 代码
     */
    public function getCustomCss(): string
    {
        return $this->customCss;
    }

    /**
     * 设置自定义 JS 代码（将直接注入到页面 <script> 标签中）。
     */
    public function setCustomJs(string $js): self
    {
        $this->customJs = $js;
        return $this;
    }

    /**
     * 获取自定义 JS 代码
     */
    public function getCustomJs(): string
    {
        return $this->customJs;
    }

    public function detail(): array
    {
        return $this->detail;
    }

    /**
     * 自定义操作配置输出（handler 在此解析为通用操作路由）
     * @param string $modelName 模型短名（如 Payment），用于拼 /api/crud/model/{model}/action/{name}
     */
    public function actions(string $modelName = ''): array
    {
        $result = [];
        foreach ($this->actions as $action) {
            if ($modelName !== '') {
                $action->resolveApi($modelName);
            }
            $config = $action->toArray();
            if ($config !== null) {
                $result[] = $config;
            }
        }
        return $result;
    }

    /**
     * 收集带行级闭包（show/params）的 action 实例：[name => Action]
     * 仅服务端用（列表接口按行求值后附加 _actionShow / _actionParams），不进 JSON
     */
    public function actionRowHandlers(): array
    {
        $handlers = [];
        foreach ($this->actions as $action) {
            if ($action->getShow() !== null || $action->getParamsCallback() !== null
                || !empty($action->getRoles()) || $action->getPermissionObject() !== null) {
                $handlers[$action->getName()] = $action;
            }
        }
        return $handlers;
    }

    /** 按名称获取 Action，供后端执行时做权限校验 */
    public function findAction(string $name): ?Action
    {
        foreach ($this->actions as $action) {
            if ($action->getName() === $name) {
                return $action;
            }
        }
        return null;
    }

    public function rules(): array
    {
        return $this->rules;
    }
    public function options(): array
    {
        return array_merge([
            'add' => true,
            'edit' => true,
            'delete' => true,
            'view' => true,
            'export' => true,
            'batchDelete' => true,
        ], $this->options);
    }

    public function dialogWidth(): string
    {
        return $this->dialogWidth;
    }

    /**
     * 表单布局元数据（columns / width / height）
     */
    public function formLayout(): array
    {
        return $this->formLayout;
    }

    /**
     * 表单提交二次确认（['create' => msg, 'edit' => msg]）
     */
    public function formConfirm(): array
    {
        return $this->formConfirm;
    }
}
