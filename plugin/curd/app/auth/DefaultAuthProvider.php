<?php
namespace plugin\curd\app\auth;

use plugin\curd\app\CurdDb;
use plugin\curd\app\rbac\Rbac;
use support\Request;

/**
 * 内置默认登录提供方（与原 admin_users 行为完全一致）
 * ------------------------------------------------------------------
 * 既作为缺省实现（config 未配置 auth_provider 时生效），
 * 也作为自定义提供方的参考样例：换表 / 换校验只需重写 login()+identity()+resolveUser()。
 */
class DefaultAuthProvider implements AuthProviderInterface
{
    public function login(array $credentials): ?array
    {
        $username = (string)($credentials['username'] ?? '');
        $password = (string)($credentials['password'] ?? '');
        if ($username === '' || $password === '') {
            return null;
        }

        $user = CurdDb::adminDb()->table('admin_users')->where('username', $username)->first();
        if (!$user || !password_verify($password, $user->password)) {
            return null;
        }
        if ((int)($user->status ?? 1) !== 1) {
            return null; // 禁用：返回 null，由 AuthController 统一报 403
        }

        return $this->buildIdentity($user);
    }

    public function identity($id): ?array
    {
        $user = CurdDb::adminDb()->table('admin_users')->where('id', $id)->first();
        return $user ? $this->buildIdentity($user) : null;
    }

    public function resolveUser($id): ?object
    {
        return CurdDb::adminDb()->table('admin_users')->where('id', $id)->first();
    }

    public function logout(Request $request): void
    {
        // 默认无需额外清理；token 由包统一删除
    }

    /**
     * 由 admin_users 行构建标准身份数组（roles / permissions 取自 casbin）。
     */
    protected function buildIdentity($user): array
    {
        return [
            'id'          => $user->id,
            'username'    => $user->username,
            'name'        => $user->name,
            'status'      => (int)$user->status,
            'avatar'      => $user->avatar ?? '',
            'email'       => $user->email ?? '',
            'roles'       => $this->userRoles((int)$user->id),
            'permissions' => $this->userPermissions((int)$user->id),
        ];
    }

    protected function userRoles(int $userId): array
    {
        try {
            return CurdDb::adminDb()->table('admin_role_user')
                ->leftJoin('roles', 'roles.id', '=', 'admin_role_user.role_id')
                ->where('admin_role_user.admin_user_id', $userId)
                ->pluck('roles.slug')
                ->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    protected function userPermissions(int $userId): array
    {
        $out = [];
        try {
            $enforcer = Rbac::enforcer();
            $existing = [];
            foreach ((array)$enforcer->getPermissionsForUser($userId) as $p) {
                $obj = $p[1] ?? null;
                $act = $p[2] ?? null;
                if ($obj === null || $act === null) {
                    continue;
                }
                $out[] = ['obj' => (string)$obj, 'act' => (string)$act];
                $existing[] = $obj . ':' . $act;
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
}
