<?php
namespace plugin\crud\app;

use support\Db;

/**
 * CRUD 插件库连接助手
 *
 * 插件不写死连接名，统一经 config('plugin.crud.crud.admin_connection' /
 * business_connection) 获取（默认 mysql / mysql_business，与宿主开发期一致，
 * 新项目可用 .env 的 CRUD_ADMIN_CONNECTION / CRUD_BUSINESS_CONNECTION 覆盖）。
 */
final class CrudDb
{
    /**
     * 认证/菜单/RBAC 库连接名
     */
    public static function admin(): string
    {
        return (string)config('plugin.crud.crud.admin_connection', 'mysql');
    }

    /**
     * 业务库连接名
     */
    public static function business(): string
    {
        return (string)config('plugin.crud.crud.business_connection', 'mysql_business');
    }

    /**
     * 认证库连接实例
     */
    public static function adminDb()
    {
        return Db::connection(self::admin());
    }

    /**
     * 认证库表查询构造器
     */
    public static function adminTable(string $table)
    {
        return self::adminDb()->table($table);
    }

    /**
     * 业务库连接实例
     */
    public static function businessDb()
    {
        return Db::connection(self::business());
    }
}
