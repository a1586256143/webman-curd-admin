#!/usr/bin/env php
<?php
/**
 * CRUD 插件一键安装引导（幂等，可重复执行）
 *
 * 用法（在 webman 宿主根目录执行）：
 *   php plugin/crud/install.php
 *   php plugin/crud/install.php --business-sql=install-business.sql   # 项目自带的业务表 SQL（放宿主根）
 *   php plugin/crud/install.php --business-sql=path/a.sql --business-sql=path/b.sql
 *   php plugin/crud/install.php --admin-user=admin --admin-pass=secret   # 自定义初始管理员
 *   php plugin/crud/install.php --progress-file=runtime/crud-installer.log  # 进度 JSONL 输出（Web 向导用）
 *
 * 等价于调用 \plugin\crud\api\Install::install()，但先完成 webman 配置加载：
 *  - require 宿主 vendor/autoload.php
 *  - 加载 .env（数据库连接等环境变量：DB_HOST / DB_PORT / DB_NAME / DB_USER / DB_PASSWORD）
 *  - 以 support/bootstrap.php 相同方式加载全部 config（含 plugin/crud/config/*）
 *    —— 不启动 worker、不注册路由，仅让 config('plugin.crud.*') / support\Db 可用
 *
 * --business-sql 接受多个（可重复或逗号分隔），路径为宿主项目相对路径或绝对路径；
 * 连接名走 config('plugin.crud.crud.business_connection')（默认 mysql_business，
 * 单库架构下与认证库 mysql 指向同一 DB_NAME），可用 --business-connection=<name> 临时覆盖。
 *
 * 注意：php -S 或常驻进程场景请勿在服务运行中执行建表，MySQL DDL 会锁表。
 */

$hostRoot = dirname(__DIR__, 2); // plugin/crud/install.php -> 宿主根
require_once $hostRoot . '/vendor/autoload.php';

// 加载 .env（存在才加载；缺库会由安装器自动建库或输出明确错误）
if (class_exists('Dotenv\Dotenv') && is_file($hostRoot . '/.env')) {
    \Dotenv\Dotenv::createUnsafeMutable($hostRoot)->load();
}

// 与 support/bootstrap.php 相同的配置加载方式（不启动 worker、不加载路由）
\Webman\Config::clear();
\support\App::loadAllConfig(['route']);

// 解析 CLI 参数
$extraSqls = [];
$businessConn = null;
$adminCreds = [];
$progressFile = null;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--business-sql=(.+)$/', $arg, $m)) {
        $items = preg_split('/,/', $m[1]);
        foreach ($items as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            // 相对路径按宿主项目根解析；已是绝对路径直接传
            $abs = ($item[0] === '/' || preg_match('#^[A-Za-z]:[\\\\/]#', $item))
                ? $item
                : $hostRoot . '/' . ltrim($item, '/');
            $extraSqls[] = ['path' => $abs];
        }
    } elseif (preg_match('/^--business-connection=(.+)$/', $arg, $m)) {
        $businessConn = trim($m[1]);
    } elseif (preg_match('/^--admin-user=(.+)$/', $arg, $m)) {
        $adminCreds['username'] = trim($m[1]);
    } elseif (preg_match('/^--admin-pass=(.+)$/', $arg, $m)) {
        $adminCreds['password'] = $m[1];
    } elseif (preg_match('/^--progress-file=(.+)$/', $arg, $m)) {
        $progressFile = trim($m[1]);
    }
}

// 进度回调：写入 JSONL 进度文件（Web 安装向导前端轮询读取）
$onProgress = null;
if ($progressFile !== null) {
    $absProgress = ($progressFile[0] === '/' || preg_match('#^[A-Za-z]:[\\\\/]#', $progressFile))
        ? $progressFile
        : $hostRoot . '/' . ltrim($progressFile, '/');
    if (!is_dir(dirname($absProgress))) {
        @mkdir(dirname($absProgress), 0755, true);
    }
    $onProgress = static function (array $line) use ($absProgress): void {
        file_put_contents($absProgress, json_encode($line, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
    };
}

// api/Install.php 位于 api/ 目录，不在宿主 composer psr-4（plugin\crud\app\）映射内，显式加载
require_once __DIR__ . '/api/Install.php';

// 返回码：0=成功（可重复执行），1=有步骤失败
exit(\plugin\crud\api\Install::install(false, $extraSqls, $businessConn, $adminCreds, $onProgress) ? 0 : 1);