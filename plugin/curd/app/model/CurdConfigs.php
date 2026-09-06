<?php

namespace plugin\curd\app\model;

use plugin\curd\app\CurdDb;
use support\Model;

/**
 * CURD 插件配置表模型（curd_configs，位于认证库 admin_connection）
 * 供插件运行时读取「已登记 CURD 配置的表名」白名单 / 动态配置回查。
 */
class CurdConfigs extends Model
{
    public static function firstByRoutePath($identifier)
    {
        return self::on(CurdDb::admin())->where('route_path', $identifier)->first();
    }

    public static function firstByTableName($modelName)
    {
        return self::on(CurdDb::admin())->where('table_name', $modelName)->first();
    }
}
