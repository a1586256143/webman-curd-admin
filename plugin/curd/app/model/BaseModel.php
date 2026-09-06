<?php

namespace plugin\curd\app\model;

use support\Model;

/**
 * 业务库模型基类（插件内置版，供新项目业务模型继承）
 *
 * 说明：
 * - 本基类不写死连接名；具体连接的指定方式见插件文档：
 *   1) 推荐由 ModelRegistry 统一解析（继承 support\Model 即可被扫描/CURD），
 *      缺省连接名回退到 config('plugin.curd.curd.business_connection')；
 *   2) 模型内显式声明 protected $connection = 'xxx' 可覆盖。
 * - 若表没有 created_at/updated_at 列，请在子类里声明 public $timestamps = false;
 * - 若表支持软删除，use Illuminate\Database\Eloquent\SoftDeletes;
 */
abstract class BaseModel extends Model
{
    /**
     * 默认关闭自动时间戳，由 BaseCurdController 按列检测决定
     * （避免表里没有 created_at/updated_at 列时 Eloquent 报错）
     */
    public $timestamps = false;

    public static function getAll()
    {
        return self::query()->pluck('title', 'id')->toArray();
    }
}
