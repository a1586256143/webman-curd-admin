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
 *
 * 想在账号自身 status 之外叠加一层业务判定（如关联平台账户被关闭），
 * 不必继承本类覆盖三个方法：实现 AuthStateGuardInterface 配到
 * config/curd-admin.php 的 auth_state_guard 即可，三个入口由本类统一收口。
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

        return $this->applyStateGuard($this->buildIdentity($user));
    }

    public function identity($id): ?array
    {
        $user = CurdDb::adminDb()->table('admin_users')->where('id', $id)->first();
        return $user ? $this->applyStateGuard($this->buildIdentity($user)) : null;
    }

    public function resolveUser($id): ?object
    {
        $user = CurdDb::adminDb()->table('admin_users')->where('id', $id)->first();
        if ($user && $this->stateGuard()?->allowed($this->guardSubject($user)) === false) {
            $user->status = 0; // AuthCheck 中间件：status ≠ 1 → 403「账号已被禁用」
        }
        return $user;
    }

    /**
     * 解析附加状态校验器（配置驱动，未配置或类不存在时返回 null = 不启用）。
     * 配置项：config('plugin.curd.curd.auth_state_guard')
     */
    protected function stateGuard(): ?AuthStateGuardInterface
    {
        $cls = config('plugin.curd.curd.auth_state_guard', '');
        if (!is_string($cls) || $cls === '' || !class_exists($cls)) {
            return null;
        }
        $inst = new $cls();
        return $inst instanceof AuthStateGuardInterface ? $inst : null;
    }

    /**
     * 把「身份数组」与「admin_users 行对象」规范成校验器约定的同一形状
     * （id / username / name / status），让宿主侧只面对一种数据结构。
     *
     * @param array|object $source
     */
    protected function guardSubject($source): object
    {
        $get = static function (string $key, $default = '') use ($source) {
            if (is_array($source)) {
                return $source[$key] ?? $default;
            }
            return $source->{$key} ?? $default;
        };

        return (object) [
            'id'       => $get('id'),
            'username' => (string)$get('username'),
            'name'     => (string)$get('name'),
            'status'   => (int)$get('status', 1),
        ];
    }

    /**
     * 身份数组统一收口：校验器判定为不可用则把 status 压成 0，
     * 由 AuthController::login() / AuthCheck 统一报 403（不改变对外错误语义）。
     */
    protected function applyStateGuard(array $identity): array
    {
        if ($this->stateGuard()?->allowed($this->guardSubject($identity)) === false) {
            $identity['status'] = 0;
        }
        return $identity;
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
