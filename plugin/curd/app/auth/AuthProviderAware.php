<?php
namespace plugin\curd\app\auth;

/**
 * 登录提供方 / 权限开关 共享 trait
 * ------------------------------------------------------------------
 * 供 AuthController、AuthCheck、PermissionCheck、Install 复用，
 * 保证「当前生效的登录提供方」与「权限总开关」判定逻辑只有一份。
 */
trait AuthProviderAware
{
    /**
     * 解析当前生效的登录提供方（配置驱动，缺省回退内置 DefaultAuthProvider）。
     * 配置项：config('plugin.curd.curd.auth_provider')
     */
    protected function authProvider(): AuthProviderInterface
    {
        $cls = config('plugin.curd.curd.auth_provider', DefaultAuthProvider::class);
        if (is_string($cls) && class_exists($cls)) {
            $inst = new $cls();
            if ($inst instanceof AuthProviderInterface) {
                return $inst;
            }
        }
        return new DefaultAuthProvider();
    }

    /**
     * 权限总开关。
     *   true  （默认）= 走 RBAC 校验、下发权限；
     *   false           = 不校验权限、不产生/不下发权限（所有人放行）。
     * 配置项：config('plugin.curd.curd.permission_enabled')
     */
    protected function permissionEnabled(): bool
    {
        return filter_var(
            config('plugin.curd.curd.permission_enabled', true),
            FILTER_VALIDATE_BOOLEAN
        );
    }
}
