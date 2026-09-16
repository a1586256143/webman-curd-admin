<?php
namespace plugin\curd\app\controller;

use plugin\curd\app\auth\AuthProviderAware;
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
 */
class AuthController
{
    use AuthProviderAware;

    /**
     * 登录
     */
    public function login(Request $request)
    {
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

        // 生成并持久化 token（多会话；admin_user_id 存提供方返回的用户 id）
        $token = bin2hex(random_bytes(32));
        $now   = date('Y-m-d H:i:s');
        CurdDb::adminDb()->table('admin_tokens')->insert([
            'admin_user_id' => $identity['id'],
            'token'         => $token,
            'expires_at'    => date('Y-m-d H:i:s', time() + 86400 * 7), // 7 天
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        // 权限总开关关闭时，不携带权限（不产生权限）
        $permissions = $this->permissionEnabled() ? ($identity['permissions'] ?? []) : [];

        return json(['code' => 200, 'msg' => 'success', 'data' => [
            'token' => $token,
            'user'  => [
                'id'                => $identity['id'],
                'username'          => $identity['username'] ?? '',
                'name'              => $identity['name'] ?? '',
                'avatar'            => $identity['avatar'] ?? '',
                'email'             => $identity['email'] ?? '',
                'roles'             => $identity['roles'] ?? [],
                'permissions'       => $permissions,
                'permission_enabled' => $this->permissionEnabled(),
            ],
        ]]);
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
