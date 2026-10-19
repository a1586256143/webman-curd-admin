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
     * 点式列自动预加载的关联名（eagerLoadDotRelations 里 with() 的 key）。
     * 仅服务于 rowValueByPath 取点式值，展开后从输出行剔除，避免整个关联对象混进列表 JSON。
     */
    protected array $autoWithRelations = [];

    /**
     * 输出前端 CURD 配置（供 dynamicCurd 渲染）
     */
    public function config(): array
    {
        // 优先使用 Grid DSL
        $grid = $this->resolveGrid();
        if ($grid) {
            // 详情字段：仅当开发者显式 $grid->detail() 声明时才输出。
            // 未声明时不输出 detail，由前端默认取「列表列 ∪ 表单字段」——
            // 这样表单里的点式字段（如 config.api_url）在详情里同样可见，
            // 不会再出现「表单能编辑、详情看不到」。
            $detail = $grid->detail();
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
                'actions' => $grid->actions($this->modelName()),
                'options' => $grid->options(),
                // 弹窗宽高：Form::width()/height() 优先，回退到 setDialogWidth()/默认值
                'dialogWidth' => $grid->formLayout()['width'] ?: $grid->dialogWidth(),
                'dialogHeight' => $grid->formLayout()['height'] ?? '',
                'formColumns' => $grid->formLayout()['columns'] ?? 1,
                // 表单标签/内容区宽度：Form::labelWidth()/labelPosition()/contentWidth()
                'labelWidth' => $grid->formLayout()['labelWidth'] ?? 0,
                'labelPosition' => $grid->formLayout()['labelPosition'] ?? '',
                'contentWidth' => $grid->formLayout()['contentWidth'] ?? '',
                'formConfirm' => $grid->formConfirm(),
                'formTabs' => $grid->getFormTabs(),
                'formSteps' => $grid->getFormSteps(),
            ];
            // 显式声明了详情字段才下发（未声明交给前端默认：列表列 ∪ 表单字段）
            if (!empty($detail)) {
                $config['detail'] = $detail;
            }
            // 场景字段白名单（Form::isCreate()/isEdit()）：任一场景声明了清单才输出
            $sceneShow = $grid->formSceneShow();
            if ($sceneShow['create'] !== null || $sceneShow['edit'] !== null) {
                $config['sceneShow'] = $sceneShow;
            }
            // 级联下拉：cascadeHandler 解析为通用级联端点 URL
            $config['formFields'] = $this->resolveCascadeUrls((array)($config['formFields'] ?? []));
            $config['addFields'] = $this->resolveCascadeUrls((array)($config['addFields'] ?? []));
            $config['editFields'] = $this->resolveCascadeUrls((array)($config['editFields'] ?? []));
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
        // detail 同样只在显式声明时下发（未声明 -> 前端默认「列表列 ∪ 表单字段」）
        $detail = $this->detail();

        $config = [
            'title' => $this->title(),
            '_modelName' => $this->modelName(),
            'api' => $this->api(),
            'columns' => $this->columns(),
            'search' => $this->search(),
            'formFields' => $this->formFields(),
            'rules' => $this->resolveRules(),
            'actions' => $this->actions(),
            'options' => $this->options(),
            'dialogWidth' => $this->dialogWidth(),
            'dialogHeight' => '',
            'formColumns' => 1,
            'formConfirm' => [],
        ];
        if (!empty($detail)) {
            $config['detail'] = $detail;
        }
        // 注意：旧写法暂不支持 rowStyle/rowClass
        return $config;
    }

    /**
     * 级联下拉：把字段的 cascadeHandler 解析为通用级联端点 URL
     * （cascadeUrl 已显式设置的跳过；handler 只允许字母数字下划线，杜绝路径注入）
     */
    protected function resolveCascadeUrls(array $fields): array
    {
        foreach ($fields as &$field) {
            if (!is_array($field) || empty($field['cascadeHandler']) || !empty($field['cascadeUrl'])) {
                continue;
            }
            $handler = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$field['cascadeHandler']);
            if ($handler === '' || $handler === null) {
                continue;
            }
            $field['cascadeUrl'] = '/api/curd/model/' . $this->modelName() . '/cascade/' . $handler;
        }
        unset($field);
        return $fields;
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
        // 点式列：根不是真实字段时视为关联关系（如 user.name），预加载避免 N+1
        $this->eagerLoadDotRelations($query);
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
        // 弹窗 / 行内展开内容：逐行求值，结果附加 _modal_{prop} / _expand_{prop}
        $list = $this->applyCellContentHandlers($list);
        // 行级样式/类名（Grid::setRowStyle/setRowClass）：$this 绑定行执行，结果附加 _rowStyle/_rowClass
        $list = $this->applyRowStyleClassHandlers($list);
        // action 每行求值处理器：show 闭包 → _actionShow[name]；params 闭包 → _actionParams[name]
        $list = $this->applyActionHandlers($list);
        // 兜底清理并在最终输出前转换为数组，避免向前端暴露模型内部属性。
        $list = $this->applyRowCleanup($list);
        $list = $this->rowsToArray($list);
        // 点式列（prop 形如 config.api_url）：根字段 JSON 字符串解码 + 字面 key 扁平值
        $list = $this->expandDotColumns($list);
        // 剔除自动预加载的关联对象（with('platform') 不应出现在列表 JSON 里）
        $list = $this->stripEagerRelations($list);
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
                            || str_starts_with($key, '_action')
                            || str_starts_with($key, '_modal_')
                            || str_starts_with($key, '_expand_')) {
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
     * 点式列数据展开（prop 形如 config.api_url 时）：
     *   - 根字段（config）是 JSON 字符串 → 解码为关联数组放进行数据。
     *     element-plus 表格单元格取值走 lodash get(row, 'config.api_url')，需要嵌套对象才能取到
     *   - 同时写一份字面 key（'config.api_url' => 值）——GenericCurd 的单元格渲染读 row[col.prop]
     * 非 JSON 字符串 / 根字段缺失 → 保持原样；没有任何点式 prop → 原样返回（零开销）。
     *
     * 编辑回显也受益于此：前端 Object.assign(formData, row) 直接拿到字面 key，
     * 无需额外请求详情接口。因此展开范围 = grid 点式列 ∪ 表单点式字段（回显需要），
     * 动态 CURD（无 grid）再叠加 curd_configs 落库配置里的点式列/字段。
     */
    protected function expandDotColumns(array $rows): array
    {
        if (empty($rows)) {
            return $rows;
        }
        $dotProps = $this->dotColumnProps();
        if (empty($dotProps)) {
            return $rows;
        }

        // 按根字段分组：同一根只解码一次
        $roots = [];
        foreach ($dotProps as $prop) {
            $roots[explode('.', $prop)[0]] = true;
        }

        foreach ($rows as $i => $row) {
            foreach (array_keys($roots) as $root) {
                if (!array_key_exists($root, $row) || !is_string($row[$root]) || $row[$root] === '') {
                    continue;
                }
                $decoded = json_decode($row[$root], true);
                if (is_array($decoded)) {
                    $row[$root] = $decoded;
                }
            }
            foreach ($dotProps as $prop) {
                $row[$prop] = $this->rowPathValue($row, $prop);
            }
            $rows[$i] = $row;
        }
        return $rows;
    }

    /**
     * 需要展开的点式 prop 清单（去重）：
     *   - Grid DSL：点式列（展示）+ 表单点式字段（编辑回显）+ 详情显式声明的点式字段
     *   - 旧写法：columns() + formFields() + detail()
     *   - 动态 CURD（DynamicCurdController）：curd_configs 落库配置里的点式列/字段
     */
    protected function dotColumnProps(): array
    {
        $dotProps = [];
        $collect = function ($prop) use (&$dotProps) {
            $prop = (string)$prop;
            if ($prop !== '' && str_contains($prop, '.')) {
                $dotProps[$prop] = true;
            }
        };

        $grid = $this->resolveGrid();
        if ($grid) {
            foreach ($grid->columns() as $col) {
                $collect($col['prop'] ?? '');
            }
            foreach (array_merge($grid->formFields(), $grid->addFields(), $grid->editFields()) as $field) {
                $collect($field['prop'] ?? '');
            }
            // 详情显式声明的点式字段：不收集就不会展开 -> 详情里取不到值
            foreach ($this->collectDetailProps($grid->detail()) as $prop) {
                $collect($prop);
            }
        } else {
            // 兼容旧写法
            foreach ($this->columns() as $col) {
                $collect($col['prop'] ?? '');
            }
            foreach ($this->formFields() as $field) {
                $collect($field['prop'] ?? '');
            }
            foreach ($this->collectDetailProps($this->detail()) as $prop) {
                $collect($prop);
            }
        }
        // 动态 CURD 落库配置（仅 DynamicCurdController 实现 dynamicDotProps）
        if (method_exists($this, 'dynamicDotProps')) {
            foreach ((array)$this->dynamicDotProps() as $prop) {
                $collect($prop);
            }
        }
        return array_keys($dotProps);
    }

    /**
     * 从 detail 配置提取 prop 清单
     * 兼容两种写法：扁平 [['prop'=>'a'], ...] 与分组 [['group'=>'G','fields'=>[['prop'=>'a']]], ...]
     */
    protected function collectDetailProps(array $detail): array
    {
        $props = [];
        foreach ($detail as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (isset($item['fields']) && is_array($item['fields'])) {
                foreach ($item['fields'] as $field) {
                    $prop = is_array($field) ? ($field['prop'] ?? '') : $field;
                    if (is_string($prop) && $prop !== '') {
                        $props[] = $prop;
                    }
                }
                continue;
            }
            $prop = $item['prop'] ?? '';
            if (is_string($prop) && $prop !== '') {
                $props[] = $prop;
            }
        }
        return $props;
    }

    /**
     * 点式列根字段不是真实字段 → 当作关联关系预加载（Eloquent with）
     *
     * 取值优先级（对应 grid->column('aaa.xxx')）：
     *   1. `aaa` 是真实字段（json/text 存 JSON）→ 按 JSON 键取 xxx（字段优先）
     *   2. `aaa` 不是字段，但模型上有同名关联方法 → with('aaa') 后取关联对象上的 xxx
     * 两者都没有 → 值为 null（不报错）
     */
    protected function eagerLoadDotRelations($query): void
    {
        if (!is_object($query) || !method_exists($query, 'with')) {
            return;
        }
        $dotProps = $this->dotColumnProps();
        if (empty($dotProps)) {
            return;
        }

        $columns = $this->tableColumns();
        $model = $this->resolvedModel();
        if ($model === '' || !class_exists($model)) {
            return;
        }

        $with = [];
        foreach ($dotProps as $prop) {
            $root = explode('.', $prop)[0];
            if ($root === '' || in_array($root, $columns, true)) {
                continue; // 真实字段：走 JSON 解码，不预加载
            }
            if (method_exists($model, $root)) {
                $with[$root] = true;
            }
        }
        if ($with) {
            $this->autoWithRelations = array_keys($with);
            $query->with($this->autoWithRelations);
        }
    }

    /**
     * 从输出行剔除点式列自动预加载的关联（如 with('platform') 产生的 platform 键）：
     * Eloquent toArray() 会把已加载的关联一并输出，前端只需要 'platform.name' 这类字面 key，
     * 不需要整个关联对象。必须在 expandDotColumns 之后执行（展开还要读关联数组取值）。
     */
    protected function stripEagerRelations(array $rows): array
    {
        if (empty($this->autoWithRelations) || empty($rows)) {
            return $rows;
        }
        foreach ($rows as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach ($this->autoWithRelations as $rel) {
                unset($row[$rel]);
            }
            $rows[$i] = $row;
        }
        return $rows;
    }

    /**
     * 从行数组按点式路径取值（config.api_url）；任一段缺失返回 null
     */
    protected function rowPathValue(array $row, string $path)
    {
        $cursor = $row;
        foreach (explode('.', $path) as $segment) {
            if (is_array($cursor)) {
                if (!array_key_exists($segment, $cursor)) {
                    return null;
                }
                $cursor = $cursor[$segment];
                continue;
            }
            if (is_object($cursor)) {
                // 关联对象（Eloquent Model）：支持 getAttribute 与直接属性
                if (method_exists($cursor, 'getAttribute')) {
                    $cursor = $cursor->getAttribute($segment);
                } elseif (isset($cursor->{$segment})) {
                    $cursor = $cursor->{$segment};
                } else {
                    return null;
                }
                continue;
            }
            return null;
        }
        return $cursor;
    }

    /**
     * 按点式路径取行值（config.api_url）：根字段为 JSON 字符串时先解码
     * 兼容 Eloquent Model / 数组行；任一段缺失返回 null
     */
    protected function rowValueByPath($row, string $path)
    {
        $segments = explode('.', $path);
        $root = array_shift($segments);
        if (is_object($row) && method_exists($row, 'getAttribute')) {
            $value = $row->getAttribute($root);
        } elseif (is_array($row)) {
            $value = $row[$root] ?? null;
        } else {
            return null;
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            }
        }
        foreach ($segments as $segment) {
            if (is_array($value)) {
                if (!array_key_exists($segment, $value)) {
                    return null;
                }
                $value = $value[$segment];
                continue;
            }
            if (is_object($value)) {
                // 关联对象（Eloquent Model）：如 $row->user->name
                if (method_exists($value, 'getAttribute')) {
                    $value = $value->getAttribute($segment);
                } elseif (isset($value->{$segment})) {
                    $value = $value->{$segment};
                } else {
                    return null;
                }
                continue;
            }
            return null;
        }
        return $value;
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
     * 弹窗 / 行内展开内容：逐行执行 Column::modal()/expand() 的处理器，
     * 结果附加为 _modal_{prop} / _expand_{prop}（组件数组，前端 ComponentRenderer 渲染）
     *
     * 支持四种内容写法：闭包 / 类名（render|schema|__invoke）/ HTML 模板 / Component 实例
     */
    protected function applyCellContentHandlers($list)
    {
        $grid = $this->resolveGrid();
        if (!$grid) {
            return $list;
        }
        $modals = $grid->modalHandlers();
        $expands = $grid->expandHandlers();
        if (empty($modals) && empty($expands)) {
            return $list;
        }

        foreach ($list as $i => $row) {
            if (!is_object($row) || !method_exists($row, 'setAttribute')) {
                continue;
            }
            foreach ($modals as $prop => $handler) {
                $row->setAttribute('_modal_' . $prop, $this->resolveCellContent($handler, $row, $prop));
            }
            foreach ($expands as $prop => $handler) {
                $row->setAttribute('_expand_' . $prop, $this->resolveCellContent($handler, $row, $prop));
            }
            $list[$i] = $row;
        }

        return $list;
    }

    /**
     * 求值单元格内容处理器 → 组件数组（失败返回 null，不影响列表）
     *
     * @param mixed $handler 闭包 / 类名 / HTML 模板 / Component 实例
     * @param mixed $row     当前行（Eloquent Model）
     */
    protected function resolveCellContent($handler, $row, string $prop): ?array
    {
        $component = \plugin\curd\app\dsl\component\Component::class;

        try {
            if ($handler instanceof $component) {
                return $handler->toArray();
            }

            // 类名：实例化后依次找 render / schema / __invoke
            if (is_string($handler) && class_exists($handler)) {
                $instance = \support\Container::get($handler);
                foreach (['render', 'schema', 'make', 'content'] as $method) {
                    if (method_exists($instance, $method)) {
                        return $component::resolve($instance->{$method}($row));
                    }
                }
                if (is_callable($instance)) {
                    return $component::resolve($instance($row));
                }
                return null;
            }

            $value = $this->rowValue($row, $prop);

            if ($handler instanceof \Closure) {
                $bound = $this->bindRowHandler($handler, $row);
                return $component::resolve($bound($value, $row));
            }
            if (is_callable($handler)) {
                return $component::resolve($handler($value, $row));
            }

            // 字符串（非类名）当 HTML 模板：{prop} 取行字段、{value} 取本列值
            if (is_string($handler)) {
                $html = preg_replace_callback('/\{\s*([a-zA-Z0-9_.]+)\s*\}/', function ($m) use ($value, $row) {
                    if ($m[1] === 'value') {
                        return is_scalar($value) ? (string)$value : '';
                    }
                    $v = $this->rowValue($row, $m[1]);
                    return is_scalar($v) ? (string)$v : '';
                }, $handler);
                return ['type' => 'html', 'html' => $html];
            }

            return $component::resolve($handler);
        } catch (\Throwable $e) {
            return null;
        }
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
        // 点式字段（config.api_url）：根字段 JSON 字符串先解码再按路径取
        if (str_contains($prop, '.')) {
            return $this->rowValueByPath($row, $prop);
        }
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
