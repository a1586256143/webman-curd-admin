<?php
namespace plugin\crud\app\middleware;

use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

/**
 * CORS 跨域中间件（插件内置版）
 *
 * 必须挂在路由组 middleware 列表的【末尾】：
 * webman 会把 array_reverse 后的第一个中间件作为最外层执行，
 * 因此只有放在路由中间件末尾，才能包裹住 AuthCheck/PermissionCheck
 * 提前返回的 401/403 响应，保证所有真实响应都带 CORS 头。
 */
class CorsMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        if ($request->method() === 'OPTIONS') {
            return $this->withCorsHeaders($request, response('', 204));
        }
        return $this->withCorsHeaders($request, $handler($request));
    }

    protected function withCorsHeaders(Request $request, Response $response): Response
    {
        $origin = $request->header('origin', '');
        // 反射请求 Origin，兼容带 credentials 的跨域请求（'*' 与 Allow-Credentials: true 不能共存）
        // 生产环境建议改为白名单（如只允许你的前端域名）
        $allowOrigin = $origin !== '' ? $origin : '*';
        return $response->withHeaders([
            'Access-Control-Allow-Origin'      => $allowOrigin,
            'Access-Control-Allow-Methods'     => 'GET, POST, PUT, DELETE, OPTIONS, PATCH',
            'Access-Control-Allow-Headers'     => 'Content-Type, Authorization, X-Requested-With, Accept, Origin, X-Encrypt-Data',
            'Access-Control-Allow-Credentials' => 'true',
            'Access-Control-Max-Age'           => '86400',
        ]);
    }
}
