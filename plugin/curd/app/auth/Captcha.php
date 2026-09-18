<?php
namespace plugin\curd\app\auth;

use support\Redis;
use Webman\Captcha\CaptchaBuilder;
use Webman\Captcha\PhraseBuilder;

/**
 * 登录图形验证码（webman/captcha + Redis 存储 + 一次性校验）
 * ------------------------------------------------------------------
 * 为什么不用官方示例里的 session：
 *   本插件是「Bearer token + 前后端分离」架构，没有引入 webman/session / 会话中间件，
 *   因此把验证码明文存 Redis（项目已依赖 webman/redis），key 一次性下发：
 *
 *     GET  /api/auth/captcha  → {"code":200,"data":{"key":"<32位hex>","image":"data:image/jpeg;base64,..."}}
 *     POST /api/auth/login    → {..., "captcha_key":"<key>", "captcha_code":"<用户输入>"}
 *
 * 与 session 方案的差别（有意为之）：
 *   - 不依赖 cookie，跨域开发（前端 8787 / 后端 18082）不需要 credentials
 *   - key 里带随机段，同一浏览器可多标签页各拿一张图，互不覆盖
 *   - **一次性**：verify() 无论对错都销毁 key，杜绝「同一张图反复试探」
 *
 * 配置（宿主 config/admin.php 优先，同 export_max_rows / home_page 的约定）：
 *   'captcha_enabled' => true,   // 关掉 = 登录页不再要验证码（也用于 Redis 不可用时的应急开关）
 *   'captcha_ttl'     => 300,    // 验证码有效期（秒）
 *   'captcha_length'  => 4,      // 位数（3~6）
 * 未配置时回退 插件 config/curd.php 的 captcha_* → 内置默认。
 */
final class Captcha
{
    /** Redis key 前缀（值 = 小写验证码；与 admin_token: / user_roles: 同一命名风格） */
    public const KEY_PREFIX = 'curd_captcha:';

    /**
     * 字符集：刻意剔除易混字符 i / l / o / 0 / 1，
     * 避免用户把 l 看成 1、o 看成 0 导致"明明输对了却说错"。
     * 值统一按小写比对（见 verify），所以这里只放小写字母。
     */
    public const CHARSET = 'abcdefghjkmnpqrstuvwxyz23456789';

    /** 图片尺寸（宽 x 高），150x40 与官方示例一致 */
    public const WIDTH  = 150;
    public const HEIGHT = 40;

    /**
     * 是否启用验证码
     */
    public static function enabled(): bool
    {
        return filter_var(self::config('captcha_enabled', true), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * 有效期（秒），非法值回落 300
     */
    public static function ttl(): int
    {
        $ttl = (int)self::config('captcha_ttl', 300);
        return $ttl > 0 ? $ttl : 300;
    }

    /**
     * 验证码位数，限制在 3~6（太小不安全，太大看不清）
     */
    public static function length(): int
    {
        $length = (int)self::config('captcha_length', 4);
        return ($length >= 3 && $length <= 6) ? $length : 4;
    }

    /**
     * 生成一张新验证码（同时写入 Redis）
     *
     * @return array{key:string,image:string} image 是可直接塞 <img src> 的 data URI
     * @throws \Throwable GD 缺失 / 字体缺失 / Redis 不可用等，由调用方兜成 json 错误
     */
    public static function make(): array
    {
        $builder = new CaptchaBuilder(null, new PhraseBuilder(self::length(), self::CHARSET));
        $builder->build(self::WIDTH, self::HEIGHT);

        $key = bin2hex(random_bytes(16));
        Redis::setex(self::KEY_PREFIX . $key, self::ttl(), strtolower($builder->getPhrase()));

        return [
            'key'   => $key,
            'image' => $builder->inline(),
        ];
    }

    /**
     * 校验验证码（一次性：无论对错都销毁本次 key）
     *
     * @param mixed $key   /api/auth/captcha 返回的 key
     * @param mixed $code  用户输入
     * @return string 空串 = 通过；非空 = 可直接回给前端的错误提示
     */
    public static function verify($key, $code): string
    {
        $key  = is_string($key) ? trim($key) : '';
        $code = is_string($code) ? trim($code) : '';

        if ($key === '' || $code === '') {
            return '请输入验证码';
        }

        $cacheKey = self::KEY_PREFIX . $key;
        try {
            $expect = Redis::get($cacheKey);
            Redis::del($cacheKey);   // 一次性：防止同一张图被反复试探
        } catch (\Throwable $e) {
            // Redis 不可用时无法校验 → 明确报错，不做「静默放行」
            // （运维应急：宿主 config/admin.php 里 captcha_enabled => false 后重启）
            return '验证码服务暂不可用，请联系管理员';
        }

        if (!$expect) {
            return '验证码已过期，请点击图片重新获取';
        }
        if (strtolower($code) !== strtolower((string)$expect)) {
            return '验证码错误';
        }
        return '';
    }

    /**
     * 读配置：宿主 config/admin.php 顶层键 → 插件 config/curd.php → 传入的默认值
     * （与 CurdActionsTrait::exportMaxRows() / AdminController::siteConfig() 同一套优先级）
     *
     * @param string $key  'captcha_enabled' | 'captcha_ttl' | 'captcha_length'
     * @param mixed  $default
     * @return mixed
     */
    protected static function config(string $key, $default)
    {
        $value = config('admin.' . $key);
        if ($value === null || $value === '') {
            $value = config('plugin.curd.curd.' . $key, $default);
        }
        return $value === null || $value === '' ? $default : $value;
    }
}
