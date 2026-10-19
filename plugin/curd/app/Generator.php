<?php
namespace plugin\curd\app;

/**
 * 配置生成器（后台 /curd-generator 页面）开关
 *
 * 生成器能在运行期把页面配置写进 curd_configs（落库配置），落库配置会盖住控制器
 * grid() 的改动 —— 生产环境应关闭，避免被误用/误操作。
 *
 * 取值优先级（与 Captcha::enabled() 同一套）：
 *   1. 宿主 config/curd-admin.php 的 generator_enabled
 *   2. 插件 config/curd.php 的 generator_enabled
 *   3. 都没配 → 跟随调试模式：APP_DEBUG=true（开发）→ 开；false（生产）→ 关
 *
 * 显式配置：'generator_enabled' => true / false 可覆盖「跟随调试模式」的行为。
 */
class Generator
{
    /**
     * 生成器是否可用
     */
    public static function enabled(): bool
    {
        $value = config('curd-admin.generator_enabled');
        if ($value === null || $value === '') {
            $value = config('plugin.curd.curd.generator_enabled');
        }
        if ($value === null || $value === '') {
            return self::debug();
        }
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * 当前是否调试模式（dev）
     *   plugin.curd.app.debug 来自 .env 的 APP_DEBUG；缺失时回落宿主 config/app.php 的 debug
     */
    public static function debug(): bool
    {
        $debug = config('plugin.curd.app.debug');
        if ($debug === null) {
            $debug = config('app.debug', false);
        }
        return filter_var($debug, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * 关闭时的统一响应（生成器相关接口都用它）
     */
    public static function denied()
    {
        return json([
            'code' => 403,
            'msg'  => '配置生成器已关闭（当前为生产模式）。如需临时启用：宿主 config/curd-admin.php 设 '
                . "'generator_enabled' => true 后 restart。",
        ]);
    }
}
