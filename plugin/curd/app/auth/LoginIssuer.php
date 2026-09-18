<?php
namespace plugin\curd\app\auth;

use plugin\curd\app\CurdDb;

/**
 * 登录签发器：token 签发 + 标准登录响应组装（供自定义登录复用）
 * ------------------------------------------------------------------
 * 内置登录（AuthController::login）用的就是它；你写自己的登录逻辑时
 * 也调它，前端就能拿到完全一致的响应结构（token + user），不用关心
 * admin_tokens 表结构 / 权限开关 / 响应字段。
 *
 * 用法一：完全自己写登录接口（宿主 config/route.php 注册 POST /api/auth/login
 *         会覆盖插件同名路由，见 docs 第 4 章）
 *
 *   public function login(Request $request)
 *   {
 *       $user = $this->myOwnVerify($request->post('username'), $request->post('password'));
 *       if (!$user) {
 *           return json(['code' => 401, 'msg' => '用户名或密码错误']);
 *       }
 *       return json(LoginIssuer::issue([
 *           'id'       => $user->id,          // 必填：唯一 id（int|string）
 *           'username' => $user->account,     // 必填
 *           'name'     => $user->nickname,    // 必填：显示名
 *           'status'   => 1,                  // 必填：1=启用
 *           'avatar'   => $user->avatar ?? '',
 *           'roles'    => ['manager'],        // 可选：角色 slug（写 casbin g 规则由你决定）
 *           'permissions' => [['obj' => 'curd.ABusiness', 'act' => 'list']], // 可选
 *       ]));
 *   }
 *
 * 用法二：只签发 token，响应自己拼
 *
 *   $token = LoginIssuer::issueToken($identity);
 *   return json(['code' => 200, 'msg' => 'success', 'data' => ['token' => $token, 'extra' => ...]]);
 *
 * 用法三：配置委派（不用改路由文件）——宿主 config/curd.php：
 *
 *   'login_handler' => \app\admin\MyLogin::class,   // 类需有 login(Request): Response
 */
final class LoginIssuer
{
    use AuthProviderAware;

    /** token 有效期（秒），默认 7 天 */
    public const TOKEN_TTL = 604800;

    /**
     * 签发 token 并落库（admin_tokens.admin_user_id 存身份里的 id）
     */
    public static function issueToken(array $identity, int $ttl = self::TOKEN_TTL): string
    {
        $token = bin2hex(random_bytes(32));
        $now   = date('Y-m-d H:i:s');

        CurdDb::adminDb()->table('admin_tokens')->insert([
            'admin_user_id' => $identity['id'] ?? 0,
            'token'         => $token,
            'expires_at'    => date('Y-m-d H:i:s', time() + $ttl),
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        return $token;
    }

    /**
     * 签发 token + 组装标准登录响应（前端登录页约定的结构）
     *
     * @param array $identity 身份数组：必填 id/username/name/status，
     *                        可选 avatar/email/roles/permissions
     * @return array ['code' => 200, 'msg' => 'success', 'data' => ['token' =>, 'user' => [...]]]
     */
    public static function issue(array $identity, int $ttl = self::TOKEN_TTL): array
    {
        $self = new self();
        $token = self::issueToken($identity, $ttl);

        // 权限总开关关闭时不下发权限（不产生、不暴露权限）
        $permissions = $self->permissionEnabled() ? ($identity['permissions'] ?? []) : [];

        return [
            'code' => 200,
            'msg'  => 'success',
            'data' => [
                'token' => $token,
                'user'  => [
                    'id'                 => $identity['id'] ?? 0,
                    'username'           => $identity['username'] ?? '',
                    'name'               => $identity['name'] ?? '',
                    'avatar'             => $identity['avatar'] ?? '',
                    'email'              => $identity['email'] ?? '',
                    'roles'              => $identity['roles'] ?? [],
                    'permissions'        => $permissions,
                    'permission_enabled' => $self->permissionEnabled(),
                ],
            ],
        ];
    }
}
