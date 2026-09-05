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
     * 确保宿主 config/database.php 为 env 驱动的可用配置：
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
                    echo "      请参考 plugin/crud/database.business.example.php 手动补上。\n";
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
     * env 驱动的数据库配置模板
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
 * - mysql          ：认证/管理面库（admin_users / roles / menus / casbin_rule ...）
 * - mysql_business ：业务库（CRUD_BUSINESS_CONNECTION 默认连接名）
 * 对应 .env 键：DB_HOST / DB_PORT / DB_NAME / DB_USER / DB_PASSWORD / DB_BUSINESS_NAME
 */
return [
    'default' => 'mysql',
    'connections' => [
        'mysql' => [
            'driver'    => 'mysql',
            'host'      => env('DB_HOST', '127.0.0.1'),
            'port'      => env('DB_PORT', '3306'),
            'database'  => env('DB_NAME', 'webman_crud'),
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
            'database'  => env('DB_BUSINESS_NAME', 'webman_crud_business'),
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
