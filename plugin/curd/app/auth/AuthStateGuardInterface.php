<?php
namespace plugin\curd\app\auth;

/**
 * 附加账号状态校验器（可选）
 * ------------------------------------------------------------------
 * 用途：在账号「自身 status」之外，再叠加一层宿主侧的业务判定。典型场景：
 *   - 关联的业务账户被关闭（如平台账号 status ≠ 1）
 *   - 合同 / 服务到期、部门被撤销、未通过审核
 *   - 白名单 / IP 段限制等
 *
 * 为什么不直接实现 AuthProviderInterface 来叠加判定：
 *   认证链路上有三个入口都会产出「账号可用性」，即 login() / identity() /
 *   resolveUser()。自己实现 provider 想叠加一条判定，就得同时覆盖这三个
 *   方法；而且它们给的数据形状并不一致（前两者是身份数组，resolveUser 是
 *   用户对象），判定逻辑被迫写两份。漏改任一入口都不会报错，只会造成
 *   「接口能访问、但 /api/auth/me 状态不对」这类静默不一致。
 *   实现本接口后判定只写一处，三个入口由 DefaultAuthProvider 统一收口。
 *
 * 生效方式（宿主 config/curd-admin.php）：
 *   'auth_state_guard' => \app\auth\MyGuard::class,
 * 未配置 = 不启用，行为与原来完全一致（纯 opt-in）。
 *
 * 判定语义（返回 false 即视为「已禁用」）：
 *   登录时        → 403「账号已被禁用」（不会报成"用户名或密码错误"）
 *   已登录会话    → 下一次请求即 403（AuthCheck 每个请求都会调，无缓存延迟）
 */
interface AuthStateGuardInterface
{
    /**
     * @param object $account 规范化后的账号对象，固定含属性：
     *                        id, username, name, status（status 为账号自身状态，1=启用）
     * @return bool|null      false = 按「已禁用」处理；true / null = 放行。
     *                        返回 null 表示「本条不表态」，便于后续组合多个校验器。
     */
    public function allowed(object $account): ?bool;
}
