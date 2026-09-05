<?php
namespace plugin\crud\app\dsl;

use support\Request;

/**
 * 自定义操作按钮（行级 Actions）链式构建器（兼容 PHP 7）
 *
 * 两种添加方式：
 *
 * 1. 类方式（推荐，文件间解耦）：每个 action 一个类，继承本类在 init() 里配置
 *      app/controller/api/actions/PaymentAuditAction.php
 *
 *      class PaymentAuditAction extends Action
 *      {
 *          protected function init()
 *          {
 *              $this->name('audit')
 *                  ->label('审核通过')
 *                  ->icon('Check')->type('success')
 *                  ->confirm('确定审核通过该付款申请吗？')
 *                  ->handler('audit')
 *                  ->show(function () {
 *                      return (int)$this->row('check_status', 0) === 0;   // 当前行数据
 *                  });
 *          }
 *      }
 *
 *      // grid() 里一行接入：
 *      $grid->addAction(new PaymentAuditAction());
 *
 * 2. 链式方式（简单场景）：
 *      $grid->action('audit', '审核通过')->icon('Check')->confirm('...')->handler('audit');
 *
 * 四种操作类型（actionType）：
 *   - confirm : 二次确认后调用接口（->confirm('提示文案') + ->api() 或 ->handler()）
 *   - form    : 弹窗表单输入后调用接口（->form(fn($form){...}) + ->api() 或 ->handler()）
 *   - modal   : 打开自定义内容弹窗（前端通过 modal-{name} 插槽渲染内容）
 *   - jump    : 跳转页面（->jump('/contracts', '_blank')，URL 支持 {字段名} 占位符）
 *
 * 按钮过多时：默认全部收进前端「更多」下拉，重要的可用 ->inline() 直接显示在行内。
 */
class Action extends BaseDsl
{
    /** @var string 操作标识（handler 路由、行级显隐都用它） */
    protected $name = '';
    /** @var string 按钮标题 */
    protected $label = '';
    /** @var string 按钮图标（Element Plus 图标名） */
    protected $icon = '';
    /** @var string el-button 类型：primary/success/warning/danger/info */
    protected $type = 'primary';
    /** @var string 操作类型：confirm | form | modal | jump */
    protected $actionType = 'confirm';
    /** @var string 自定义弹窗的静态 HTML（仅适用于可信配置） */
    protected $modalHtml = '';
    /** @var string 二次确认文案 */
    protected $confirm = '';
    /** @var string ElMessageBox 类型：warning/info/error/success */
    protected $confirmType = 'warning';
    /** @var string 请求地址 */
    protected $api = '';
    /** @var string 请求方法 */
    protected $method = 'POST';
    /** @var string 控制器处理方法名（自动拼 /api/crud/model/{model}/action/{name}） */
    protected $handler = '';
    /** @var array 静态附加参数（随每次请求携带） */
    protected $params = [];
    /** @var \Closure|null 按行求值的动态参数（仅服务端用） */
    protected $paramsCallback = null;
    /** @var string 随请求携带的行字段名（默认 id） */
    protected $field = 'id';
    /** @var \Closure|null 条件显示闭包（仅服务端用） */
    protected $show = null;
    /** @var string 成功提示文案 */
    protected $successMsg = '操作成功';
    /** @var string 弹窗标题（form 类型） */
    protected $dialogTitle = '';
    /** @var string 弹窗宽度（form 类型） */
    protected $dialogWidth = '';
    /** @var array 弹窗表单字段（form 类型，Form DSL 序列化结果） */
    protected $formFields = [];
    /** @var string 跳转地址（jump 类型） */
    protected $jumpUrl = '';
    /** @var string 跳转方式：_self 站内路由 | _blank 新窗口 */
    protected $jumpTarget = '_self';
    /** @var bool 是否直接显示在行内（默认 false 收进「更多」下拉） */
    protected $inline = false;
    /** @var bool 是否为批量操作（前端渲染在批量工具栏，随请求携带 ids 数组） */
    protected $batch = false;
    /** @var bool 是否为页面级（工具栏）全局操作：不依赖行数据，点击携带当前搜索条件执行 */
    protected $global = false;
    /** @var string[] 允许显示/执行的角色 slug */
    protected $roles = [];
    /** @var string|null 权限资源对象，如 crud.Payments */
    protected $permissionObject = null;
    /** @var string|null 权限动作，如 audit */
    protected $permissionAction = null;
    /** @var object|null 当前渲染行数据（列表接口按行求值时注入） */
    protected $rowData = null;
    /** @var object|null 当前行对应的模型实例（框架调用 handle() 前自动注入，未命中为 null） */
    protected $model = null;

    /**
     * @param string $name  操作标识（handler 路由、行级显隐都用它）
     * @param string $label 按钮标题
     */
    public function __construct($name = '', $label = '')
    {
        if ($name !== '') {
            $this->name = $name;
        }
        if ($label !== '') {
            $this->label = $label;
        }
        $this->init();
    }

    /**
     * 子类配置入口（推荐写法：每个 action 一个类，文件间解耦）
     * 在 init() 里用 $this->name()/->label()/->icon() 等链式配置，
     * 闭包里可通过 $this->row('字段') 拿到当前渲染行的数据。
     */
    protected function init()
    {
    }

    /**
     * 操作执行入口（推荐：把业务逻辑写在当前 Action 类，而不是控制器 actionXxx()）
     * 子类覆盖本方法处理业务并返回 json()，例如：
     *   public function handle(Request $request) {
     *       $row = $this->model();          // 框架自动注入的当前行模型实例
     *       if (!$row) return $this->error('记录不存在', 404);
     *       ...
     *   }
     * 未覆盖时由 CrudActionsTrait 回退调用控制器上的 action{Name}(Request $request)。
     */
    public function handle(Request $request)
    {
        throw new \RuntimeException('Action ' . $this->name . ' 未实现 handle(Request $request)');
    }

    /**
     * 注入当前行模型实例（框架分发 action 时自动调用，无需手动）
     */
    public function setModel($model): self
    {
        $this->model = $model;
        return $this;
    }

    /**
     * 获取当前行模型实例（handle() 中使用）
     * 由框架在调用 handle() 前按请求 id 自动 find 注入；未传 id / 记录不存在时返回 null。
     */
    public function model()
    {
        return $this->model;
    }

    /**
     * 操作标识（也可在构造参数传入）
     */
    public function name($name)
    {
        $this->name = $name;
        return $this;
    }

    /**
     * 按钮标题（也可在构造参数传入）
     */
    public function label($label)
    {
        $this->label = $label;
        return $this;
    }

    /**
     * 按钮图标（Element Plus 图标名，如 'Check'、'Close'、'Money'、'Document'）
     */
    public function icon($icon)
    {
        $this->icon = $icon;
        return $this;
    }

    /**
     * 按钮类型（el-button type）：primary / success / warning / danger / info
     */
    public function type($type)
    {
        $this->type = $type;
        return $this;
    }

    /**
     * 二次确认（actionType = confirm）
     * @param string $message 确认提示文案
     * @param string $type    ElMessageBox 类型：warning / info / error / success
     */
    public function confirm($message, $type = 'warning')
    {
        $this->actionType = 'confirm';
        $this->confirm = $message;
        $this->confirmType = $type;
        return $this;
    }

    /**
     * 弹窗表单输入（actionType = form），复用 Form DSL 定义弹窗内字段
     *   ->form(function ($form) {
     *       $form->textarea('refuse_reason', '拒绝理由')->required()->rows(4);
     *   })
     */
    public function form(callable $callback)
    {
        $form = new Form();
        $callback($form);
        $this->formFields = $form->toArray();
        $this->actionType = 'form';
        return $this;
    }

    /**
     * 打开自定义内容弹窗。前端使用 modal-{name} 插槽渲染 Vue 组件。
     * @param string $html 可选的可信静态 HTML
     */
    public function modal($html = '')
    {
        $this->actionType = 'modal';
        $this->modalHtml = (string)$html;
        return $this;
    }

    /**
     * 跳转页面（actionType = jump）
     * @param string $url    站内路由（/contracts）或完整 URL（https://...）；
     *                       支持 {字段名} 占位符，如 '/contracts?project_name={project_name}'
     * @param string $target _self 站内路由跳转；_blank 新窗口打开（http 链接自动新窗口）
     */
    public function jump($url, $target = '_self')
    {
        $this->actionType = 'jump';
        $this->jumpUrl = $url;
        $this->jumpTarget = $target;
        return $this;
    }

    /**
     * 自定义请求地址（confirm/form 类型用）
     */
    public function api($url, $method = 'POST')
    {
        $this->api = $url;
        $this->method = strtoupper($method);
        return $this;
    }

    /**
     * 控制器处理方法名：自动指向 /api/crud/model/{model}/action/{name}
     * 控制器里实现 action{Name}(Request $request) 即被调用（如 handler('audit') → actionAudit()）
     */
    public function handler($name)
    {
        $this->handler = $name;
        return $this;
    }

    /**
     * 附加请求参数：
     *   - 数组：静态参数，随每次请求携带
     *   - 闭包：function ($row) { return [...]; }，每行求值（服务端执行，不进 JSON）
     */
    public function params($params)
    {
        if ($params instanceof \Closure) {
            $this->paramsCallback = $params;
        } else {
            $this->params = (array)$params;
        }
        return $this;
    }

    /**
     * 随请求携带的行字段名（默认 id）：payload[field] = row[field]
     */
    public function field($field)
    {
        $this->field = $field;
        return $this;
    }

    /**
     * 条件显示（服务端按行求值，如仅待审核显示）。
     * 闭包两种写法：
     *   ->show(function ($row) { return $row->check_status == 0; });   // 参数拿行
     *   ->show(function () { return $this->row('check_status') == 0; }); // $this->row() 拿行
     */
    public function show(callable $show)
    {
        $this->show = \Closure::fromCallable($show);
        return $this;
    }

    /**
     * 成功提示文案（默认「操作成功」）
     */
    public function successMsg($msg)
    {
        $this->successMsg = $msg;
        return $this;
    }

    /**
     * 弹窗标题（form 类型，默认取按钮标题）
     */
    public function dialogTitle($title)
    {
        $this->dialogTitle = $title;
        return $this;
    }

    /**
     * 弹窗宽度（form 类型，默认 600px）
     */
    public function dialogWidth($width)
    {
        $this->dialogWidth = $width;
        return $this;
    }

    /**
     * 直接显示在行内按钮（默认所有自定义操作收进前端「更多」下拉）
     * 重要的操作（如审核）可调用本方法直接展示，避免都藏在下拉里
     */
    public function inline($inline = true)
    {
        $this->inline = (bool)$inline;
        return $this;
    }

    /**
     * 批量操作：前端渲染在批量工具栏（勾选行后出现），随请求携带 ids 数组。
     * handle(Request $request) 中用 $request->post('ids', []) 获取选中行主键。
     */
    public function batch($batch = true)
    {
        $this->batch = (bool)$batch;
        return $this;
    }

    /**
     * 页面级全局操作：不依赖行数据，渲染在列表工具栏（与新增/导出同级），
     * 点击携带当前搜索条件执行。适合「导出当前数据」「整页同步」等操作。
     * handle(Request $request) 中用 $request->post() 取当前搜索条件。
     */
    public function global($global = true)
    {
        $this->global = (bool)$global;
        return $this;
    }

    /**
     * 按角色控制按钮显示和接口执行。
     * ->roles('admin') 或 ->roles(['admin', 'finance'])，满足任一角色即可。
     */
    public function roles($roles)
    {
        $this->roles = array_values(array_filter((array)$roles, function ($role) {
            return is_string($role) && $role !== '';
        }));
        return $this;
    }

    /**
     * 按 Casbin 权限控制按钮显示和接口执行。
     * 例如 ->permission('crud.Payments', 'audit')。
     */
    public function permission($object, $action = 'execute')
    {
        $this->permissionObject = (string)$object;
        $this->permissionAction = (string)$action;
        return $this;
    }

    public function getRoles()
    {
        return $this->roles;
    }

    public function getPermissionObject()
    {
        return $this->permissionObject;
    }

    public function getPermissionAction()
    {
        return $this->permissionAction;
    }

    // ===== 行数据访问（列表接口按行求值时注入） =====

    /**
     * 当前渲染行的数据（供 show/params 闭包使用）
     *   $this->row()              → 整行数据对象（stdClass）
     *   $this->row('check_status') → 取字段值，不存在返回 $default
     */
    public function row($key = null, $default = null)
    {
        if ($this->rowData === null) {
            return $default;
        }
        if ($key === null) {
            return $this->rowData;
        }
        return isset($this->rowData->{$key}) ? $this->rowData->{$key} : $default;
    }

    /**
     * 注入当前行数据（列表接口调用）
     */
    public function setRow($row)
    {
        $this->rowData = $row;
        return $this;
    }

    /**
     * 按行求值 show 闭包（服务端列表接口调用）
     */
    public function evaluateShow($row)
    {
        if ($this->show === null) {
            return true;
        }
        $this->rowData = $row;
        try {
            return (bool)call_user_func($this->show, $row);
        } catch (\Throwable $e) {
            return true;
        }
    }

    /** 当前登录用户是否满足 Action 权限 */
    public function allowedForCurrentUser()
    {
        if (!empty($this->roles) && !has_any_role($this->roles)) {
            return false;
        }
        if ($this->permissionObject !== null && !can($this->permissionObject, $this->permissionAction ?: 'execute')) {
            return false;
        }
        return true;
    }

    /**
     * 按行求值 params 闭包（服务端列表接口调用）
     */
    public function evaluateParams($row)
    {
        if ($this->paramsCallback === null) {
            return [];
        }
        $this->rowData = $row;
        try {
            $result = call_user_func($this->paramsCallback, $row);
            return is_array($result) ? $result : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ===== 输出 =====

    public function getName()
    {
        return $this->name;
    }

    public function getField()
    {
        return $this->field;
    }

    public function getShow()
    {
        return $this->show;
    }

    public function getParamsCallback()
    {
        return $this->paramsCallback;
    }

    /**
     * handler 未显式给 api 时，解析为通用操作路由。
     * handler 为空时自动使用 name，因此 Action 子类无需再显式 ->handler('xxx')。
     */
    public function resolveApi($modelName)
    {
        if ($this->api === '') {
            $handler = $this->handler !== '' ? $this->handler : $this->name;
            if ($handler !== '') {
                $this->api = '/api/crud/model/' . $modelName . '/action/' . $handler;
            }
        }
    }

    // ===== API 响应统一封装（handle() 内使用，避免各 Action 零散写 json()） =====

    /**
     * 成功响应：{code:200, msg:..., data:...}
     */
    protected function ok($msg = 'success', $data = null)
    {
        $out = ['code' => 200, 'msg' => $msg];
        if ($data !== null) {
            $out['data'] = $data;
        }
        return json($out);
    }

    /**
     * 失败响应：{code:xxx, msg:...}
     * @param int $code 业务码/HTTP 语义码（默认 400）
     */
    protected function error($msg, $code = 400)
    {
        return json(['code' => $code, 'msg' => $msg]);
    }

    /**
     * 输出前端配置（show / params 闭包不进 JSON，由服务端按行求值后附加到行数据）
     */
    public function toArray()
    {
        if (!$this->allowedForCurrentUser()) {
            return null;
        }

        $out = [
            'name'       => $this->name,
            'label'      => $this->label,
            'icon'       => $this->icon,
            'type'       => $this->type,
            'actionType' => $this->actionType,
            'field'      => $this->field,
        ];
        if ($this->inline) {
            $out['inline'] = true;
        }
        if ($this->batch && in_array($this->actionType, ['confirm', 'form'], true)) {
            $out['batch'] = true;
        }
        if ($this->global && in_array($this->actionType, ['confirm', 'form'], true)) {
            $out['global'] = true;
        }

        if ($this->actionType === 'jump') {
            $out['url'] = $this->jumpUrl;
            $out['target'] = $this->jumpTarget;
            return $out;
        }

        // confirm / form：请求地址
        if ($this->api !== '') {
            $out['api'] = $this->api;
        }
        if (strtoupper($this->method) !== 'POST') {
            $out['method'] = strtoupper($this->method);
        }
        if (!empty($this->params)) {
            $out['params'] = $this->params;
        }
        if ($this->confirm !== '') {
            $out['confirm'] = $this->confirm;
            $out['confirmType'] = $this->confirmType;
        }
        if ($this->successMsg !== '操作成功') {
            $out['successMsg'] = $this->successMsg;
        }

        if ($this->actionType === 'form') {
            $out['formFields'] = $this->formFields;
            if ($this->dialogTitle !== '') {
                $out['dialogTitle'] = $this->dialogTitle;
            }
            if ($this->dialogWidth !== '') {
                $out['dialogWidth'] = $this->dialogWidth;
            }
        }

        if ($this->actionType === 'modal') {
            if ($this->modalHtml !== '') {
                $out['html'] = $this->modalHtml;
            }
            if ($this->dialogTitle !== '') {
                $out['dialogTitle'] = $this->dialogTitle;
            }
            if ($this->dialogWidth !== '') {
                $out['dialogWidth'] = $this->dialogWidth;
            }
        }

        return $out;
    }
}
