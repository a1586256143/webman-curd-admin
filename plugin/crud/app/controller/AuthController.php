<?php
namespace plugin\crud\app\controller;

use plugin\crud\app\CrudDb;
use plugin\crud\app\rbac\Rbac;
use support\Request;

/**
 * 认证控制器
 * 独立认证:用户存认证库(admin_connection) admin_users 表,与业务库解耦
 * token 存认证库 admin_tokens 表
 * 权限:casbin RBAC(plugin\crud\app\rbac\Rbac)
 */
class AuthController
{
    /**
     * 认证库连接(独立认证库)
     */
    protected function db()
    {
        return CrudDb::adminDb();
    }

    /**
     * 登录
     */
    public function login(Request $request)
    {
        $username = $request->post('username', '');
        $password = $request->post('password', '');

        if (!$username || !$password) {
            return json(['code' => 400, 'msg' => '用户名和密码不能为空']);
        }

        $user = $this->db()->table('admin_users')->where('username', $username)->first();

        if (!$user || !password_verify($password, $user->password)) {
            return json(['code' => 401, 'msg' => '用户名或密码错误']);
        }

        if ((int)$user->status !== 1) {
            return json(['code' => 403, 'msg' => '账号已被禁用']);
        }

        // 生成并持久化 token(多会话)
        $token = bin2hex(random_bytes(32));
        $now   = date('Y-m-d H:i:s');
        $this->db()->table('admin_tokens')->insert([
            'admin_user_id' => $user->id,
            'token'         => $token,
            'expires_at'    => date('Y-m-d H:i:s', time() + 86400 * 7), // 7天
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        // 用户角色
        $roles = $this->db()->table('admin_role_user')
            ->leftJoin('roles', 'roles.id', '=', 'admin_role_user.role_id')
            ->where('admin_role_user.admin_user_id', $user->id)
            ->pluck('roles.slug')
            ->toArray();

        // 用户权限列表（casbin RBAC），便于前端在按钮/字段层级做权限判断
        $permissions = $this->loadPermissions((string)$user->id);

        return json(['code' => 200, 'msg' => 'success', 'data' => [
            'token' => $token,
            'user'  => [
                'id'          => $user->id,
                'username'    => $user->username,
                'name'        => $user->name,
                'avatar'      => $user->avatar,
                'email'       => property_exists($user, 'email') ? ($user->email ?? '') : '',
                'roles'       => $roles,
                'permissions' => $permissions,
            ],
        ]]);
    }

    /**
     * 退出登录(删除当前 token，并清除 Redis 缓存)
     */
    public function logout(Request $request)
    {
        $token = $this->extractToken($request);
        if ($token) {
            $row = $this->db()->table('admin_tokens')->where('token', $token)->first();
            $this->db()->table('admin_tokens')->where('token', $token)->delete();
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
        $roles = $this->db()->table('admin_role_user')
            ->leftJoin('roles', 'roles.id', '=', 'admin_role_user.role_id')
            ->where('admin_role_user.admin_user_id', $user->id)
            ->pluck('roles.slug')
            ->toArray();

        $permissions = $this->loadPermissions((string)$user->id);
        cache_user_roles($user->id, $roles);

        return json(['code' => 200, 'msg' => 'success', 'data' => [
            'id'          => $user->id,
            'username'    => $user->username,
            'name'        => $user->name,
            'avatar'      => $user->avatar,
            'email'       => property_exists($user, 'email') ? ($user->email ?? '') : '',
            'roles'       => $roles,
            'permissions' => $permissions,
        ]]);
    }

    /**
     * 加载用户的全部权限（直接授予 + 通过角色继承）
     * 返回 [{obj: 'crud.APackage', act: 'list'}, ...]
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
                    $existing[] = $obj.':'.$act;
                }
            }
            foreach ((array)$enforcer->getRolesForUser($userId) as $role) {
                foreach ((array)$enforcer->getPermissionsForUser($role) as $p) {
                    $obj = $p[1] ?? null;
                    $act = $p[2] ?? null;
                    if ($obj === null || $act === null) continue;
                    $k = $obj.':'.$act;
                    if (in_array($k, $existing, true)) continue;
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
