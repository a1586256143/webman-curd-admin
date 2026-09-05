<?php
namespace plugin\crud\app\controller\base\concerns;

use app\validation\Validator;
use support\Request;

/**
 * CRUD 写操作：校验、新增、更新、删除、批量删除、导出（CSV）
 *
 * 从 BaseCrudController 抽取，保持方法签名与行为完全一致。
 * 依赖 CrudConfigTrait（rules/formFields/grid...）与 CrudQueryTrait（query/hasSoftDelete...）。
 */
trait CrudActionsTrait
{
    /**
     * 自定义操作分发器（配合 Grid DSL 的 $grid->action()->handler('audit')）
     * 自动路由：POST /api/crud/model/{model}/action/{name}
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
            return $action->handle($request);
        }

        $method = 'action' . str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $name)));
        if (!method_exists($this, $method)) {
            return json(['code' => 404, 'msg' => "操作 {$name} 未实现：请在 " . static::class . " 中定义 {$method}(Request \$request) 或在 Action 类中实现 handle(Request \$request)"]);
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
     * 校验提交数据
     */
    protected function validateData(array $data, bool $partial = false): array
    {
        $rules = $this->resolveRules();
        if (empty($rules)) {
            return [];
        }
        if ($partial) {
            $rules = array_intersect_key($rules, $data);
        }
        return Validator::validate($data, $rules);
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

        // 表单验证
        $errors = $this->validateData($data);
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

        // 表单验证（部分校验：只校验提交的字段）
        $errors = $this->validateData($data, true);
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

        $rowsQuery = clone $query;
        $page = (int)$request->input('page', 0);
        $size = (int)$request->input('size', 0);
        if ($page > 0 && $size > 0 && $ids === '') {
            $rowsQuery->forPage($page, min(100000, $size));
        } else {
            $rowsQuery->limit(100000);
        }

        // 保留为 Model（不 toArray），以便设置 display_{prop} 属性
        $rows = $rowsQuery->orderByDesc('id')->get();
        // 应用 display 闭包 → 每行附 display_{prop}，与列表渲染一致
        $rows = $this->applyDisplayHandlers($rows);

        // 收集导出列（按 grid 定义顺序）；子类可重写 exportColumns() 返回 prop 子集
        $columns = $this->collectExportColumns();
        if (empty($columns)) {
            // 无 grid 配置（动态 CRUD）：回退到表字段
            $first = $rows->first();
            $fallbackFields = $first ? array_keys($first->getAttributes()) : array_keys($columnMap);
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

        $filename = $this->table() . '_' . date('YmdHis') . '.csv';

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * 收集导出列配置：[{prop, label, map, type}, ...]
     * 子类可重写 exportColumns() 返回 prop 数组自定义子集；返回 null 时按 grid 全列导出（含 hidden）。
     */
    protected function collectExportColumns(): array
    {
        $custom = null;
        if (method_exists($this, 'exportColumns')) {
            $custom = $this->exportColumns();
        }
        $grid = $this->resolveGrid();
        $all = $grid ? $grid->columns() : [];
        $byProp = array_column($all, null, 'prop');
        if ($custom !== null) {
            $out = [];
            foreach ($custom as $prop) {
                if (isset($byProp[$prop])) $out[] = $byProp[$prop];
            }
            return $out;
        }
        return $all;
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
