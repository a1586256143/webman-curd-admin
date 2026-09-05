<?php

namespace plugin\crud\app\schema;

/**
 * Schema 页面注册表（插件通用能力）
 *
 * 插件的 CustomPageController 通过本注册表把「PHP DSL 构建的页面 Schema」
 * 暴露为 /api/custom/page?name=xxx 可访问的页面（format=schema）。
 *
 * 注册表默认是空的——业务项目在自身 config/route.php（或其它启动点）里
 * 用 PageRegistry::register() 声明自己的页面，例如：
 *
 *   PageRegistry::register('home', [\app\controller\admin\api\HomePage::class, 'schema']);
 *
 * 页面定义类（用户级文件）放业务侧任意位置均可，只需返回 PageSchema 实例。
 */
final class PageRegistry
{
    /** 页面名 → PageSchema 构建回调（register 时传入的闭包/可调用） */
    private static array $pages = [];

    /** 页面名 → 已构建的 PageSchema（进程内缓存，防重复构建） */
    private static array $resolved = [];

    /**
     * 注册一个 Schema 页面。
     *
     * @param string                 $name    页面名（/api/custom/page?name=<name>，即菜单 /custom-page/<name>）
     * @param callable|PageSchema    $builder 直接给 PageSchema 实例，或返回 PageSchema 的可调用（类静态方法/闭包均可）
     */
    public static function register(string $name, callable|PageSchema $builder): void
    {
        self::$pages[$name] = $builder;
        unset(self::$resolved[$name]);
    }

    /** 全部已注册页面（name => PageSchema） */
    public static function all(): array
    {
        foreach (array_keys(self::$pages) as $name) {
            self::find($name);
        }
        return self::$resolved;
    }

    /** 按页面名取 PageSchema；未注册或构建失败返回 null */
    public static function find(string $name): ?PageSchema
    {
        if (isset(self::$resolved[$name])) {
            return self::$resolved[$name];
        }
        $builder = self::$pages[$name] ?? null;
        if ($builder === null) {
            return null;
        }

        $schema = $builder instanceof PageSchema ? $builder : call_user_func($builder);
        if (!$schema instanceof PageSchema) {
            return null;
        }
        return self::$resolved[$name] = $schema;
    }
}
