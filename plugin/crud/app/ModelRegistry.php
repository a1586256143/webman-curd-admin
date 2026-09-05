<?php
namespace plugin\crud\app;

use support\Model;

/**
 * 模型注册表（白名单 + ORM 解析）
 *
 * URL 里不允许直接传表名（不安全），统一传**模型类名**（如 APackage），
 * 模型类内自带 $table / $connection，通过 Eloquent ORM 操作数据库。
 * 只有继承 support\Model 的类才允许 CRUD，前端无法通过换表名探查其他表。
 *
 * 模型名约定：直接使用模型类短类名（PascalCase）
 *   - app\model\APackage → 模型名 APackage, 表名 a_packages
 *   - app\model\AdminUser → 模型名 AdminUser, 表名 admin_users
 *   - app\model\ATail     → 模型名 ATail,     表名 a_tails
 *
 * 注册方式（bootstrap.php）：
 *   \plugin\crud\app\ModelRegistry::registerModel(\app\model\APackage::class);
 *   \plugin\crud\app\ModelRegistry::registerModel(\app\model\AdminUser::class);
 *   或批量自动扫描：
 *   \plugin\crud\app\ModelRegistry::scanModels(); // 扫描 app/model 目录（不含 BaseModel）
 *
 * 解析：
 *   ModelRegistry::resolve('APackage')  → 'app\model\APackage'（未注册返回 null）
 *   ModelRegistry::table('APackage')    → 'a_packages'
 *   ModelRegistry::query('APackage')    → Eloquent Builder（已带连接，可链式查询）
 */
class ModelRegistry
{
    /**
     * 注册表：模型名 => 模型类全名
     */
    protected static array $models = [];

    /**
     * 模型名 => 关联控制器类（用于 config 动态配置获取）
     */
    protected static array $controllers = [];

    /**
     * 模型类文件根目录
     */
    protected static string $modelDir = '';

    /**
     * 模型类命名空间
     */
    protected static string $modelNamespace = 'app\model';

    /**
     * 是否允许对「未登记的任意表」做动态解析（模型名反推表名 + eval 生成）。
     * 默认 false（收紧）：只有注册表里的模型 / app\model 下真实存在的模型类
     * 以及 dynamicAllowedTables 里的表名才能被解析，杜绝通过猜模型名访问任意表。
     */
    protected static bool $allowDynamicResolve = false;

    /**
     * 显式允许动态解析的表名（启动时从 crud_configs 已登记的表加载）
     */
    protected static array $dynamicAllowedTables = [];

    /**
     * 开启/关闭「任意表动态解析」开关（默认关闭）
     */
    public static function setAllowDynamicResolve(bool $allow): void
    {
        self::$allowDynamicResolve = $allow;
    }

    /**
     * 设置允许动态解析的表名白名单（crud_configs 已登记的表）
     */
    public static function allowDynamicTables(array $tables): void
    {
        self::$dynamicAllowedTables = array_values(array_unique(array_filter($tables, 'is_string')));
    }

    /**
     * 判断某表是否允许被动态解析（未登记模型的表）
     */
    protected static function dynamicAllowed(string $table): bool
    {
        if (self::$allowDynamicResolve) {
            return true;
        }
        return in_array($table, self::$dynamicAllowedTables, true);
    }

    /**
     * 设置模型目录与命名空间（默认 app/model）
     */
    public static function setModelPath(string $dir, string $namespace): void
    {
        self::$modelDir = rtrim($dir, '/');
        self::$modelNamespace = rtrim($namespace, '\\');
    }

    /**
     * 注册一个模型类
     *
     * @param string $class 全限定类名，如 \app\model\APackage::class
     */
    public static function registerModel(string $class, ?string $controller = null): void
    {
        if (!class_exists($class)) {
            return;
        }
        // 必须是 Eloquent 模型
        if (!is_subclass_of($class, Model::class) && $class !== Model::class) {
            return;
        }
        $short = self::shortName($class);
        self::$models[$short] = $class;
        if ($controller) {
            self::$controllers[$short] = $controller;
        }
    }

    /**
     * 批量注册
     * ['APackage' => \app\model\APackage::class, ...]
     */
    public static function registerModels(array $map): void
    {
        foreach ($map as $name => $class) {
            if (is_string($class) && is_int($name)) {
                // 只给类名，用类名短名注册
                self::registerModel($class);
            } else {
                // 显式指定模型名 → 类
                self::$models[$name] = $class;
            }
        }
    }

    /**
     * 扫描模型目录，自动注册所有模型类
     * 需先 setModelPath()，否则默认 app/model
     */
    public static function scanModels(): int
    {
        $dir = self::$modelDir ?: (string)config('plugin.crud.crud.model_dir', base_path() . '/app/model');
        if (!is_dir($dir)) {
            return 0;
        }

        $count = 0;
        foreach (glob($dir . '/*.php') ?: [] as $file) {
            $base = basename($file, '.php');
            if ($base === 'BaseModel') {
                continue;
            }
            $class = self::$modelNamespace . '\\' . $base;
            if (class_exists($class) && is_subclass_of($class, Model::class)) {
                self::$models[$base] = $class;
                $count++;
            }
        }
        return $count;
    }

    /**
     * 扫描控制器目录，自动把继承 BaseCrudController 的控制器注册为模型关联
     * （供 /api/crud/config?model=xxx 拿页面 grid 配置；无专属 controller 的模型可跳过）
     */
    public static function scanControllers(): int
    {
        // 控制器目录 => 命名空间（兼容 app/controller/api 与 app/controller/admin/api，
        // 默认值见 config/plugin/crud/crud.php controller_dirs，新项目可按需调整）
        // make:crud 默认生成到 app/controller/api，但实际业务控制器可能放在 admin/api 下，
        // 必须两个目录都扫描，否则专属控制器无法与模型关联，通配路由会回退到 DynamicCrudController，
        // 导致 grid()/display 处理器/业务钩子全部失效。
        $dirs = config('plugin.crud.crud.controller_dirs', []);
        $count = 0;
        foreach ($dirs as $dir => $namespace) {
            if (!is_dir($dir)) {
                continue;
            }
            foreach (glob($dir . '/*.php') ?: [] as $file) {
                $base = basename($file, '.php');
                $class = $namespace . '\\' . $base;
                if (class_exists($class)
                    && is_subclass_of($class, controller\base\BaseCrudController::class)) {
                    self::registerFromController($class);
                    $count++;
                }
            }
        }
        return $count;
    }

    /**
     * 取关联控制器类
     */
    public static function controller(string $name): ?string
    {
        $class = self::resolve($name);
        if ($class === null) {
            return null;
        }
        $short = self::shortName($class);
        return self::$controllers[$short] ?? null;
    }

    /**
     * 解析模型名 → 类全名；未注册返回 null
     * 兼容：模型名大小写不敏感（apackage / APackage 均可）
     *
     * 四级查找：
     *   1. 注册表（scanModels 扫描到的模型类）
     *   2. 动态发现：app\model\{Name} 类存在则自动注册（PSR-4 自动加载）
     *   3. 动态生成：模型名反推表名，运行时 eval 生成模型类（零注册即可用）
     *   4. 表名兼容：传入的可能是表名（admin_users / a_packages），
     *      反推模型名后再走上面逻辑
     */
    public static function resolve(string $name): ?string
    {
        if ($name === '') {
            return null;
        }
        if (isset(self::$models[$name])) {
            return self::$models[$name];
        }
        // 大小写不敏感兜底
        foreach (self::$models as $key => $class) {
            if (strcasecmp($key, $name) === 0) {
                return $class;
            }
        }
        // 动态发现：app\model\{Name} 类存在则自动注册
        $guess = self::$modelNamespace . '\\' . $name;
        if ($name !== 'BaseModel' && class_exists($guess)
            && (is_subclass_of($guess, Model::class) || $guess === Model::class)) {
            self::registerModel($guess);
            return $guess;
        }
        // 动态生成：模型名反推表名（AArea → a_area, AdminUser → admin_users）
        // 仅当表在白名单内（crud_configs 已登记 / 显式开启）才允许，防止猜模型名访问任意表
        $table = self::guessTable($name);
        if ($table !== null && self::dynamicAllowed($table)) {
            self::generateModel($name, $table);
            // 生成后再次解析（此时已进注册表）
            return self::$models[$name] ?? null;
        }
        // 表名兼容：传入的是表名（admin_users / a_packages）→ 反推模型名再解析
        // 同样受白名单限制
        $modelName = self::tableNameToModelName($name);
        if ($modelName !== '' && $modelName !== $name && self::dynamicAllowed($name)) {
            $class = self::resolve($modelName);
            if ($class !== null) {
                // 反推出的模型表名必须与传入一致，防止错位
                try {
                    $realTable = (new $class())->getTable();
                    if ($realTable === $name) {
                        self::$models[$name] = $class;
                        return $class;
                    }
                } catch (\Throwable $e) {
                    // 忽略
                }
            }
        }
        return null;
    }

    /**
     * 由模型名反推表名（只返回业务库中真实存在的表）
     *   AArea     → a_area
     *   APackage  → a_packages
     *   ATail     → a_tails
     *   AdminUser → admin_users
     *   AOrder    → a_orders
     *   ABusiness → a_businesses
     * 规则：
     *   1. A + 驼峰（AArea）→ a_{snake_case}，去掉复数 s 也尝试
     *   2. 普通驼峰（AdminUser）→ {snake_case}
     */
    protected static function guessTable(string $name): ?string
    {
        if (!preg_match('/^[A-Z][A-Za-z0-9_]*$/', $name)) {
            return null;
        }

        // 驼峰 → 下划线
        $snake = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
        // AArea → a_area / a_areas；APackage → a_package / a_packages
        $candidates = [];
        if (str_starts_with($name, 'A') && preg_match('/^A[A-Z]/', $name)) {
            $base = substr($snake, 2); // a_area → area
            $candidates[] = 'a_' . $base;
            $candidates[] = 'a_' . $base . 's';
            $candidates[] = 'a_' . $base . 'es';
        } else {
            $candidates[] = $snake;
            $candidates[] = $snake . 's';
        }

        foreach ($candidates as $table) {
            if (self::tableExists($table)) {
                return $table;
            }
        }
        return null;
    }

    /**
     * 表是否存在（业务库）
     */
    protected static function tableExists(string $table): bool
    {
        try {
            $db = CrudDb::businessDb();
            $tables = $db->select('SHOW TABLES');
            foreach ($tables as $row) {
                $name = array_values((array)$row)[0];
                if ($name === $table) {
                    return true;
                }
            }
            return false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 运行时生成模型类（继承 BaseModel，自动连业务库）
     * 生成后写入注册表，避免重复 eval
     */
    protected static function generateModel(string $name, string $table): void
    {
        if (isset(self::$models[$name])) {
            return;
        }
        // 命名校验：只允许类名格式，防注入
        if (!preg_match('/^[A-Z][A-Za-z0-9_]*$/', $name)) {
            return;
        }
        $class = self::$modelNamespace . '\\' . $name;
        // 动态生成的模型继承的基类（config('plugin.crud.crud.base_model_class')，
        $baseClass = (string)config('plugin.crud.crud.base_model_class', '\app\model\BaseModel');
        if ($baseClass === '' || !str_starts_with($baseClass, '\\')) {
            $baseClass = '\\' . $baseClass;
        }
        $code = 'namespace ' . self::$modelNamespace . '; '
              . 'class ' . $name . ' extends ' . $baseClass . ' { '
              . 'protected $table = \'' . addslashes($table) . '\'; '
              . '}';
        eval($code);
        if (class_exists($class)) {
            self::$models[$name] = $class;
        }
    }

    /**
     * 是否已注册
     */
    public static function exists(string $name): bool
    {
        return self::resolve($name) !== null;
    }

    /**
     * 取模型实例（未注册返回 null）
     */
    public static function instance(string $name): ?Model
    {
        $class = self::resolve($name);
        if ($class === null) {
            return null;
        }
        return new $class();
    }

    /**
     * 解析模型名 → 表名（从模型实例 getTable() 读取，无需再注册表名映射）
     */
    public static function table(string $name): ?string
    {
        $model = self::instance($name);
        return $model ? $model->getTable() : null;
    }

    /**
     * 解析模型名 → Eloquent Builder（已带连接）
     * 未注册返回 null
     */
    public static function query(string $name)
    {
        $model = self::instance($name);
        if ($model === null) {
            return null;
        }
        $connection = $model->getConnectionName() ?: CrudDb::business();
        return $model::on($connection)->newQuery();
    }

    /**
     * 取模型连接名
     */
    public static function connection(string $name): ?string
    {
        $model = self::instance($name);
        return $model ? ($model->getConnectionName() ?: CrudDb::business()) : null;
    }

    /**
     * 解析模型名/表名 → [连接名, 真实表名]
     * 未注册返回 null
     * 供 schema/generate 等接口按「真实表名 + 正确连接」查表结构，
     * 避免把模型名（如 AdminUser）直接拼进 SHOW FULL COLUMNS 报 1146。
     *   AdminUser  → ['mysql', 'admin_users']（主库）
     *   name_types → ['mysql_business', 'name_types']（业务库）
     */
    public static function resolveTable(string $name): ?array
    {
        $model = self::instance($name);
        if ($model === null) {
            return null;
        }
        return [$model->getConnectionName() ?: CrudDb::business(), $model->getTable()];
    }

    /**
     * 列出全部模型名
     */
    public static function names(): array
    {
        return array_keys(self::$models);
    }

    /**
     * 列出全部 [name => class]
     */
    public static function all(): array
    {
        return self::$models;
    }

    /**
     * 表名反查（找到第一个使用此表的模型名）
     */
    public static function modelByTable(string $table): ?string
    {
        foreach (self::$models as $name => $class) {
            if (class_exists($class)) {
                $model = new $class();
                if ($model->getTable() === $table) {
                    return $name;
                }
            }
        }
        return null;
    }

    /**
     * 表名 → 模型名（反推，含动态生成场景）
     *   admin_users  → AdminUser
     *   a_packages   → APackage
     *   a_tails      → ATail
     *   a_bills      → ABill
     * 规则：表名去 a_/t_ 前缀 → 去复数 → 驼峰 → 加 A 前缀（无前缀则直接驼峰）
     */
    public static function tableNameToModelName(string $table): string
    {
        if ($table === '') {
            return '';
        }
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
     * 由控制器类自动注册（模型名 = 控制器类名去 Controller 后缀）
     * 要求控制器继承 BaseCrudController
     */
    public static function registerFromController(string $controllerClass): void
    {
        if (!class_exists($controllerClass)) {
            return;
        }
        if (!is_subclass_of($controllerClass, controller\base\BaseCrudController::class)) {
            return;
        }

        $short = $controllerClass;
        $pos = strrpos($controllerClass, '\\');
        if ($pos !== false) {
            $short = substr($controllerClass, $pos + 1);
        }
        $modelName = preg_replace('/Controller$/', '', $short);

        // 优先：反射读控制器声明的 $modelClass 属性（getDefaultProperties 不实例化、
        // 不执行 grid()，worker 启动扫描阶段绝对安全）。声明了就不执行任何业务代码。
        try {
            $defaults = (new \ReflectionClass($controllerClass))->getDefaultProperties();
            $declared = $defaults['modelClass'] ?? '';
            if (is_string($declared) && $declared !== '' && class_exists($declared)
                && is_subclass_of($declared, Model::class)) {
                self::registerModel($declared, $controllerClass);
                return;
            }
        } catch (\Throwable $e) {
            // 反射失败则继续兼容旧路径
        }

        $controller = new $controllerClass();

        // Grid 构造时传入模型实例是当前推荐写法，优先从 Grid 读取真实模型。
        // 注意：仅在未声明 $modelClass 时兜底调用（会执行 grid()，需保证启动期安全）。
        if (method_exists($controller, 'getGridModel')) {
            try {
                $gridModel = $controller->getGridModel();
                if ($gridModel instanceof Model) {
                    self::registerModel(get_class($gridModel), $controllerClass);
                    return;
                }
            } catch (\Throwable $e) {
                // Grid 构建失败时继续兼容旧写法
            }
        }

        // 兼容旧控制器：若声明了模型类，直接注册（带上 controller 关联）。
        try {
            $reflection = new \ReflectionMethod($controllerClass, 'model');
            $reflection->setAccessible(true);
            $modelClass = $reflection->invoke($controller);
            if (is_string($modelClass) && $modelClass !== '' && class_exists($modelClass)) {
                self::registerModel($modelClass, $controllerClass);
                return;
            }
        } catch (\Throwable $e) {
            // 无 model() 方法，继续按表名推测
        }

        // 否则尝试按命名空间推测模型：app\model\{ModelName}
        $guess = self::$modelNamespace . '\\' . $modelName;
        if (class_exists($guess) && is_subclass_of($guess, Model::class)) {
            self::registerModel($guess, $controllerClass);
        }
    }

    /**
     * 取类短名
     */
    protected static function shortName(string $class): string
    {
        $pos = strrpos($class, '\\');
        return $pos === false ? $class : substr($class, $pos + 1);
    }
}
