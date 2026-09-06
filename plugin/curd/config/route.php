<?php
/**
 * CURD 插件路由
 *
 * 插件内控制器的路由统一放在本文件，随 plugin/curd 自动加载。
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
if (!function_exists('curd_route')) {
    /**
     * 注册一条插件路由；若宿主（或其它插件）已注册同 method+path 则跳过。
     *
     * @param string $method      GET / POST
     * @param string $path        完整路径（不再使用 Route::group，便于逐条冲突检测）
     * @param array  $callback    [控制器类, 方法]
     * @param array  $middlewares 中间件列表（顺序同 Route::group：第一位最终最外层）
     */
    function curd_route(string $method, string $path, array $callback, array $middlewares = []): void
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
$curdAuthMiddleware = [
    \plugin\curd\app\middleware\CorsMiddleware::class,
    \plugin\curd\app\middleware\AuthCheck::class,
    \plugin\curd\app\middleware\PermissionCheck::class,
];
// 仅 CORS（免鉴权接口）
$curdCorsOnly = [
    \plugin\curd\app\middleware\CorsMiddleware::class,
];

// ============================================================
// 插件内置前端（SPA）：{page_base}/* 静态托管 + history fallback
//  - 前端以 VITE_BASE_PATH=<page_base> 构建，产物放 plugin/curd/public/
//  - 真实文件静态返回；未命中路径（/dashboard 等前端路由）回退 index.html
//  - 不挂 AuthCheck：登录态由前端跳转控制
//  - page_base 与 config('plugin.curd.curd.page_base') 联动，可整体改前缀
// ============================================================
$curdPageBase = '/' . trim((string)config('plugin.curd.curd.page_base', '/app/curd'), '/');
curd_route('GET', $curdPageBase, [
    \plugin\curd\app\controller\PageController::class,
    'index',
]);
// 尾斜杠形式（FastRoute 不折叠尾斜杠，浏览器访问 /app/curd/ 时需显式注册）
curd_route('GET', $curdPageBase . '/', [
    \plugin\curd\app\controller\PageController::class,
    'index',
]);
curd_route('GET', $curdPageBase . '/{path:.+}', [
    \plugin\curd\app\controller\PageController::class,
    'index',
]);

// 登录接口（不需要鉴权，但必须保证响应带 CORS 头）
curd_route('POST', '/api/auth/login', [
    \plugin\curd\app\controller\AuthController::class,
    'login',
], $curdCorsOnly);

// ============================================================
// Web 安装向导（新项目首次安装用；安装完成生成 runtime/curd-installed.lock
// 后，setup 拒绝重复执行——页面也会引导直接进后台登录）
// ============================================================
curd_route('GET', '/app/curd-installer', [
    \plugin\curd\app\controller\InstallerController::class,
    'page',
]);
curd_route('GET', '/api/curd-installer/status', [
    \plugin\curd\app\controller\InstallerController::class,
    'status',
], $curdCorsOnly);
curd_route('POST', '/api/curd-installer/setup', [
    \plugin\curd\app\controller\InstallerController::class,
    'setup',
], $curdCorsOnly);
curd_route('GET', '/api/curd-installer/progress', [
    \plugin\curd\app\controller\InstallerController::class,
    'progress',
], $curdCorsOnly);

// 站点信息（免鉴权：登录页未登录时也要显示站点标题/logo）
curd_route('GET', '/api/config/site', [
    \plugin\curd\app\controller\AdminController::class,
    'siteConfig',
], $curdCorsOnly);

// ============================================================
// 其余 CURD 插件接口统一挂鉴权和权限中间件
// ============================================================

// 个人中心
curd_route('GET', '/api/admin/profile', [
    \plugin\curd\app\controller\AdminController::class,
    'profile',
], $curdAuthMiddleware);
curd_route('POST', '/api/admin/profile/update', [
    \plugin\curd\app\controller\AdminController::class,
    'profileUpdate',
], $curdAuthMiddleware);
curd_route('POST', '/api/admin/password/update', [
    \plugin\curd\app\controller\AdminController::class,
    'passwordUpdate',
], $curdAuthMiddleware);

// 系统管理：用户 / 角色（数据来自认证库，与业务库解耦）
curd_route('GET', '/api/admin/users', [\plugin\curd\app\controller\AdminController::class, 'users'], $curdAuthMiddleware);
curd_route('POST', '/api/admin/users/add', [\plugin\curd\app\controller\AdminController::class, 'userAdd'], $curdAuthMiddleware);
curd_route('POST', '/api/admin/users/update', [\plugin\curd\app\controller\AdminController::class, 'userUpdate'], $curdAuthMiddleware);
curd_route('POST', '/api/admin/users/delete', [\plugin\curd\app\controller\AdminController::class, 'userDelete'], $curdAuthMiddleware);
curd_route('POST', '/api/admin/users/batch-delete', [\plugin\curd\app\controller\AdminController::class, 'userBatchDelete'], $curdAuthMiddleware);

curd_route('GET', '/api/admin/roles', [\plugin\curd\app\controller\AdminController::class, 'roles'], $curdAuthMiddleware);
curd_route('POST', '/api/admin/roles/add', [\plugin\curd\app\controller\AdminController::class, 'roleAdd'], $curdAuthMiddleware);
curd_route('POST', '/api/admin/roles/update', [\plugin\curd\app\controller\AdminController::class, 'roleUpdate'], $curdAuthMiddleware);
curd_route('POST', '/api/admin/roles/delete', [\plugin\curd\app\controller\AdminController::class, 'roleDelete'], $curdAuthMiddleware);
curd_route('GET', '/api/admin/roles/options', [\plugin\curd\app\controller\AdminController::class, 'roleOptions'], $curdAuthMiddleware);

// 认证
curd_route('POST', '/api/auth/logout', [
    \plugin\curd\app\controller\AuthController::class,
    'logout',
], $curdAuthMiddleware);
curd_route('GET', '/api/auth/me', [
    \plugin\curd\app\controller\AuthController::class,
    'me',
], $curdAuthMiddleware);

// CURD 配置与模型接口
curd_route('GET', '/api/curd/tables', [
    \plugin\curd\app\controller\CurdController::class,
    'tables',
], $curdAuthMiddleware);
curd_route('GET', '/api/curd/models', [
    \plugin\curd\app\controller\CurdController::class,
    'models',
], $curdAuthMiddleware);
curd_route('GET', '/api/curd/schema', [
    \plugin\curd\app\controller\CurdController::class,
    'schema',
], $curdAuthMiddleware);
curd_route('GET', '/api/curd/generate', [
    \plugin\curd\app\controller\CurdController::class,
    'generate',
], $curdAuthMiddleware);
curd_route('GET', '/api/curd/config', [
    \plugin\curd\app\controller\CurdController::class,
    'config',
], $curdAuthMiddleware);
curd_route('GET', '/api/curd/config/list', [
    \plugin\curd\app\controller\CurdController::class,
    'configList',
], $curdAuthMiddleware);
curd_route('GET', '/api/curd/config/all', [
    \plugin\curd\app\controller\CurdController::class,
    'allConfigs',
], $curdAuthMiddleware);
curd_route('POST', '/api/curd/save-config', [
    \plugin\curd\app\controller\CurdController::class,
    'saveConfig',
], $curdAuthMiddleware);

// 自定义页面（.vue 运行时编译 / PHP Schema 组件树，插件通用能力）
//   业务 .vue 页面放 <宿主根>/app/custom/pages/；Schema 页面经 PageRegistry::register 声明
//   前端访问 /custom-page/<name>（catch-all 路由承载），/api/custom/* 仅登录即可
curd_route('GET', '/api/custom/pages', [
    \plugin\curd\app\controller\CustomPageController::class,
    'pages',
], $curdAuthMiddleware);
curd_route('GET', '/api/custom/page', [
    \plugin\curd\app\controller\CustomPageController::class,
    'content',
], $curdAuthMiddleware);

// 菜单管理
curd_route('GET', '/api/menu', [
    \plugin\curd\app\controller\MenuController::class,
    'index',
], $curdAuthMiddleware);
curd_route('GET', '/api/menu/all', [
    \plugin\curd\app\controller\MenuController::class,
    'all',
], $curdAuthMiddleware);
curd_route('POST', '/api/menu/add', [
    \plugin\curd\app\controller\MenuController::class,
    'add',
], $curdAuthMiddleware);
curd_route('POST', '/api/menu/update', [
    \plugin\curd\app\controller\MenuController::class,
    'update',
], $curdAuthMiddleware);
curd_route('POST', '/api/menu/delete', [
    \plugin\curd\app\controller\MenuController::class,
    'delete',
], $curdAuthMiddleware);

// 通用远程下拉数据源
curd_route('GET', '/api/options/model/{model}', [
    \plugin\curd\app\controller\OptionsController::class,
    'index',
], $curdAuthMiddleware);

// 上传（阿里云 OSS）
curd_route('POST', '/api/upload', [
    \plugin\curd\app\controller\UploadController::class,
    'upload',
], $curdAuthMiddleware);
curd_route('POST', '/api/upload/image', [
    \plugin\curd\app\controller\UploadController::class,
    'image',
], $curdAuthMiddleware);

// 模型 CURD 路由：具体操作必须放在模型详情通配路由之前
curd_route('GET', '/api/curd/model/{model}/export', [
    \plugin\curd\app\controller\CurdController::class,
    'export',
], $curdAuthMiddleware);
curd_route('POST', '/api/curd/model/{model}/add', [
    \plugin\curd\app\controller\CurdController::class,
    'add',
], $curdAuthMiddleware);
curd_route('POST', '/api/curd/model/{model}/update', [
    \plugin\curd\app\controller\CurdController::class,
    'update',
], $curdAuthMiddleware);
curd_route('POST', '/api/curd/model/{model}/delete', [
    \plugin\curd\app\controller\CurdController::class,
    'delete',
], $curdAuthMiddleware);
curd_route('POST', '/api/curd/model/{model}/batch-delete', [
    \plugin\curd\app\controller\CurdController::class,
    'batchDelete',
], $curdAuthMiddleware);
curd_route('POST', '/api/curd/model/{model}/action/{name}', [
    \plugin\curd\app\controller\CurdController::class,
    'action',
], $curdAuthMiddleware);
curd_route('GET', '/api/curd/model/{model}', [
    \plugin\curd\app\controller\CurdController::class,
    'list',
], $curdAuthMiddleware);
