<?php
/**
 * CURD 插件异常处理器注册（config('plugin.curd.exception')）
 *
 * 作用：让插件自己的路由（plugin\curd\app\controller\*）在异常时走
 * plugin\curd\app\exception\CurdExceptionHandler，即
 *   线上模式（.env APP_DEBUG=false）→ /api 返回 HTTP 500「服务内部错误（编号 xxx）」+ 结构化日志；
 *   本地模式（默认）            → 保留原调试体验（调试页/真实 msg+traces）。
 *
 * 框架判定规则（Webman\App::exceptionResponse）：插件请求读 config('plugin.curd.exception')，
 * 取 '' 键作为默认处理器 —— 所以插件自带本文件即对插件路由生效，宿主无需任何配置。
 *
 * ⚠️ 宿主自己写的 /api 控制器（如 app\controller\admin\api\*）默认走宿主 config/exception.php
 *    的 '' 键；想让它们也有同样的 500 兜底，把宿主的 '' 指向本类：
 *      return ['' => \plugin\curd\app\exception\CurdExceptionHandler::class];
 *    （只加 '@' 键无效：本 app 已有 '' 键时框架会忽略 '@'。）
 */
return [
    '' => \plugin\curd\app\exception\CurdExceptionHandler::class,
];
