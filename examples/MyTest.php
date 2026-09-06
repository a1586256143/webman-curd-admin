<?php

namespace app\model;

/**
 * 后台示例模型（MyTestController 配套，开箱演示用）
 *
 * 表：my_test（随安装向导 / plugin/curd/install.sql 在默认连接创建）
 * 连接：示例模型继承插件基类（默认连接 = 认证/默认库）；
 *       若你的业务表在业务库（mysql_business），请改为继承
 *       app\model\BaseModel（业务库基类），或显式声明：
 *           protected $connection = 'mysql_business';
 *
 * 模型目录约定：宿主根 app/model 下的模型会被 ModelRegistry::scanModels()
 * 启动时自动扫描登记（无需手动注册），/api/curd/model/MyTest 即能解析到本类。
 *
 * 正式环境不需要本示例时，可整组删除：
 *   app/model/MyTest.php + app/controller/admin/api/MyTestController.php
 *   + config/route.php 的 /my-test 注册行
 *   + menus「测试管理」菜单（删行）+ my_test 表
 */
class MyTest extends \plugin\curd\app\model\BaseModel
{
    /**
     * 数据表名
     */
    protected $table = 'my_test';
}
