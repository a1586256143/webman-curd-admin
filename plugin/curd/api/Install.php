<?php
namespace plugin\curd\api;

use support\Db;

/**
 * CURD 插件安装器
 *
 * 触发方式（任选其一，全部幂等可重复执行）：
 *  1. 命令行：php plugin/curd/install.php [--business-sql=<path>]...
 *  2. 代码调用：\plugin\curd\api\Install::install($isFirst, $extraSqls)
 *  3. webman 官方插件安装器/市场 zip 安装（自动发现本类 install/update 钩子）
 *
 * 执行内容：
 *  A. 建表：执行同目录 install.sql 的核心表（admin_users/admin_tokens/roles/
 *     admin_role_user/role_permission/casbin_rule/curd_configs/menus）
 *     及后台示例表 my_test（MyTestController「测试管理」演示用，可删除）
 *  B. （可选）建表：按 --business-sql 传入的路径依次执行额外 SQL，连接走
 *     config('plugin.curd.curd.business_connection')（默认 mysql_business）
 *  C. 种子：roles admin 角色（仅插超级管理员）、menus 基础菜单、admin 初始账号
 *     （admin/admin123，仅当表为空时插入；密码用运行时 password_hash 生成）；
 *     my_test 表存在时补 1 条示例数据 + 后台「测试管理」菜单（幂等，老项目升级可补齐）
 *  D. 密钥：生成 RSA-2048 密钥对到 config/keys/（api_rsa_private.pem / api_rsa_public.pem），
 *     供 API_ENCRYPT=true 的信封加密部署使用（公钥需替换进前端后重新构建）
 *
 * DB 连接（单库架构，认证与业务同一库）：
 * 认证库 config('plugin.curd.curd.admin_connection')（默认 mysql）；
 * 业务库 config('plugin.curd.curd.business_connection')（默认 mysql_business，
 * 与 mysql 指向同一 DB_NAME）。连接配置来自 .env 的 DB_* 键（config/database.php
 * 用 env() 读取）。
 *
 * 注意：本类位于 api/ 目录，不在宿主 composer psr-4（plugin\curd\app\）映射内，
 *       调用方需显式 require 本文件后再使用（install.php 引导已处理）。
 */
class Install
{
    /**
     * webman 官方 composer 基础插件识别常量（zip/市场安装器扫描用）
     */
    const WEBMAN_PLUGIN = true;

    /**
     * 初始管理员凭据（install() 的 $adminCreds 覆盖；admin_users 空表时创建）
     */
    protected static array $adminCreds = ['username' => 'admin', 'password' => 'admin123'];

    /**
     * 进度回调（Web 安装向导 / CLI --progress-file 使用）
     * @var callable|null 签名：function(array $line): void
     *                    $line = ['t'=>int,'type'=>'step|error|info','msg'=>string,'error'=>string]
     */
    protected static $onProgress = null;

    /**
     * 安装完成标记文件（宿主根 runtime/curd-installed.lock）：
     * Web 安装向导以此判定「已安装」并拒绝重复安装；删除该文件可重新进入向导。
     * 放 runtime 目录（运行态产物；重装 = 删锁 + 清库）。
     */
    protected static function lockFile(): string
    {
        return dirname(__DIR__, 3) . '/runtime/curd-installed.lock';
    }

    /**
     * 安装入口（官方 zip 安装器约定签名：install($isFirstInstall)）
     *
     * @param bool        $isFirstInstall  是否首次安装（保留参数兼容官方调用）
     * @param array       $extraSqls       额外 SQL 文件列表，元素为：
     *                                     ['path' => string, 'connection' => string|null]
     *                                     - path: 相对插件目录（plugin/curd/）或绝对路径
     *                                     - connection: 业务库连接名（默认 business_connection）
     * @param string|null $businessConn    覆盖业务库连接名（影响全部 extraSqls 默认值）
     * @param array       $adminCreds      初始管理员凭据 ['username'=>, 'password'=>]，
     *                                     留空用默认 admin/admin123（仅在 admin_users 空表时生效）
     * @param callable|null $onProgress    进度回调 function(array $line): void；
     *                                     $line=['t'=>int,'type'=>'step|error|info','msg'=>string,'error'=>string]
     */
    public static function install($isFirstInstall = false, array $extraSqls = [], ?string $businessConn = null, array $adminCreds = [], ?callable $onProgress = null)
    {
        if ($adminCreds !== []) {
            static::$adminCreds = array_merge(static::$adminCreds, $adminCreds);
        }
        static::$onProgress = $onProgress;
        static::emit('info', 'CURD 插件安装开始' . ($isFirstInstall ? '（首次安装）' : ''));
        $ok = true;

        // A0. 自动建库：库不存在时（1049 Unknown database 是首跑最常见错误）
        //     连接 MySQL server 执行 CREATE DATABASE IF NOT EXISTS（幂等）
        if (!static::ensureDatabases()) {
            static::emit('error', '数据库连接/自动建库失败，请根据上方 [ERROR] 排查后重试（可重复执行）');
            return false;
        }

        // A. 建表（认证/管理面）
        try {
            $created = static::runSqlFile('install.sql', static::adminDb());
            static::report($created > 0, "建表完成（执行 {$created} 条 DDL）", 'install.sql 未找到或无语句');
        } catch (\Throwable $e) {
            $ok = false;
            static::report(false, '', '建表失败: ' . $e->getMessage());
        }

        // B. 建表（业务库，可选）
        if (!empty($extraSqls)) {
            foreach ($extraSqls as $i => $spec) {
                $path = $spec['path'] ?? '';
                $conn = $spec['connection'] ?? $businessConn ?? (string)config('plugin.curd.curd.business_connection', 'mysql_business');
                if ($path === '') {
                    continue;
                }
                try {
                    $resolved = static::resolveSqlPath($path);
                    if (!is_file($resolved)) {
                        static::report(false, '', "业务 SQL 不存在: {$resolved}");
                        $ok = false;
                        continue;
                    }
                    $content = file_get_contents($resolved);
                    $db = Db::connection($conn);
                    $created = static::runSqlFile($content, $db, true);
                    static::report($created > 0, "业务表建表完成: " . basename($resolved) . "（{$created} 条 DDL，连 {$conn}）", basename($resolved) . ' 无语句');
                } catch (\Throwable $e) {
                    $ok = false;
                    static::report(false, '', "业务 SQL 执行失败 ({$path}): " . $e->getMessage());
                }
            }
        }

        // C. 种子（表空才插入，幂等）
        $ok = static::seedAll() && $ok;

        // D. 密钥
        $ok = static::ensureKeys() && $ok;

        if ($ok) {
            $account = static::$adminCreds['username'] ?? 'admin';
            static::report(true, "安装完成 ✅ 初始账号 {$account}（请登录后立即修改密码）");
            // 写「已安装」锁：Web 安装向导据此拒绝重复安装；删除锁文件可重新进入向导
            $lock = static::lockFile();
            if (!is_dir(dirname($lock))) {
                @mkdir(dirname($lock), 0755, true);
            }
            @file_put_contents($lock, date('Y-m-d H:i:s') . ' by ' . static::class . PHP_EOL, LOCK_EX);
        } else {
            static::report(false, '安装过程有步骤失败，请根据上方 [ERROR] 信息排查后重试（可重复执行）');
        }

        static::$onProgress = null;
        return $ok;
    }

    /**
     * 升级入口（官方约定）：仅补种子与密钥，不重建表
     */
    public static function update()
    {
        static::$onProgress = null;
        static::emit('info', 'CURD 插件升级：补种子与密钥');
        $ok = static::seedAll();
        $ok = static::ensureKeys() && $ok;
        return $ok;
    }

    /**
     * 业务库连接（与 ModelRegistry / CurdDb 一致）
     */
    public static function businessDb()
    {
        return Db::connection((string)config('plugin.curd.curd.business_connection', 'mysql_business'));
    }

    /**
     * 卸载入口（官方约定）：默认不删表（数据安全），如需删除请手动执行 DROP。
     */
    public static function uninstall()
    {
        static::emit('info', 'CURD 插件卸载：仅移除内置前端 public/ 与密钥（表与数据保留，如需删除请手动 DROP）');
        $lock = static::lockFile();
        if (is_file($lock) && @unlink($lock)) {
            static::report(true, '已移除已安装标记（可重新进入 Web 安装向导）');
        }
        $publicDir = (string)config('plugin.curd.curd.public_dir', dirname(__DIR__) . '/public');
        foreach (['api_rsa_private.pem', 'api_rsa_public.pem'] as $file) {
            $path = dirname(__DIR__) . '/config/keys/' . $file;
            if (is_file($path) && @unlink($path)) {
                static::report(true, "已移除 {$file}");
            }
        }
        if (is_dir($publicDir)) {
            // 不递归删除：交给用户确认（可能自行改造过内置前端）
            static::report(true, "内置前端目录存在：{$publicDir}（如需删除请手动执行 rm -rf）");
        }
        return true;
    }

    // ============================================================
    // 内部实现
    // ============================================================

    /**
     * 确保 admin / business 连接指向的数据库已存在：
     * 连接 MySQL server（不指定库名）执行 CREATE DATABASE IF NOT EXISTS（幂等）。
     *
     * 解决首跑最常见的 1049 Unknown database：新项目尚未建库时 install.php
     * 直接建表必然失败。配置来源 config('database.connections.*')（自动生成的
     * config/database.php 读 .env 的 DB_* 键；单库架构下 mysql 与 mysql_business
     * 指向同一 DB_NAME，本方法按 (host,port,user,database) 去重只建一次）。
     *
     * @return bool 全部就绪/建好返回 true
     */
    protected static function ensureDatabases(): bool
    {
        $ok = true;
        $seen = [];
        $connNames = array_values(array_unique(array_filter([
            (string)config('plugin.curd.curd.admin_connection', 'mysql'),
            (string)config('plugin.curd.curd.business_connection', 'mysql_business'),
        ])));

        foreach ($connNames as $conn) {
            $cfg = (array)config("database.connections.{$conn}", []);
            $host = (string)($cfg['host'] ?? '');
            $port = (string)($cfg['port'] ?? '3306');
            $user = (string)($cfg['username'] ?? '');
            $pass = (string)($cfg['password'] ?? '');
            $db   = (string)($cfg['database'] ?? '');
            if ($host === '' || $db === '') {
                static::report(false, '', "连接 {$conn} 配置不完整（缺 host/database），请检查 .env 的 DB_HOST/DB_NAME 等 DB_* 键");
                $ok = false;
                continue;
            }
            if (!preg_match('/^[A-Za-z0-9_\-]+$/', $db)) {
                static::report(false, '', "库名含非法字符（仅允许字母数字下划线连字符）: {$db}，请检查 .env 的 DB_NAME");
                $ok = false;
                continue;
            }
            $key = "{$host}:{$port}|{$user}|{$db}";
            if (isset($seen[$key])) {
                continue; // 与已处理连接指向同一库（单库：认证=业务同库）
            }
            $seen[$key] = true;
            try {
                $pdo = new \PDO(
                    "mysql:host={$host};port={$port};charset=utf8mb4",
                    $user,
                    $pass,
                    [\PDO::ATTR_TIMEOUT => 5, \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
                );
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
                static::report(true, "数据库已就绪: {$db}（不存在则已自动创建，连接 {$conn}）");
            } catch (\Throwable $e) {
                $ok = false;
                static::report(false, '', "自动建库失败 ({$conn}/{$db}): " . $e->getMessage() . '（请先手动 CREATE DATABASE，或核对 .env 的 DB_* 键）');
            }
        }
        return $ok;
    }

    /**
     * 执行 SQL 文件（按 --SPLIT-- 切分逐条执行）
     *
     * @param string|\PDO  $pathOrContent 相对插件目录的文件名 / 绝对路径 / 已读入内容（字符串）
     *                                    若 pathOrContent 是资源 / 已读入内容，$isContent=true
     * @param mixed        $db           webman Db 连接对象（Db::connection(...)）
     * @param bool         $isContent    $pathOrContent 是否为已读入内容字符串
     * @return int 成功执行的语句数
     * @throws \Throwable DB 不可用 / SQL 执行失败
     */
    public static function runSqlFile($pathOrContent, $db = null, bool $isContent = false): int
    {
        if ($isContent) {
            $content = (string)$pathOrContent;
        } else {
            $path = static::resolveSqlPath((string)$pathOrContent);
            if (!is_file($path)) {
                throw new \RuntimeException("SQL 文件不存在: {$path}");
            }
            $content = file_get_contents($path);
        }

        // 逐行剥离 -- 注释；--SPLIT-- 作为语句分隔标记（不能用纯注释剥离，会误删分隔符）
        $stmts = [];
        $buffer = '';
        foreach (explode("\n", (string)$content) as $line) {
            $trimmed = ltrim($line);
            if ($trimmed === '--SPLIT--') {
                if (trim($buffer) !== '') {
                    $stmts[] = trim($buffer);
                    $buffer = '';
                }
                continue;
            }
            if (str_starts_with($trimmed, '--') || $trimmed === '') {
                continue;
            }
            $buffer .= $line . "\n";
        }
        if (trim($buffer) !== '') {
            $stmts[] = trim($buffer);
        }

        $db = $db ?? static::adminDb();
        $count = 0;
        foreach ($stmts as $stmt) {
            $db->statement($stmt);
            $count++;
        }
        return $count;
    }

    /**
     * 解析 SQL 路径：绝对路径直接返回；相对路径按以下顺序探测：
     *  1. CWD（宿主项目根，通常 install.php 在根目录执行）
     *  2. 插件目录（plugin/curd/，供 Install::install() 被独立调用时使用）
     * 都找不到则原样返回（让 is_file 抛不存在错误，便于排查）。
     */
    protected static function resolveSqlPath(string $path): string
    {
        if ($path === '' || $path[0] === '/' || preg_match('#^[A-Za-z]:[\\\\/]#', $path)) {
            return $path;
        }
        $cwd = getcwd();
        if ($cwd && is_file($cwd . '/' . $path)) {
            return $cwd . '/' . $path;
        }
        return dirname(__DIR__) . '/' . ltrim($path, '/');
    }

    /**
     * 种子数据（幂等：仅在对应表为空时插入）
     */
    protected static function seedAll(): bool
    {
        $ok = true;
        $db = null;
        try {
            $db = static::adminDb();
        } catch (\Throwable $e) {
            static::report(false, '', '无法连接认证库: ' . $e->getMessage());
            return false;
        }

        // roles
        try {
            if ($db->table('roles')->count() === 0) {
                $now = date('Y-m-d H:i:s');
                $db->table('roles')->insert([
                    ['name' => '超级管理员', 'slug' => 'admin', 'description' => '拥有全部权限（casbin p,admin,*,*）', 'created_at' => $now, 'updated_at' => $now],
                ]);
                static::report(true, 'roles 种子已插入（仅超级管理员 admin；其它角色请在后台按需新增）');
            } else {
                static::report(true, 'roles 已有数据，跳过种子');
            }
        } catch (\Throwable $e) {
            $ok = false;
            static::report(false, '', 'roles 种子失败: ' . $e->getMessage());
        }

        // menus（显式 id 保证父子关联；表空才插）
        try {
            if ($db->table('menus')->count() === 0) {
                $now = date('Y-m-d H:i:s');
                $rows = [
                    [1, 0, '首页', 'Monitor', '/dashboard', '', 1, 1, 1],
                    [2, 0, '系统管理', 'Setting', '', '', 2, 2, 1],
                    [3, 2, '用户管理', 'User', '/user', 'sys.user.manage', 1, 1, 1],
                    [4, 2, '角色管理', 'UserFilled', '/role', 'sys.role.manage', 2, 1, 1],
                    [5, 2, '菜单管理', 'Menu', '/menu-manage', 'sys.menu.manage', 3, 1, 1],
                    [6, 0, '配置生成器', 'MagicStick', '/curd-generator', '', 3, 1, 1],
                ];
                foreach ($rows as $r) {
                    [$id, $pid, $title, $icon, $path, $perm, $sort, $type, $visible] = $r;
                    $db->table('menus')->insert([
                        'id' => $id, 'parent_id' => $pid, 'title' => $title, 'icon' => $icon,
                        'path' => $path, 'permission' => $perm, 'sort' => $sort,
                        'type' => $type, 'visible' => $visible, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
                static::report(true, 'menus 种子已插入（6 条基础菜单）');
            } else {
                static::report(true, 'menus 已有数据，跳过种子');
            }
        } catch (\Throwable $e) {
            $ok = false;
            static::report(false, '', 'menus 种子失败: ' . $e->getMessage());
        }

        // admin 初始账号 + 角色关联 + casbin 全权限（admin_users 空才插）
        try {
            if ($db->table('admin_users')->count() === 0) {
                $now = date('Y-m-d H:i:s');
                $userName = (string)(static::$adminCreds['username'] ?? 'admin');
                $userPass = (string)(static::$adminCreds['password'] ?? 'admin123');
                $userId = $db->table('admin_users')->insertGetId([
                    'username' => $userName,
                    'password' => password_hash($userPass, PASSWORD_BCRYPT),
                    'name'     => 'Administrator',
                    'status'   => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                static::report(true, "初始管理员已创建（{$userName}，id={$userId}）");

                // 关联超级管理员角色
                $role = $db->table('roles')->where('slug', 'admin')->first();
                if ($role && $db->table('admin_role_user')->where('admin_user_id', $userId)->count() === 0) {
                    $db->table('admin_role_user')->insert(['admin_user_id' => $userId, 'role_id' => $role->id]);
                }

                // casbin：用户 g 角色 + 角色 p 全权限
                $gExists = $db->table('casbin_rule')
                    ->where('ptype', 'g')->where('v0', (string)$userId)->where('v1', 'admin')->count();
                if (!$gExists) {
                    $db->table('casbin_rule')->insert([
                        'ptype' => 'g', 'v0' => (string)$userId, 'v1' => 'admin',
                        'v2' => '', 'v3' => '', 'v4' => '', 'v5' => '',
                    ]);
                }
                $pExists = $db->table('casbin_rule')
                    ->where('ptype', 'p')->where('v0', 'admin')->where('v1', '*')->count();
                if (!$pExists) {
                    $db->table('casbin_rule')->insert([
                        'ptype' => 'p', 'v0' => 'admin', 'v1' => '*', 'v2' => '*',
                        'v3' => '', 'v4' => '', 'v5' => '',
                    ]);
                }
            } else {
                static::report(true, 'admin_users 已有账号，跳过初始账号创建');
            }
        } catch (\Throwable $e) {
            $ok = false;
            static::report(false, '', 'admin 账号种子失败: ' . $e->getMessage());
        }

        // my_test 后台示例（宿主根示例控制器 MyTestController + my_test 表，开箱演示）：
        // 表存在时 → ① 空表插 1 条示例数据 ② menus 补「测试管理」菜单（path=/my-test，
        // 幂等：已存在跳过，老项目升级执行 update() 时也能自动补齐）。
        // 表不存在（老项目尚未建 my_test）时跳过并提示，不阻断安装/升级。
        try {
            $testTableExists = $db->getSchemaBuilder()->hasTable('my_test');
        } catch (\Throwable $e) {
            $testTableExists = false;
        }

        if ($testTableExists) {
            // 1) 示例数据（空表插 1 条）
            try {
                if ($db->table('my_test')->count() === 0) {
                    $now = date('Y-m-d H:i:s');
                    $db->table('my_test')->insert([
                        'name'       => 'webman-curd-admin 安装成功',
                        'remark'     => '安装向导自动写入的示例数据，可在后台「测试管理」中增删改查',
                        'status'     => 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    static::report(true, 'my_test 示例数据已插入（后台「测试管理」演示用）');
                } else {
                    static::report(true, 'my_test 已有数据，跳过示例种子');
                }
            } catch (\Throwable $e) {
                echo '[WARN] my_test 示例数据写入失败（可忽略）: ' . $e->getMessage() . PHP_EOL;
            }

            // 2) 后台菜单「测试管理」（menus 无该顶级菜单时插入，幂等）
            try {
                $hasMenu = $db->table('menus')
                    ->where('parent_id', 0)
                    ->where('path', '/my-test')
                    ->count() > 0;
                if (!$hasMenu) {
                    $now = date('Y-m-d H:i:s');
                    $db->table('menus')->insert([
                        'parent_id'  => 0,
                        'title'      => '测试管理',
                        'icon'       => 'Collection',
                        'path'       => '/my-test',
                        'component'  => '',
                        'permission' => '',
                        'sort'       => 4,
                        'type'       => 1,
                        'visible'    => 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    static::report(true, '后台菜单已补充：「测试管理」（/my-test，MyTestController 演示页）');
                } else {
                    static::report(true, '「测试管理」菜单已存在，跳过');
                }
            } catch (\Throwable $e) {
                echo '[WARN] 「测试管理」菜单写入失败（可忽略，重跑 install.php 可补齐）: ' . $e->getMessage() . PHP_EOL;
            }

            // 3) curd_configs「/my-test」示例配置（前端 GET /api/curd/config?route_path=/my-test
            //    直接查本表，找不到即返回 404「配置不存在，请先在配置生成器中生成并保存」。
            //    表里没该 route_path 时插入一份基础 CURD 配置（与 MyTestController 的
            //    columns/search/formFields/rules 等价），让宿主开箱即可访问。
            //    已存在则跳过（用户可去配置生成器修改，幂等不覆盖）。
            try {
                $hasConfig = $db->table('curd_configs')->where('route_path', '/my-test')->count() > 0;
                if (!$hasConfig) {
                    $now = date('Y-m-d H:i:s');
                    $configJson = json_encode([
                        'columns' => [
                            ['prop' => 'id',         'label' => 'ID',         'width' => 80,  'align' => 'center'],
                            ['prop' => 'name',       'label' => '名称'],
                            ['prop' => 'remark',     'label' => '备注'],
                            ['prop' => 'status',     'label' => '状态',     'dict'  => ['1' => '启用', '0' => '禁用']],
                            ['prop' => 'created_at', 'label' => '创建时间', 'width' => 170],
                            ['prop' => 'updated_at', 'label' => '更新时间', 'width' => 170],
                        ],
                        'search' => [
                            ['type' => 'input',  'prop' => 'name',   'label' => '名称'],
                            ['type' => 'select', 'prop' => 'status', 'label' => '状态', 'options' => ['1' => '启用', '0' => '禁用']],
                        ],
                        'formFields' => [
                            ['type' => 'input',    'prop' => 'name',   'label' => '名称', 'placeholder' => '请输入名称'],
                            ['type' => 'textarea', 'prop' => 'remark', 'label' => '备注', 'placeholder' => '请输入备注'],
                            ['type' => 'select',   'prop' => 'status', 'label' => '状态', 'options' => ['1' => '启用', '0' => '禁用']],
                        ],
                        'rules' => ['name' => 'required|max:100'],
                    ], JSON_UNESCAPED_UNICODE);
                    $db->table('curd_configs')->insert([
                        'table_name' => 'my_test',
                        'title'      => '测试管理',
                        'page_name'  => '测试管理',
                        'route_path' => '/my-test',
                        'config'     => $configJson,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    static::report(true, 'curd_configs「/my-test」示例配置已插入（前端可立即访问）');
                } else {
                    static::report(true, 'curd_configs「/my-test」已存在，跳过示例配置');
                }
            } catch (\Throwable $e) {
                echo '[WARN] curd_configs「/my-test」写入失败（可忽略，去配置生成器手动生成即可）: ' . $e->getMessage() . PHP_EOL;
            }
        } else {
            echo '[INFO]  my_test 表不存在，跳过内置示例（数据 + 菜单）。该表随 install.sql 创建；老项目可重装或手动执行其中建表 SQL 补齐' . PHP_EOL;
            static::emit('info', 'my_test 表不存在，跳过内置示例（数据 + 菜单）');
        }

        return $ok;
    }

    /**
     * 生成 RSA-2048 密钥对（config/keys/，已存在则跳过）
     */
    protected static function ensureKeys(): bool
    {
        $keysDir = dirname(__DIR__) . '/config/keys';
        $private = $keysDir . '/api_rsa_private.pem';
        $public  = $keysDir . '/api_rsa_public.pem';

        if (is_file($private) && is_file($public)) {
            static::report(true, 'RSA 密钥已存在，跳过生成');
            return true;
        }
        if (!function_exists('openssl_pkey_new')) {
            static::report(false, '', 'openssl 扩展不可用，无法生成 RSA 密钥（如不需加密可忽略）');
            return true; // 不阻断安装：无密钥时 API_ENCRYPT 应保持 false
        }

        try {
            if (!is_dir($keysDir)) {
                mkdir($keysDir, 0700, true);
            }
            $res = openssl_pkey_new([
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ]);
            if ($res === false) {
                throw new \RuntimeException('openssl_pkey_new 失败');
            }
            $privPem = '';
            openssl_pkey_export($res, $privPem);
            $details = openssl_pkey_get_details($res);
            file_put_contents($private, $privPem, LOCK_EX);
            chmod($private, 0600);
            file_put_contents($public, $details['key'], LOCK_EX);
            chmod($public, 0644);
            static::report(true, "RSA-2048 密钥对已生成：\n    {$private}\n    {$public}");
            static::report(true, '如需开启 API_ENCRYPT=true：把 api_rsa_public.pem 内容替换进前端 src/utils/apiCrypto.js 的 RSA_PUBLIC_KEY 后重新构建');
            return true;
        } catch (\Throwable $e) {
            static::report(false, '', 'RSA 密钥生成失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 认证库连接（与 AuthController / Rbac / MenuController 一致）
     */
    public static function adminDb()
    {
        return Db::connection((string)config('plugin.curd.curd.admin_connection', 'mysql'));
    }

    /**
     * 控制台报告（webman 环境也能正常输出）
     */
    protected static function report(bool $ok, string $message, string $error = ''): void
    {
        if ($ok) {
            echo '[OK]    ' . $message . PHP_EOL;
            static::emit('step', $message);
            return;
        }
        echo '[ERROR] ' . $message . ($error !== '' ? ($message !== '' ? ' — ' : '') . $error : '') . PHP_EOL;
        static::emit('error', $message, $error);
    }

    /**
     * 进度事件（设置过 onProgress 时投递；type: step=单步成功 / error=失败 / info=阶段信息）
     */
    protected static function emit(string $type, string $message, string $error = ''): void
    {
        if (!static::$onProgress) {
            return;
        }
        call_user_func(static::$onProgress, [
            't'     => time(),
            'type'  => $type,
            'msg'   => $message,
            'error' => $error,
        ]);
    }

    protected static function banner(string $text): void
    {
        echo PHP_EOL . '== ' . $text . ' ==' . PHP_EOL;
    }
}
