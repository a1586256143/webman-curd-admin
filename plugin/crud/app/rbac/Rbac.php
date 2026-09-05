<?php
namespace plugin\crud\app\rbac;

use Casbin\Enforcer;
use plugin\crud\app\CrudDb;
use support\Redis;

/**
 * casbin RBAC 权限服务（插件内置版，单例）
 *
 * 用法:
 *   Rbac::enforce($userId, 'crud.APackage', 'list');   // bool
 *   Rbac::check($userId, 'crud.APackage', 'list');     // 无权限抛异常
 *
 * 规则说明:
 *   p = sub, obj, act        策略: 角色/用户 对 资源 的操作
 *   g = _, _                 角色继承(用户属于角色 / 角色继承角色)
 *   模型文件: plugin/crud/config/casbin.conf（随插件分发）
 *   规则存储: 认证库(admin_connection) casbin_rule 表
 *
 * 多进程同步:
 * webman 多 worker 下每个进程持独立的 Enforcer 内存策略。
 * DatabaseAdapter 每次写库成功后 INCR Redis 版本号，
 * 本类在 enforce() 前比对版本号，不一致则 loadPolicy() 重载，
 * 从而让其他进程在下一个请求即感知策略变更，无需重启。
 * Redis 不可用时降级：跳过版本比对（各进程按各自内存策略工作）。
 */
class Rbac
{
    protected static ?Enforcer $enforcer = null;

    protected static ?int $lastLoadedUserId = null;

    /**
     * 当前进程已加载策略的版本号（null=未初始化）
     */
    protected static ?int $loadedVersion = null;

    public static function enforcer(): Enforcer
    {
        if (self::$enforcer === null) {
            $adapter = new DatabaseAdapter();
            $modelPath = (string)config('plugin.crud.crud.casbin_model_path', '');
            if ($modelPath === '' || !is_file($modelPath)) {
                // 兜底：随插件分发的模型文件
                $modelPath = dirname(__DIR__, 2) . '/config/casbin.conf';
            }
            self::$enforcer = new Enforcer($modelPath, $adapter);
            self::$enforcer->enableAutoSave(true);
        }
        return self::$enforcer;
    }

    /**
     * 校验用户对 资源:操作 是否有权限
     * sub 传用户ID(如 "1")或角色slug(如 "admin")
     */
    public static function enforce(string $sub, string $obj, string $act = 'list'): bool
    {
        self::syncPolicyVersion();
        try {
            return self::enforcer()->enforce($sub, $obj, $act);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 无权限抛异常(中间件用)
     */
    public static function check(string $sub, string $obj, string $act = 'list'): void
    {
        if (!self::enforce($sub, $obj, $act)) {
            throw new \RuntimeException("没有权限: {$obj}:{$act}", 403);
        }
    }

    /**
     * 比对 Redis 策略版本号，不一致则重载内存策略（多进程同步）
     * Redis 不可用时跳过（降级，不影响鉴权）
     */
    protected static function syncPolicyVersion(): void
    {
        try {
            $remote = (int)Redis::get(DatabaseAdapter::POLICY_VERSION_KEY);
        } catch (\Throwable $e) {
            return; // Redis 挂：跳过版本比对
        }
        if (self::$loadedVersion !== $remote) {
            if (self::$enforcer !== null) {
                self::$enforcer->loadPolicy();
            }
            self::$loadedVersion = $remote;
        }
    }

    /**
     * 添加策略 p=sub,obj,act
     */
    public static function addPolicy(string $sub, string $obj, string $act): void
    {
        self::enforcer()->addPolicy($sub, $obj, $act);
    }

    /**
     * 从数据库重新加载全部策略（策略变更后调用，或修改 casbin_rule 表后）
     * 加载后同步本进程版本号，避免重复加载
     */
    public static function reload(): void
    {
        if (self::$enforcer !== null) {
            self::$enforcer->loadPolicy();
        }
        try {
            self::$loadedVersion = (int)Redis::get(DatabaseAdapter::POLICY_VERSION_KEY);
        } catch (\Throwable $e) {
            self::$loadedVersion = null;
        }
    }

    /**
     * 添加角色继承 g=user,role
     */
    public static function addRoleForUser(string $userId, string $role): void
    {
        self::enforcer()->addRoleForUser($userId, $role);
    }

    /**
     * 删除用户的角色
     */
    public static function deleteRolesForUser(string $userId): void
    {
        self::enforcer()->deleteRolesForUser($userId);
    }

    /**
     * 删除用户所有权限
     */
    public static function deleteUser(string $userId): void
    {
        self::enforcer()->deleteUser($userId);
    }
}
