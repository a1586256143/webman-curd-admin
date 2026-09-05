<?php
/**
 * CRUD 插件宿主接入配置（插件内置默认，随 composer require 拷贝到宿主）
 *
 * 配置读取优先级（高 → 低）：
 *   1. 宿主 config/crud.php（推荐入口，顶层同名键覆盖本文件默认值；
 *      首次 composer require 时由 src/Install.php 自动生成，不会覆盖宿主 .env）
 *   2. .env 的 CRUD_* 键（兼容旧用法：CRUD_ADMIN_CONNECTION 等）
 *   3. 本文件默认值（对齐「嵌入宿主开发」时的取值）
 *
 * 本文件声明「与宿主约定」的可调点：
 *  - 库连接名：认证/菜单/RBAC 策略库（admin_connection）与业务模型库（business_connection）
 *  - 宿主业务资产的扫描/读取目录约定（模型、CRUD 控制器、.vue 页面）
 *  - casbin 模型文件位置
 */

$defaults = [
    // 认证/菜单/RBAC 库连接名（admin_users / admin_tokens / roles / menus /
    // role_permission / crud_configs / casbin_rule 所在库）
    'admin_connection' => env('CRUD_ADMIN_CONNECTION', 'mysql'),

    // 业务库连接名（业务模型 CRUD 默认所在库）
    'business_connection' => env('CRUD_BUSINESS_CONNECTION', 'mysql_business'),

    // 宿主模型扫描约定（自动登记进 ModelRegistry）
    'model_dir'       => base_path() . '/app/model',
    'model_namespace' => 'app\model',

    // 宿主 CRUD 业务控制器扫描约定（继承 BaseCrudController 的控制器 → 自动关联模型）
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
    'base_model_class' => 'plugin\crud\app\model\BaseModel',

    // 权限推导不出 obj 的路径处理（同宿主 config/app.php permission.allow_unresolved）
    'allow_unresolved' => true,

    // /api/admin/*（用户/角色/个人中心）是否强制 RBAC 校验
    //   false（默认）= 仅登录即可（与宿主历史行为一致，兼容存量项目）
    //   true          = 走 casbin 校验，obj 由路径推导（/api/admin/users → obj=admin act=users）
    // 新项目建议设 true：默认 admin 角色有 p,admin,*,* 不受影响，
    // 其余角色需在「角色管理」勾选对应权限后才可访问。
    'admin_require_permission' => env('CRUD_ADMIN_REQUIRE_PERMISSION', false),

    // 插件内置前端（dist 放 plugin/crud/public/，见 install.sql 同目录 README）
    // page_base：页面与静态资源的 URL 前缀（无尾斜杠），前端构建时 VITE_BASE_PATH 需一致
    'page_base'  => rtrim(env('CRUD_PAGE_BASE', '/app/crud'), '/'),
    'public_dir' => dirname(__DIR__) . '/public',
];

// 宿主 config/crud.php 顶层同名键覆盖默认值（无同名键的项保持默认；database 组等
// 额外键一并并入，仅作为集中配置载体，不参与插件运行逻辑）。
// 新项目推荐全部插件配置写在此文件，无需在宿主 .env 配 DB_* / CRUD_*。
if (function_exists('base_path')) {
    $hostCrudFile = base_path() . '/config/crud.php';
    if (is_file($hostCrudFile)) {
        $hostCrud = require $hostCrudFile;
        if (is_array($hostCrud)) {
            $defaults = array_merge($defaults, $hostCrud);
        }
    }
}

return $defaults;
