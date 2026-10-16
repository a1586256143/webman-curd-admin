<?php
/**
 * 插件域翻译配置（webman Translation）
 *
 * 为什么需要这个文件：
 * ------------------------------------------------------------
 * webman 的 Translation 按「当前请求所属插件」取配置
 * （见 vendor/workerman/webman-framework/src/support/Translation.php::__callStatic：
 *  $plugin = request()->plugin;  static::instance($plugin)），
 * 而 instance() 读的是 config($plugin ? "plugin.$plugin.translation" : 'translation')。
 *
 * 也就是说：走插件路由（/api/curd/*、/api/upload 等）的请求里调用 trans() / __()，
 * 读的是 config('plugin.curd.translation')。**插件若不提供本文件，该配置为 []，
 * 于是 $config['locale'] 未定义 → 直接抛 ErrorException，整页 500。**
 *
 * path 为什么是「两段」：
 * ------------------------------------------------------------
 *   ① 插件内置默认：plugin/curd/resource/translations  ← 随插件升级一起更新
 *   ② 宿主自定义　：{宿主根}/resource/translations     ← 跟随宿主 config/translation.php
 *
 * Symfony 的 MessageCatalogue 是 **按键合并（array_replace）** 的：
 * 两段目录的同名语言文件会叠加，**后加载的覆盖先加载的**，且不存在的键不会互相删除。
 * 所以：
 *   - 宿主文件缺某 key 时，自动落到插件内置默认（老宿主装完新插件即生效，无需手工合并）；
 *   - 宿主文件写了某 key 时，宿主优先（用户自定义永远说了算）；
 *   - 宿主连目录都没有时，纯插件默认也能跑。
 *
 * 加载顺序保证：support\App::loadAllConfig() 先 Config::load(config_path())，
 * 再逐个 load 插件 config 目录 —— 所以这里能安全读到 config('translation.*')。
 */

$pluginTranslations = __DIR__ . '/../resource/translations';
$hostTranslations = config('translation.path', base_path() . '/resource/translations');

// 去重：宿主把 path 指到插件目录时避免重复加载
$paths = [$pluginTranslations];
foreach ((array)$hostTranslations as $hostPath) {
    if ($hostPath && !in_array($hostPath, $paths, true)) {
        $paths[] = $hostPath;
    }
}

return [
    // 默认语言（跟随宿主 config/translation.php）
    'locale' => config('translation.locale', 'zh_CN'),

    // 回退语言（跟随宿主；缺省 zh_CN → en）
    'fallback_locale' => config('translation.fallback_locale', ['zh_CN', 'en']),

    // 语言文件根目录：先插件内置默认，后宿主（宿主同名 key 优先）
    'path' => $paths,

    // loader 不写 → 用 webman 默认的 PhpFileLoader(.php) + PoFileLoader(.po)，
    // 即 resource/translations/{locale}/{domain}.php，domain = 文件名（如 fields）。
];
