<?php
namespace plugin\curd\app\controller\base\concerns;

use support\Request;

/**
 * 列表查询 + 前端配置输出 + 展示后处理（display/href 闭包）
 *
 * 从 BaseCurdController 抽取，保持方法签名与行为完全一致。
 * 依赖 CurdConfigTrait（config/grid/columns...）与 CurdQueryTrait（query/tableColumns...）。
 */
trait CurdListTrait
{
    /**
     * 输出前端 CURD 配置（供 dynamicCurd 渲染）
     */
    public function config(): array
    {
        // 优先使用 Grid DSL
        $grid = $this->resolveGrid();
        if ($grid) {
            $detail = $grid->detail();
            if (empty($detail)) {
                $detail = array_map(fn($c) => [
                    'prop' => $c['prop'],
                    'label' => $c['label'],
                    'type' => $c['type'] ?? '',
                    'map' => $c['map'] ?? null,
                    'tagType' => $c['tagType'] ?? null,
                    'display' => $c['display'] ?? null,
                ], $grid->columns());
            }
            $config = [
                'title' => $grid->title() ?: $this->title(),
                '_modelName' => $this->modelName(),
                'api' => $this->api(),
                'columns' => $grid->columns(),
                'search' => $grid->search(),
                'formFields' => $grid->formFields(),
                'addFields' => $grid->addFields(),
                'editFields' => $grid->editFields(),
                'rules' => $grid->rules(),
                'detail' => $detail,
                'actions' => $grid->actions($this->modelName()),
                'options' => $grid->options(),
                // 弹窗宽高：Form::width()/height() 优先，回退到 setDialogWidth()/默认值
                'dialogWidth' => $grid->formLayout()['width'] ?: $grid->dialogWidth(),
                'dialogHeight' => $grid->formLayout()['height'] ?? '',
                'formColumns' => $grid->formLayout()['columns'] ?? 1,
                'formConfirm' => $grid->formConfirm(),
                'formTabs' => $grid->getFormTabs(),
                'formSteps' => $grid->getFormSteps(),
            ];
        // 行级样式和类名回调改为服务端执行（结果挂行数据 _rowStyle/_rowClass，前端统一读取），
        // 闭包不能进 JSON（json_encode 会输出 {}），此前塞进 config 导致样式完全不生效
        $rowStyleCallback = $grid->getRowStyleCallback();
        $rowClassCallback = $grid->getRowClassCallback();
            // 自定义 CSS 和 JS
            $customCss = $grid->getCustomCss();
            $customJs = $grid->getCustomJs();
            if ($customCss !== '') {
                $config['customCss'] = $customCss;
            }
            if ($customJs !== '') {
                $config['customJs'] = $customJs;
            }
            return $config;
        }

        // 兼容旧写法（数组方式）
        $detail = $this->detail();
        if (empty($detail)) {
            $detail = array_map(fn($c) => [
                'prop' => $c['prop'],
                'label' => $c['label'],
                'type' => $c['type'] ?? '',
                'map' => $c['map'] ?? null,
                'tagType' => $c['tagType'] ?? null,
                'display' => $c['display'] ?? null,
            ], $this->columns());
        }

        $config = [
            'title' => $this->title(),
            '_modelName' => $this->modelName(),
            'api' => $this->api(),
            'columns' => $this->columns(),
            'search' => $this->search(),
            'formFields' => $this->formFields(),
            'rules' => $this->resolveRules(),
            'detail' => $detail,
            'actions' => $this->actions(),
            'options' => $this->options(),
            'dialogWidth' => $this->dialogWidth(),
            'dialogHeight' => '',
            'formColumns' => 1,
            'formConfirm' => [],
        ];
        // 注意：旧写法暂不支持 rowStyle/rowClass
        return $config;
    }

    /**
     * 模型名（类短名，如 APackage）——config 输出用，替代暴露表名
     */
    protected function modelName(): string
    {
        $model = $this->resolvedModel();
        if ($model !== '' && class_exists($model)) {
            $pos = strrpos($model, '\\');
            return $pos === false ? $model : substr($model, $pos + 1);
        }
        // 无模型时按表名反推（与 ModelRegistry::tableNameToModelName 规则一致）
        $table = $this->table();
        return \plugin\curd\app\ModelRegistry::tableNameToModelName($table);
    }

    /**
     * 列表
     */
    public function index(Request $request)
    {
        $page = (int)$request->get('page', 1);
        $pageSize = (int)$request->get('size', 10);
        $params = $request->all();
        unset($params['page'], $params['size'], $params['table'], $params['_t']);

        $columnMap = array_flip($this->tableColumns());
        $query = $this->query();
        $grid = $this->resolveGrid(); // DSL 控制器：取 grid 上的 filter 实例（自定义 where 用）

        // 软删除过滤
        if ($this->hasSoftDelete()) {
            $query->whereNull('deleted_at');
        }

        // 搜索匹配方式：优先用搜索配置里的 match；否则按类型（字符串模糊，其余精确）
        $searchMap = $this->searchFieldMap();
        $columnTypes = $this->tableColumnTypes();

        foreach ($params as $key => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            if (!isset($columnMap[$key])) {
                continue;
            }
            // 单日期搜索（Filter::singleDate）：直接等值匹配（date 列；datetime 列需注意只匹配 00:00:00 那条）
            if (($searchMap[$key]['type'] ?? null) === 'date' && is_string($value) && $value !== '') {
                $query->where($key, $value);
                continue;
            }
            if (is_array($value) && count($value) === 2) {
                $query->whereBetween($key, $value);
            } else {
                $mode = $this->resolveMatchMode($key, $searchMap[$key] ?? null, $columnTypes[$key] ?? '');
                if ($mode === 'like') {
                    $query->where($key, 'like', "%{$value}%");
                } else {
                    $query->where($key, $value);
                }
            }
        }

        // 自定义 SQL 搜索条件（Filter::where）：有输入值才执行闭包，闭包内 $this->input = 用户输入
        $searchFilter = $grid ? $grid->searchFilter() : null;
        if ($searchFilter && $searchFilter->whereCallbacks()) {
            foreach ($searchFilter->whereCallbacks() as $prop => $callback) {
                $value = $params[$prop] ?? '';
                if ($value === '' || $value === null) {
                    continue;
                }
                $searchFilter->setInput($value);
                $callback($query);
            }
        }

        $count = $query->count();
        $list = $query->orderByDesc('id')->forPage($page, $pageSize)->get();

        // 列表头部汇总（Grid::summary）：基于「已应用当前筛选」的查询求值，随列表一起返回
        $summaryCb = $grid ? $grid->summaryCallback() : null;
        $payload = ['count' => $count, 'list' => null];
        if ($summaryCb) {
            try {
                $summary = $summaryCb(clone $query);
                if (is_array($summary)) {
                    $payload['summary'] = $summary;
                } elseif (is_string($summary) && $summary !== '') {
                    $payload['summaryHtml'] = $summary;
                }
            } catch (\Throwable $e) {
                // 汇总失败不影响列表
            }
        }

        // 保留 Eloquent Model 到所有行级处理完成，display/href/action 闭包可直接访问模型属性、方法和关联关系。
        // 仅在最终 JSON 输出前转换为数组。
        // display 处理器：服务端执行闭包，结果附加为 display_{prop} 字段（原始值保留）
        $list = $this->applyDisplayHandlers($list);
        // href 动态链接处理器：$this 绑定当前行执行，结果附加为 href_{prop} 字段
        $list = $this->applyHrefHandlers($list);
        // 行级样式/类名（Grid::setRowStyle/setRowClass）：$this 绑定行执行，结果附加 _rowStyle/_rowClass
        $list = $this->applyRowStyleClassHandlers($list);
        // action 每行求值处理器：show 闭包 → _actionShow[name]；params 闭包 → _actionParams[name]
        $list = $this->applyActionHandlers($list);
        // 兜底清理并在最终输出前转换为数组，避免向前端暴露模型内部属性。
        $list = $this->applyRowCleanup($list);
        $list = $this->rowsToArray($list);
        $payload['list'] = $list;

        return json(['code' => 200, 'msg' => 'success', 'data' => $payload]);
    }

    /**
     * 把列表行统一转为干净数组
     * Eloquent Model 用 toArray()（只输出真实字段）；stdClass/数组用 (array)
     * 避免 (array)Model 产生 "\0*\0xxx" 内部属性键，且保证顶层有真实字段（id 等）
     */
    protected function rowsToArray($list)
    {
        $result = [];
        foreach ($list as $item) {
            if (is_object($item) && method_exists($item, 'toArray')) {
                $row = $item->toArray();
                if (method_exists($item, 'getAttributes')) {
                    foreach ($item->getAttributes() as $key => $value) {
                        if (str_starts_with($key, 'display_')
                            || str_starts_with($key, 'href_')
                            || str_starts_with($key, '_row')
                            || str_starts_with($key, '_action')) {
                            $row[$key] = $value;
                        }
                    }
                }
                $result[] = $row;
            } else {
                $result[] = (array)$item;
            }
        }
        return $result;
    }

    /**
     * 对列表数据执行 grid 里定义的 display 闭包
     * 每行附加 display_{prop} = fn($value, $row)，原始字段不动（供编辑回显）
     */
    protected function applyDisplayHandlers($list)
    {
        $grid = $this->resolveGrid();
        if (!$grid) {
            return $list;
        }
        $handlers = $grid->displayHandlers();
        if (empty($handlers)) {
            return $list;
        }

        foreach ($list as $i => $row) {
            foreach ($handlers as $prop => $handler) {
                $value = $this->rowValue($row, $prop);
                try {
                    $bound = $this->bindRowHandler($handler, $row);
                    $row->setAttribute('display_' . $prop, $bound($value, $row));
                } catch (\Throwable $e) {
                    $row->setAttribute('display_' . $prop, $value);
                }
            }
            $list[$i] = $row;
        }

        return $list;
    }

    /**
     * 对列表数据执行 grid 里定义的 href 动态链接闭包
     * 闭包中 $this 绑定为当前行数据对象（可 $this->id 取字段）
     * 回调返回 string 当 URL 直接用；返回 array 当 column 格式（读取 url/target 字段）—— 两种都支持
     * 每行附加 href_{prop} = { url?: string, target?: string }；闭包异常时回退原始列值
     */
    protected function applyHrefHandlers($list)
    {
        $grid = $this->resolveGrid();
        if (!$grid) {
            return $list;
        }
        $handlers = $grid->hrefHandlers();
        if (empty($handlers)) {
            return $list;
        }

        foreach ($list as $i => $row) {
            foreach ($handlers as $prop => $handler) {
                $value = $this->rowValue($row, $prop);
                try {
                    $fn = $this->bindRowHandler($handler, $row);
                    $result = $fn($value, $row);
                    if (is_array($result)) {
                        $row->setAttribute('href_' . $prop, $result);
                    } else {
                        // string 自动归一化为 column 格式
                        $row->setAttribute('href_' . $prop, ['url' => $result]);
                    }
                } catch (\Throwable $e) {
                    $row->setAttribute('href_' . $prop, ['url' => $value]);
                }
            }
            $list[$i] = $row;
        }

        return $list;
    }

    /**
     * 对列表数据执行 grid 里定义的 action 每行求值闭包：
     *  - show 闭包   → row['_actionShow'][name] = bool（前端按此显隐操作按钮）
     *  - params 闭包 → row['_actionParams'][name] = array（前端合并进操作请求参数）
     * 闭包中 $this 绑定为当前行数据对象（可 $this->id 取字段）
     */
    protected function applyActionHandlers($list)
    {
        $grid = $this->resolveGrid();
        if (!$grid) {
            return $list;
        }
        $handlers = $grid->actionRowHandlers();
        if (empty($handlers)) {
            return $list;
        }

        foreach ($list as $i => $row) {
            foreach ($handlers as $name => $action) {
                if (!$action->allowedForCurrentUser()) {
                    $this->mergeRowAttribute($row, '_actionShow', [$name => false]);
                    continue;
                }
                if ($action->getShow() !== null) {
                    $this->mergeRowAttribute($row, '_actionShow', [$name => $action->evaluateShow($row)]);
                }
                if ($action->getParamsCallback() !== null) {
                    $this->mergeRowAttribute($row, '_actionParams', [$name => $action->evaluateParams($row)]);
                }
            }
            $list[$i] = $row;
        }

        return $list;
    }

    protected function rowValue($row, string $prop)
    {
        if (is_object($row) && method_exists($row, 'getAttribute')) {
            return $row->getAttribute($prop);
        }
        if (is_array($row)) {
            return $row[$prop] ?? null;
        }
        return null;
    }

    protected function bindRowHandler(callable $handler, $row): callable
    {
        if ($handler instanceof \Closure && is_object($row)) {
            return \Closure::bind($handler, $row) ?? $handler;
        }
        return $handler;
    }

    /**
     * 行级样式/类名处理器（Grid::setRowStyle / setRowClass）
     * 服务端每行执行回调（$this 绑定当前行，支持 $this->字段 访问），
     * 结果附加到行数据 _rowStyle / _rowClass，前端 el-table row-style / row-class-name 读取。
     */
    protected function applyRowStyleClassHandlers($list)
    {
        $grid = $this->resolveGrid();
        if (!$grid) {
            return $list;
        }
        $styleCb = $grid->getRowStyleCallback();
        $classCb = $grid->getRowClassCallback();
        if ($styleCb === null && $classCb === null) {
            return $list;
        }

        foreach ($list as &$row) {
            try {
                if ($styleCb !== null) {
                    $bound = $this->bindRowHandler($styleCb, $row);
                    $style = (string)$bound($row);
                    if ($style !== '') {
                        $this->setRowExtra($row, '_rowStyle', $style);
                    }
                }
                if ($classCb !== null) {
                    $bound = $this->bindRowHandler($classCb, $row);
                    $class = $bound($row);
                    if (is_array($class)) {
                        $class = implode(' ', array_filter($class, 'is_string'));
                    }
                    if ((string)$class !== '') {
                        $this->setRowExtra($row, '_rowClass', (string)$class);
                    }
                }
            } catch (\Throwable $e) {
                // 行级回调异常不影响列表返回
            }
        }
        unset($row);
        return $list;
    }

    /**
     * 给行数据附加额外字段（兼容 Eloquent 模型 / stdClass / 数组）
     */
    protected function setRowExtra($row, string $key, $value): void
    {
        if (is_object($row)) {
            if (method_exists($row, 'setAttribute')) {
                $row->setAttribute($key, $value);
            } else {
                $row->{$key} = $value;
            }
        } elseif (is_array($row)) {
            $row[$key] = $value;
        }
    }

    protected function mergeRowAttribute($row, string $attribute, array $values): void
    {
        if (is_object($row) && method_exists($row, 'setAttribute')) {
            $current = $row->getAttribute($attribute);
            $row->setAttribute($attribute, array_merge(is_array($current) ? $current : [], $values));
        }
    }

    /**
     * 兼容旧的数组行，Model 行无需清理内部属性，最终由 toArray() 输出。
     */
    protected function applyRowCleanup($list)
    {
        foreach ($list as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach (array_keys($row) as $k) {
                if (is_string($k) && ($k !== '' && ($k[0] === "\0" || $k[0] === '*'))) {
                    unset($row[$k]);
                }
            }
            $list[$i] = $row;
        }
        return $list;
    }
}
