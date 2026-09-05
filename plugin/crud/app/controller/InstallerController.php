<?php
namespace plugin\crud\app\controller;

use support\Request;
use Webman\Http\Response;

/**
 * Web 安装向导（新项目一键安装，仅首次可用）
 *
 * 访问：
 *   GET  /app/crud-installer          → 向导页面（静态页）
 *   GET  /api/crud-installer/status   → 是否已安装
 *   POST /api/crud-installer/setup    → 提交并执行安装（DB 写 .env，其它配置写 config/crud.php）
 *   GET  /api/crud-installer/progress → 安装进度（轮询，JSONL）
 *
 * 设计约定（与 v1.0.5 配置方案一致）：
 *   - 数据库信息（DB_HOST/PORT/NAME/USER/PASSWORD/…）写入宿主 .env：按键合并/替换，
 *     绝不删除 .env 里其它内容（回应“不要 cp env.example 覆盖宿主 .env”）；
 *   - 插件调参（page_base 等）写入 config/crud.php，并移除其 database 段
 *     （避免占位值抢占 .env —— config/database.php 取数优先级 config/crud.php > .env）；
 *   - 执行安装用子进程 php plugin/crud/install.php（独立加载 .env/config，
 *     规避运行期配置缓存），进度写 runtime/crud-installer-progress.log 供轮询；
 *   - 安装成功生成 config/crud-installed.lock（由 api/Install 统一写），
 *     存在即拒绝再次安装；删除锁文件可重新进入向导（需先清库）。
 *
 * 默认【单库模式】：业务库与认证库放同一个库（business_db 不填）。
 */
class InstallerController
{
    /** 向导静态页相对 public/ 的路径 */
    protected const PAGE_FILE = '/installer/index.html';

    /** 进度文件相对宿主根 */
    protected const PROGRESS_FILE = '/runtime/crud-installer-progress.log';

    /** 已安装锁（与 plugin\crud\api\Install::lockFile 一致） */
    protected static function lockFile(): string
    {
        return static::hostRoot() . '/config/crud-installed.lock';
    }

    protected static function hostRoot(): string
    {
        // plugin/crud/app/controller → 宿主根
        return dirname(__DIR__, 4);
    }

    protected static function progressFile(): string
    {
        return static::hostRoot() . static::PROGRESS_FILE;
    }

    protected static function json(array $data, int $status = 200): Response
    {
        return response(json_encode($data, JSON_UNESCAPED_UNICODE), $status, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    /**
     * 向导页面（纯静态 HTML，禁止缓存）
     */
    public function page(): Response
    {
        $file = dirname(__DIR__, 2) . '/public' . static::PAGE_FILE;
        if (!is_file($file)) {
            return response('安装向导页面缺失：plugin/crud/public/installer/index.html', 404);
        }
        return response(file_get_contents($file), 200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-cache']);
    }

    /**
     * 状态：是否已安装（锁文件存在 = 已安装）
     */
    public function status(): Response
    {
        return static::json([
            'installed' => is_file(static::lockFile()),
            'lock_file' => static::lockFile(),
        ]);
    }

    /**
     * 执行安装
     */
    public function setup(Request $request): Response
    {
        // 已安装 → 拒绝（防未授权重装/覆盖管理员）
        if (is_file(static::lockFile())) {
            return static::json(['ok' => false, 'message' => '系统已安装（检测到 ' . static::lockFile() . '）。如需重装请先删除该文件并清空数据库。'], 409);
        }

        $body = json_decode((string)$request->rawBody(), true);
        if (!is_array($body)) {
            $body = $request->post();
        }
        if (!is_array($body)) {
            return static::json(['ok' => false, 'message' => '请求体格式错误'], 400);
        }

        $db = [
            'host'     => trim((string)($body['db_host'] ?? '')),
            'port'     => trim((string)($body['db_port'] ?? '3306')),
            'name'     => trim((string)($body['db_name'] ?? '')),
            'user'     => trim((string)($body['db_user'] ?? '')),
            'password' => (string)($body['db_pass'] ?? ''),
        ];
        // 单库模式：business_db 为空 → .env 不写 DB_BUSINESS_NAME，自动回退认证库
        $sameDb = !empty($body['business_same']);
        $businessDb = $sameDb ? '' : trim((string)($body['business_db'] ?? ''));
        $adminUser = trim((string)($body['admin_user'] ?? ''));
        $adminPass = (string)($body['admin_pass'] ?? '');
        $adminPass2 = (string)($body['admin_pass2'] ?? '');
        $pageBase = trim((string)($body['page_base'] ?? '/app/crud'));

        // ---- 校验 ----
        $errors = [];
        foreach ([['host', '数据库主机', $db['host']], ['name', '数据库名', $db['name']], ['user', '数据库用户', $db['user']]] as [$k, $label, $v]) {
            if ($v === '') {
                $errors[] = "{$label}不能为空";
            }
        }
        if ($db['port'] === '' || !ctype_digit($db['port'])) {
            $errors[] = '数据库端口必须为数字';
        }
        if (!preg_match('/^[A-Za-z0-9_\-]+$/', $db['name'])) {
            $errors[] = '数据库名只能包含字母/数字/下划线/连字符';
        }
        if (!$sameDb && $businessDb !== '' && !preg_match('/^[A-Za-z0-9_\-]+$/', $businessDb)) {
            $errors[] = '业务库名只能包含字母/数字/下划线/连字符';
        }
        if (!preg_match('/^[A-Za-z0-9_\-]{2,32}$/', $adminUser)) {
            $errors[] = '管理员账号需为 2-32 位字母/数字/下划线/连字符';
        }
        if (strlen($adminPass) < 6) {
            $errors[] = '管理员密码至少 6 位';
        }
        if ($adminPass !== $adminPass2) {
            $errors[] = '两次输入的管理员密码不一致';
        }
        if ($errors) {
            return static::json(['ok' => false, 'message' => implode('；', $errors)], 400);
        }

        $root = static::hostRoot();
        $progressFile = static::progressFile();

        try {
            // 0) 写 .env（数据库信息；按键替换/追加，不删其它内容）
            static::writeEnv($root, $db, $businessDb);

            // 1) 写 config/crud.php（其它配置；移除 database 段避免抢占 .env）
            static::writeCrudConfig($root, $pageBase);

            // 2) 清空进度文件 → 子进程执行安装（独立进程重新加载 .env，规避运行期配置缓存）
            @unlink($progressFile);
            if (!is_dir(dirname($progressFile))) {
                @mkdir(dirname($progressFile), 0755, true);
            }
            $cmd = [
                PHP_BINARY,
                $root . '/plugin/crud/install.php',
                '--admin-user=' . $adminUser,
                '--admin-pass=' . $adminPass,
                '--progress-file=' . $progressFile,
            ];
            $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
            if (!is_resource($proc)) {
                return static::json(['ok' => false, 'message' => '无法启动安装子进程（php ' . PHP_BINARY . ' 不可执行）'], 500);
            }
            fclose($pipes[0]);
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($proc);

            $steps = static::readProgress($progressFile);
            $ok = ($exitCode === 0 && is_file(static::lockFile()));

            return static::json([
                'ok'       => $ok,
                'exit'     => $exitCode,
                'message'  => $ok
                    ? "安装完成！管理员 {$adminUser} 已创建。访问后台：" . $pageBase . '/'
                    : '安装未成功，请查看下方错误步骤（stdout 尾部见日志）',
                'steps'    => $steps,
                'stderr'   => trim((string)$err) !== '' ? mb_substr($err, 0, 2000) : '',
                'admin'    => $adminUser,
                'page_base' => $pageBase,
                // 安装成功时附带重启命令（webman worker 配置在启动时固化，wizard 写完
                // config/crud.php 后需 restart 才生效；避免 1045 Access denied 等
                // 「config 已更新但 worker 还用旧配置」类问题）
                'restart_cmd' => $ok ? 'php start.php restart' : '',
            ]);
        } catch (\Throwable $e) {
            return static::json(['ok' => false, 'message' => '安装过程异常: ' . $e->getMessage()], 500);
        }
    }

    /**
     * 进度（JSONL 全量返回）
     */
    public function progress(): Response
    {
        return static::json([
            'steps' => static::readProgress(static::progressFile()),
            'installed' => is_file(static::lockFile()),
        ]);
    }

    // ============================================================
    // 内部实现
    // ============================================================

    /**
     * 把数据库信息合并写入 .env：
     *  - 已有键：原位替换（不删其它行/注释/键）
     *  - 缺失键：按标准顺序追加到文件尾
     * 值统一单引号包裹（phpdotenv 字面量语义），密码含 #/空格/引号均安全。
     */
    protected static function writeEnv(string $root, array $db, string $businessDb): void
    {
        $envPath = $root . '/.env';
        $exists = is_file($envPath);
        $lines = $exists ? file($envPath, FILE_IGNORE_NEW_LINES) : [];
        if ($lines === false) {
            $lines = [];
        }

        $want = [
            'DB_HOST'          => $db['host'],
            'DB_PORT'          => $db['port'],
            'DB_NAME'          => $db['name'],
            'DB_USER'          => $db['user'],
            'DB_PASSWORD'      => $db['password'],
            'DB_BUSINESS_NAME' => $businessDb, // 空=单库，不写该键
        ];

        $set = [];
        $out = [];
        foreach ($lines as $line) {
            $trim = ltrim($line);
            if ($trim !== '' && $trim[0] !== '#') {
                if (preg_match('/^(DB_(?:HOST|PORT|NAME|USER|PASSWORD|BUSINESS_NAME))\s*=/', $line, $m)) {
                    $key = $m[1];
                    $val = $want[$key] ?? '';
                    if ($key === 'DB_BUSINESS_NAME' && $val === '') {
                        continue; // 单库模式：不保留旧业务库键（避免误导）
                    }
                    $out[] = $key . '=' . static::envQuote($val);
                    $set[$key] = true;
                    continue;
                }
            }
            $out[] = $line;
        }
        // 追加缺失键（按固定顺序，保证可读）
        $order = ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_BUSINESS_NAME'];
        $appended = false;
        foreach ($order as $key) {
            $val = $want[$key] ?? '';
            if ($key === 'DB_BUSINESS_NAME' && $val === '') {
                continue; // 单库模式不写
            }
            if (!isset($set[$key])) {
                if (!$appended) {
                    $out[] = '';
                    $appended = true;
                }
                $out[] = $key . '=' . static::envQuote($val);
            }
        }

        file_put_contents($envPath, implode(PHP_EOL, $out) . PHP_EOL, LOCK_EX);
    }

    /**
     * .env 值安全转义（单引号字面量；转义内部反斜杠与单引号）
     */
    protected static function envQuote(string $value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    }

    /**
     * 写 config/crud.php（插件调参 + 数据库连接入口）：
     *  - 包含 database 段（DB 信息同时写在这里；config/database.php 模板 $__pick()
     *    优先取本文件的 database 段，绕开 .env 的 putenv 缓存问题）。
     *  - 原文件先备份 config/crud.php.wizard.bak
     */
    protected static function writeCrudConfig(string $root, string $pageBase, array $db, string $businessDb): void
    {
        $file = $root . '/config/crud.php';
        if (is_file($file)) {
            @copy($file, $file . '.wizard.bak');
        }
        // 单库模式：business_db 空则填回 admin_db，保证 mysql_business 也有明确库名
        $businessDbOut = $businessDb !== '' ? $businessDb : $db['name'];
        $pageBaseOut = addslashes($pageBase); // 仅防 PHP 字符串解析异常
        $dbHostOut = addslashes($db['host']);
        $dbNameOut = addslashes($db['name']);
        $dbUserOut = addslashes($db['user']);
        $dbPassOut = addslashes($db['password']);
        $businessDbEsc = addslashes($businessDbOut);
        $php = <<<PHP
<?php
/**
 * webman-crud 插件集中配置（Web 安装向导生成）
 * - database 段：DB 连接。config/database.php 模板 \$__pick() 优先读这里（避免
 *   .env 的 putenv 在 worker 启动后无法刷新导致的 1045 Access denied）。
 * - 其它段：插件调参
 */
return [
    // ---- 数据库（认证/管理面 + 业务库；单库模式下两连接指向同库）----
    'database' => [
        'host'        => '{$dbHostOut}',
        'port'        => '{$db['port']}',
        'username'    => '{$dbUserOut}',
        'password'    => '{$dbPassOut}',
        'admin_db'    => '{$dbNameOut}',
        'business_db' => '{$businessDbEsc}',
    ],
    // 前端挂载前缀（改了需同步重建前端：VITE_BASE_PATH）
    'page_base' => rtrim(env('CRUD_PAGE_BASE', '{$pageBaseOut}'), '/'),
    // /api/admin/* 是否强制 RBAC 校验：生产建议 true（默认 admin 角色不受影响）
    'admin_require_permission' => env('CRUD_ADMIN_REQUIRE_PERMISSION', false),
];
PHP;
        file_put_contents($file, $php, LOCK_EX);
    }

    /**
     * 读取进度 JSONL → 数组
     */
    protected static function readProgress(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $steps = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $steps[] = $decoded;
            }
        }
        return $steps;
    }
}
