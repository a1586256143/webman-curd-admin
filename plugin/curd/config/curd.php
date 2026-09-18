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

    // 单次导出的最大行数（<=0 = 不限制）。
    // 读取优先级：宿主 config/admin.php 的 export_max_rows（推荐改这个）→ 本键 → 兜底 100000。
    // 超限时导出接口返回 {"code":400,"msg":"本次将导出 N 条，超过单次导出上限 M 条..."}，
    // 前端会把它当错误提示而不是下载文件（见 dynamicCurd/index.vue 的 export）。
    'export_max_rows' => 100000,

    // 默认落地页（前端路由路径）：登录成功后、以及直接访问后台根路径 / 刷新时打开它。
    // 用「相对路径」写，不带 /app/curd 前缀（部署前缀由前端 VITE_BASE_PATH 决定）：
    //   '/custom-page/home'  → 自定义页面（app/custom/pages/home.vue 或 PageRegistry::register('home', ...)）
    //   '/dashboard'         → 内置首页（默认值，不配置即保持旧行为）
    //   也可以写完整 URL（http://...），前端会整页跳转过去。
    // 优先级：宿主 config/admin.php 的 home_page → config/admin.php site.home_page → 本键 → /dashboard
    // 注意：这里只决定「打开哪个页面」，不校验用户有没有该页面的菜单/权限。
    'home_page' => '/dashboard',

    // ===== 登录图形验证码（webman/captcha）=====
    // 登录页是否显示验证码；校验发生在 AuthController::login 的最前面（含自定义 login_handler 之前）。
    //   读取优先级：宿主 config/admin.php 的 captcha_enabled → 本键 → true
    //   关掉即登录页完全不显示验证码（也用于 Redis 异常时的应急开关：验证码明文存 Redis，
    //   Redis 挂了就没人能登录，此时把它设为 false 并重启即可放行）
    'captcha_enabled' => true,

    // 验证码有效期（秒）：超时提示「验证码已过期，请点击图片重新获取」
    'captcha_ttl' => 300,

    // 验证码位数（3~6，越界回落 4）；字符集已剔除易混的 i/l/o/0/1（见 app/auth/Captcha.php）
    'captcha_length' => 4,

    // /api/admin/*（用户/角色/个人中心）是否强制 RBAC 校验
    //   false（默认）= 仅登录即可（与宿主历史行为一致，兼容存量项目）
    //   true          = 走 casbin 校验，obj 由路径推导（/api/admin/users → obj=admin act=users）
    // 新项目建议设 true：默认 admin 角色有 p,admin,*,* 不受影响，
    // 其余角色需在「角色管理」勾选对应权限后才可访问。
    'admin_require_permission' => false,

    // 登录提供方（可插拔）：实现 AuthProviderInterface 的类名。
    //   不设 = 内置 DefaultAuthProvider（admin_users 表 + password_verify 校验，原行为）。
    //   设为自定义类即可换登录表 / 换校验逻辑 / 对接外部账号体系：
    //   class MyAuth implements \plugin\curd\app\auth\AuthProviderInterface {
    //       public function login(array $c): ?array { /* 校验后返回身份数组 */ }
    //       public function identity($id): ?array { /* 重新取完整身份 */ }
    //       public function resolveUser($id): ?object { /* 还原 $request->user */ }
    //       public function logout(\support\Request $r): void {}
    //   }
    //   控制器只认返回的「身份数组」，token 签发/存储由包统一处理。
    'auth_provider' => \plugin\curd\app\auth\DefaultAuthProvider::class,

    // 自定义登录入口（可选）：整个 /api/auth/login 交给这个类处理。
    // 与 auth_provider 的区别：
    //   auth_provider  只换「凭据校验 + 身份来源」，token 签发与响应结构仍由包负责（推荐先试这个）
    //   login_handler  连响应结构一起接管，适合要加验证码 / 风控 / 外部单点登录 / 额外返回字段的场景
    // 用法（宿主 config/curd.php）：
    //   'login_handler' => \app\admin\MyLogin::class,
    // 类约定：public function login(\support\Request $request): \Webman\Http\Response
    //   校验通过后调 \plugin\curd\app\auth\LoginIssuer::issue($identity) 拿到标准响应即可：
    //   return json(LoginIssuer::issue(['id' => 1, 'username' => 'x', 'name' => 'X', 'status' => 1]));
    // 留空 = 用内置登录逻辑。
    'login_handler' => '',

    // 权限总开关：
    //   true  （默认）= 走 RBAC 校验、登录/me 下发权限；
    //   false           = 关闭权限校验（所有人放行），且不生成/不下发任何权限。
    //   关闭后 PermissionCheck 直接放行，Install 不再写 casbin_rule。
    'permission_enabled' => true,

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
