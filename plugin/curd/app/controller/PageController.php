<?php
namespace plugin\curd\app\controller;

use support\Request;
use Webman\Http\Response;

/**
 * 插件内置前端页面控制器（静态托管 + history 模式 SPA fallback）
 *
 * 用法：
 *  - 前端以 VITE_BASE_PATH=<page_base> 构建，产物整体放入 plugin/curd/public/
 *    （page_base 默认 /app/curd，config/plugin/curd/curd.php 可调，前后端需一致）
 *  - 本控制器把 <page_base>/* 下真实存在的文件按静态文件返回（带 mime/cache 头）；
 *    其余路径（vue-router history 路由，如 /app/curd/dashboard）统一回退 index.html
 *  - 页面/静态资源不挂 AuthCheck —— 登录态由前端跳转控制，与宿主业务页面一致
 *
 * 安全：路径穿越防护 —— 解析后的真实路径必须落在 public_dir 内。
 */
class PageController
{
    /**
     * 常见静态类型 mime 映射（覆盖 dist 产物类型即可）
     */
    protected static array $mimes = [
        'html'  => 'text/html; charset=utf-8',
        'htm'   => 'text/html; charset=utf-8',
        'js'    => 'application/javascript; charset=utf-8',
        'mjs'   => 'application/javascript; charset=utf-8',
        'css'   => 'text/css; charset=utf-8',
        'json'  => 'application/json; charset=utf-8',
        'svg'   => 'image/svg+xml',
        'png'   => 'image/png',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'gif'   => 'image/gif',
        'webp'  => 'image/webp',
        'ico'   => 'image/x-icon',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'   => 'font/ttf',
        'eot'   => 'application/vnd.ms-fontobject',
        'txt'   => 'text/plain; charset=utf-8',
        'map'   => 'application/json',
    ];

    public function index(Request $request, string $path = ''): Response
    {
        $publicDir = (string)config('plugin.curd.curd.public_dir', dirname(__DIR__, 2) . '/public');
        $indexFile = rtrim($publicDir, '/') . '/index.html';

        if (!is_dir($publicDir) || !is_file($indexFile)) {
            // 未内置前端（未构建或独立部署）：提示而非 404 迷宫
            return response('CURD 插件内置前端未安装：请先构建前端产物到 plugin/curd/public/（见插件 README）', 404);
        }

        // 真实文件优先（assets 等）；找不到则回退 index.html（SPA history）
        $file = $this->resolveFile($publicDir, $path);
        if ($file === null) {
            $file = $indexFile;
        }

        $content = file_get_contents($file);
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $headers = [
            'Content-Type' => self::$mimes[$ext] ?? 'application/octet-stream',
        ];
        // 带内容 hash 的构建资源可长缓存；入口 html 禁止缓存（每次回源拿最新）
        if ($ext === 'html') {
            $headers['Cache-Control'] = 'no-cache';
        } else {
            $headers['Cache-Control'] = 'public, max-age=31536000, immutable';
        }
        return response($content, 200, $headers);
    }

    /**
     * 安全解析 public_dir 下的真实文件路径；越界/不存在返回 null
     */
    protected function resolveFile(string $publicDir, string $path): ?string
    {
        if ($path === '' || $path === '/') {
            return null; // 交给 index.html
        }
        // 路径穿越与非法字符防护
        if (str_contains($path, '..') || str_contains($path, "\0") || str_starts_with($path, '/')) {
            return null;
        }
        $realPublic = realpath($publicDir);
        $candidate = realpath(rtrim($publicDir, '/') . '/' . $path);
        if ($candidate === false || $realPublic === false) {
            return null;
        }
        if ($candidate !== $realPublic && !str_starts_with($candidate, $realPublic . DIRECTORY_SEPARATOR)) {
            return null; // 越界
        }
        if (!is_file($candidate)) {
            return null; // 目录或不存在 → SPA fallback
        }
        return $candidate;
    }
}
