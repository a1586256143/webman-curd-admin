<?php
namespace plugin\curd\app\controller;

use plugin\curd\app\controller\base\BaseCurdController;
use plugin\curd\app\controller\base\DynamicCurdController;
use plugin\curd\app\CurdConfigRegistry;
use plugin\curd\app\CurdDb;
use plugin\curd\app\ModelRegistry;
use plugin\curd\app\model\CurdConfigs;
use support\Request;

/**
 * CURD 配置管理 + 通配路由入口
 *
 * 职责划分（2026-08 重构后）：
 * 1. 配置管理能力：configList/config/allConfigs/saveConfig/tables/schema/generate —— 保留在本类
 * 2. 通配 CURD（/api/curd/model/{model}/*）—— 不再复制实现，统一收敛到 BaseCurdController：
 *    - 模型有专属业务控制器（继承 BaseCurdController，如 PackageController）→ 直接复用其实例，
 *      业务钩子（createdAfter 等）与 grid 配置天然生效
 *    - 没有专属控制器 → 用 DynamicCurdController 按模型动态复用基类能力
 *    这样 CURD 逻辑只有 BaseCurdController 一份，删除了本类里重复的 list/export 等实现。
 */
class CurdController
{
    public static function getCurdConfig(){
        // 明确走认证库连接（curd_configs 位于认证库）
        return \plugin\curd\app\model\CurdConfigs::on(CurdDb::admin())->newQuery();
    }

    /**
     * 解析模型名 → 模型类全名（白名单）
     * 未注册直接 404，杜绝任意表名访问
     */
    protected function resolveModel(string $model): ?string
    {
        if ($model === '') {
            return null;
        }
        // 直接查 ModelRegistry（模型名 = 模型类短名，如 APackage）
        $class = ModelRegistry::resolve($model);
        if ($class !== null) {
            return $class;
        }
        // fallback：curd_configs 表里的 table_name 反查已注册模型
        // （兼容历史配置：保存时落库的是 table_name）
        try {
            $row = CurdConfigs::firstByTableName($model);
            if ($row) {
                $modelName = ModelRegistry::modelByTable($row->table_name);
                if ($modelName !== null) {
                    return ModelRegistry::resolve($modelName);
                }
            }
        } catch (\Throwable $e) {
            // 忽略
        }
        return null;
    }

    /**
     * 兼容旧方法名：返回模型对应的表名
     */
    protected function resolveTable(string $model): ?string
    {
        $class = $this->resolveModel($model);
        if ($class === null) {
            return null;
        }
        return (new $class())->getTable();
    }

    /**
     * 获取所有已注册的 CURD 配置列表（主库）
     */
    public function configList()
    {
        $list = CurdConfigRegistry::all();
        return json(['code' => 200, 'msg' => 'success', 'data' => $list]);
    }

    /**
     * 获取单个 CURD 配置（主库）
     * 支持 model / table / route_path / key / path 参数
     * 优先级：model → table → route_path → path → key
     */
    public function config(Request $request)
    {
        $model     = $request->get('model');
        $table     = $request->get('table');
        $routePath = $request->get('route_path');
        $key       = $request->get('key');
        $pathParam = $request->get('path');
        $identifier = $model ?: $table ?: $routePath ?: $pathParam ?: $key;

        if (!$identifier) {
            return json(['code' => 400, 'msg' => '模型名、表名、路由路径或配置键不能为空']);
        }

        // ---- 1. 模型名直查（最高优先级，URL 不暴露表名） ----
        if ($model && ModelRegistry::exists($model)) {
            $controller = ModelRegistry::controller($model);
            if ($controller) {
                $instance = new $controller();
                $frontendConfig = $instance->config();
                $frontendConfig['_modelName'] = $model;
                unset($frontendConfig['table'], $frontendConfig['_tableName']);
                if (!isset($frontendConfig['_routePath'])) {
                    $frontendConfig['_routePath'] = null;
                }
                return json(['code' => 200, 'msg' => 'success', 'data' => $frontendConfig]);
            }
        }

        // 优先按 table_name 查找（主库 curd_configs）
        $configRow = CurdConfigs::firstByTableName($identifier);

        // 再按 route_path 查找（严格大小写敏感，注册路径与访问路径必须一致）
        if (!$configRow) {
            try {
                $configRow = CurdConfigs::firstByRoutePath($identifier);
            } catch (\Throwable $e) {
                // route_path 字段不存在，忽略
            }
        }
        if (!$configRow && is_string($identifier) && str_starts_with($identifier, '/')) {
            try {
                $configRow = CurdConfigs::firstByRoutePath($identifier);
            } catch (\Throwable $e) {
                // route_path 字段不存在，忽略
            }
        }

        if ($configRow) {
            $config = json_decode($configRow->config, true);
            $config['_routePath'] = $configRow->route_path;
            // 只暴露模型名，不暴露表名
            $config['_modelName'] = ModelRegistry::modelByTable($configRow->table_name)
                ?? $this->tableNameToModelName($configRow->table_name);
            unset($config['table'], $config['_tableName']);
            return json(['code' => 200, 'msg' => 'success', 'data' => $config]);
        }

        // 如果数据库没有，尝试从注册表获取
        $config = CurdConfigRegistry::get($identifier);
        if ($config) {
            return json(['code' => 200, 'msg' => 'success', 'data' => $config->toFrontendConfig()]);
        }

        // 再尝试从路由→控制器注册表获取（继承 BaseCurdController 的控制器动态配置）
        if (is_string($identifier)) {
            $controller = \plugin\curd\app\RouteControllerRegistry::instance($identifier);
            if ($controller) {
                $frontendConfig = $controller->config();
                // 补充 route_path，供前端回显
                if (!isset($frontendConfig['_routePath'])) {
                    $frontendConfig['_routePath'] = $identifier;
                }
                // 只暴露模型名，不暴露表名
                // 优先用 config() 已输出的 _modelName（BaseCurdController 已改造）
                $modelName = $frontendConfig['_modelName'] ?? '';
                if (!$modelName) {
                    // 兜底：反射调用 controller 受保护的 model() 反查
                    $modelClass = $this->callControllerModel($controller);
                    $realTable = $modelClass ? (new $modelClass())->getTable() : '';
                    $modelName = ModelRegistry::modelByTable($realTable)
                        ?? $this->tableNameToModelName($realTable);
                }
                $frontendConfig['_modelName'] = $modelName;
                unset($frontendConfig['table'], $frontendConfig['_tableName']);
                return json(['code' => 200, 'msg' => 'success', 'data' => $frontendConfig]);
            }
        }

        return json(['code' => 404, 'msg' => '配置不存在，请先在配置生成器中生成并保存']);
    }

    /**
     * 反射调用控制器受保护的 model() 方法，返回模型类全名
     */
    protected function callControllerModel($controller): ?string
    {
        try {
            $reflection = new \ReflectionMethod($controller, 'model');
            $reflection->setAccessible(true);
            $modelClass = $reflection->invoke($controller);
            return is_string($modelClass) && $modelClass !== '' ? $modelClass : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 表名 → 模型名（兜底，从表名反推驼峰）
     * 与 MakeCurdCommand::tableToModelName 规则一致：
     *   a_tails     → ATail
     *   a_packages  → APackage
     *   admin_users → AdminUser
     */
    protected function tableNameToModelName(string $table): string
    {
        if ($table === '') return '';
        $hasPrefix = (bool)preg_match('/^(a_|t_)/', $table);
        $name = preg_replace('/^(a_|t_)/', '', $table);

        // 复数 → 单数（简单规则，按优先级）
        if (str_ends_with($name, 'sses') && strlen($name) > 5) {
            $name = substr($name, 0, -2);
        } elseif (str_ends_with($name, 'ies') && strlen($name) > 4) {
            $prev = substr($name, -4, 1);
            if (!in_array($prev, ['a', 'e', 'i', 'o', 'u'], true)) {
                $name = substr($name, 0, -3) . 'y';
            } else {
                $name = substr($name, 0, -1);
            }
        } elseif (str_ends_with($name, 's') && !str_ends_with($name, 'ss')
            && !str_ends_with($name, 'us') && !str_ends_with($name, 'is')) {
            $name = substr($name, 0, -1);
        }

        $parts = array_filter(explode('_', $name));
        $camel = '';
        foreach ($parts as $p) {
            $camel .= ucfirst($p);
        }
        $camel = $camel ?: ucfirst($name);

        return $hasPrefix ? 'A' . $camel : $camel;
    }

    /**
     * 获取所有 CURD 配置（主库）
     */
    public function allConfigs()
    {
        $configs = CurdConfigRegistry::allConfigs();
        return json(['code' => 200, 'msg' => 'success', 'data' => $configs]);
    }

    /**
     * 保存 CURD 配置到主库
     */
    public function saveConfig(Request $request)
    {
        $data      = $request->post();
        $table     = $data['table']     ?? '';
        $title     = $data['title']     ?? $table;
        $pageName  = $data['pageName']  ?? $table;
        $routePath = $data['route_path'] ?? ($data['routePath'] ?? '');
        $config    = $data;

        if (!$table) {
            return json(['code' => 400, 'msg' => '表名不能为空']);
        }

        if ($routePath && !str_starts_with($routePath, '/')) {
            $routePath = '/' . $routePath;
        }
        if (!$routePath) {
            $routePath = null;
        }

        $exists     = self::getCurdConfig()->where('table_name', $table)->exists();
        $configJson = json_encode($config, JSON_UNESCAPED_UNICODE);
        $now        = date('Y-m-d H:i:s');

        try {
            if ($exists) {
                $updateData = [
                    'title'      => $title,
                    'page_name'  => $pageName,
                    'config'     => $configJson,
                    'updated_at' => $now,
                ];
                if ($routePath !== null) {
                    $updateData['route_path'] = $routePath;
                }
                self::getCurdConfig()->where('table_name', $table)->update($updateData);
            } else {
                $insertData = [
                    'table_name' => $table,
                    'title'      => $title,
                    'page_name'  => $pageName,
                    'route_path' => $routePath,
                    'config'     => $configJson,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                self::getCurdConfig()->insert($insertData);
            }
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'route_path')) {
                if ($exists) {
                    self::getCurdConfig()->where('table_name', $table)->update([
                        'title'      => $title,
                        'page_name'  => $pageName,
                        'config'     => $configJson,
                        'updated_at' => $now,
                    ]);
                } else {
                    self::getCurdConfig()->insert([
                        'table_name' => $table,
                        'title'      => $title,
                        'page_name'  => $pageName,
                        'config'     => $configJson,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
                return json(['code' => 200, 'msg' => '配置保存成功！建议执行 SQL 添加 route_path 字段：ALTER TABLE curd_configs ADD COLUMN route_path VARCHAR(255) NULL UNIQUE AFTER page_name;']);
            }
            throw $e;
        }

        return json(['code' => 200, 'msg' => '配置保存成功']);
    }

    /**
     * 获取业务库连接
     */
    protected function businessDb()
    {
        return CurdDb::businessDb();
    }

    /**
     * 获取业务库所有表
     */
    public function tables()
    {
        $tables = $this->businessDb()->select('SHOW TABLES');
        $tableList = [];
        foreach ($tables as $table) {
            $tableName = array_values((array)$table)[0];
            $tableList[] = [
                'name'    => $tableName,
                'comment' => $this->getTableComment($tableName)
            ];
        }
        return json(['code' => 200, 'msg' => 'success', 'data' => $tableList]);
    }

    /**
     * 获取已注册的模型列表（用于下拉数据源）
     * 返回 [{ name: 'AdminUser', class: '...', table: 'admin_users', connection: 'mysql' }, ...]
     */
    public function models()
    {
        ModelRegistry::scanModels();
        $list = [];
        foreach (ModelRegistry::all() as $name => $class) {
            if (!class_exists($class)) continue;
            $model = new $class();
            $list[] = [
                'name'       => $name,
                'class'      => $class,
                'table'      => $model->getTable(),
                'connection' => $model->getConnectionName() ?: CurdDb::business(),
            ];
        }
        usort($list, fn($a, $b) => strcmp($a['name'], $b['name']));
        return json(['code' => 200, 'msg' => 'success', 'data' => $list]);
    }

    /**
     * 获取业务表结构
     */
    public function schema(Request $request)
    {
        $table = $request->get('table');
        if (!$table) {
            return json(['code' => 400, 'msg' => '表名不能为空']);
        }
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            return json(['code' => 400, 'msg' => '非法表名']);
        }

        // 优先解析为已注册模型的真实表名（模型名/表名均可，如 AdminUser → admin_users@主库、name_types@业务库）
        $resolved = ModelRegistry::resolveTable($table);
        if ($resolved !== null) {
            [$conn, $realTable] = $resolved;
            $columns = Db::connection($conn)->select("SHOW FULL COLUMNS FROM `{$realTable}`");
        } else {
            // 未注册模型：必须是业务库真实存在的表（白名单校验，防越权查任意库/表）
            $exists = false;
            foreach ($this->businessDb()->select('SHOW TABLES') as $row) {
                if ((array_values((array)$row)[0]) === $table) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                return json(['code' => 404, 'msg' => "表 {$table} 不存在或未授权"]);
            }
            $columns = $this->businessDb()->select("SHOW FULL COLUMNS FROM `{$table}`");
        }

        $schema = [];
        foreach ($columns as $col) {
            $field     = $col->Field;
            $type      = $col->Type;
            $comment   = $col->Comment ?: $field;
            $fieldType = $this->inferFieldType($field, $type);

            $schema[] = [
                'field'     => $field,
                'type'      => $type,
                'comment'   => $comment,
                'nullable'  => $col->Null === 'YES',
                'key'       => $col->Key,
                'default'   => $col->Default,
                'inputType' => $fieldType,
            ];
        }

        return json(['code' => 200, 'msg' => 'success', 'data' => $schema]);
    }

    /**
     * 生成 CURD 配置（读取业务表结构）
     */
    public function generate(Request $request)
    {
        $table = $request->get('table');
        $title = $request->get('title', $table);

        if (!$table) {
            return json(['code' => 400, 'msg' => '表名不能为空']);
        }
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            return json(['code' => 400, 'msg' => '非法表名']);
        }

        $columns = $this->businessDb()->select("SHOW FULL COLUMNS FROM `{$table}`");

        $columnsConfig = [];
        $searchConfig  = [];
        $formConfig    = [];
        $excludeFields  = ['password', 'delete_at', 'updated_at', 'deleted_at'];

        foreach ($columns as $col) {
            $field  = $col->Field;
            $type   = $col->Type;
            $comment = $col->Comment ?: $field;
            $isPrimary = $col->Key === 'PRI';
            $isAutoIncrement = strpos($col->Extra, 'auto_increment') !== false;

            if (in_array($field, $excludeFields) || ($isPrimary && $isAutoIncrement)) {
                if ($field === 'id' && $isPrimary) {
                    $columnsConfig[] = [
                        'prop'  => $field,
                        'label' => $this->tl($comment),
                        'width' => 80,
                        'align' => 'center'
                    ];
                }
                continue;
            }

            $inputType  = $this->inferFieldType($field, $type);
            // 可搜索字段：input/select/date/daterange 是直接支持的；number/datetime 在 searchConfig 阶段会转为 input/daterange
            $isSearchable = in_array($inputType, ['input', 'select', 'date', 'daterange', 'number', 'datetime']);

            // 智能 label：COMMENT 含枚举说明时截断前缀；COMMENT 等于字段名时做翻译
            $label = $this->smartLabel($comment, $field);

            // 尝试从 COMMENT 中解析枚举 options（用于 select 字段）
            $enumOptions = $this->parseCommentOptions($comment);
            $hasEnum = !empty($enumOptions);

            $columnConfig = [
                'prop'  => $field,
                'label' => $label,
            ];

            // 兜底：每个列都写 type（前端生成器回填依赖 type 字段；下方 switch/时间/status 分支会覆盖）
            // 修复：之前只有 switch / created_at / status / select+枚举 才设 type，其余列缺失 type，
            //       导致生成器打开时 savedType=undefined，回填跳过，类型显示与接口不一致
            $columnConfig['type'] = match ($inputType) {
                'input'     => 'input',
                'textarea'  => 'textarea',
                'number'    => 'number',
                'select'    => 'select',
                'date'      => 'date',
                'datetime'  => 'datetime',
                'daterange' => 'daterange',
                default     => 'input',
            };

            if ($inputType === 'switch') {
                $columnConfig['type'] = 'switch';
                $columnConfig['activeValue'] = 1;
                $columnConfig['inactiveValue'] = 0;
            } elseif (in_array($field, ['created_at', 'updated_at', 'add_time'])) {
                $columnConfig['type'] = 'datetime';
                $columnConfig['width'] = 180;
            } elseif (strpos($field, '_status') !== false || strpos($field, 'status') !== false) {
                $columnConfig['type'] = 'tag';
            }

            // select 类型且有解析出的枚举：把 options 和 map 一起写入列配置
            if ($inputType === 'select' && $hasEnum) {
                $columnConfig['type'] = 'tag';
                $map = [];
                foreach ($enumOptions as $opt) {
                    $map[(string)$opt['value']] = ['label' => $opt['label'], 'type' => 'primary'];
                }
                // 强转 stdClass：0/1 等连续整数键会被 json_encode 序列化成数组丢失键值
                $columnConfig['map'] = (object)$map;
            }

            $columnsConfig[] = $columnConfig;

            if ($isSearchable && !in_array($field, ['id', 'created_at', 'updated_at'])) {
                $searchItem = [
                    'prop'  => $field,
                    'label' => $label,
                    'type'  => $inputType === 'number' ? 'input' : $inputType,
                ];

                // select 类型填入 options（自动支持有全部选项）
                if ($inputType === 'select' && $hasEnum) {
                    $searchItem['options'] = array_merge(
                        [['value' => '', 'label' => '全部']],
                        $enumOptions
                    );
                }

                // datetime 在搜索栏转为 daterange（GenericCurd 搜索栏支持 date/daterange）
                if ($searchItem['type'] === 'datetime') {
                    $searchItem['type'] = 'daterange';
                }

                $searchConfig[] = $searchItem;
            }

            if (!in_array($field, ['created_at', 'updated_at', 'add_time'])) {
                $formField = [
                    'prop' => $field,
                    'label' => $label,
                    'type' => $inputType,
                ];

                if ($field === 'id') {
                    $formField['disabled'] = true;
                }

                if (strpos($field, '_status') !== false || $field === 'status') {
                    $formField['type'] = 'radio';
                    $formField['options'] = [
                        ['label' => '禁用', 'value' => 0],
                        ['label' => '启用', 'value' => 1]
                    ];
                    $formField['defaultValue'] = 1;
                }

                // select 类型且有解析出的枚举：写入表单 options
                if ($inputType === 'select' && $hasEnum) {
                    $formField['options'] = $enumOptions;
                }

                if (in_array($field, ['created_at', 'updated_at'])) {
                    $formField['type'] = 'datetime';
                }

                $formConfig[] = $formField;
            }
        }

        $config = [
            'title'      => $title,
            'table'      => $table,
            'columns'    => $columnsConfig,
            'search'     => $searchConfig,
            'formFields' => $formConfig,
        ];

        return json(['code' => 200, 'msg' => 'success', 'data' => $config]);
    }

    // ============================================================
    // 通配 CURD：统一收敛到 BaseCurdController（薄转发）
    // ============================================================

    /**
     * 解析模型名 → BaseCurdController 实例
     * 优先级：专属业务控制器（继承 BaseCurdController）→ DynamicCurdController 兜底
     */
    protected function resolveCurd(string $model): ?BaseCurdController
    {
        // 1. 有专属业务控制器（如 PackageController，继承 BaseCurdController 且带 grid/钩子）→ 复用其实例
        $controller = ModelRegistry::controller($model);
        if ($controller !== null && class_exists($controller)
            && is_subclass_of($controller, BaseCurdController::class)) {
            return new $controller();
        }

        // 2. 无专属控制器 → 解析模型类，动态复用基类能力
        $modelClass = $this->resolveModel($model);
        if ($modelClass === null) {
            return null;
        }
        return DynamicCurdController::forModel($modelClass);
    }

    /**
     * 通用列表（业务库）
     */
    public function list($model, Request $request)
    {
        $curd = $this->resolveCurd($model);
        if ($curd === null) {
            return json(['code' => 404, 'msg' => "模型 {$model} 未注册"]);
        }
        return $curd->index($request);
    }

    /**
     * 通用新增（业务库）
     */
    public function add($model, Request $request)
    {
        $curd = $this->resolveCurd($model);
        if ($curd === null) {
            return json(['code' => 404, 'msg' => "模型 {$model} 未注册"]);
        }
        return $curd->add($request);
    }

    /**
     * 通用更新（业务库）
     */
    public function update($model, Request $request)
    {
        $curd = $this->resolveCurd($model);
        if ($curd === null) {
            return json(['code' => 404, 'msg' => "模型 {$model} 未注册"]);
        }
        return $curd->update($request);
    }

    /**
     * 通用删除（业务库，支持软删除）
     */
    public function delete($model, Request $request)
    {
        $curd = $this->resolveCurd($model);
        if ($curd === null) {
            return json(['code' => 404, 'msg' => "模型 {$model} 未注册"]);
        }
        return $curd->delete($request);
    }

    /**
     * 自定义操作分发（Action DSL ->handler('xxx') 自动指向这里）
     * URL: POST /api/curd/model/{model}/action/{name}
     * 由业务控制器实现 action{Name}(Request $request) 处理业务
     */
    public function action($model, $name, Request $request)
    {
        $curd = $this->resolveCurd($model);
        if ($curd === null) {
            return json(['code' => 404, 'msg' => "模型 {$model} 未注册"]);
        }
        return $curd->action($name, $request);
    }

    /**
     * 通用批量删除（业务库，支持软删除）
     */
    public function batchDelete($model, Request $request)
    {
        $curd = $this->resolveCurd($model);
        if ($curd === null) {
            return json(['code' => 404, 'msg' => "模型 {$model} 未注册"]);
        }
        return $curd->batchDelete($request);
    }

    /**
     * 通用导出（业务库，CSV 带 BOM）
     */
    public function export($model, Request $request)
    {
        $curd = $this->resolveCurd($model);
        if ($curd === null) {
            return json(['code' => 404, 'msg' => "模型 {$model} 未注册"]);
        }
        return $curd->export($request);
    }

    /**
     * 获取表注释（业务库）
     */
    protected function getTableComment($table)
    {
        $result = $this->businessDb()->select("SHOW TABLE STATUS LIKE '{$table}'");
        if (!empty($result)) {
            return $result[0]->Comment ?? $table;
        }
        return $table;
    }

    /**
     * 根据字段名和类型推断表单类型
     */
    protected function inferFieldType($field, $type)
    {
        $type = strtolower($type);

        // 枚举型字段（type / status / category 等）→ select
        // 必须放在数字判断之前，否则 tinyint/int 类型的 type/status 会被错误归类为 number
        if (strpos($field, 'status') !== false || strpos($field, 'type') !== false || strpos($field, 'category') !== false) {
            return 'select';
        }

        // 时间字段（含 timestamp / datetime / date / time）
        if (preg_match('/(date|time|timestamp)/i', $type)) {
            if (strpos($type, 'range') !== false) {
                return 'daterange';
            }
            return 'datetime';
        }

        if (strpos($type, 'int') !== false || strpos($type, 'decimal') !== false || strpos($type, 'float') !== false) {
            return 'number';
        }

        if (strpos($type, 'text') !== false) {
            return 'textarea';
        }

        return 'input';
    }

    /**
     * 智能 label 处理：
     * - COMMENT 是英文/字段名（如 "package_id"）→ 简单转为中文友好 label
     * - COMMENT 含枚举说明（如 "类型 1订购黑名单 2投诉黑名单"）→ 截取到第一个数字前作为 label
     * - 其他情况 → 原样返回 COMMENT
     */
    protected function smartLabel(string $comment, string $field): string
    {
        $comment = trim($comment);
        if ($comment === '' || $comment === '' || strcasecmp($comment, $field) === 0) {
            return $this->fieldToLabel($field);
        }

        // 含枚举说明（"类型 1xxx 2yyy"）：截到第一个数字之前作为 label
        if (preg_match('/^(.+?)\s*\d+/u', $comment, $m)) {
            $candidate = trim($m[1]);
            // 截取结果不能太短（至少 2 字符）且不能是字段名本身
            if (mb_strlen($candidate) >= 2 && strcasecmp($candidate, $field) !== 0) {
                return $this->tl($candidate);
            }
        }

        return $this->tl($comment);
    }

    /**
     * 字段名 → 中文友好 label（简易映射，未匹配则原样返回）
     * 所有中文标题统一经 trans() 处理，便于多语言
     */
    protected function fieldToLabel(string $field): string
    {
        $map = [
            'id'         => 'field.id',
            'mobile'     => 'field.mobile',
            'phone'      => 'field.phone',
            'email'      => 'field.email',
            'name'       => 'field.name',
            'title'      => 'field.title',
            'status'     => 'field.status',
            'type'       => 'field.type',
            'remark'     => 'field.remark',
            'description'=> 'field.description',
            'prov'       => 'field.prov',
            'city'       => 'field.city',
            'qid'        => 'field.qid',
            'package_id' => 'field.package_id',
            'business_id'=> 'field.business_id',
            'channel_id' => 'field.channel_id',
            'user_id'    => 'field.user_id',
            'order_id'   => 'field.order_id',
            'created_at' => 'field.created_at',
            'updated_at' => 'field.updated_at',
            'deleted_at' => 'field.deleted_at',
            'day'        => 'field.day',
            'amount'     => 'field.amount',
            'price'      => 'field.price',
            'total'      => 'field.total',
            'count'      => 'field.count',
            'deduct'     => 'field.deduct',
        ];

        if (isset($map[$field])) {
            return $this->tl($map[$field]);
        }
        // 去除下划线 + 驼峰
        $formatted = preg_replace('/_id$/', 'ID', $field);
        $formatted = str_replace('_', ' ', $formatted);
        return $this->tl(ucwords($formatted));
    }

    /**
     * 解析 COMMENT 中的枚举说明（如 "类型 1订购黑名单 2投诉黑名单"）
     * 返回 [{value:1, label:'订购黑名单'}, ...]；解析失败返回 []
     */
    protected function parseCommentOptions(string $comment): array
    {
        $comment = trim($comment);
        $options = [];

        // 匹配 "数字+可选分隔符(:：= )+文本" 序列
        if (preg_match_all('/(\d+)\s*[:：= ]?\s*([^\d]+?)(?=\s*\d+\s*[:：= ]?|\s*$)/u', $comment, $matches)) {
            foreach ($matches[1] as $i => $value) {
                $label = trim($matches[2][$i]);
                if ($label !== '') {
                    $options[] = ['value' => (int)$value, 'label' => $label];
                }
            }
        }
        return $options;
    }
}
