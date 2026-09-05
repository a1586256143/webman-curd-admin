<?php
/**
 * CRUD 插件自动加载文件清单
 *
 * webman 框架启动时会自动 include 插件配置中的 autoload.files，
 * 因此插件初始化逻辑（模型扫描、路由控制器注册）放在 plugin/crud/app/bootstrap.php，
 * 由本文件声明加载，项目 config/autoload.php 无需再手动引入。
 */

return [
    'files' => [
        // 先加载全局辅助函数（function_exists 防重），再执行插件初始化引导
        base_path() . '/plugin/crud/app/functions.php',
        base_path() . '/plugin/crud/app/bootstrap.php',
    ],
];
