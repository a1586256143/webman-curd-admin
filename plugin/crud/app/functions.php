<?php
/**
 * CRUD 插件全局辅助函数（插件内置版，随 config/autoload.php 加载）
 *
 * 与宿主 app/functions.php 同名函数保持一致语义，均以 function_exists
 * 保护：宿主先加载则用宿主版，插件先加载则用插件版（等价实现）。
 * 插件版内部访问插件自带 Rbac / CrudDb，不再依赖宿主类。
 */

use plugin\crud\app\CrudDb;
use plugin\crud\app\rbac\Rbac;

if (!function_exists('current_user')) {
    /**
     * 当前登录用户对象（基于 AuthCheck 中间件注入到 $request->user）
     * 用法：current_user()->id / current_user()->name
     */
    function current_user(): ?object
    {
        try {
            $request = request();
        } catch (\Throwable $e) {
            return null;
        }
        return $request->user ?? null;
    }
}

if (!function_exists('current_user_id')) {
    /**
     * 当前登录用户 id（未登录返回 null）
     * 用法：if (current_user_id() === 1) { ... }
     */
    function current_user_id(): ?int
    {
        $u = current_user();
        return $u && isset($u->id) ? (int)$u->id : null;
    }
}

if (!function_exists('current_user_name')) {
    /**
     * 当前登录用户显示名（name || username）
     */
    function current_user_name(): ?string
    {
        $u = current_user();
        if (!$u) return null;
        return $u->name ?? $u->username ?? null;
    }
}

if (!function_exists('current_user_roles')) {
    /**
     * 当前用户角色 slug 列表
     * 优先用缓存：user_roles:{id}，TTL 300s
     * 缓存未命中回源认证库 admin_role_user + roles 表
     *
     * @return string[] 角色 slug 列表（如 ['admin','manager']）
     */
    function current_user_roles(): array
    {
        $uid = current_user_id();
        if (!$uid) return [];
        try {
            $cached = \support\Redis::get('user_roles:' . $uid);
            if ($cached !== false && $cached !== null) {
                $arr = json_decode($cached, true);
                if (is_array($arr)) return $arr;
            }
        } catch (\Throwable $e) {
            // 忽略缓存异常
        }
        $roles = [];
        try {
            $rows = CrudDb::adminTable('admin_role_user')
                ->leftJoin('roles', 'roles.id', '=', 'admin_role_user.role_id')
                ->where('admin_role_user.admin_user_id', $uid)
                ->pluck('roles.slug')
                ->toArray();
            $roles = array_values(array_filter((array)$rows, fn($v) => $v !== null && $v !== ''));
        } catch (\Throwable $e) {
            // 容错：表结构异常时回空数组
        }
        try {
            cache_user_roles($uid, $roles);
        } catch (\Throwable $e) {
        }
        return $roles;
    }
}

if (!function_exists('cache_user_roles')) {
    /**
     * 缓存一下用户的角色信息
     * @param $uid
     * @param $roles
     * @return void
     */
    function cache_user_roles($uid, $roles): void
    {
        \support\Redis::setex('user_roles:' . $uid, 300, json_encode($roles, JSON_UNESCAPED_UNICODE));
    }
}

if (!function_exists('has_role')) {
    /**
     * 当前用户是否拥有指定角色（slug）
     * 用法：if (has_role('admin')) { ... }
     */
    function has_role(string $role): bool
    {
        return in_array($role, current_user_roles(), true);
    }
}

if (!function_exists('has_any_role')) {
    /**
     * 当前用户是否拥有任一角色
     * 用法：if (has_any_role(['admin','manager'])) { ... }
     */
    function has_any_role(array $roles): bool
    {
        if (empty($roles)) return false;
        $mine = current_user_roles();
        foreach ($roles as $r) {
            if (in_array($r, $mine, true)) return true;
        }
        return false;
    }
}

if (!function_exists('can')) {
    /**
     * 当前用户是否对 obj:act 有权限（基于 casbin RBAC）
     * 用法：if (can('crud.APackage', 'add')) { ... }
     */
    function can(string $obj, string $act = 'list'): bool
    {
        $uid = current_user_id();
        if (!$uid) return false;
        return Rbac::enforce((string)$uid, $obj, $act);
    }
}

if (!function_exists('current_user_permissions')) {
    /**
     * 当前用户全部权限 [[obj, act], ...]
     * 直接走 casbin Enforcer，未做缓存（casbin 模型本身有内部缓存）
     */
    function current_user_permissions(): array
    {
        $uid = current_user_id();
        if (!$uid) return [];
        try {
            $perms = Rbac::enforcer()->getPermissionsForUser((string)$uid);
            $out = [];
            foreach ($perms as $p) {
                // p: [sub, obj, act] / [sub, obj, act, ...]
                $obj = $p[1] ?? null;
                $act = $p[2] ?? null;
                if ($obj !== null && $act !== null) {
                    $out[] = ['obj' => $obj, 'act' => $act];
                }
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }
}
