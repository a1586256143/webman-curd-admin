<?php
namespace plugin\crud\app\middleware;

use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;
use plugin\crud\app\CrudDb;
use plugin\crud\app\rbac\Rbac;

/**
 * 权限校验中间件（插件内置版，casbin RBAC）
 * 基于当前登录用户($request->user)校验其对 资源:操作 的权限
 *
 * 权限标识从请求路径自动推导（无需逐路由配置）:
 *   GET  /api/crud/model/APackage       → obj=crud.APackage.list,     act=*
 *   POST /api/crud/model/APackage/add   → obj=crud.APackage.add,      act=*
 *   POST /api/crud/model/APackage/delete→ obj=crud.APackage.delete,   act=*
 *   POST /api/crud/model/APackage/action/audit → obj=crud.APackage.audit, act=*
 * 即 CRUD 权限精确到「模型.操作」粒度，配合 permissions 表里的
 * 「管理容器(crud.{Model}) + 操作路由(crud.{Model}.{op})」树形结构，
 * 角色可勾选容器自动获得全部操作，也可单独去掉某一个操作实现精准控制。
 *
 * 兼容性：若该模型尚未拆分成「模型.操作」粒度（menus 表里没有
 * crud.{Model}.% 节点），则回退到旧的模型级容器权限 crud.{Model}，保证旧数据可用。
 *
 * 白名单: 以下路径仅登录即可(不校验权限)
 *   - /api/auth/* (登录/登出/当前用户)
 *   - /api/menu    (菜单是登录后必用的基础数据,可按需加回)
 *
 * 未命中任何规则的路径: 仅要求登录(AuthCheck 已保证), 不强制权限
 * 这保证引入 RBAC 后系统默认可用, 需要收紧的接口再补策略即可
 */
class PermissionCheck implements MiddlewareInterface
{
    /**
     * 免权限校验路径前缀(仅登录)
     * 注意：表结构探测（tables/schema/generate）与配置写入（save-config）
     * 已移出白名单，走 RBAC 校验（admin 通配可访问，普通用户需配对应策略）
     */
    protected array $whitelist = [
        '/api/auth/',
        '/api/menu',
        '/api/crud/config',
        '/api/admin/',
        '/api/custom/',
        '/api/schema/',
    ];

    public function process(Request $request, callable $handler): Response
    {
        // 未登录(理论不会发生,AuthCheck 已拦)直接放行给 AuthCheck 报 401
        if (empty($request->user)) {
            return $handler($request);
        }

        $path = $request->path();
        $method = strtoupper($request->method());

        // 白名单:仅登录即可
        // /api/admin/ 例外：config('plugin.crud.crud.admin_require_permission') 打开后
        // 该类敏感接口（用户/角色管理）需走 casbin 校验，默认关闭以保持历史行为。
        // 用 filter_var 而非 (bool)：.env 里写 false 取到的是字符串 'false'，(bool) 会误判为真
        $adminRequiresPermission = filter_var(
            config('plugin.crud.crud.admin_require_permission', false),
            FILTER_VALIDATE_BOOLEAN
        );
        foreach ($this->whitelist as $prefix) {
            if ($prefix === '/api/admin/' && $adminRequiresPermission) {
                continue;
            }
            if (str_starts_with($path, $prefix)) {
                return $handler($request);
            }
        }

        // 推导权限标识: obj / act
        [$obj, $act] = $this->resolvePermission($path, $method);

        // 推导不出权限标识的路径: 按配置处理（config('plugin.crud.crud.allow_unresolved')）
        //   true  = 仅登录放行（默认，兼容旧路径）
        //   false = 一律 403（严格模式）
        if ($obj === '') {
            $allow = config('plugin.crud.crud.allow_unresolved',
                config('app.permission.allow_unresolved', true));
            if ($allow) {
                return $handler($request);
            }
            return json(['code' => 403, 'msg' => '没有权限操作: ' . $path]);
        }

        $userId = (string)$request->user->id;

        // 校验:用户ID 直接有权限,或通过角色继承有权限(casbin 通配 admin 放行)
        $allowed = Rbac::enforce($userId, $obj, $act);

        // 兼容旧模型：CRUD 路由若尚未拆分为「模型.操作」粒度（没有 crud.{Model}.% 节点），
        // 则回退到模型级容器权限 crud.{Model}，避免升级后旧角色丢失权限。
        if (!$allowed && $this->isCrudObj($obj)) {
            $model = $this->modelFromObj($obj);
            if (!$this->modelIsGranular($model)) {
                $allowed = Rbac::enforce($userId, 'crud.' . $model, $act);
            }
        }

        if (!$allowed) {
            return json(['code' => 403, 'msg' => "没有权限操作: {$obj}:{$act}"]);
        }

        return $handler($request);
    }

    /**
     * 从路径推导 (obj, act)
     */
    protected function resolvePermission(string $path, string $method): array
    {
        $path = '/' . trim($path, '/');
        $segments = array_values(array_filter(explode('/', $path), fn($s) => $s !== ''));

        // /api/xxx/... → 去掉 /api
        array_shift($segments);
        if (empty($segments)) {
            return ['', ''];
        }

        $module = $segments[0]; // crud / options / menu / order / package ...

        // CRUD 模型路由: /api/crud/model/{Model}[/action]
        if ($module === 'crud' && ($segments[1] ?? '') === 'model') {
            $model = $segments[2] ?? '';
            if ($model === '') {
                return ['', ''];
            }
            $act = $segments[3] ?? $this->defaultAction($method);
            // 精确到「模型.操作」粒度：crud.Payment.list / crud.Payment.delete / crud.Payment.audit ...
            return ['crud.' . $model . '.' . $act, '*'];
        }

        // options 数据源: /api/options/model/{Model}
        if ($module === 'options') {
            $model = $segments[2] ?? '';
            return $model !== '' ? ['options.' . $model, 'list'] : ['', ''];
        }

        // 其他: /api/order/index → obj=order act=index; /api/order/mark-deduct → act=mark-deduct
        $action = $segments[1] ?? $this->defaultAction($method);
        return [$module, $action];
    }

    protected function defaultAction(string $method): string
    {
        return match ($method) {
            'GET'     => 'list',
            'POST'    => 'add',
            'PUT'     => 'update',
            'DELETE'  => 'delete',
            'PATCH'   => 'update',
            default   => 'list',
        };
    }

    /**
     * 是否为 CRUD 粒度权限 obj（crud.{Model}.{act}）
     */
    protected function isCrudObj(string $obj): bool
    {
        return str_starts_with($obj, 'crud.');
    }

    /**
     * 从 crud.{Model}.{act} 取出 Model 段
     */
    protected function modelFromObj(string $obj): string
    {
        $parts = explode('.', $obj, 3);
        return $parts[1] ?? '';
    }

    /**
     * 该模型是否已拆分为「模型.操作」粒度权限节点。
     * 判断 menus 表是否存在 crud.{Model}.% 的 permission。
     * 结果按请求内静态缓存，避免每条 CRUD 请求都查库。
     */
    private static array $granularCache = [];

    protected function modelIsGranular(string $model): bool
    {
        if (array_key_exists($model, self::$granularCache)) {
            return self::$granularCache[$model];
        }
        try {
            $exists = CrudDb::adminTable('menus')
                ->where('permission', 'like', 'crud.' . $model . '.%')
                ->exists();
        } catch (\Throwable $e) {
            $exists = false;
        }
        self::$granularCache[$model] = $exists;
        return $exists;
    }
}
