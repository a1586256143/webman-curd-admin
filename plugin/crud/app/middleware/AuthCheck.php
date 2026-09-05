<?php
namespace plugin\crud\app\middleware;

use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;
use plugin\crud\app\CrudDb;
use support\Redis;

/**
 * 登录认证中间件（插件内置版，多会话，独立认证库）
 * 校验 Authorization: Bearer <token>，通过后把用户挂到 $request->user
 * token 存于认证库(admin_connection) admin_tokens 表，每会话一行，互不影响
 * 查询带 Redis 缓存（admin_token:{token}，TTL 300s）：未命中回源 DB 并回填；
 * Redis 不可用时自动降级直查 DB，不影响服务可用性。
 * 未登录返回 401
 */
class AuthCheck implements MiddlewareInterface
{
    /**
     * token 查询缓存 TTL（秒）。与 updated_at 节流刷新间隔一致。
     */
    protected int $cacheTtl = 300;

    public function process(Request $request, callable $handler): Response
    {
        // OPTIONS 预检请求直接放行（CORS 由 CorsMiddleware 处理）
        if ($request->method() === 'OPTIONS') {
            return $handler($request);
        }

        $authorization = $request->header('authorization', '');
        $token = '';
        if (preg_match('/Bearer\s+(.+)/i', $authorization, $m)) {
            $token = trim($m[1]);
        }

        if (!$token) {
            return json(['code' => 401, 'msg' => '未登录或登录已过期']);
        }

        // 多会话：从认证库 admin_tokens 表查 token（先查 Redis 缓存）
        $cacheKey = 'admin_token:' . $token;
        $tokenRow = $this->fetchToken($token, $cacheKey);

        if (!$tokenRow) {
            return json(['code' => 401, 'msg' => '登录已过期，请重新登录']);
        }

        // token 过期检查
        if ($tokenRow->expires_at && strtotime($tokenRow->expires_at) < time()) {
            CrudDb::adminTable('admin_tokens')->where('id', $tokenRow->id)->delete();
            $this->forgetTokenCache($cacheKey);
            return json(['code' => 401, 'msg' => '登录已过期，请重新登录']);
        }

        $user = CrudDb::adminTable('admin_users')
            ->where('id', $tokenRow->admin_user_id)
            ->first();

        if (!$user) {
            return json(['code' => 401, 'msg' => '用户不存在']);
        }

        if ((int)$user->status !== 1) {
            return json(['code' => 403, 'msg' => '账号已被禁用']);
        }

        // 更新最后使用时间（避免频繁写库，仅当超过 5 分钟）
        if (!$tokenRow->updated_at || (time() - strtotime($tokenRow->updated_at)) > 300) {
            CrudDb::adminTable('admin_tokens')
                ->where('id', $tokenRow->id)
                ->update(['updated_at' => date('Y-m-d H:i:s')]);
        }

        $request->user = $user;

        return $handler($request);
    }

    /**
     * 查询 token 行：Redis 缓存命中直接返回；未命中回源 DB 并回填缓存。
     * Redis 异常时降级直查 DB。
     */
    protected function fetchToken(string $token, string $cacheKey): ?object
    {
        // 1. 查缓存
        try {
            $cached = Redis::get($cacheKey);
            if ($cached !== false && $cached !== null) {
                $row = json_decode($cached);
                return is_object($row) ? $row : null;
            }
        } catch (\Throwable $e) {
            // Redis 不可用：降级直查 DB
        }

        // 2. 回源 DB
        $row = CrudDb::adminTable('admin_tokens')
            ->where('token', $token)
            ->first();

        // 3. 回填缓存（仅当查到有效行）
        if ($row) {
            try {
                Redis::setex($cacheKey, $this->cacheTtl, json_encode((array)$row, JSON_UNESCAPED_UNICODE));
            } catch (\Throwable $e) {
                // 忽略：缓存写失败不影响鉴权
            }
        }

        return $row;
    }

    /**
     * 删除 token 缓存（登出 / 过期时调用）
     */
    protected function forgetTokenCache(string $cacheKey): void
    {
        try {
            Redis::del($cacheKey);
        } catch (\Throwable $e) {
            // 忽略
        }
    }
}
