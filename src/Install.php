<?php
namespace Huafei\WebmanCrud;

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
 *   把本包内的 plugin/crud 同步到宿主 {项目根}/plugin/crud。
 *   因此宿主只需 `composer require huafei/webman-crud`，无需任何手动拷贝。
 *
 * 幂等安全策略：
 *   - 目标 plugin/crud 已存在（本地开发中的插件）→ 跳过，绝不覆盖本地改动；
 *   - 仅当目标不存在时整体拷贝，排除 .git/node_modules 等构建目录。
 */
class Install
{
    /**
     * 插件名（必须与包内 plugin/{name} 目录名一致）
     */
    const WEBMAN_PLUGIN = 'crud';

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
        $src = __DIR__ . '/../plugin/crud';
        $root = self::projectRoot();
        $dst = $root . '/plugin/crud';
        if (!is_dir($src)) {
            self::ensureDatabaseConfig($root);
            return;
        }
        if (is_dir($dst)) {
            echo "  [webman-crud] plugin/crud 已存在，跳过拷贝（保留本地版本）\n";
        } else {
            if (!is_dir(dirname($dst))) {
                mkdir(dirname($dst), 0755, true);
            }
            self::copyDir($src, $dst);
            echo "  [webman-crud] 已安装应用插件: plugin/crud\n";
        }
        self::ensureCrudConfig($root);
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
     * 下目录有风险，故不自动删除；请手动删除 plugin/crud 后 reload 生效。
     */
    public static function uninstall(): void
    {
        // no-op（见方法注释）
    }

    /**
     * 确保宿主存在 config/crud.php（本插件的集中配置入口）：
     *   - 缺失 → 生成默认模板（database 段 + 插件调参说明）；
     *   - 已存在 → 绝不覆盖（可能已被用户/其他工具写入）。
     *
     * 该文件是「新项目无需配 .env」的关键：config/database.php 与插件配置
     * （plugin/crud/config/crud.php）都会优先读它。
     */
    protected static function ensureCrudConfig(string $root): void
    {
        $configDir = $root . '/config';
        $file = $configDir . '/crud.php';
        if (!is_dir($configDir)) {
            return;
        }
        if (is_file($file)) {
            return; // 已有配置，不覆盖
        }
        file_put_contents($file, self::crudConfigTemplate());
        echo "  [webman-crud] 已生成集中配置 config/crud.php（数据库与插件调参入口，不再依赖 .env）\n";
    }

    /**
     * 宿主 config/crud.php 默认模板
     */
    protected static function crudConfigTemplate(): string
    {
        return <<<'PHP'
<?php
/**
 * webman-crud 插件集中配置（首次 composer require 时由 huafei/webman-crud 自动生成；
 * 已存在则不会覆盖，可自由修改）。新项目**无需在 .env 配 DB_* / CRUD_***——
 * 数据库与插件调参全部集中在本文件，避免覆盖宿主已有 .env。
 *
 * 取值关系：
 *   - database 段 → 自动生成的 config/database.php 的 mysql / mysql_business 连接
 *     （config/database.php 优先读本段，本段留空的键回退 .env 的 DB_*）
 *   - 下方注释的插件调参键 → 覆盖 plugin/crud/config/crud.php 的同名默认值
 *   - plugin/crud/install.php 会自动 CREATE DATABASE（库不存在时）
 */
return [
    // ---- 数据库（安装/建表/模型库使用）----
    // 默认【单库模式】：认证库与业务库放同一个库（business_db 留空即同 admin_db）。
    // 如需分库：填独立的 business_db，并确保数据库账号有权限建库/读写。
    'database' => [
        'host'        => '127.0.0.1',
        'port'        => '3306',
        'username'    => 'root',
        'password'    => '',
        // 认证/管理面库：admin_users/roles/menus/casbin_rule/crud_configs 等核心表
        'admin_db'    => 'webman_crud',
        // 业务库：业务模型 CRUD 默认库；留空 = 与 admin_db 同库（单库模式，推荐）
        'business_db' => '',
    ],

    // ---- 以下为插件调参（键与 plugin/crud/config/crud.php 同名才生效；不写用内置默认）----
    // 'admin_connection'         => 'mysql',          // 认证库连接名（config/database.php connections 键）
    // 'business_connection'      => 'mysql_business', // 业务库连接名
    // 'admin_require_permission' => false,            // /api/admin/* 是否强制 RBAC
    // 'page_base'                => '/app/crud',      // 前端挂载前缀（改需同步重建前端）
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
                    echo "  [webman-crud] 提示：宿主 config/database.php 未含 mysql_business 连接，业务表建表/迁移会失败；\n";
                    echo "      请在 config/crud.php 的 database 段配置 business_db 后重跑安装器，或参考 plugin/crud/database.business.example.php 手动补上。\n";
                }
                return; // 已有自定义配置，跳过
            }
            if (@rename($file, $file . '.webman-db.bak') === false) {
                echo "  [webman-crud] 警告：config/database.php 为占位模板但备份失败，跳过替换（请手动配置）\n";
                return;
            }
            echo "  [webman-crud] 检测到 webman/database 占位 config/database.php，已备份为 database.php.webman-db.bak\n";
            $needWrite = true;
        }
        if ($needWrite) {
            file_put_contents($file, self::databaseConfigTemplate());
            echo "  [webman-crud] 已生成 env 驱动的 config/database.php（mysql + mysql_business 读 DB_* 键）\n";
        }
    }

    /**
     * env / config/crud.php 驱动的数据库配置模板
     *
     * 取值优先级：宿主 config/crud.php 的 database 段（推荐）> .env 的 DB_* 键 > 内置默认。
     */
    protected static function databaseConfigTemplate(): string
    {
        return <<<'PHP'
<?php
/**
 * webman-crud 自动生成的数据库配置模板（宿主 config/database.php 缺失或为
 * webman/database 占位模板时，由 huafei/webman-crud 的 src/Install.php 生成，
 * 可自由修改）。
 *
 * 取值优先级：
 *   1. 宿主 config/crud.php 的 database 段（新项目推荐入口，无需 .env）
 *   2. .env 的 DB_* 键（兼容老宿主/传统用法）
 *
 * 默认【单库模式】：mysql_business 未单独指定库名时，与 mysql 指向同一库
 * （业务库名取值链：config/crud.php business_db → .env DB_BUSINESS_NAME → 认证库名）。
 *
 * - mysql          ：认证/管理面库（admin_users / roles / menus / casbin_rule ...）
 * - mysql_business ：业务库（CRUD_BUSINESS_CONNECTION 默认连接名；单库模式=同 mysql 库）
 */
$__crudDb = [];
$__crudFile = __DIR__ . '/crud.php';
if (is_file($__crudFile)) {
    $__cfg = require $__crudFile;
    if (is_array($__cfg) && isset($__cfg['database']) && is_array($__cfg['database'])) {
        $__crudDb = $__cfg['database'];
    }
}
$__pick = static function (string $key, string $envKey, string $default) use ($__crudDb): string {
    return (isset($__crudDb[$key]) && $__crudDb[$key] !== '')
        ? (string)$__crudDb[$key]
        : (string)env($envKey, $default);
};
$__adminDb = $__pick('admin_db', 'DB_NAME', 'webman_crud');
$__businessDb = $__pick('business_db', 'DB_BUSINESS_NAME', $__adminDb);

return [
    'default' => 'mysql',
    'connections' => [
        'mysql' => [
            'driver'    => 'mysql',
            'host'      => $__pick('host', 'DB_HOST', '127.0.0.1'),
            'port'      => $__pick('port', 'DB_PORT', '3306'),
            'database'  => $__adminDb,
            'username'  => $__pick('username', 'DB_USER', 'root'),
            'password'  => $__pick('password', 'DB_PASSWORD', ''),
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_general_ci',
            'prefix'    => '',
            'strict'    => true,
            'engine'    => null,
        ],
        'mysql_business' => [
            'driver'    => 'mysql',
            'host'      => $__pick('host', 'DB_HOST', '127.0.0.1'),
            'port'      => $__pick('port', 'DB_PORT', '3306'),
            'database'  => $__businessDb,
            'username'  => $__pick('username', 'DB_USER', 'root'),
            'password'  => $__pick('password', 'DB_PASSWORD', ''),
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
        // 包位于 vendor/huafei/webman-crud/src
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
