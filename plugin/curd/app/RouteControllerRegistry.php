<?php
namespace plugin\curd\app;

use support\Container;

/**
 * 路由 → 控制器注册表
 * 把 route_path 映射到继承 BaseCurdController 的控制器类，
 * 前端访问 /xxx 时，CurdController::config() 能从控制器动态拿到配置
 *
 * 用法（bootstrap.php 中注册）：
 *   RouteControllerRegistry::register('/aPackages', \app\controller\api\PackageController::class);
 */
class RouteControllerRegistry
{
    /**
     * route_path => 控制器类
     */
    protected static array $routes = [];

    /**
     * 注册路由到控制器
     */
    public static function register(string $routePath, string $controllerClass): void
    {
        // 统一补前导斜杠
        if ($routePath !== '' && !str_starts_with($routePath, '/')) {
            $routePath = '/' . $routePath;
        }
        self::$routes[$routePath] = $controllerClass;
    }

    /**
     * 批量注册
     */
    public static function registerMany(array $routes): void
    {
        foreach ($routes as $path => $class) {
            self::register($path, $class);
        }
    }

    /**
     * 获取控制器类（兼容带/不带前导斜杠，严格大小写敏感）
     */
    public static function get(string $routePath): ?string
    {
        if (isset(self::$routes[$routePath])) {
            return self::$routes[$routePath];
        }
        if (!str_starts_with($routePath, '/')) {
            return self::$routes['/' . $routePath] ?? null;
        }
        return null;
    }

    /**
     * 获取控制器实例（存在且有 config 方法则返回实例）
     */
    public static function instance(string $routePath): ?object
    {
        $class = self::get($routePath);
        if (!$class || !class_exists($class)) {
            return null;
        }
        $instance = Container::get($class);
        if (!method_exists($instance, 'config')) {
            return null;
        }
        return $instance;
    }

    /**
     * 所有注册项
     */
    public static function all(): array
    {
        return self::$routes;
    }
}
