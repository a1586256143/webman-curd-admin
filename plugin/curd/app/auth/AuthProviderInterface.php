<?php
namespace plugin\curd\app\auth;

use support\Request;

/**
 * 可插拔登录提供方接口
 * ------------------------------------------------------------------
 * 实现本接口并把类名配置到 config/curd.php 的 `auth_provider`，
 * 即可替换包内置的 admin_users 登录逻辑（换表 / 换校验方式 / 对接外部账号体系）。
 *
 * 约定：
 *  - login()      成功返回身份数组，凭据错误/失败返回 null；
 *                 数组必须含键：id(int|string), username(string), name(string), status(int, 1=启用)；
 *                 可选键：avatar(string), email(string), roles(array 角色slug), permissions(array 权限项)。
 *                 包会据此统一签发 token 并完成「登录成功」动作（写 admin_tokens、组装响应）。
 *  - identity()   按 user id 重新取回完整身份（/api/auth/me 用）；返回形状同 login，找不到返回 null。
 *  - resolveUser() 按 user id 还原用户对象，供 AuthCheck 中间件填充 $request->user；
 *                 对象须含属性：id, username, name, status, avatar（status=1 才放行）。
 *  - logout()     退出时回调（默认 token 清理由包负责，可在此清理外部会话）。
 *
 * 注意：admin_tokens.admin_user_id 存放的是「提供方返回的用户 id」，
 *       自定义提供方请保证该 id 与 resolveUser() 入参一致（建议数值型）。
 */
interface AuthProviderInterface
{
    /**
     * 校验凭据，成功返回身份数组，失败返回 null。
     * @param array{credentials:string,username:string,password:string} $credentials
     */
    public function login(array $credentials): ?array;

    /**
     * 按 user id 重新取回完整身份（/api/auth/me 使用）。
     * @param int|string $id
     * @return array{id:mixed,username:string,name:string,status:int,avatar?:string,email?:string,roles?:array,permissions?:array}|null
     */
    public function identity($id): ?array;

    /**
     * 按 user id 还原用户对象（AuthCheck 填充 $request->user 用）。
     * @param int|string $id
     */
    public function resolveUser($id): ?object;

    /**
     * 退出时回调（默认无需额外清理）。
     */
    public function logout(Request $request): void;
}
