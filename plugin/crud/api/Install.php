<?php
namespace plugin\crud\api;

use support\Db;

/**
 * CRUD 插件安装器
 *
 * 触发方式（任选其一，全部幂等可重复执行）：
 *  1. 命令行：php plugin/crud/install.php [--business-sql=<path>]...
 *  2. 代码调用：\plugin\crud\api\Install::install($isFirst, $extraSqls)
 *  3. webman 官方插件安装器/市场 zip 安装（自动发现本类 install/update 钩子）
 *
 * 执行内容：
 *  A. 建表：执行同目录 install.sql 的 8 张核心表（admin_users/admin_tokens/roles/
 *     admin_role_user/role_permission/casbin_rule/crud_configs/menus）
 *  B. （可选）建表：按 --business-sql 传入的路径依次执行额外 SQL，连接走
 *     config('plugin.crud.crud.business_connection')（默认 mysql_business）
 *  C. 种子：roles 三默认角色、menus 基础菜单、admin 初始账号（admin/admin123，
 *     仅当表为空时插入；密码用运行时 password_hash 生成）
 *  D. 密钥：生成 RSA-2048 密钥对到 config/keys/（api_rsa_private.pem / api_rsa_public.pem），
 *     供 API_ENCRYPT=true 的信封加密部署使用（公钥需替换进前端后重新构建）
 *
 * DB 连接：认证库 config('plugin.crud.crud.admin_connection')（默认 mysql）；
 * 业务库 config('plugin.crud.crud.business_connection')（默认 mysql_business）。
 *
 * 注意：本类位于 api/ 目录，不在宿主 composer psr-4（plugin\crud\app\）映射内，
 *       调用方需显式 require 本文件后再使用（install.php 引导已处理）。
 */
class Install
{
    /**
     * webman 官方 composer 基础插件识别常量（zip/市场安装器扫描用）
     */
    const WEBMAN_PLUGIN = true;

    /**
     * 安装入口（官方 zip 安装器约定签名：install($isFirstInstall)）
     *
     * @param bool        $isFirstInstall  是否首次安装（保留参数兼容官方调用）
     * @param array       $extraSqls       额外 SQL 文件列表，元素为：
     *                                     ['path' => string, 'connection' => string|null]
     *                                     - path: 相对插件目录（plugin/crud/）或绝对路径
     *                                     - connection: 业务库连接名（默认 business_connection）
     * @param string|null $businessConn    覆盖业务库连接名（影响全部 extraSqls 默认值）
     */
    public static function install($isFirstInstall = false, array $extraSqls = [], ?string $businessConn = null)
    {
        static::banner('CRUD 插件安装开始' . ($isFirstInstall ? '（首次安装）' : ''));
        $ok = true;

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
                $conn = $spec['connection'] ?? $businessConn ?? (string)config('plugin.crud.crud.business_connection', 'mysql_business');
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

        static::banner($ok
            ? '安装完成 ✅ 默认账号 admin / admin123（请登录后立即修改）'
            : '安装过程有步骤失败，请根据上方 [ERROR] 信息排查后重试（可重复执行）');

        return $ok;
    }

    /**
     * 升级入口（官方约定）：仅补种子与密钥，不重建表
     */
    public static function update()
    {
        static::banner('CRUD 插件升级：补种子与密钥');
        $ok = static::seedAll();
        $ok = static::ensureKeys() && $ok;
        return $ok;
    }

    /**
     * 业务库连接（与 ModelRegistry / CrudDb 一致）
     */
    public static function businessDb()
    {
        return Db::connection((string)config('plugin.crud.crud.business_connection', 'mysql_business'));
    }

    /**
     * 卸载入口（官方约定）：默认不删表（数据安全），如需删除请手动执行 DROP。
     */
    public static function uninstall()
    {
        static::banner('CRUD 插件卸载：仅移除内置前端 public/ 与密钥（表与数据保留，如需删除请手动 DROP）');
        $publicDir = (string)config('plugin.crud.crud.public_dir', dirname(__DIR__) . '/public');
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
     *  2. 插件目录（plugin/crud/，供 Install::install() 被独立调用时使用）
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
                    ['name' => '运营', 'slug' => 'yunying', 'description' => '运营角色（按需授予 crud.* 权限）', 'created_at' => $now, 'updated_at' => $now],
                    ['name' => '财务', 'slug' => 'caiwu', 'description' => '财务角色（按需授予 crud.* 权限）', 'created_at' => $now, 'updated_at' => $now],
                ]);
                static::report(true, 'roles 种子已插入（admin/yunying/caiwu）');
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
                    [6, 0, '配置生成器', 'MagicStick', '/crud-generator', '', 3, 1, 1],
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
                $userId = $db->table('admin_users')->insertGetId([
                    'username' => 'admin',
                    'password' => password_hash('admin123', PASSWORD_BCRYPT),
                    'name'     => 'Administrator',
                    'status'   => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                static::report(true, "admin 初始账号已创建（id={$userId}，密码 admin123）");

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
        return Db::connection((string)config('plugin.crud.crud.admin_connection', 'mysql'));
    }

    /**
     * 控制台报告（webman 环境也能正常输出）
     */
    protected static function report(bool $ok, string $message, string $error = ''): void
    {
        if ($ok) {
            echo '[OK]    ' . $message . PHP_EOL;
            return;
        }
        echo '[ERROR] ' . $message . ($error !== '' ? ($message !== '' ? ' — ' : '') . $error : '') . PHP_EOL;
    }

    protected static function banner(string $text): void
    {
        echo PHP_EOL . '== ' . $text . ' ==' . PHP_EOL;
    }
}
