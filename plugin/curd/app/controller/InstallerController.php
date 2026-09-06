<?php
namespace plugin\curd\app\controller;

use support\Request;
use Webman\Http\Response;

/**
 * Web 安装向导（新项目一键安装，仅首次可用）
 *
 * 访问：
 *   GET  /app/curd-installer          → 向导页面（静态页）
 *   GET  /api/curd-installer/status   → 是否已安装
 *   POST /api/curd-installer/setup    → 提交并执行安装（DB 写 .env，调参写 config/curd.php）
 *   GET  /api/curd-installer/progress → 安装进度（轮询，JSONL）
 *
 * 配置分工（单库架构）：
 *   - 数据库信息（DB_HOST/PORT/NAME/USER/PASSWORD）写入宿主 .env：按键合并/替换，
 *     绝不删除 .env 里其它内容；config/database.php 用 env() 读取这些键。
 *   - 认证库与业务库【同一库】（强制单库，向导不提供分库选项，无 DB_BUSINESS_NAME）。
 *   - 插件调参（page_base 等）写入宿主 config/curd.php（仅调参，不含数据库段）。
 *   - 执行安装用子进程 php plugin/curd/install.php（独立加载 .env/config，
 *     规避运行期配置缓存），进度写 runtime/curd-installer-progress.log 供轮询；
 *   - 安装成功生成 runtime/curd-installed.lock（由 api/Install 统一写），
 *     存在即拒绝再次安装；删除锁文件可重新进入向导（需先清库）。
 *   - 安装成功【自动平滑 reload】（免手动重启，webman-admin 同款）：向 master 发
 *     SIGUSR1 → worker 处理完当前请求后重启并重读 .env/config → 新 DB_* 立即生效
 *     （避免 1045），无需 php start.php restart；信号不可用/Windows 时回退手动命令。
 */
class InstallerController
{
    /** 向导静态页相对 public/ 的路径 */
    protected const PAGE_FILE = '/installer/index.html';

    /** 进度文件相对宿主根 */
    protected const PROGRESS_FILE = '/runtime/curd-installer-progress.log';

    /** 已安装锁（与 plugin\curd\api\Install::lockFile 一致；放 runtime，属运行态文件） */
    protected static function lockFile(): string
    {
        return static::hostRoot() . '/runtime/curd-installed.lock';
    }

    protected static function hostRoot(): string
    {
        // plugin/curd/app/controller → 宿主根
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
            return response('安装向导页面缺失：plugin/curd/public/installer/index.html', 404);
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
        $adminUser = trim((string)($body['admin_user'] ?? ''));
        $adminPass = (string)($body['admin_pass'] ?? '');
        $adminPass2 = (string)($body['admin_pass2'] ?? '');
        $pageBase = trim((string)($body['page_base'] ?? '/app/curd'));

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
            // 0) 写 .env（数据库信息是唯一 DB 来源；按键替换/追加，不删其它内容）
            static::writeEnv($root, $db);

            // 1) 写 config/curd.php（仅插件调参，不含数据库段）
            //    原文件先备份 config/curd.php.wizard.bak。
            static::writeCurdConfig($root, $pageBase);

            // 2) 清空进度文件 → 子进程执行安装（独立进程重新加载 .env，规避运行期配置缓存）
            @unlink($progressFile);
            if (!is_dir(dirname($progressFile))) {
                @mkdir(dirname($progressFile), 0755, true);
            }
            $cmd = [
                PHP_BINARY,
                $root . '/plugin/curd/install.php',
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

            // 安装成功 → 触发平滑 reload（向 master 发 SIGUSR1，webman-admin 同款机制）：
            // worker 处理完当前请求后退出重启，新 worker 重新执行 support/bootstrap.php
            // （Dotenv 重读 .env + Config::clear 重载 config/database.php）→ 新 DB_* 生效，
            // 无需手动 restart。失败时 auto_reload=false，前端回退手动命令。
            $autoReload = $ok && static::signalReload();

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
                // 已触发平滑 reload（SIGUSR1）：worker 处理完当前请求后重启并重读
                // .env/config，新 DB_* 即刻生效（避免 1045）。auto_reload=true 时前端
                // 短暂等待后进入后台；restart_cmd 保留为信号不可用/守护场景的兜底提示。
                'auto_reload' => $autoReload,
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
     * 安装成功后触发 webman 平滑 reload（免手动重启，webman-admin 同款机制）。
     *
     * 原理：
     *   - .env / config 由 worker 进程启动时固化（support/bootstrap.php 里
     *     Dotenv->load() + Config::clear()），写 .env 后运行中 worker 仍是旧值 →
     *     数据库请求 1045。必须让 worker 重启重读配置；
     *   - 向 master（当前 worker 的父进程）发 SIGUSR1 = workerman reload 信号：
     *     master 逐个让 reloadable worker 处理完当前请求后退出并 fork 新 worker，
     *     新 worker 重新执行 bootstrap → .env/config 全部重载 → 新 DB_* 生效；
     *   - reload 平滑且轻量：master 不退出、端口不断开、正在处理的 HTTP 响应
     *     能正常返回（默认 stop_timeout 兜底强杀），远优于 restart（杀 master 重建）。
     *
     * 限制（返回 false，前端回退手动 restart_cmd）：
     *   - Windows（无 posix_kill / workerman 无信号机制）
     *   - PHP 未装 posix 扩展
     *
     * @return bool 信号是否已发出（reload 是否完成看 runtime 日志/worker 重启）
     */
    protected static function signalReload(): bool
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return false; // Windows：workerman 无信号 reload，需手动重启
        }
        if (!function_exists('posix_kill') || !defined('SIGUSR1')) {
            return false; // 缺 posix 扩展
        }
        $masterPid = function_exists('posix_getppid') ? posix_getppid() : 0;
        if ($masterPid <= 0) {
            return false;
        }
        set_error_handler(static fn() => true);
        $ok = posix_kill($masterPid, SIGUSR1); // 当前 worker 的父进程 = master
        restore_error_handler();
        return $ok;
    }

    /**
     * 把数据库信息合并写入 .env（单库架构，仅 DB_HOST/PORT/NAME/USER/PASSWORD 五个键）：
     *  - 已有键：原位替换（不删其它行/注释/键）
     *  - 缺失键：按标准顺序追加到文件尾
     *  - 遗留的 DB_BUSINESS_NAME 键自动剔除（分库已废弃）
     * 值统一单引号包裹（phpdotenv 字面量语义），密码含 #/空格/引号均安全。
     */
    protected static function writeEnv(string $root, array $db): void
    {
        $envPath = $root . '/.env';
        $exists = is_file($envPath);
        $lines = $exists ? file($envPath, FILE_IGNORE_NEW_LINES) : [];
        if ($lines === false) {
            $lines = [];
        }

        $want = [
            'DB_HOST'     => $db['host'],
            'DB_PORT'     => $db['port'],
            'DB_NAME'     => $db['name'],
            'DB_USER'     => $db['user'],
            'DB_PASSWORD' => $db['password'],
        ];

        $set = [];
        $out = [];
        foreach ($lines as $line) {
            $trim = ltrim($line);
            if ($trim !== '' && $trim[0] !== '#') {
                if (preg_match('/^(DB_(?:HOST|PORT|NAME|USER|PASSWORD|BUSINESS_NAME))\s*=/', $line, $m)) {
                    $key = $m[1];
                    if ($key === 'DB_BUSINESS_NAME') {
                        continue; // 单库架构：剔除遗留的分库键
                    }
                    $out[] = $key . '=' . static::envQuote($want[$key]);
                    $set[$key] = true;
                    continue;
                }
            }
            $out[] = $line;
        }
        // 追加缺失键（按固定顺序，保证可读）
        $order = ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'];
        $appended = false;
        foreach ($order as $key) {
            if (!isset($set[$key])) {
                if (!$appended) {
                    $out[] = '';
                    $appended = true;
                }
                $out[] = $key . '=' . static::envQuote($want[$key]);
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
     * 写 config/curd.php（仅插件调参，不含数据库段）：
     * 数据库连接唯一入口是 .env 的 DB_*（config/database.php 用 env() 读取）。
     * 原文件先备份 config/curd.php.wizard.bak
     */
    protected static function writeCurdConfig(string $root, string $pageBase): void
    {
        $file = $root . '/config/curd.php';
        if (is_file($file)) {
            @copy($file, $file . '.wizard.bak');
        }
        $pageBaseOut = $pageBase !== '' ? $pageBase : '/app/curd';
        $pageBaseEsc = addslashes($pageBaseOut);
        $php = str_replace('__PAGE_BASE__', $pageBaseEsc, <<<'PHP'
<?php
/**
 * webman-curd-admin 插件集中配置（Web 安装向导生成）
 *
 * 本文件只承载【插件调参】；数据库连接信息在宿主 .env（DB_*，由 config/database.php
 * 用 env() 读取）。认证库与业务库同一库（单库架构）。
 * 顶层键覆盖 plugin/curd/config/curd.php 同名默认值，不写的键沿用内置默认。
 */
return [
    // 前端挂载前缀（改了需同步重建前端：VITE_BASE_PATH）
    'page_base' => '__PAGE_BASE__',

    // /api/admin/* 是否强制 RBAC 校验：生产建议 true（默认 admin 角色不受影响）
    'admin_require_permission' => false,

    // 连接名（config/database.php connections 键；单库下两者指向同一 DB_NAME）：
    // 'admin_connection'    => 'mysql',            // 认证库连接名
    // 'business_connection' => 'mysql_business',   // 业务模型默认连接名
];
PHP
        );
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
