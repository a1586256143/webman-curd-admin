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

    /**
     * 命令行显式注册（宿主零配置）
     *
     * 为什么不能只靠目录扫描：webman/console 的 Command::installCommands() 用
     *   getRealPath() 解析文件路径，再做字符串前缀裁剪拼类名：
     *     str_replace($path . '\\', '', $file->getRealPath())
     * 当 plugin/curd 是软链（composer path 仓库方式开发）时，realpath 会解析到软链的
     * 真实目标，前缀对不上 → 拼出的类名是乱的 → class_exists 失败后**静默跳过**（不报错），
     * 表现为 `php webman make:curd` 命令凭空消失。
     *
     * 显式写在 'command' 里则走 Command::createCommandInstance()，直接按类名反射，
     * 不经过路径拼接，天然免疫软链。目录扫描成功时两边同名注册，后者覆盖前者（无副作用）。
     */
    'command' => [
        \plugin\curd\app\command\MakeCurdCommand::class,
        // 追加操作按钮（行级 / 批量）：php webman make:curd-action -t 表名 --name=audit:审核 [--batch]
        \plugin\curd\app\command\MakeCurdActionCommand::class,
        // 追加 Excel 导入：php webman make:curd-import -t 表名 [--name=import:导入]
        \plugin\curd\app\command\MakeCurdImportCommand::class,
        // 接口加密密钥：php webman curd:crypto-key [--force] [--bits=4096] [--show] [--print-public]
        \plugin\curd\app\command\CryptoKeyCommand::class,
    ],
];
