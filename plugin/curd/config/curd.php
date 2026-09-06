<?php
/**
 * CURD 插件宿主接入配置（插件内置默认，随 composer require 拷贝到宿主）
 *
 * 配置读取优先级（高 → 低）：
 *   1. 宿主 config/curd.php（推荐入口，顶层同名键覆盖本文件默认值；
 *      首次 composer require 时由 src/Install.php 自动生成）
 *   2. 本文件默认值
 *
 * 注：数据库连接不在插件配置里——由 .env 的 DB_* 键承载，config/database.php 用
 * env() 读取；认证库与业务库【同一库】（单库架构）。旧 CURD_* 环境变量用法已移除，
 * 插件调参统一收敛到宿主 config/curd.php。
 *
 * 本文件声明「与宿主约定」的可调点：
 *  - 库连接名：认证/菜单/RBAC 策略库（admin_connection）与业务模型库（business_connection）
 *  - 宿主业务资产的扫描/读取目录约定（模型、CURD 控制器、.vue 页面）
 *  - casbin 模型文件位置
 */

$defaults = [
    // 认证/菜单/RBAC 库连接名（admin_users / admin_tokens / roles / menus /
    // role_permission / curd_configs / casbin_rule 所在库）
    'admin_connection' => 'mysql',

    // 业务库连接名（业务模型 CURD 默认所在库；单库架构下与 mysql 指向同一 DB_NAME）
    'business_connection' => 'mysql_business',

    // 宿主模型扫描约定（自动登记进 ModelRegistry）
    'model_dir'       => base_path() . '/app/model',
    'model_namespace' => 'app\model',

    // 宿主 CURD 业务控制器扫描约定（继承 BaseCurdController 的控制器 → 自动关联模型）
    'controller_dirs' => [
        app_path() . '/controller/api'       => 'app\controller\api',
        app_path() . '/controller/admin/api' => 'app\controller\admin\api',
    ],

    // 宿主业务 .vue 页面目录（CustomPageController 读取分发）
    'vue_pages_dir' => base_path() . '/app/custom/pages',

    // casbin 模型文件（Rbac 读取，随插件分发）
    'casbin_model_path' => __DIR__ . '/casbin.conf',

    // 运行时动态生成模型时的基类（ModelRegistry::generateModel eval 使用）
    // 默认插件内置 BaseModel（与宿主版等价）；宿主若已实现增强版可改回 app\model\BaseModel
    'base_model_class' => 'plugin\curd\app\model\BaseModel',

    // 权限推导不出 obj 的路径处理（同宿主 config/app.php permission.allow_unresolved）
    'allow_unresolved' => true,

    // /api/admin/*（用户/角色/个人中心）是否强制 RBAC 校验
    //   false（默认）= 仅登录即可（与宿主历史行为一致，兼容存量项目）
    //   true          = 走 casbin 校验，obj 由路径推导（/api/admin/users → obj=admin act=users）
    // 新项目建议设 true：默认 admin 角色有 p,admin,*,* 不受影响，
    // 其余角色需在「角色管理」勾选对应权限后才可访问。
    'admin_require_permission' => false,

    // 插件内置前端（dist 放 plugin/curd/public/，见 install.sql 同目录 README）
    // page_base：页面与静态资源的 URL 前缀（无尾斜杠），前端构建时 VITE_BASE_PATH 需一致
    'page_base'  => '/app/curd',
    'public_dir' => dirname(__DIR__) . '/public',
];

// 宿主 config/curd.php 顶层同名键覆盖默认值（无同名键的项保持默认）。
// 插件调参统一写在该文件（宿主根 config/curd.php），由 src/Install.php 首次
// composer require 时自动生成。
if (function_exists('base_path')) {
    $hostCurdFile = base_path() . '/config/curd.php';
    if (is_file($hostCurdFile)) {
        $hostCurd = require $hostCurdFile;
        if (is_array($hostCurd)) {
            $defaults = array_merge($defaults, $hostCurd);
        }
    }
}

return $defaults;
