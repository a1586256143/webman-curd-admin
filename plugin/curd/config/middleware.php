<?php
/**
 * CURD 插件中间件（插件自带，宿主无需任何配置）
 *
 * 为什么键名是 `plugin.curd` 而不是 `''`：
 * ------------------------------------------------------------
 * webman 的 support/bootstrap.php 对每个插件都会执行
 *     Middleware::load(config('plugin.{插件名}.middleware'))
 * 而 Middleware::load() 解析键名的规则是：
 *     'plugin.curd'        → 插件 curd、app ''（= 该插件【所有】路由）
 *     'plugin.curd.admin'  → 插件 curd、app admin（只该 app）
 *     '@'                  → 全局（所有 app + 所有插件）
 *     ''                   → 只作用于【默认 app】，插件路由拿不到
 * 所以这里必须写 `plugin.curd`，写 `''` 是不生效的。
 *
 * 作用范围：
 * ------------------------------------------------------------
 * 只覆盖「控制器在 plugin\curd\... 命名空间下」的路由，也就是插件自带的
 * /api/auth/*、/api/curd/*、/api/admin/*、/api/menu/*、/api/config/* 等，
 * 以及 CURD 模型接口 /api/curd/model/{model}/*（业务控制器只是提供 Grid/Form
 * 配置，真正的接口仍由插件 CurdController 承载）。
 *
 * 宿主自己写的、不在本插件命名空间下的 /api 接口（例如 app/controller/api/Foo.php）
 * 插件管不到 —— 那种情况才需要宿主在 config/middleware.php 里再挂一次：
 *     return ['@' => [\plugin\curd\app\middleware\ApiCrypto::class]];
 * 两边都挂也不会重复加解密：ApiCrypto 内部有请求级幂等标记（apiCryptoHandled），
 * 同一条请求只会处理一次。
 *
 * 顺序说明：
 * ------------------------------------------------------------
 * 插件级中间件比路由中间件（Cors / AuthCheck / PermissionCheck）更靠外，
 * 因此鉴权失败返回的 401 也会被加密，与正常响应一致。
 */

return [
    // 接口加解密（RSA + AES 信封）：请求解密 + 响应回加密
    'plugin.curd' => [
        \plugin\curd\app\middleware\ApiCrypto::class,
    ],
];
