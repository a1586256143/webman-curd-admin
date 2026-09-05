<?php

namespace plugin\crud\app\model;

use plugin\crud\app\CrudDb;
use support\Model;

/**
 * CRUD 插件配置表模型（crud_configs，位于认证库 admin_connection）
 * 供插件运行时读取「已登记 CRUD 配置的表名」白名单 / 动态配置回查。
 */
class CrudConfigs extends Model
{
    public static function firstByRoutePath($identifier)
    {
        return self::on(CrudDb::admin())->where('route_path', $identifier)->first();
    }

    public static function firstByTableName($modelName)
    {
        return self::on(CrudDb::admin())->where('table_name', $modelName)->first();
    }
}
