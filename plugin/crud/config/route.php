<?php
/**
 * CRUD 插件路由
 *
 * 插件内控制器的路由统一放在本文件，随 plugin/crud 自动加载。
 * 业务项目自身的控制器路由请放在项目 config/route.php。
 *
 * ⚠️ 宿主优先原则
 * ------------------------------------------------------------
 * webman 的 Route 对「同 method + 同 path」重复注册会直接抛 RuntimeException
 * （Route.php::addRoute → "Route conflict"），而路由加载顺序是
 *   宿主 config/route.php  →  plugin/{name}/config/route.php
 * （见 support/bootstrap.php：$paths = [config_path(), ...plugin]）
 * 因此插件注册前先检测：该路径已被注册则跳过，保留宿主实现。
 * 这样存量项目（自己注册过 /api/admin/* 等）装插件不会崩，行为也不变；
 * 新项目没有同名路由，插件路由全部生效。
 */

use Webman\Route;

// ============================================================
// 路由注册助手（冲突检测：宿主已注册则跳过）
// ============================================================
if (!function_exists('crud_route')) {
    /**
     * 注册一条插件路由；若宿主（或其它插件）已注册同 method+path 则跳过。
     *
     * @param string $method      GET / POST
     * @param string $path        完整路径（不再使用 Route::group，便于逐条冲突检测）
     * @param array  $callback    [控制器类, 方法]
     * @param array  $middlewares 中间件列表（顺序同 Route::group：第一位最终最外层）
     */
    function crud_route(string $method, string $path, array $callback, array $middlewares = []): void
    {
        $method = strtoupper($method);

        // 已注册则跳过（宿主优先）
        foreach (Route::getRoutes() as $route) {
            if ($route->getPath() === $path && in_array($method, $route->getMethods(), true)) {
                return;
            }
        }

        $route = match ($method) {
            'GET'  => Route::get($path, $callback),
            'POST' => Route::post($path, $callback),
            default => Route::get($path, $callback),
        };

        if ($middlewares !== []) {
            $route->middleware($middlewares);
        }
    }
}

// 鉴权中间件组（顺序不可改：Cors 必须在最前，最终成为最外层）
$crudAuthMiddleware = [
    \plugin\crud\app\middleware\CorsMiddleware::class,
    \plugin\crud\app\middleware\AuthCheck::class,
    \plugin\crud\app\middleware\PermissionCheck::class,
];
// 仅 CORS（免鉴权接口）
$crudCorsOnly = [
    \plugin\crud\app\middleware\CorsMiddleware::class,
];

// ============================================================
// 插件内置前端（SPA）：{page_base}/* 静态托管 + history fallback
//  - 前端以 VITE_BASE_PATH=<page_base> 构建，产物放 plugin/crud/public/
//  - 真实文件静态返回；未命中路径（/dashboard 等前端路由）回退 index.html
//  - 不挂 AuthCheck：登录态由前端跳转控制
//  - page_base 与 config('plugin.crud.crud.page_base') 联动，可整体改前缀
// ============================================================
$crudPageBase = '/' . trim((string)config('plugin.crud.crud.page_base', '/app/crud'), '/');
crud_route('GET', $crudPageBase, [
    \plugin\crud\app\controller\PageController::class,
    'index',
]);
// 尾斜杠形式（FastRoute 不折叠尾斜杠，浏览器访问 /app/crud/ 时需显式注册）
crud_route('GET', $crudPageBase . '/', [
    \plugin\crud\app\controller\PageController::class,
    'index',
]);
crud_route('GET', $crudPageBase . '/{path:.+}', [
    \plugin\crud\app\controller\PageController::class,
    'index',
]);

// 登录接口（不需要鉴权，但必须保证响应带 CORS 头）
crud_route('POST', '/api/auth/login', [
    \plugin\crud\app\controller\AuthController::class,
    'login',
], $crudCorsOnly);

// ============================================================
// Web 安装向导（新项目首次安装用；安装完成生成 config/crud-installed.lock
// 后，setup 拒绝重复执行——页面也会引导直接进后台登录）
// ============================================================
crud_route('GET', '/app/crud-installer', [
    \plugin\crud\app\controller\InstallerController::class,
    'page',
]);
crud_route('GET', '/api/crud-installer/status', [
    \plugin\crud\app\controller\InstallerController::class,
    'status',
], $crudCorsOnly);
crud_route('POST', '/api/crud-installer/setup', [
    \plugin\crud\app\controller\InstallerController::class,
    'setup',
], $crudCorsOnly);
crud_route('GET', '/api/crud-installer/progress', [
    \plugin\crud\app\controller\InstallerController::class,
    'progress',
], $crudCorsOnly);

// 站点信息（免鉴权：登录页未登录时也要显示站点标题/logo）
crud_route('GET', '/api/config/site', [
    \plugin\crud\app\controller\AdminController::class,
    'siteConfig',
], $crudCorsOnly);

// ============================================================
// 其余 CRUD 插件接口统一挂鉴权和权限中间件
// ============================================================

// 个人中心
crud_route('GET', '/api/admin/profile', [
    \plugin\crud\app\controller\AdminController::class,
    'profile',
], $crudAuthMiddleware);
crud_route('POST', '/api/admin/profile/update', [
    \plugin\crud\app\controller\AdminController::class,
    'profileUpdate',
], $crudAuthMiddleware);
crud_route('POST', '/api/admin/password/update', [
    \plugin\crud\app\controller\AdminController::class,
    'passwordUpdate',
], $crudAuthMiddleware);

// 系统管理：用户 / 角色（数据来自认证库，与业务库解耦）
crud_route('GET', '/api/admin/users', [\plugin\crud\app\controller\AdminController::class, 'users'], $crudAuthMiddleware);
crud_route('POST', '/api/admin/users/add', [\plugin\crud\app\controller\AdminController::class, 'userAdd'], $crudAuthMiddleware);
crud_route('POST', '/api/admin/users/update', [\plugin\crud\app\controller\AdminController::class, 'userUpdate'], $crudAuthMiddleware);
crud_route('POST', '/api/admin/users/delete', [\plugin\crud\app\controller\AdminController::class, 'userDelete'], $crudAuthMiddleware);
crud_route('POST', '/api/admin/users/batch-delete', [\plugin\crud\app\controller\AdminController::class, 'userBatchDelete'], $crudAuthMiddleware);

crud_route('GET', '/api/admin/roles', [\plugin\crud\app\controller\AdminController::class, 'roles'], $crudAuthMiddleware);
crud_route('POST', '/api/admin/roles/add', [\plugin\crud\app\controller\AdminController::class, 'roleAdd'], $crudAuthMiddleware);
crud_route('POST', '/api/admin/roles/update', [\plugin\crud\app\controller\AdminController::class, 'roleUpdate'], $crudAuthMiddleware);
crud_route('POST', '/api/admin/roles/delete', [\plugin\crud\app\controller\AdminController::class, 'roleDelete'], $crudAuthMiddleware);
crud_route('GET', '/api/admin/roles/options', [\plugin\crud\app\controller\AdminController::class, 'roleOptions'], $crudAuthMiddleware);

// 认证
crud_route('POST', '/api/auth/logout', [
    \plugin\crud\app\controller\AuthController::class,
    'logout',
], $crudAuthMiddleware);
crud_route('GET', '/api/auth/me', [
    \plugin\crud\app\controller\AuthController::class,
    'me',
], $crudAuthMiddleware);

// CRUD 配置与模型接口
crud_route('GET', '/api/crud/tables', [
    \plugin\crud\app\controller\CrudController::class,
    'tables',
], $crudAuthMiddleware);
crud_route('GET', '/api/crud/models', [
    \plugin\crud\app\controller\CrudController::class,
    'models',
], $crudAuthMiddleware);
crud_route('GET', '/api/crud/schema', [
    \plugin\crud\app\controller\CrudController::class,
    'schema',
], $crudAuthMiddleware);
crud_route('GET', '/api/crud/generate', [
    \plugin\crud\app\controller\CrudController::class,
    'generate',
], $crudAuthMiddleware);
crud_route('GET', '/api/crud/config', [
    \plugin\crud\app\controller\CrudController::class,
    'config',
], $crudAuthMiddleware);
crud_route('GET', '/api/crud/config/list', [
    \plugin\crud\app\controller\CrudController::class,
    'configList',
], $crudAuthMiddleware);
crud_route('GET', '/api/crud/config/all', [
    \plugin\crud\app\controller\CrudController::class,
    'allConfigs',
], $crudAuthMiddleware);
crud_route('POST', '/api/crud/save-config', [
    \plugin\crud\app\controller\CrudController::class,
    'saveConfig',
], $crudAuthMiddleware);

// 自定义页面（.vue 运行时编译 / PHP Schema 组件树，插件通用能力）
//   业务 .vue 页面放 <宿主根>/app/custom/pages/；Schema 页面经 PageRegistry::register 声明
//   前端访问 /custom-page/<name>（catch-all 路由承载），/api/custom/* 仅登录即可
crud_route('GET', '/api/custom/pages', [
    \plugin\crud\app\controller\CustomPageController::class,
    'pages',
], $crudAuthMiddleware);
crud_route('GET', '/api/custom/page', [
    \plugin\crud\app\controller\CustomPageController::class,
    'content',
], $crudAuthMiddleware);

// 菜单管理
crud_route('GET', '/api/menu', [
    \plugin\crud\app\controller\MenuController::class,
    'index',
], $crudAuthMiddleware);
crud_route('GET', '/api/menu/all', [
    \plugin\crud\app\controller\MenuController::class,
    'all',
], $crudAuthMiddleware);
crud_route('POST', '/api/menu/add', [
    \plugin\crud\app\controller\MenuController::class,
    'add',
], $crudAuthMiddleware);
crud_route('POST', '/api/menu/update', [
    \plugin\crud\app\controller\MenuController::class,
    'update',
], $crudAuthMiddleware);
crud_route('POST', '/api/menu/delete', [
    \plugin\crud\app\controller\MenuController::class,
    'delete',
], $crudAuthMiddleware);

// 通用远程下拉数据源
crud_route('GET', '/api/options/model/{model}', [
    \plugin\crud\app\controller\OptionsController::class,
    'index',
], $crudAuthMiddleware);

// 上传（阿里云 OSS）
crud_route('POST', '/api/upload', [
    \plugin\crud\app\controller\UploadController::class,
    'upload',
], $crudAuthMiddleware);
crud_route('POST', '/api/upload/image', [
    \plugin\crud\app\controller\UploadController::class,
    'image',
], $crudAuthMiddleware);

// 模型 CRUD 路由：具体操作必须放在模型详情通配路由之前
crud_route('GET', '/api/crud/model/{model}/export', [
    \plugin\crud\app\controller\CrudController::class,
    'export',
], $crudAuthMiddleware);
crud_route('POST', '/api/crud/model/{model}/add', [
    \plugin\crud\app\controller\CrudController::class,
    'add',
], $crudAuthMiddleware);
crud_route('POST', '/api/crud/model/{model}/update', [
    \plugin\crud\app\controller\CrudController::class,
    'update',
], $crudAuthMiddleware);
crud_route('POST', '/api/crud/model/{model}/delete', [
    \plugin\crud\app\controller\CrudController::class,
    'delete',
], $crudAuthMiddleware);
crud_route('POST', '/api/crud/model/{model}/batch-delete', [
    \plugin\crud\app\controller\CrudController::class,
    'batchDelete',
], $crudAuthMiddleware);
crud_route('POST', '/api/crud/model/{model}/action/{name}', [
    \plugin\crud\app\controller\CrudController::class,
    'action',
], $crudAuthMiddleware);
crud_route('GET', '/api/crud/model/{model}', [
    \plugin\crud\app\controller\CrudController::class,
    'list',
], $crudAuthMiddleware);
