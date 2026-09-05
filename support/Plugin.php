<?php
namespace support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * 应用插件 Composer 安装器（huafei/webman-crud 随包附带）
 *
 * ⚠️ 重要：webman 2.x 新鲜骨架（如 workerman/webman 2.1.x）的 composer.json
 * scripts 引用了 `support\Plugin::install`，但骨架目录里**默认不含**这个文件。
 * 因此新项目首次接入本包时，必须先把本文件放到宿主的 `support/Plugin.php`：
 *
 *     composer require huafei/webman-crud:^1.0
 *     cp vendor/huafei/webman-crud/support/Plugin.php support/Plugin.php
 *     composer update huafei/webman-crud     # 触发拷贝；或等价地：
 *     # cp -r vendor/huafei/webman-crud/plugin/crud plugin/crud
 *
 * 之后任何 `composer require` / `composer update` 都会自动把插件同步到 `plugin/crud`。
 *
 * 机制（与 webman 官方应用插件分发保持一致）：
 *   本类在 install/update 钩子触发时，把 vendor/{vendor}/{pkg}/plugin/{name}
 *   同步到 {项目根}/plugin/{name}；webman 启动即按官方应用插件机制自动加载
 *   （plugin/{name}/config 自动合并、route/autoload 生效），宿主零改动。
 *
 * 幂等安全策略：
 *   - 目标 plugin/{name} 已存在（本地开发中的插件）→ 跳过，绝不覆盖本地改动；
 *   - 仅当目标不存在时整体拷贝，排除 .git/.idea/node_modules 等构建目录。
 */
class Plugin
{
    /**
     * 拷贝时排除的目录/文件（相对插件根）
     */
    protected static array $exclude = ['.git', '.idea', '.vscode', 'node_modules', '__pycache__'];

    /**
     * 项目根：support/Plugin.php → 项目根
     */
    protected static function projectRoot(): string
    {
        return dirname(__DIR__);
    }

    /**
     * 安装钩子：扫描 vendor 内所有包，同步其 plugin/{name} 到项目 plugin/
     * 无参数实现（不依赖 composer 事件注入），幂等可重复执行。
     */
    public static function install(): void
    {
        $root = self::projectRoot();
        $vendorDir = $root . '/vendor';
        if (!is_dir($vendorDir)) {
            return;
        }
        $targetPluginDir = $root . '/plugin';
        foreach (glob($vendorDir . '/*/*') ?: [] as $packageDir) {
            $pkgPlugin = $packageDir . '/plugin';
            if (!is_dir($pkgPlugin)) {
                continue;
            }
            foreach (glob($pkgPlugin . '/*') ?: [] as $pluginDir) {
                if (!is_dir($pluginDir)) {
                    continue;
                }
                $name = basename($pluginDir);
                $target = $targetPluginDir . '/' . $name;
                if (is_dir($target)) {
                    echo "  [Plugin] plugin/{$name} 已存在，跳过拷贝（保留本地版本）\n";
                    continue;
                }
                if (!is_dir($targetPluginDir)) {
                    mkdir($targetPluginDir, 0755, true);
                }
                self::copyDir($pluginDir, $target);
                echo "  [Plugin] 已安装应用插件: plugin/{$name}\n";
            }
        }
    }

    /**
     * 卸载钩子：composer 卸载时无包上下文，无法精确定位目标目录；
     * 且删除宿主 plugin/ 下的目录存在误删风险，故不自动删除。
     * 请手动删除对应的 plugin/{name} 目录后 reload 生效。
     */
    public static function uninstall(): void
    {
        // no-op（见方法注释）
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
