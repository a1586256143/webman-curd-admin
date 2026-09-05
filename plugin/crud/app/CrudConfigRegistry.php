<?php
namespace plugin\crud\app;

use support\Container;

/**
 * CRUD 配置注册中心
 * 管理所有 CRUD 配置类的注册和发现
 */
class CrudConfigRegistry
{
    /**
     * 配置类映射
     */
    protected static array $configs = [];

    /**
     * 注册配置类
     */
    public static function register(string $key, string $class): void
    {
        self::$configs[$key] = $class;
    }

    /**
     * 批量注册配置类
     */
    public static function registerMany(array $configs): void
    {
        foreach ($configs as $key => $class) {
            self::register($key, $class);
        }
    }

    /**
     * 获取配置实例
     */
    public static function get(string $key): ?CrudConfig
    {
        if (!isset(self::$configs[$key])) {
            return null;
        }

        $class = self::$configs[$key];
        return Container::get($class);
    }

    /**
     * 获取所有配置键
     */
    public static function keys(): array
    {
        return array_keys(self::$configs);
    }

    /**
     * 获取所有配置的简要信息
     */
    public static function all(): array
    {
        $list = [];
        foreach (self::$configs as $key => $class) {
            $instance = Container::get($class);
            $list[] = [
                'key' => $key,
                'title' => $instance->getTitle(),
            ];
        }
        return $list;
    }

    /**
     * 获取所有配置的前端格式
     */
    public static function allConfigs(): array
    {
        $list = [];
        foreach (self::$configs as $key => $class) {
            $instance = Container::get($class);
            $list[] = $instance->toFrontendConfig();
        }
        return $list;
    }

    /**
     * 检查配置是否存在
     */
    public static function has(string $key): bool
    {
        return isset(self::$configs[$key]);
    }
}
