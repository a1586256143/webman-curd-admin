<?php
namespace plugin\crud\app\model;

/**
 * 后台示例模型（MyTestController 配套，开箱演示用）
 *
 * 表：my_test（安装时随 plugin/crud/install.sql 自动创建）
 * 连接：单库架构下模型默认连接即认证库，与业务库同库；
 *       若未来恢复分库，请显式声明：
 *           protected $connection = 'mysql_business';
 *
 * 正式环境不需要本示例时，可整组删除：
 *   model/MyTest.php + controller/MyTestController.php
 *   + bootstrap.php 中「内置示例」注册段
 *   + menus 中「测试管理」菜单（seedAll 写入，删行即可）
 *   + my_test 表
 */
class MyTest extends BaseModel
{
    /**
     * 数据表名
     */
    protected $table = 'my_test';
}
