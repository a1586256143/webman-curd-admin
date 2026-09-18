<?php
namespace plugin\curd\app\controller\base\concerns;

use app\validation\Validator;
use support\Request;

/**
 * CURD 写操作：校验、新增、更新、删除、批量删除、导出（CSV）
 *
 * 从 BaseCurdController 抽取，保持方法签名与行为完全一致。
 * 依赖 CurdConfigTrait（rules/formFields/grid...）与 CurdQueryTrait（query/hasSoftDelete...）。
 */
trait CurdActionsTrait
{
    /**
     * 自定义操作分发器（配合 Grid DSL 的 $grid->action()->handler('audit')）
     * 自动路由：POST /api/curd/model/{model}/action/{name}
     *
     * 执行优先级：
     *   1. 优先调用 Action 实例上的 handle(Request $request)（推荐，业务写在 Action 类内）
     *   2. 否则回退调用控制器上的 action{Name}(Request $request)
     */
    public function action(string $name, Request $request)
    {
        $grid = $this->resolveGrid();
        $action = $grid ? $grid->findAction($name) : null;
        if ($action && !$action->allowedForCurrentUser()) {
            return json(['code' => 403, 'msg' => '没有权限执行该操作']);
        }

        // 优先执行 Action 类内的 handle()，业务无需再在控制器定义 actionXxx()
        if ($action && method_exists($action, 'handle')) {
            // 自动注入当前行模型实例：handle 里可直接用 $this->model()（等价 $model::find($id)）
            $action->setModel($this->resolveActionModel($request, $action));
            // 自动解析 excel 字段（$form->excel()）：multipart 上传的二进制 → 解析好的数据行
            $formFields = method_exists($action, 'getFormFields') ? (array)$action->getFormFields() : [];
            $excelData = $this->resolveActionExcelData($request, $formFields);
            if (method_exists($action, 'setExcelData')) {
                $action->setExcelData($excelData);
            }
            return $action->handle($request);
        }

        $method = 'action' . str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $name)));
        if (!method_exists($this, $method)) {
            return json(['code' => 404, 'msg' => "操作 {$name} 未实现：请在 " . static::class . " 中定义 {$method}(Request \$request) 或在 Action 类中实现 handle(Request \$request)"]);
        }
        // 数组式 / 链式 action：同样自动解析 excel 字段，控制器内 $this->excelRows('prop') 取解析结果
        $formFields = [];
        if ($action && method_exists($action, 'getFormFields')) {
            $formFields = (array)$action->getFormFields();
        } elseif (method_exists($this, 'actions')) {
            foreach ((array)$this->actions() as $a) {
                if (is_array($a) && ($a['name'] ?? '') === $name && !empty($a['formFields'])) {
                    $formFields = (array)$a['formFields'];
                    break;
                }
            }
        }
        if ($formFields) {
            $this->excelActionData = $this->resolveActionExcelData($request, $formFields);
        }
        return $this->$method($request);
    }

    /**
     * 解析 action 对应的当前行模型实例（handle() 注入用）
     * 按请求中的 id（兼容 action->field 自定义主键字段名）find，未命中返回 null
     *
     * @param Request $request
     * @param object  $action Grid DSL Action 实例
     */
    protected function resolveActionModel(Request $request, $action)
    {
        $modelClass = $this->resolvedModel();
        if ($modelClass === '' || !class_exists($modelClass)) {
            return null;
        }
        // 取主键字段名：action->field 默认为 id
        $field = method_exists($action, 'getField') ? $action->getField() : 'id';
        $id = $request->post($field, null) ?? $request->get($field, null);
        if ($id === null || $id === '') {
            return null;
        }
        return $modelClass::on($this->modelConnection())->find($id);
    }

    /**
     * 解析 action form 中的 excel 字段（$form->excel()）：multipart 直传的文件二进制
     * → 按字段 options（maxRows/sheet）解析为首行表头的关联数组行集
     *
     * @param Request $request
     * @param array   $formFields action 的 formFields（DSL Action 或数组式均可）
     * @return array ['prop' => ['rows' => [...], 'count' => n, 'file_name' => ..., 'sheets' => ?int], ...]
     */
    protected function resolveActionExcelData(Request $request, array $formFields): array
    {
        $data = [];
        foreach ($formFields as $field) {
            if (!is_array($field) || ($field['type'] ?? '') !== 'excel' || empty($field['prop'])) {
                continue;
            }
            $prop = $field['prop'];
            $upload = $request->file($prop);
            if ($upload === null || !$upload->isValid()) {
                continue; // 未上传：交由 handle 内自行判断/报错
            }
            $data[$prop] = \plugin\curd\app\support\ExcelParser::parse(
                $upload->getRealPath(),
                // webman UploadFile 无 getOriginalName()：优先 getUploadName()（客户端原始文件名），再退 SplFileInfo 文件名
                method_exists($upload, 'getUploadName') ? (string)$upload->getUploadName() : (string)$upload->getFilename(),
                [
                    'maxRows' => (int)($field['maxRows'] ?? 5000),
                    'sheet' => (int)($field['sheet'] ?? 1),
                ]
            );
        }
        return $data;
    }

    /**
     * 数组式 action 的 excel 解析结果（Action 类走 setExcelData 注入，数组式走本属性）
     * @var array
     */
    protected array $excelActionData = [];

    /**
     * 取 excel 字段解析出的数据行（数组式 action 控制器内使用）
     *   $rows = $this->excelRows('import_file');  // [['手机号' => '138...', '金额' => '10'], ...]
     */
    public function excelRows(string $prop): array
    {
        return isset($this->excelActionData[$prop]['rows']) ? $this->excelActionData[$prop]['rows'] : [];
    }

    /**
     * 取 excel 字段解析元信息（数组式 action 控制器内使用）
     * @return array ['count' => n, 'file_name' => 'xx.xlsx', 'sheets' => ?int]
     */
    public function excelInfo(string $prop): array
    {
        $d = isset($this->excelActionData[$prop]) ? $this->excelActionData[$prop] : [];
        unset($d['rows']);
        return $d;
    }

    /**
     * 校验提交数据
     *
     * @param array  $data    提交数据
     * @param bool   $partial 部分校验（只校验提交了的字段，编辑用）
     * @param string|null $mode 场景 'add'|'edit'：传入时校验规则先按场景白名单收敛
     *                             （该场景不展示/禁用的字段不参与校验）
     */
    protected function validateData(array $data, bool $partial = false, ?string $mode = null): array
    {
        $rules = $this->resolveRules();
        if (empty($rules)) {
            return [];
        }
        if ($mode !== null) {
            $writable = $this->writableFields($mode);
            if ($writable !== null) {
                $rules = array_intersect_key($rules, $writable);
            }
        }
        if ($partial) {
            $rules = array_intersect_key($rules, $data);
        }
        return Validator::validate($data, $rules);
    }

    /**
     * 场景可写字段白名单（'add'|'edit'）：来自 Grid DSL 表单配置
     *
     * 规则（与前端 resolveFieldForMode/sanitizeFormData 行为对齐）：
     *  - hiddens.{mode|common}=true / modeOnly 不匹配 → 剔除（该场景不展示不提交）
     *  - sceneShow（isCreate/isEdit）对应场景为 false → 剔除
     *  - disableds.{mode|common}=true → 剔除（禁用字段不提交）
     *  - Form::hidden() 声明字段（顶层 hidden=true）→ 始终可写（隐藏提交字段语义）
     *  - 条件字段（visibleWhen）→ 放行（可见性由父字段值在前端控制）
     *
     * @return array<string,true> prop 白名单；无 grid/无表单配置时返回 null（不过滤，保持旧行为）
     */
    protected function writableFields(string $mode): ?array
    {
        $grid = $this->resolveGrid();
        if (!$grid) {
            return null;
        }
        // extra（addForm/editForm）覆盖同名字段，与前端 override 逻辑一致
        $fields = $grid->formFields();
        $extra = $mode === 'add' ? $grid->addFields() : $grid->editFields();
        $map = [];
        foreach ($fields as $f) {
            $map[$f['prop'] ?? ''] = $f;
        }
        foreach ($extra as $f) {
            $map[$f['prop'] ?? ''] = $f;
        }
        unset($map['']);

        // Form::isCreate()/isEdit() 场景白名单：该场景仅保留清单内字段（hidden 提交字段始终保留）
        $sceneShow = $grid->formSceneShow();
        $whitelist = $sceneShow[$mode === 'add' ? 'create' : 'edit'] ?? null;
        if (is_array($whitelist)) {
            $allowed = array_flip($whitelist);
            foreach ($map as $prop => $f) {
                if (empty($f['hidden']) && !isset($allowed[$prop])) {
                    unset($map[$prop]);
                }
            }
        }

        $writable = [];
        foreach ($map as $prop => $f) {
            if (!empty($f['visibleWhen'])) {
                $writable[$prop] = true;
                continue;
            }
            if ($this->fieldWritableInMode($f, $mode)) {
                $writable[$prop] = true;
            }
        }
        return $writable;
    }

    /**
     * 单字段在指定场景是否可写（展示并提交）
     */
    protected function fieldWritableInMode(array $field, string $mode): bool
    {
        // modeOnly：仅 add / 仅 edit（场景块内声明的字段会被自动打上这个标记）
        // 放在 hidden 短路之前：场景专属字段即便声明成 Form::hidden() 隐藏提交字段，也不跨场景提交
        if (!empty($field['modeOnly']) && $field['modeOnly'] !== $mode) {
            return false;
        }
        // Form::hidden() 声明的隐藏提交字段：始终可写
        if (!empty($field['hidden'])) {
            return true;
        }
        // hiddens.{add|edit|common}
        if (isset($field['hiddens']) && is_array($field['hiddens'])) {
            $h = $field['hiddens'];
            if (!empty($h[$mode] ?? $h['common'] ?? false)) {
                return false;
            }
        }
        // disableds.{add|edit|common}：禁用字段不提交
        if (isset($field['disableds']) && is_array($field['disableds'])) {
            $d = $field['disableds'];
            if (!empty($d[$mode] ?? $d['common'] ?? false)) {
                return false;
            }
        }
        return true;
    }

    /**
     * 按场景白名单过滤提交数据（后台兜底：即使前端多提交了字段，也会被剔除）
     * 无表单配置（动态 CURD 等）时原样返回。
     */
    protected function filterWritableFields(array $data, string $mode): array
    {
        $writable = $this->writableFields($mode);
        if ($writable === null) {
            return $data;
        }
        // 主键与前端时间戳标记始终保留（update 自身也 unset 过）
        return array_intersect_key($data, $writable + ['id' => true, '_t' => true]);
    }

    /**
     * 解析规则：优先子类 rules()，其次 Grid DSL 的 rules，否则按表单字段推断
     */
    protected function resolveRules(): array
    {
        $rules = $this->rules();
        if ($rules) {
            return $rules;
        }

        // Grid DSL 模式
        $grid = $this->resolveGrid();
        if ($grid) {
            $gridRules = $grid->rules();
            if ($gridRules) {
                return $gridRules;
            }
        }

        // 旧数组写法：按表单字段推断
        $rules = [];
        foreach ($this->formFields() as $field) {
            $prop = $field['prop'] ?? null;
            if (!$prop) {
                continue;
            }
            $list = [];
            if (!empty($field['required'])) {
                $list[] = 'required';
            }
            if (in_array($field['type'] ?? '', ['select', 'radio', 'radioButton', 'checkbox'], true) && !empty($field['options'])) {
                $values = array_filter(array_column($field['options'], 'value'), fn($v) => $v !== '' && $v !== null);
                if ($values) {
                    $list[] = 'in:' . implode(',', $values);
                }
            }
            if (($field['type'] ?? '') === 'number') {
                $list[] = 'numeric';
            }
            if ($list) {
                $rules[$prop] = implode('|', $list);
            }
        }
        return $rules;
    }

    /**
     * 新增（Eloquent Model::create → 触发 creating/created 事件 + casts）
     * 钩子时序：createdBefore → Model::create（saving/creating/saved/created）→ createdAfter
     * 无模型类时回退到 query builder（保留原行为）
     */
    public function add(Request $request)
    {
        $data = $request->post();
        if (empty($data)) {
            return json(['code' => 400, 'msg' => '数据不能为空']);
        }

        // 场景字段过滤：只保留创建场景展示（isCreate/未隐藏）且未禁用的字段，多余字段剔除
        $data = $this->filterWritableFields($data, 'add');
        if (empty($data)) {
            return json(['code' => 400, 'msg' => '没有可写入的字段']);
        }

        // 表单验证（规则同步按场景白名单收敛）
        $errors = $this->validateData($data, false, 'add');
        if ($errors) {
            return json(['code' => 400, 'msg' => json_encode(['errors' => $errors], JSON_UNESCAPED_UNICODE)]);
        }

        // 自动填充创建人（表含 created_uid 字段时写入当前登录用户 ID）
        $this->applyCreatedUid($data, $request);

        // 事件：新增前
        $this->createdBefore($data);

        // 多选字段（数组）入库前序列化，避免 PDO 报 Array to string conversion
        $data = $this->normalizeArrayValues($data);

        // 自动时间戳
        $this->applyTimestamps($data, true);

        $data = array_filter($data, fn($v) => $v !== '' && $v !== null);

        $id = $this->insertViaModel($data);

        // 事件：新增后
        $this->createdAfter($id, $data);

        return json(['code' => 200, 'msg' => 'success', 'data' => ['id' => $id]]);
    }

    /**
     * 更新（Eloquent Model::find($id)->update → 触发 updating/updated 事件 + casts）
     * 钩子时序：updateBefore → Model 更新（saving/updating/saved/updated）→ updateAfter
     * 无模型类时回退到 query builder（保留原行为）
     */
    public function update(Request $request)
    {
        $id = $request->post('id');
        $data = $request->post();

        if (!$id) {
            return json(['code' => 400, 'msg' => 'ID不能为空']);
        }

        unset($data['id'], $data['_t']);

        // 场景字段过滤：只保留编辑场景展示（isEdit/未隐藏）且未禁用的字段，多余字段剔除
        $data = $this->filterWritableFields($data, 'edit');

        // 表单验证（部分校验：只校验提交的字段；规则同步按场景白名单收敛）
        $errors = $this->validateData($data, true, 'edit');
        if ($errors) {
            return json(['code' => 400, 'msg' => json_encode(['errors' => $errors], JSON_UNESCAPED_UNICODE)]);
        }

        // 事件：更新前
        $this->updateBefore($id, $data);

        // 多选字段（数组）入库前序列化，避免 PDO 报 Array to string conversion
        $data = $this->normalizeArrayValues($data);

        // 自动时间戳
        $this->applyTimestamps($data, false);

        $data = array_filter($data, fn($v) => $v !== '' && $v !== null);

        if (empty($data)) {
            return json(['code' => 400, 'msg' => '没有要更新的数据']);
        }

        $this->updateViaModel($id, $data);

        // 事件：更新后
        $this->updateAfter($id, $data);

        return json(['code' => 200, 'msg' => 'success']);
    }

    /**
     * 数组值序列化：多选字段（select multiple / tree-select multiple / tags）前端提交的是数组，
     * 直接交给 PDO 绑定会抛 "Array to string conversion"。
     *
     * 处理规则：
     *  - 模型已声明 cast 的字段（如 'invoice' => 'json'）：Eloquent 的 setAttribute 会自动 json_encode，跳过不处理
     *  - 其余数组字段：按项目惯例存逗号分隔字符串（与 FinancesController::project 的 explode(',') 配套）
     *  - 空数组：转为空字符串，随后被 array_filter 过滤，不写入该列
     *
     * @param array $data 待入库数据
     * @return array 序列化后的数据
     */
    protected function normalizeArrayValues(array $data): array
    {
        $hasArray = false;
        foreach ($data as $value) {
            if (is_array($value)) {
                $hasArray = true;
                break;
            }
        }
        if (!$hasArray) {
            return $data;
        }

        // 读取模型 cast 配置：已声明 cast 的字段交给 Eloquent 处理
        $casts = [];
        $model = $this->resolvedModel();
        if ($model !== '' && class_exists($model)) {
            $instance = new $model();
            if (method_exists($instance, 'getCasts')) {
                $casts = $instance->getCasts();
            }
        }

        foreach ($data as $key => $value) {
            if (!is_array($value) || isset($casts[$key])) {
                continue;
            }
            $data[$key] = implode(',', array_map(
                fn($v) => is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE),
                $value
            ));
        }
        return $data;
    }

    /**
     * 走 Eloquent Model::create 插入（触发 creating/created 事件 + casts）
     * 子类未声明 model() 时回退到 query builder insertGetId
     */
    protected function insertViaModel(array $data): int
    {
        $model = $this->resolvedModel();
        if ($model === '' || !class_exists($model)) {
            return (int)$this->query()->insertGetId($data);
        }
        // BaseModel timestamps=false → 关掉 Eloquent 自动时间戳，由 applyTimestamps 按列检测统一写入
        // 否则 save() 在表里没有 created_at/updated_at 列时会抛 Unknown column
        $instance = new $model();
        $instance->setConnection($this->modelConnection());
        $instance->timestamps = false;
        $instance->forceFill($data)->save();
        return (int)$instance->getKey();
    }

    /**
     * 走 Eloquent Model 更新（触发 updating/updated 事件 + casts）
     * 子类未声明 model() 时回退到 query builder update
     */
    protected function updateViaModel(int|string $id, array $data): void
    {
        $model = $this->resolvedModel();
        if ($model === '' || !class_exists($model)) {
            $this->query()->where('id', $id)->update($data);
            return;
        }
        // 走 Eloquent：先 find 再 update（触发 saving/updating/saved/updated 事件）
        // 不存在时返回 404 而不是静默写 0 行
        $instance = $model::on($this->modelConnection())->find($id);
        if (!$instance) {
            return;
        }
        $instance->timestamps = false;
        $instance->forceFill($data)->save();
    }

    /**
     * 删除（支持软删除）
     */
    public function delete(Request $request)
    {
        $id = $request->post('id');
        if (!$id) {
            return json(['code' => 400, 'msg' => 'ID不能为空']);
        }

        $row = $this->query()->where('id', $id)->first();
        if (!$row) {
            return json(['code' => 404, 'msg' => '记录不存在']);
        }

        // 事件：删除前
        $this->deleteBefore($id, (array)$row);

        $query = $this->query()->where('id', $id);
        if ($this->hasSoftDelete()) {
            $query->update(['deleted_at' => date('Y-m-d H:i:s')]);
        } else {
            $query->delete();
        }

        // 事件：删除后
        $this->deleteAfter($id);

        return json(['code' => 200, 'msg' => 'success']);
    }

    /**
     * 批量删除（支持软删除）
     */
    public function batchDelete(Request $request)
    {
        $ids = $request->post('ids', []);
        if (empty($ids) || !is_array($ids)) {
            return json(['code' => 400, 'msg' => '请选择要删除的数据']);
        }

        // 事件：批量删除前
        $this->batchDeleteBefore($ids);

        $query = $this->query()->whereIn('id', $ids);
        if ($this->hasSoftDelete()) {
            $query->update(['deleted_at' => date('Y-m-d H:i:s')]);
        } else {
            $query->delete();
        }

        // 事件：批量删除后
        $this->batchDeleteAfter($ids);

        return json(['code' => 200, 'msg' => 'success']);
    }

    /**
     * 导出（CSV 带 BOM）
     * 输出与列表展示一致：列标题用 grid label；列值依次走 display 闭包 > map 字典 > date/datetime 格式化 > 原始值
     */
    public function export(Request $request)
    {
        $params = $request->all();
        unset($params['_t']);

        $columnMap = array_flip($this->tableColumns());
        $query = $this->query();

        if ($this->hasSoftDelete()) {
            $query->whereNull('deleted_at');
        }

        foreach ($params as $key => $value) {
            if ($value === '' || $value === null || in_array($key, ['page', 'size', 'ids'], true)) {
                continue;
            }
            if (!isset($columnMap[$key])) {
                continue;
            }
            // 单日期搜索（与列表 index 一致：直接等值，date 列；datetime 列只会命中 00:00:00 那条）
            $searchMap = $this->searchFieldMap();
            if (($searchMap[$key]['type'] ?? null) === 'date' && is_string($value) && $value !== '') {
                $query->where($key, $value);
                continue;
            }
            if (is_array($value) && count($value) === 2) {
                $query->whereBetween($key, $value);
            } else {
                $query->where($key, 'like', "%{$value}%");
            }
        }

        // 导出指定 id（前端"导出选中"）
        $ids = $request->input('ids', '');
        if ($ids !== '') {
            $idList = array_filter(explode(',', $ids), fn($v) => $v !== '');
            if (!empty($idList)) {
                $query->whereIn('id', $idList);
            }
        }

        $page = (int)$request->input('page', 0);
        $size = (int)$request->input('size', 0);

        // 单次导出行数上限（config/admin.php 的 export_max_rows，见 exportMaxRows()）
        // 超限直接报错，不做静默截断——静默截断会让用户以为"导全了"，比报错更危险
        $maxRows = $this->exportMaxRows();
        $total = (int)(clone $query)->count();
        $willExport = ($page > 0 && $size > 0 && $ids === '') ? min($total, $size) : $total;
        if ($maxRows > 0 && $willExport > $maxRows) {
            return json([
                'code' => 400,
                'msg'  => "本次将导出 {$willExport} 条，超过单次导出上限 {$maxRows} 条，请缩小筛选范围或分批导出",
            ]);
        }

        // 硬上限兜底（上面的校验已保证不会真的截断，这里只防意外）
        $cap = $maxRows > 0 ? $maxRows : 100000;
        $rowsQuery = clone $query;
        if ($page > 0 && $size > 0 && $ids === '') {
            $rowsQuery->forPage($page, min($cap, $size));
        } else {
            $rowsQuery->limit($cap);
        }

        // 保留为 Model（不 toArray），以便设置 display_{prop} 属性
        $rows = $rowsQuery->orderByDesc('id')->get();
        // 应用 display 闭包 → 每行附 display_{prop}，与列表渲染一致
        $rows = $this->applyDisplayHandlers($rows);

        // 收集导出列（按 grid 定义顺序）；子类可重写 exportColumns() 返回 prop 子集
        // 前端「列配置」勾选结果随请求带上（_columns=prop1,prop2），用于把导出列收敛到当前可见列
        $only = $this->requestedExportColumns($request);
        $columns = $this->collectExportColumns($only);
        if (empty($columns)) {
            // 无 grid 配置（动态 CURD）：回退到表字段（同样受前端勾选约束，否则该口径在该类页面失效）
            $first = $rows->first();
            $fallbackFields = $first ? array_keys($first->getAttributes()) : array_keys($columnMap);
            if (!empty($only)) {
                $fallbackFields = array_values(array_intersect($fallbackFields, $only));
            }
            $columns = array_map(fn($f) => ['prop' => $f, 'label' => $f, 'map' => null, 'type' => null], $fallbackFields);
        }

        $csv = "\xEF\xBB\xBF";
        $header = array_map(fn($c) => $c['label'] ?? $c['prop'], $columns);
        $csv .= implode(',', $header) . "\n";

        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $col) {
                $line[] = $this->csvField($this->exportValue($row, $col));
            }
            $csv .= implode(',', $line) . "\n";
        }

        // 文件名用「页面标题」（与 /api/curd/config 的 data.title 同源），不再是裸表名
        $filename = $this->exportFilename();

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $this->exportAsciiFilename($filename)
                . '"; filename*=UTF-8\'\'' . rawurlencode($filename),
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * 单次导出行数上限（<=0 表示不限制）
     *
     * 读取优先级：
     *   ① 宿主 config/admin.php 的 export_max_rows（与 upload_path / site 同一个宿主配置文件）
     *   ② 插件 config/curd.php 的 export_max_rows（宿主 config/curd.php 顶层同名键可覆盖）
     *   ③ 兜底 100000
     */
    protected function exportMaxRows(): int
    {
        $value = config('admin.export_max_rows');
        if ($value === null || $value === '') {
            $value = config('plugin.curd.curd.export_max_rows', 100000);
        }
        return (int)$value;
    }

    /**
     * 导出文件名：{页面标题}_{YmdHis}.csv
     *
     * 标题与 /api/curd/config 返回的 data.title 同源：
     *   控制器 $title（title()）→ 落库配置 curd_configs.title（动态 CURD 页 title() 会退化成表名时再查一次）
     *   → 表名兜底。
     * 前端 blob 下载名同样取页面标题（dynamicCurd/index.vue 的 export），两边保持一致。
     */
    protected function exportFilename(): string
    {
        $name = $this->exportTitle();
        // 文件名安全：去掉路径分隔符 / 控制字符，避免 Content-Disposition 注入与路径穿越
        $name = preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', '_', $name) ?? '';
        $name = trim($name, " ._");
        if ($name === '') {
            $name = 'export';
        }
        return $name . '_' . date('YmdHis') . '.csv';
    }

    /**
     * 导出用的页面标题（拿不到时回退表名）
     */
    protected function exportTitle(): string
    {
        $title = '';
        $table = '';
        try {
            $table = trim((string)$this->table());
        } catch (\Throwable $e) {
            // 控制器未实现 model()/table()：保持空，走兜底
        }
        try {
            $title = trim((string)$this->title());
        } catch (\Throwable $e) {
            // 无标题（title() 内部也可能因 table() 抛错）：忽略，稍后用表名兜底
        }

        // 动态 CURD 控制器没有显式标题，title() 会退化成表名 → 再取一次落库配置的标题
        if ($title === '' || $title === $table) {
            try {
                $row = \plugin\curd\app\model\CurdConfigs::firstByTableName($table);
                if ($row && !empty($row->title)) {
                    $title = trim((string)$row->title);
                }
            } catch (\Throwable $e) {
                // 配置表不可用（未安装 / 未迁移）时忽略，继续用表名
            }
        }

        return $title !== '' ? $title : $table;
    }

    /**
     * ASCII 兜底文件名（老客户端不认 filename* 时使用）
     *
     * 中文标题剥掉非 ASCII 后会变成空 / 一串下划线（下载下来完全看不出是什么），
     * 这种情况下退回**表名**（如 HfAccoountWater），至少是可读的英文标识。
     */
    protected function exportAsciiFilename(string $filename): string
    {
        // 只看标题主干：去掉 _YmdHis.csv 尾巴，避免时间戳里的数字骗过「有没有 ASCII 字符」的判断
        $suffix = '';
        if (preg_match('/(_\d{14}\.csv)$/', $filename, $m)) {
            $suffix = $m[1];   // 复用同一时间戳，两个头里的文件名不会差 1 秒
        }
        if ($suffix === '') {
            $suffix = '_' . date('YmdHis') . '.csv';
        }

        $stem = substr($filename, 0, strlen($filename) - strlen($suffix));
        $ascii = preg_replace('/[^\x20-\x7E]/', '', $stem) ?? '';
        $ascii = str_replace(['"', '\\'], '_', $ascii);
        $ascii = trim($ascii, " ._");

        if ($ascii === '' || !preg_match('/[A-Za-z0-9]/', $ascii)) {
            $fallback = '';
            try {
                $fallback = trim((string)$this->table());
            } catch (\Throwable $e) {
                // 无表名：保持空，走 export
            }
            $ascii = $fallback !== '' ? $fallback : 'export';
        }

        return $ascii . $suffix;
    }

    /**
     * 解析前端「列配置」传参：_columns=prop1,prop2（逗号分隔）
     *
     * 该参数只用于**收窄**导出列，不会扩大范围：最终列始终是「grid 定义列 ∩ 允许导出的列 ∩ 勾选列」。
     * 缺省 / 空串 / 解析后为空 → 返回 null，即沿用原有口径（全列），保证老调用方与直连接口的兼容。
     *
     * @return string[]|null
     */
    protected function requestedExportColumns(Request $request): ?array
    {
        $raw = $request->input('_columns', null);
        // 兼容数组形式（_columns[]=a&_columns[]=b）
        if (is_array($raw)) {
            $list = $raw;
        } elseif (is_string($raw) && $raw !== '') {
            $list = explode(',', $raw);
        } else {
            return null;
        }

        $out = [];
        foreach ($list as $prop) {
            if (!is_string($prop)) continue;
            $prop = trim($prop);
            if ($prop !== '' && !in_array($prop, $out, true)) {
                $out[] = $prop;
            }
        }

        return empty($out) ? null : $out;
    }

    /**
     * 收集导出列配置：[{prop, label, map, type}, ...]
     *
     * 优先级（只收窄、不放宽）：
     *  1. 子类重写 exportColumns() 返回的 prop 白名单（开发者口径，最高优先）
     *  2. $only 前端「列配置」勾选列（跟随前端列设置）
     *  3. 都没有 → grid 全列（含 hidden），保持历史行为
     * 两种来源同时存在时取交集；未知 prop 一律忽略，顺序始终按 grid 定义。
     *
     * @param string[]|null $only 前端勾选的 prop（null = 不限制）
     */
    protected function collectExportColumns(?array $only = null): array
    {
        $custom = null;
        if (method_exists($this, 'exportColumns')) {
            $custom = $this->exportColumns();
        }
        $grid = $this->resolveGrid();
        $all = $grid ? $grid->columns() : [];
        $byProp = array_column($all, null, 'prop');

        // 待导出的 prop 清单：白名单 → 再与前端勾选求交
        $picked = $custom;
        if (!empty($only)) {
            // 用 grid 的 prop 顺序做基准，天然去重 + 按定义顺序输出
            $picked = array_values(array_intersect(array_keys($byProp), $only));
            if ($custom !== null) {
                $picked = array_values(array_intersect($picked, $custom));
            }
        }

        if ($picked === null) {
            return $all;
        }

        $out = [];
        foreach ($picked as $prop) {
            if (isset($byProp[$prop])) $out[] = $byProp[$prop];
        }
        return $out;
    }

    /**
     * 单个导出值格式化
     * 优先级：display_{prop}（display 闭包结果） > map 字典（id→label）> date/datetime 格式化 > 原始值
     */
    protected function exportValue($row, array $col): string
    {
        $prop = $col['prop'];

        // 1) display 闭包结果（applyDisplayHandlers 已为每行设置）
        $val = $row->getAttribute('display_' . $prop);
        if ($val !== null) {
            return $this->stringifyExportValue($val);
        }

        $raw = $row->getAttribute($prop);
        if ($raw === null || $raw === '') return '';

        // 2) map 字典（col.map 是 stdClass，键为 (string)value，值为 label）
        if (!empty($col['map']) && is_object($col['map'])) {
            $map = $col['map'];
            $key = (string)$raw;
            if (isset($map->$key)) return (string)$map->$key;
        }

        // 3) date/datetime 格式化（与前端 formatDate/formatDateOnly 输出保持一致）
        $type = $col['type'] ?? null;
        if ($type === 'datetime') {
            $ts = strtotime((string)$raw);
            return $ts !== false ? date('Y-m-d H:i:s', $ts) : (string)$raw;
        }
        if ($type === 'date') {
            $ts = strtotime((string)$raw);
            return $ts !== false ? date('Y-m-d', $ts) : (string)$raw;
        }

        return $this->stringifyExportValue($raw);
    }

    protected function stringifyExportValue($val): string
    {
        if (is_array($val) || is_object($val)) {
            return json_encode($val, JSON_UNESCAPED_UNICODE);
        }
        return (string)$val;
    }

    /**
     * CSV 字段转义
     */
    protected function csvField($value)
    {
        $value = (string)$value;
        if (preg_match('/[",\r\n]/', $value)) {
            return '"' . str_replace('"', '""', $value) . '"';
        }
        return $value;
    }
}
