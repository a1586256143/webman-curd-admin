<?php
namespace plugin\curd\app\controller;

use plugin\curd\app\auth\AuthProviderAware;
use plugin\curd\app\auth\Captcha;
use plugin\curd\app\auth\LoginIssuer;
use plugin\curd\app\CurdDb;
use plugin\curd\app\rbac\Rbac;
use support\Request;

/**
 * 认证控制器
 * ------------------------------------------------------------------
 * 登录提供方可插拔（config/curd.php auth_provider），默认 admin_users；
 * 权限受总开关控制（config/curd.php permission_enabled）。
 *
 * token 存认证库 admin_tokens 表（admin_user_id 存放提供方返回的用户 id）。
 *
 * 想「完全自己写一套登录」有三种方式（docs 第 4 章有完整示例）：
 *   ① config/curd.php 的 login_handler 指向你的类（login(Request): Response）——
 *      本控制器直接把 /api/auth/login 交给它，其余接口不变；
 *   ② 宿主 config/route.php 里注册 POST /api/auth/login 指向自己的控制器
 *      （插件路由检测到已注册会跳过，宿主优先）；
 *   ③ 只换凭据校验：实现 AuthProviderInterface 配到 auth_provider。
 * 自定义实现里用 LoginIssuer::issue($identity) 签发 token + 拼标准响应即可。
 *
 * 登录图形验证码（webman/captcha）：
 *   默认开启，GET /api/auth/captcha 取图，登录时带 captcha_key + captcha_code。
 *   校验发生在「委派自定义 login_handler 之前」，所以自定义登录入口不用自己实现验证码；
 *   不需要验证码的宿主在 config/admin.php 里 'captcha_enabled' => false 即可（详见 app/auth/Captcha.php）。
 */
class AuthController
{
    use AuthProviderAware;

    /**
     * 输出登录验证码
     *
     * 响应：{"code":200,"data":{"key":"<32位随机串>","image":"data:image/jpeg;base64,..."}}
     * 前端把 image 直接塞进 <img src>，登录时把 key 原样回传（见 app/auth/Captcha.php）。
     */
    public function captcha(Request $request)
    {
        if (!Captcha::enabled()) {
            return json(['code' => 403, 'msg' => '验证码未启用']);
        }
        try {
            $data = Captcha::make();
        } catch (\Throwable $e) {
            // 常见原因：PHP 未装 ext-gd / 字体文件缺失 / Redis 不可用
            return json(['code' => 500, 'msg' => '验证码生成失败：' . $e->getMessage()]);
        }
        return json(['code' => 200, 'msg' => 'success', 'data' => $data]);
    }

    /**
     * 登录
     */
    public function login(Request $request)
    {
        // ① 验证码校验（在所有分支之前，含下面的自定义 login_handler 委派）：
        //    自定义登录类不必自己实现验证码；不想要验证码就关掉 captcha_enabled。
        //    失败响应带 data.captcha = true，前端据此自动展开验证码输入框并刷新一张图。
        if (Captcha::enabled()) {
            $error = Captcha::verify(
                $request->post('captcha_key', ''),
                $request->post('captcha_code', '')
            );
            if ($error !== '') {
                return json(['code' => 400, 'msg' => $error, 'data' => ['captcha' => true]]);
            }
        }

        // ② 自定义登录入口：config/curd.php 'login_handler' => 你的类名（login(Request): Response）
        //    命中就把整个登录逻辑交出去（含响应结构），便于接入风控/外部账号体系。
        $handler = config('plugin.curd.curd.login_handler', '');
        if (is_string($handler) && $handler !== '' && class_exists($handler)) {
            $instance = new $handler();
            if (method_exists($instance, 'login')) {
                return $instance->login($request);
            }
        }

        $credentials = [
            'username' => $request->post('username', ''),
            'password' => $request->post('password', ''),
        ];

        $identity = $this->authProvider()->login($credentials);
        if ($identity === null) {
            return json(['code' => 401, 'msg' => '用户名或密码错误']);
        }
        if ((int)($identity['status'] ?? 1) !== 1) {
            return json(['code' => 403, 'msg' => '账号已被禁用']);
        }

        // 签发 token + 组装标准响应（自定义登录复用同一份 LoginIssuer）
        return json(LoginIssuer::issue($identity));
    }

    /**
     * 退出登录（先回调提供方，再清 token + Redis 缓存）
     */
    public function logout(Request $request)
    {
        $this->authProvider()->logout($request);

        $token = $this->extractToken($request);
        if ($token) {
            $db  = CurdDb::adminDb();
            $row = $db->table('admin_tokens')->where('token', $token)->first();
            $db->table('admin_tokens')->where('token', $token)->delete();
            try {
                \support\Redis::del('admin_token:' . $token);
                if ($row && !empty($row->admin_user_id)) {
                    \support\Redis::del('user_roles:' . $row->admin_user_id);
                }
            } catch (\Throwable $e) {
                // 忽略：Redis 不可用时仅删 DB 行
            }
        }
        return json(['code' => 200, 'msg' => 'success']);
    }

    /**
     * 当前登录用户信息
     */
    public function me(Request $request)
    {
        $user = $request->user;

        // 优先用提供方取完整身份（自定义提供方也能正确返回 roles/permissions）
        $identity = $this->authProvider()->identity($user->id);
        if ($identity === null) {
            $identity = [
                'id'       => $user->id,
                'username' => $user->username,
                'name'     => $user->name,
                'status'   => 1,
                'avatar'   => $user->avatar ?? '',
                'roles'    => [],
                'permissions' => [],
            ];
        }

        $roles       = $identity['roles'] ?? [];
        $permissions = $this->permissionEnabled() ? ($identity['permissions'] ?? []) : [];
        cache_user_roles($user->id, $roles);

        return json(['code' => 200, 'msg' => 'success', 'data' => [
            'id'                => $identity['id'],
            'username'          => $identity['username'] ?? $user->username,
            'name'              => $identity['name'] ?? $user->name,
            'avatar'            => $identity['avatar'] ?? ($user->avatar ?? ''),
            'email'             => $identity['email'] ?? '',
            'roles'             => $roles,
            'permissions'       => $permissions,
            'permission_enabled' => $this->permissionEnabled(),
        ]]);
    }

    /**
     * 加载用户的全部权限（直接授予 + 通过角色继承）。仅权限开启时调用。
     * @deprecated 权限读取已上移到 AuthProviderInterface::identity()，保留供自定义兜底。
     */
    protected function loadPermissions(string $userId): array
    {
        $out = [];
        try {
            $enforcer = Rbac::enforcer();
            $existing = [];
            foreach ((array)$enforcer->getPermissionsForUser($userId) as $p) {
                $obj = $p[1] ?? null;
                $act = $p[2] ?? null;
                if ($obj !== null && $act !== null) {
                    $out[] = ['obj' => (string)$obj, 'act' => (string)$act];
                    $existing[] = $obj . ':' . $act;
                }
            }
            foreach ((array)$enforcer->getRolesForUser($userId) as $role) {
                foreach ((array)$enforcer->getPermissionsForUser($role) as $p) {
                    $obj = $p[1] ?? null;
                    $act = $p[2] ?? null;
                    if ($obj === null || $act === null) {
                        continue;
                    }
                    $k = $obj . ':' . $act;
                    if (in_array($k, $existing, true)) {
                        continue;
                    }
                    $existing[] = $k;
                    $out[] = ['obj' => (string)$obj, 'act' => (string)$act];
                }
            }
        } catch (\Throwable $e) {
            // 容错：casbin 不可用时回空
        }
        return $out;
    }

    /**
     * 从请求头提取 Bearer token
     */
    protected function extractToken(Request $request): string
    {
        $authorization = $request->header('authorization', '');
        if (preg_match('/Bearer\s+(.+)/i', $authorization, $m)) {
            return trim($m[1]);
        }
        return '';
    }
}
