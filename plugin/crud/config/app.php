<?php
/**
 * CRUD 插件应用配置
 *
 * webman 解析插件控制器回调时会读取 plugin.crud.app.debug，
 * 缺失会因 null 传入 bool 类型参数触发 TypeError（500）。
 */

return [
    'debug' => (bool)env('APP_DEBUG', true),
    'controller_suffix' => 'Controller',
    'controller_reuse' => false,
    'version' => '1.0.0',
];
