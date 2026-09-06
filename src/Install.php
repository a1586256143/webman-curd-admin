<?php
namespace Amcolin\WebmanCurdAdmin;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * webman 2.x 官方应用插件安装器（由 workerman/webman-framework 的
 * support\Plugin::install($event) 在 composer 安装/更新本包时自动调用）。
 *
 * 机制（webman 官方应用插件分发）：
 *   framework 的 support\Plugin::install 读取本次被安装包的 PSR-4，定位到
 *   {命名空间}Install::WEBMAN_PLUGIN 常量，再调用本类的 install()——
 *   把本包内的 plugin/curd 同步到宿主 {项目根}/plugin/curd。
 *   因此宿主只需 `composer require amcolin/webman-curd-admin`，无需任何手动拷贝。
 *
 * 幂等安全策略：
 *   - 目标 plugin/curd 已存在（本地开发中的插件）→ 跳过，绝不覆盖本地改动；
 *   - 仅当目标不存在时整体拷贝，排除 .git/node_modules 等构建目录。
 */
class Install
{
    /**
     * 插件名（必须与包内 plugin/{name} 目录名一致）
     */
    const WEBMAN_PLUGIN = 'curd';

    /**
     * 拷贝时排除的目录/文件（相对插件根）
     */
    protected static array $exclude = ['.git', '.idea', '.vscode', 'node_modules', '__pycache__'];

    /**
     * 安装钩子：由 framework 的 support\Plugin 在 composer 安装/更新时调用。
     * @param bool $isFirst 是否首次安装（framework 传入，本类忽略，行为一致）
     */
    public static function install($isFirst = true): void
    {
        $src = __DIR__ . '/../plugin/curd';
        $root = self::projectRoot();
        $dst = $root . '/plugin/curd';
        if (!is_dir($src)) {
            self::ensureDatabaseConfig($root);
            return;
        }
        if (is_dir($dst)) {
            echo "  [webman-curd-admin] plugin/curd 已存在，跳过拷贝（保留本地版本）\n";
        } else {
            if (!is_dir(dirname($dst))) {
                mkdir(dirname($dst), 0755, true);
            }
            self::copyDir($src, $dst);
            echo "  [webman-curd-admin] 已安装应用插件: plugin/curd\n";
        }
        self::ensureExamples($root);
        self::ensureCurdConfig($root);
        self::ensureDatabaseConfig($root);
    }

    /**
     * 更新钩子：framework 优先调 update()，缺失时退回 install(false)。
     * 这里不重复实现，统一走 install 的幂等逻辑。
     */
    public static function update(): void
    {
        self::install(false);
    }

    /**
     * 卸载钩子：composer 卸载时无精确目标目录上下文，且删除宿主 plugin/
     * 下目录有风险，故不自动删除；请手动删除 plugin/curd 后 reload 生效。
     */
    public static function uninstall(): void
    {
        // no-op（见方法注释）
    }

    /**
     * 确保宿主存在后台 CURD 示例（MyTestController「测试管理」，给用户看的示例文件）：
     *   - 控制器：{项目}/app/controller/admin/api/MyTestController.php
     *   - 模型：  {项目}/app/model/MyTest.php
     * 示例源在包内 examples/ 目录；目标已存在则跳过（宿主可自由修改/删除）。
     * 控制器与模型由插件 ModelRegistry 启动扫描自动登记，路由注册需在宿主
     * config/route.php 加一行（本方法只打印引导，不自动改宿主路由文件）。
     */
    protected static function ensureExamples(string $root): void
    {
        $examplesDir = __DIR__ . '/../examples';
        if (!is_dir($examplesDir)) {
            return;
        }
        $map = [
            'app/controller/admin/api/MyTestController.php' => 'MyTestController.php',
            'app/model/MyTest.php'                          => 'MyTest.php',
        ];
        $created = [];
        foreach ($map as $relative => $source) {
            $dst = $root . '/' . $relative;
            if (is_file($dst)) {
                continue; // 已存在（宿主自建或本包先前落位），绝不覆盖
            }
            $src = $examplesDir . '/' . $source;
            if (!is_file($src)) {
                continue;
            }
            if (!is_dir(dirname($dst))) {
                mkdir(dirname($dst), 0755, true);
            }
            if (@copy($src, $dst)) {
                $created[] = $relative;
            }
        }
        if ($created) {
            echo "  [webman-curd-admin] 已落位后台 CURD 示例（可自由修改/删除）：\n";
            foreach ($created as $rel) {
                echo "      - $rel\n";
            }
            echo "  [webman-curd-admin] 路由注册（宿主 config/route.php，示例已含写法注释）：\n";
            echo "      RouteControllerRegistry::register('/my-test', \\app\\controller\\admin\\api\\MyTestController::class);\n";
            echo "      或加入 RouteControllerRegistry::registerMany([...]) 数组。my_test 表与「测试管理」菜单由安装向导自动创建。\n";
        }
    }

    /**
     * 确保宿主存在 config/curd.php（本插件的插件调参入口）：
     *   - 缺失 → 生成默认模板（仅插件调参，不含数据库）；
     *   - 已存在 → 绝不覆盖（可能已被用户/其他工具写入）。
     *
     * 数据库连接不在这里：由 .env 的 DB_* 键承载（config/database.php 用 env() 读取）。
     * 该文件的顶层同名键覆盖 plugin/curd/config/curd.php 的内置默认值。
     */
    protected static function ensureCurdConfig(string $root): void
    {
        $configDir = $root . '/config';
        $file = $configDir . '/curd.php';
        if (!is_dir($configDir)) {
            return;
        }
        if (is_file($file)) {
            return; // 已有配置，不覆盖
        }
        file_put_contents($file, self::curdConfigTemplate());
        echo "  [webman-curd-admin] 已生成集中配置 config/curd.php（插件调参入口；数据库连接走 .env 的 DB_* 键）\n";
    }

    /**
     * 宿主 config/curd.php 默认模板
     *
     * 只承载【插件调参】；数据库信息不放这里（安装向导写入宿主 .env 的 DB_*，
     * config/database.php 用 env() 读取）。认证库与业务库【同一库】。
     */
    protected static function curdConfigTemplate(): string
    {
        return <<<'PHP'
<?php
/**
 * webman-curd-admin 插件集中配置（首次 composer require 时由 amcolin/webman-curd-admin 自动生成；
 * 已存在则不会覆盖，可自由修改）。
 *
 * 职责边界：
 *   - 本文件只做【插件调参】。数据库连接信息（DB_HOST / DB_PORT / DB_NAME /
 *     DB_USER / DB_PASSWORD）写入宿主 .env，由 config/database.php 用 env() 读取
 *     （Web 安装向导会自动写 .env；手动部署可参考 plugin/curd/env.example）。
 *   - 认证库与业务库【始终同一库】（单库架构），已无 DB_BUSINESS_NAME / 分库选项。
 *   - 顶层键与 plugin/curd/config/curd.php 内置默认值同名时覆盖生效；
 *     不写的键沿用内置默认。
 */
return [
    // 前端挂载前缀（改了需同步重建前端：VITE_BASE_PATH）
    'page_base' => '/app/curd',

    // /api/admin/* 是否强制 RBAC 校验：生产建议 true（默认 admin 角色不受影响）
    'admin_require_permission' => false,

    // 连接名（config/database.php connections 键；单库下两者指向同一 DB_NAME）：
    // 'admin_connection'    => 'mysql',            // 认证库连接名
    // 'business_connection' => 'mysql_business',   // 业务模型默认连接名
];
PHP;
    }

    /**
     * 确保宿主 config/database.php 为可用配置：
     *   - 缺失 → 生成模板（mysql + mysql_business，读 DB_* 键）；
     *   - 是 webman/database 生成的占位模板（含 your_database/your_username）→ 备份后替换；
     *   - 已是自定义配置 → 不动（仅当缺 mysql_business 时提示按模板补）。
     */
    protected static function ensureDatabaseConfig(string $root): void
    {
        $configDir = $root . '/config';
        $file = $configDir . '/database.php';
        if (!is_dir($configDir)) {
            return;
        }
        $needWrite = !is_file($file);
        if (!$needWrite) {
            $content = (string)file_get_contents($file);
            if (!str_contains($content, 'your_database') && !str_contains($content, 'your_username')) {
                if (!str_contains($content, 'mysql_business')) {
                    echo "  [webman-curd-admin] 提示：宿主 config/database.php 未含 mysql_business 连接，业务表建表/迁移会失败；\n";
                    echo "      单库架构下它应与 mysql 指向同一 DB_NAME。可删除 config/database.php 后重跑 composer require/update 由本包重新生成，\n";
                    echo "      或参考 plugin/curd/database.business.example.php 手动补上该连接。\n";
                }
                return; // 已有自定义配置，跳过
            }
            if (@rename($file, $file . '.webman-db.bak') === false) {
                echo "  [webman-curd-admin] 警告：config/database.php 为占位模板但备份失败，跳过替换（请手动配置）\n";
                return;
            }
            echo "  [webman-curd-admin] 检测到 webman/database 占位 config/database.php，已备份为 database.php.webman-db.bak\n";
            $needWrite = true;
        }
        if ($needWrite) {
            file_put_contents($file, self::databaseConfigTemplate());
            echo "  [webman-curd-admin] 已生成 env 驱动的 config/database.php（mysql + mysql_business 读 DB_* 键）\n";
        }
    }

    /**
     * env 驱动的数据库配置模板
     *
     * 连接信息全部取自 .env 的 DB_* 键（安装向导 / 手动部署写入）。单库架构：
     * 认证库与业务库同一库，mysql_business 与 mysql 指向同一 DB_NAME，
     * 已无 DB_BUSINESS_NAME。
     */
    protected static function databaseConfigTemplate(): string
    {
        return <<<'PHP'
<?php
/**
 * webman-curd-admin 自动生成的数据库配置模板（宿主 config/database.php 缺失或为
 * webman/database 占位模板时，由 amcolin/webman-curd-admin 的 src/Install.php 生成，
 * 可自由修改）。
 *
 * 取值：全部来自 .env 的 DB_* 键（Web 安装向导 / install.php 会自动写入 .env，
 * 新项目无需手工配）。【单库架构】：认证与业务同一库——
 *   - mysql          ：认证/管理面库（admin_users / roles / menus / casbin_rule ...）
 *   - mysql_business ：业务模型默认连接名，与 mysql 指向同一 DB_NAME（别名）
 */
return [
    'default' => 'mysql',
    'connections' => [
        'mysql' => [
            'driver'    => 'mysql',
            'host'      => env('DB_HOST', '127.0.0.1'),
            'port'      => env('DB_PORT', '3306'),
            'database'  => env('DB_NAME', 'webman_curd'),
            'username'  => env('DB_USER', 'root'),
            'password'  => env('DB_PASSWORD', ''),
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_general_ci',
            'prefix'    => '',
            'strict'    => true,
            'engine'    => null,
        ],
        'mysql_business' => [
            'driver'    => 'mysql',
            'host'      => env('DB_HOST', '127.0.0.1'),
            'port'      => env('DB_PORT', '3306'),
            'database'  => env('DB_NAME', 'webman_curd'),
            'username'  => env('DB_USER', 'root'),
            'password'  => env('DB_PASSWORD', ''),
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_general_ci',
            'prefix'    => '',
            'strict'    => true,
            'engine'    => null,
        ],
    ],
];
PHP;
    }

    /**
     * 项目根目录：优先 webman base_path()，其次 composer 运行目录，最后回退推导。
     */
    protected static function projectRoot(): string
    {
        if (function_exists('base_path')) {
            return rtrim(base_path(), '/');
        }
        $cwd = getcwd();
        if ($cwd) {
            return rtrim($cwd, '/');
        }
        // 包位于 vendor/amcolin/webman-curd-admin/src
        return dirname(__DIR__, 4);
    }

    /**
     * 递归拷贝目录（排除 self::$exclude 列表）
     */
    protected static function copyDir(string $src, string $dst): void
    {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $item) {
            $rel = substr($item->getPathname(), strlen($src) + 1);
            foreach (self::$exclude as $ex) {
                if ($rel === $ex || str_starts_with($rel, $ex . DIRECTORY_SEPARATOR)) {
                    continue 2;
                }
            }
            if ($item->isDir()) {
                if (!is_dir($dst . '/' . $rel)) {
                    mkdir($dst . '/' . $rel, 0755, true);
                }
                continue;
            }
            $file = $dst . '/' . $rel;
            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0755, true);
            }
            copy($item->getPathname(), $file);
        }
    }
}
