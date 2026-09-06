#!/usr/bin/env php
<?php
/**
 * CURD 插件 · 增量 migration 运行器
 *
 * 约定：
 *  - 迁移文件放在 plugin/curd/migrations/ 目录，命名 V{版本}__{描述}.sql
 *    例：V1__create_goods.sql  V2__add_goods_stock.sql  V10__index_orders.sql
 *  - 版本号为纯数字，按数字升序执行；同一版本内的多条语句用 --SPLIT-- 分隔
 *  - 已执行的迁移记录进 {连接}.{前缀}_curd_migrations 表，幂等：只跑未执行的
 *  - 运行库连接走 config('plugin.curd.curd.business_connection')（默认 mysql_business），
 *    可用 --connection=<name> 覆盖；跟踪表建立在该连接上
 *
 * 用法（在 webman 宿主根目录执行）：
 *   php plugin/curd/migrate.php                 # 跑全部未执行迁移
 *   php plugin/curd/migrate.php --connection=mysql_business
 *   php plugin/curd/migrate.php --dry-run      # 只列出将要执行的文件，不落库
 *
 * 注意：迁移文件只追加、不修改已发布版本（改结构请用新的 V{n}__*.sql），
 *       已执行版本不可重命名/删除，否则会导致重复执行。
 */

$hostRoot = dirname(__DIR__, 2);
require_once $hostRoot . '/vendor/autoload.php';

if (class_exists('Dotenv\Dotenv') && is_file($hostRoot . '/.env')) {
    \Dotenv\Dotenv::createUnsafeMutable($hostRoot)->load();
}

\Webman\Config::clear();
\support\App::loadAllConfig(['route']);

require_once __DIR__ . '/api/Install.php';

// 解析参数
$connection = null;
$dryRun = false;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--connection=(.+)$/', $arg, $m)) {
        $connection = trim($m[1]);
    } elseif ($arg === '--dry-run') {
        $dryRun = true;
    }
}
$connection = $connection ?? (string)config('plugin.curd.curd.business_connection', 'mysql_business');

use support\Db;

$migrationsDir = __DIR__ . '/migrations';
if (!is_dir($migrationsDir)) {
    echo '[WARN] 迁移目录不存在：' . $migrationsDir . '（无需迁移）' . PHP_EOL;
    exit(0);
}

// 收集 V{n}__*.sql，按版本号升序
$files = [];
foreach (glob($migrationsDir . '/V*__*.sql') as $f) {
    if (preg_match('/V(\d+)__/', basename($f), $m)) {
        $files[(int)$m[1]][] = $f;
    }
}
ksort($files);

$db = Db::connection($connection);

// 确保跟踪表存在
$db->statement(
    "CREATE TABLE IF NOT EXISTS `_curd_migrations` (
        `id` bigint unsigned NOT NULL AUTO_INCREMENT,
        `version` int unsigned NOT NULL,
        `file` varchar(255) NOT NULL,
        `batch` int unsigned NOT NULL DEFAULT 1,
        `executed_at` datetime DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_version` (`version`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$applied = $db->table('_curd_migrations')->pluck('version')->map(fn($v) => (int)$v)->all();
$applied = array_flip($applied);

$toRun = [];
foreach ($files as $version => $group) {
    foreach ($group as $f) {
        if (isset($applied[$version])) {
            continue;
        }
        $toRun[] = [$version, $f];
    }
}

if (empty($toRun)) {
    echo '[OK] 没有待执行的迁移（已全部应用）' . PHP_EOL;
    exit(0);
}

echo '== 待执行迁移（连接 ' . $connection . '，共 ' . count($toRun) . ' 个）==' . PHP_EOL;
foreach ($toRun as [$version, $f]) {
    echo '  V' . $version . '  ' . basename($f) . PHP_EOL;
}
if ($dryRun) {
    echo '[DRY-RUN] 未执行任何语句' . PHP_EOL;
    exit(0);
}

$batch = ((int)$db->table('_curd_migrations')->max('batch')) + 1;
$ok = true;
foreach ($toRun as [$version, $f]) {
    $content = file_get_contents($f);
    // 复用 Install 的分隔解析（isContent=true）
    try {
        $n = \plugin\curd\api\Install::runSqlFile($content, $db, true);
        $db->table('_curd_migrations')->insert([
            'version' => $version,
            'file' => basename($f),
            'batch' => $batch,
            'executed_at' => date('Y-m-d H:i:s'),
        ]);
        echo '[OK]    V' . $version . ' 执行 ' . $n . ' 条语句' . PHP_EOL;
    } catch (\Throwable $e) {
        $ok = false;
        echo '[ERROR] V' . $version . ' 失败: ' . $e->getMessage() . PHP_EOL;
        break;
    }
}

echo $ok ? '== 迁移完成 ✅ ==' . PHP_EOL : '== 迁移中断（请修复后重跑，已成功的版本不会重复执行）==' . PHP_EOL;
exit($ok ? 0 : 1);
