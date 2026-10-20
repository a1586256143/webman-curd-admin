<?php

namespace plugin\curd\app\command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CURD 脚手架公共基类
 *
 * make:curd / make:curd-action / make:curd-import 三个命令共享的代码生成与文件操作能力：
 * 表结构读取、命名推导、PHP 字面量拼装、宿主翻译探测、控制器/路由文件写入与修改。
 *
 * 之所以抽成基类，是因为「加操作按钮 / 加导入」需要**改写已存在的控制器文件**，
 * 与「首次生成」共用同一套命名与字面量规则，放在一处避免逻辑漂移。
 */
abstract class CurdScaffoldCommand extends Command
{
    /** 表单里不出现的自动维护字段 */
    protected const SYSTEM_FIELDS = ['created_at', 'updated_at', 'deleted_at'];

    /** 隐藏列（列表默认不展示） */
    protected const HIDDEN_COLUMNS = ['updated_at', 'deleted_at'];

    /** enum 标签颜色轮转 */
    protected const TAG_TYPES = ['primary', 'success', 'warning', 'danger', 'info'];

    // ============================================================
    // 表结构读取
    // ============================================================

    /**
     * 读取表字段元信息（SHOW FULL COLUMNS）
     *
     * @return array<int, array<string, mixed>>
     */
    protected function readColumns(string $connection, string $table): array
    {
        $rows = \support\Db::connection($connection)->select(
            'SHOW FULL COLUMNS FROM `' . str_replace('`', '', $table) . '`'
        );
        $columns = [];
        foreach ($rows as $row) {
            $columns[] = $this->parseColumn((array)$row);
        }
        return $columns;
    }

    /**
     * 归一化单个字段的元信息
     */
    protected function parseColumn(array $row): array
    {
        $field   = (string)($row['Field'] ?? '');
        $type    = strtolower((string)($row['Type'] ?? ''));
        $extra   = strtolower((string)($row['Extra'] ?? ''));
        $nullable = strtoupper((string)($row['Null'] ?? 'YES')) === 'YES';
        $default = $row['Default'] ?? null;
        $comment = trim((string)($row['Comment'] ?? ''));

        $base = $type;
        $args = '';
        if (preg_match('/^([a-z]+)\s*(?:\((.*)\))?/', $type, $m)) {
            $base = $m[1];
            $args = isset($m[2]) ? (string)$m[2] : '';
        }

        $enumOptions = [];
        if (in_array($base, ['enum', 'set'], true) && $args !== '') {
            foreach (explode(',', $args) as $item) {
                $value = trim($item);
                if ($value !== '' && ($value[0] === "'" || $value[0] === '"')) {
                    $value = substr($value, 1, -1);
                }
                $enumOptions[] = str_replace("''", "'", $value);
            }
        }

        return [
            'field'         => $field,
            'type'          => $type,
            'base'          => $base,
            'args'          => $args,
            'unsigned'      => str_contains($type, 'unsigned'),
            'nullable'      => $nullable,
            'default'       => $default,
            'hasDefault'    => $default !== null,
            'extra'         => $extra,
            'autoIncrement' => str_contains($extra, 'auto_increment'),
            'comment'       => $comment,
            'enumOptions'   => $enumOptions,
            'isBoolean'     => $base === 'tinyint' && $args === '1',
            'label'         => $comment !== '' ? $comment : $this->humanize($field),
            // 标题 key：始终用「字段名推导的英文短语」（request_order → Request order），
            // 中文注释只作为翻译建议（写进 fields.php 的 'Request order' => '请求订单号'）
            'label_en'      => $this->humanize($field),
        ];
    }

    /**
     * 表注释（SHOW TABLE STATUS.Comment）
     */
    protected function readTableComment(string $connection, string $table): string
    {
        try {
            $row = \support\Db::connection($connection)->selectOne(
                'SHOW TABLE STATUS WHERE Name = ?',
                [$table]
            );
            return $row ? trim((string)($row->Comment ?? '')) : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    // ============================================================
    // 命名推导 / 字面量拼装
    // ============================================================

    /**
     * 表名 → 模型名（与 CurdController::tableNameToModelName 规则一致）
     *   my_test → MyTest ｜ a_packages → APackage ｜ admin_users → AdminUser
     */
    protected function tableToModelName(string $table): string
    {
        if ($table === '') {
            return '';
        }
        $hasPrefix = (bool)preg_match('/^(a_|t_)/', $table);
        $name = preg_replace('/^(a_|t_)/', '', $table);

        if (str_ends_with($name, 'sses') && strlen($name) > 5) {
            $name = substr($name, 0, -2);
        } elseif (str_ends_with($name, 'ies') && strlen($name) > 4) {
            $name = substr($name, 0, -3) . 'y';
        } elseif (str_ends_with($name, 's') && !str_ends_with($name, 'ss')
            && !str_ends_with($name, 'us') && !str_ends_with($name, 'is')) {
            $name = substr($name, 0, -1);
        }

        $parts = array_filter(explode('_', $name));
        $camel = '';
        foreach ($parts as $p) {
            $camel .= ucfirst($p);
        }
        $camel = $camel ?: ucfirst($name);

        return $hasPrefix ? 'A' . $camel : $camel;
    }

    /**
     * 表名 → 前端路由路径：my_test → /my-test
     */
    protected function tableToRoute(string $table): string
    {
        $name = preg_replace('/^(a_|t_)/', '', $table);
        return '/' . str_replace('_', '-', (string)$name);
    }

    /**
     * 字段名 → 英文短语标题：created_at → Created at ｜ ip_platform → Ip platform
     */
    protected function humanize(string $name): string
    {
        // 常见缩写保持惯用大写（与内置 fields.php 的 'ID' 键对齐）
        if (strtolower($name) === 'id') {
            return 'ID';
        }
        $text = trim((string)preg_match('/\s+/', $name) ? preg_replace('/\s+/', ' ', str_replace(['-', '_'], ' ', $name)) : str_replace(['-', '_'], ' ', $name));
        return $text === '' ? $name : ucfirst($text);
    }

    /**
     * 目录（相对 base_path）→ 命名空间
     */
    protected function dirToNamespace(string $dir): string
    {
        $dir = trim(str_replace('\\', '/', $dir), '/');
        $dir = preg_replace('#^\./#', '', $dir);
        return str_replace('/', '\\', $dir) ?: 'app';
    }

    /**
     * 命名空间 → 目录（相对 base_path）（dirToNamespace 的逆运算）
     */
    protected function namespaceToDir(string $namespace): string
    {
        return trim(str_replace('\\', '/', $namespace), '/');
    }

    /**
     * 操作名 → 方法名 / Studly：audit → actionAudit，batch-on → actionBatchOn
     */
    protected function toActionMethod(string $name): string
    {
        return 'action' . str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $name)));
    }

    /**
     * 解析 `name[:label]` 写法
     */
    protected function parseNameLabel(string $raw): array
    {
        $raw = trim((string)$raw);
        $parts = explode(':', $raw, 2);
        $name = trim($parts[0]);
        $label = trim($parts[1] ?? '') ?: $name;
        return [$name, $label];
    }

    /**
     * 字段标题表达式：统一包 __()，key 用「字段名推导的英文短语」
     */
    protected function labelExpr(array $col): string
    {
        return '__(' . $this->phpString($this->labelKey($col)) . ')';
    }

    /**
     * 取字段的翻译 key（优先英文短语）
     */
    protected function labelKey(array $col): string
    {
        $en = trim((string)($col['label_en'] ?? ''));
        return $en !== '' ? $en : trim((string)($col['label'] ?? ''));
    }

    /**
     * 宿主 zh_CN/fields.php 里已登记的 key（不存在返回空数组）
     *
     * @return array<string, string>
     */
    protected function registeredTranslations(): array
    {
        $file = base_path() . '/resource/translations/zh_CN/fields.php';
        if (!is_file($file)) {
            return [];
        }
        try {
            $loaded = include $file;
            return is_array($loaded) ? $loaded : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 尚未在宿主 zh_CN/fields.php 里登记的字段标题：[英文 key => 中文注释]
     *
     * @return array<string, string>
     */
    protected function missingTranslations(array $columns): array
    {
        $existing = $this->registeredTranslations();

        $missing = [];
        foreach ($columns as $col) {
            $key = $this->labelKey($col);
            if ($key === '' || isset($existing[$key])) {
                continue;
            }
            $comment = trim((string)($col['comment'] ?? ''));
            if ($comment === '' || !preg_match('/[\x{4e00}-\x{9fff}]/u', $comment)) {
                continue;
            }
            $missing[$key] = $comment;
        }
        return $missing;
    }

    /**
     * enum 选项 → PHP 字面量 ['pending' => __('Pending'), ...]
     */
    protected function mapLiteral(array $options): string
    {
        $parts = [];
        foreach ($options as $value) {
            $key = is_numeric($value) ? $value : $this->phpString($value);
            $parts[] = $key . ' => __(' . $this->phpString(ucfirst((string)$value)) . ')';
        }
        return '[' . implode(', ', $parts) . ']';
    }

    /**
     * enum 选项 → 标签颜色 ['pending' => 'warning', ...]
     */
    protected function tagLiteral(array $options): string
    {
        $parts = [];
        foreach ($options as $i => $value) {
            $type = self::TAG_TYPES[$i % count(self::TAG_TYPES)];
            $key = is_numeric($value) ? $value : $this->phpString($value);
            $parts[] = $key . " => '{$type}'";
        }
        return '[' . implode(', ', $parts) . ']';
    }

    /**
     * PHP 单引号字符串字面量
     */
    protected function phpString(string $value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    }

    protected function indent(string $text, int $extra = 0): string
    {
        if ($extra <= 0) {
            return $text;
        }
        $pad = str_repeat(' ', $extra);
        return $pad . str_replace("\n", "\n" . $pad, $text);
    }

    // ============================================================
    // 控制器定位 / 改写
    // ============================================================

    /**
     * 根据 --table 或 --controller 解析出控制器文件路径
     *
     * - --controller 含反斜杠：当作 FQCN，按命名空间倒推文件；
     * - --controller 只有短名：按默认后台目录 app/controller/admin/api 拼；
     * - 都没有：由 --table 推导（模型名 + Controller，默认目录）。
     */
    protected function resolveControllerFile(string $table, string $controller): ?string
    {
        if ($controller !== '') {
            if (str_contains($controller, '\\')) {
                $rel = $this->namespaceToDir($controller);
                return base_path() . '/' . $rel . '.php';
            }
            return base_path() . '/app/controller/admin/api/' . $controller . '.php';
        }
        if ($table !== '') {
            $model = $this->tableToModelName($table);
            return base_path() . '/app/controller/admin/api/' . $model . 'Controller.php';
        }
        return null;
    }

    /**
     * 向已存在的控制器里注入「grid DSL 行」与「方法块」
     *
     * 设计（针对 make:curd 生成的控制器结构）：
     *  - grid DSL 行插在 grid() 里最后一个 `return $grid;` 之前；
     *  - 方法块插在类最后一个 `}`（类闭合）之前。
     *
     * 幂等：若目标方法已存在（正则匹配 `public function Xxx(`），整体跳过；
     * 若仅 DSL 行已存在，也跳过该行注入。
     *
     * @param string   $file       控制器文件绝对路径
     * @param string[] $dslLines   要插入 grid() 的 DSL 语句（不含行尾分号/换行）
     * @param string   $methodCode 要追加的类方法代码（含 PHP 缩进，无外层花括号外的多余 `}`）
     * @param string   $methodName 方法名（用于幂等判定）
     * @param bool     $force      已存在时是否覆盖（覆盖 = 重新写入，一般用于 --force 重做）
     *
     * @return array{ok:bool, reason:string, method_existed:bool, dsl_existed:bool}
     */
    protected function injectIntoController(
        string $file,
        array $dslLines,
        string $methodCode,
        string $methodName,
        bool $force
    ): array {
        if (!is_file($file)) {
            return ['ok' => false, 'reason' => '找不到控制器文件：' . $this->relative($file), 'method_existed' => false, 'dsl_existed' => false];
        }
        $code = file_get_contents($file);
        if ($code === false) {
            return ['ok' => false, 'reason' => '读取控制器失败', 'method_existed' => false, 'dsl_existed' => false];
        }

        $methodExisted = (bool)preg_match('/public\s+function\s+' . preg_quote($methodName, '/') . '\s*\(/', $code);
        if ($methodExisted && !$force) {
            return ['ok' => true, 'reason' => '方法 ' . $methodName . ' 已存在，跳过', 'method_existed' => true, 'dsl_existed' => true];
        }

        $dslExisted = false;
        foreach ($dslLines as $line) {
            if (str_contains($code, trim($line))) {
                $dslExisted = true;
                break;
            }
        }

        // --- 注入 grid DSL 行（插在最后一个 return $grid; 那一行的行首之前）---
        if (!$dslExisted) {
            $dslBlock = implode("\n", array_map(fn($l) => '        ' . $l, $dslLines)) . "\n\n";
            $pos = strrpos($code, 'return $grid;');
            if ($pos === false) {
                return ['ok' => false, 'reason' => '控制器里找不到 `return $grid;`（非标准结构？）', 'method_existed' => $methodExisted, 'dsl_existed' => $dslExisted];
            }
            // 退到该行行首（保留原缩进），否则插入后 return 会被顶到行首
            $lineStart = strrpos(substr($code, 0, $pos), "\n");
            $lineStart = $lineStart === false ? 0 : $lineStart + 1;
            $code = substr_replace($code, $dslBlock, $lineStart, 0);
        }

        // --- 注入方法块（插在类最后一个 } 之前）---
        if (!$methodExisted) {
            $closePos = strrpos($code, '}');
            if ($closePos === false) {
                return ['ok' => false, 'reason' => '控制器结构异常，找不到类闭合 }', 'method_existed' => $methodExisted, 'dsl_existed' => $dslExisted];
            }
            $code = substr_replace($code, "\n" . rtrim($methodCode) . "\n", $closePos, 0);

            // 方法签名用了 Request 时，确保顶部有 use support\Request;
            if (str_contains($methodCode, 'Request $request')) {
                $code = $this->ensureUseStatement($code, 'support\\Request');
            }
        }

        // 先备份，写坏立刻回滚
        $bak = $file . '.bak';
        if (!@copy($file, $bak)) {
            return ['ok' => false, 'reason' => '备份 ' . $this->relative($bak) . ' 失败（目录不可写？）', 'method_existed' => $methodExisted, 'dsl_existed' => $dslExisted];
        }
        if (file_put_contents($file, $code) === false) {
            @copy($bak, $file);
            return ['ok' => false, 'reason' => '写入失败，已回滚', 'method_existed' => $methodExisted, 'dsl_existed' => $dslExisted];
        }
        $lint = $this->lint($file);
        if ($lint !== true) {
            @copy($bak, $file);
            return ['ok' => false, 'reason' => '写入后语法检查失败，已回滚：' . $lint, 'method_existed' => $methodExisted, 'dsl_existed' => $dslExisted];
        }

        return ['ok' => true, 'reason' => '已更新 ' . $this->relative($file), 'method_existed' => $methodExisted, 'dsl_existed' => $dslExisted];
    }

    /**
     * 确保文件顶部有 `use {FQCN};`（没有才插，插在最后一个 use 之后）
     */
    protected function ensureUseStatement(string $code, string $fqcn): string
    {
        if (preg_match('/^use\s+' . preg_quote($fqcn, '/') . '\s*;/m', $code)) {
            return $code;
        }
        if (preg_match_all('/^use\s+[^;]+;\s*$/m', $code, $m, PREG_OFFSET_CAPTURE) && $m[0] !== []) {
            $last = end($m[0]);
            $pos  = $last[1] + strlen($last[0]);
            return substr_replace($code, "\nuse " . $fqcn . ";\n", $pos, 0);
        }
        if (preg_match('/^namespace\s+[^;]+;\s*$/m', $code, $m, PREG_OFFSET_CAPTURE) && $m[0] !== []) {
            $pos = $m[0][1] + strlen($m[0][0]);
            return substr_replace($code, "\n\nuse " . $fqcn . ";", $pos, 0);
        }
        return $code;
    }

    // ============================================================
    // 文件写入 / 路由注册
    // ============================================================

    /**
     * 路由注册写到哪里：config/admin-route.php（标准）→ 根 admin-route.php（旧）→ config/route.php
     */
    protected function routeRegisterTarget(): string
    {
        $configRoute = base_path() . '/config/admin-route.php';
        if (is_file($configRoute)) {
            return $configRoute;
        }
        $legacy = base_path() . '/admin-route.php';
        if (is_file($legacy)) {
            return $legacy;
        }
        return $configRoute; // 都没有：--register 时按模板创建它
    }

    /**
     * 按模板创建 config/admin-route.php（已存在则不动）
     */
    protected function createAdminRouteFile(string $file): void
       {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($file, $this->adminRouteTemplate());
    }

    /**
     * config/admin-route.php 模板
     */
    protected function adminRouteTemplate(): string
    {
        return <<<'PHP'
<?php
/**
 * 后台路由（webman-curd-admin）
 *
 * 分工：
 *   - 本文件（config/admin-route.php）：后台页面路由（RouteControllerRegistry）、后台专用 Route::group
 *   - config/route.php：项目自身的接口 / 前台路由
 *
 * ⚠️ 为什么这里是「return 数组 + 闭包」，而不是直接写 Route::group()：
 *   webman 启动时会把 config/ 下除 route.php 以外的 .php 当【配置】加载
 *   （Config::loadFromDir，早于路由初始化），此时 Route 还没初始化，
 *   顶层直接调 Route::group() 会报 “Call to a member function addGroup() on null”。
 *   所以本文件只负责 return，路由注册放进 register 闭包，由插件在路由加载阶段执行。
 *
 * 加载：plugin/curd/config/route.php 会取 config('admin-route.register') 并执行。
 *       宿主不需要在 config/route.php 里加任何一行。
 *       加载顺序在插件路由之前 → 这里注册的同名路由优先，插件的同路径路由会被跳过。
 *
 * 注意：路由在进程启动时注册，改完要 `php webman restart`（reload 不重新加载路由）。
 */

use plugin\curd\app\RouteControllerRegistry;
use Webman\Route;

return [
    'register' => function () {
        // 后台 CURD 页面：路由路径 => 控制器类（控制器继承 BaseCurdController）
        RouteControllerRegistry::registerMany([
            // '/my-test' => \app\controller\admin\api\MyTestController::class,
        ]);

        // 后台专用接口（需要鉴权）：直接写 Route::group 即可，插件路由不会重复注册
        // Route::group('/api/admin', function () {
        //     Route::get('/stats', [\app\controller\admin\api\AdminStatsController::class, 'index']);
        // })->middleware([
        //     \plugin\curd\app\middleware\CorsMiddleware::class,
        //     \plugin\curd\app\middleware\AuthCheck::class,
        //     \plugin\curd\app\middleware\PermissionCheck::class,
        //     \plugin\curd\app\middleware\ApiCrypto::class,
        // ]);
    },
];
PHP;
    }

    protected function writeFile(string $path, string $content): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, $content);
    }

    protected function relative(string $path): string
    {
        $base = base_path() . DIRECTORY_SEPARATOR;
        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }

    /**
     * 把注册行追加进宿主路由文件（项目根目录 admin-route.php 优先，其次 config/route.php）
     *
     * @return true|string true = 成功；string = 失败原因
     */
    protected function registerRoute(string $routeFile, string $registerLine)
    {
        $rel = $this->relative($routeFile);
        if (!is_file($routeFile)) {
            return "找不到 {$rel}";
        }
        $code = file_get_contents($routeFile);
        if ($code === false) {
            return "读取 {$rel} 失败";
        }
        if (str_contains($code, $registerLine)) {
            return true; // 已注册，幂等
        }

        if (preg_match('/(RouteControllerRegistry::registerMany\(\s*\[)(.*?)(\n[ \t]*)(\]\s*\);)/s', $code, $m)) {
            $body = rtrim($m[2]);
            $hasItems = trim($body) !== '';
            if ($hasItems) {
                $body = rtrim($body, ',') . ',';
            }
            $closeIndent = str_replace(["\r", "\n"], '', $m[3]);
            $itemIndent = $closeIndent . '    ';
            $replacement = $m[1] . $body . "\n" . $itemIndent . trim($registerLine) . "\n" . $closeIndent . $m[4];
            $newCode = str_replace($m[0], $replacement, $code);
        } else {
            $newCode = rtrim($code) . "\n\n"
                . "// 由 `php webman make:curd --register` 追加\n"
                . "\\plugin\\curd\\app\\RouteControllerRegistry::registerMany([\n"
                . $registerLine . "\n"
                . "]);\n";
        }

        if (!@copy($routeFile, $routeFile . '.bak')) {
            return "备份 {$rel}.bak 失败（目录不可写？）";
        }
        if (file_put_contents($routeFile, $newCode) === false) {
            @copy($routeFile . '.bak', $routeFile);
            return "写入 {$rel} 失败，已回滚";
        }

        $lint = $this->lint($routeFile);
        if ($lint !== true) {
            @copy($routeFile . '.bak', $routeFile);
            return '写入后语法检查失败，已回滚：' . $lint;
        }

        return true;
    }

    /**
     * php -l 语法检查
     *
     * @return true|string
     */
    protected function lint(string $file)
    {
        $php = PHP_BINARY ?: 'php';
        $output = [];
        $code = 0;
        @exec(escapeshellarg($php) . ' -l ' . escapeshellarg($file) . ' 2>&1', $output, $code);
        return $code === 0 ? true : implode(' ', $output);
    }

    protected function printRouteHint(OutputInterface $output, string $registerLine, string $routePath): void
    {
        $target = $this->relative($this->routeRegisterTarget());
        $isAdminRoute = str_ends_with($target, 'admin-route.php');
        $output->writeln('');
        $output->writeln('  <comment>路由注册（不要进 curd_configs 配置表）：</comment>');
        $output->writeln('    ' . ($isAdminRoute
            ? '写进 <info>' . $target . '</info>（后台路由独立文件，插件自动加载）'
            : '写进 <info>' . $target . '</info>；想和项目自身路由分开，可新建 config/admin-route.php（插件自动加载）'));
        $output->writeln('');
        $output->writeln('    use plugin\curd\app\RouteControllerRegistry;');
        $output->writeln('    RouteControllerRegistry::registerMany([');
        $output->writeln('    ' . $registerLine);
        $output->writeln('    ]);');
        $output->writeln('');
        $output->writeln("  <comment>下次想自动写入可加</comment> --register <comment>（优先写 config/admin-route.php，没有则新建；再退到 config/route.php。写前备份 .bak 并 php -l 自检）。路由路径：</comment>{$routePath}");
    }
}
