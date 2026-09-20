<?php
/**
 * CURD 插件应用配置
 *
 * webman 解析插件控制器回调时会读取 plugin.curd.app.debug，
 * 缺失会因 null 传入 bool 类型参数触发 TypeError（500）。
 */

return [
    // 本地/线上模式开关（宿主 .env 的 APP_DEBUG）：
    //   true （默认）= 本地开发：接口未捕获异常（含 SQL 报错）原样抛出，由 webman 调试页展示堆栈
    //   false        = 线上：API 异常统一兜成 HTTP 500「服务内部错误（编号 xxx）」并写日志
    //                  （见 app/exception/CurdExceptionHandler.php + 本插件 config/exception.php）
    'debug' => (bool)env('APP_DEBUG', true),
    'controller_suffix' => 'Controller',
    'controller_reuse' => false,
    'version' => '1.0.0',
];
