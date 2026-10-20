<?php

namespace plugin\curd\app\command;

use plugin\curd\app\CurdDb;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/**
 * 快速创建后台 CURD 控制器（Grid 列表 + Form 表单，字段/控件类型全部来自数据库表结构）
 *
 * 用法：
 *   php webman make:curd --table=my_test
 *   php webman make:curd -t my_test --title=测试管理 --route=/my-test --register
 *
 * 生成物（均为「已存在则跳过」，加 --force 才覆盖）：
 *   1) 模型     {app/model}/{Model}.php          —— 继承宿主 app\model\BaseModel（缺失则继承插件基类）
 *   2) 控制器   {app/controller/admin/api}/{Model}Controller.php
 *               · 列表列   = 表字段全量，标题统一 __()（created_at → __('Created at')）
 *               · 搜索项   = 前若干 varchar 走 like，enum 走 select
 *               · 新增/编辑 = 按字段类型映射控件（text/textarea/number/select/switch/date/datetime/json）
 *               · 自动维护字段（id / created_at / updated_at / deleted_at）不进表单
 *               · **不声明 $modelClass**：Grid 里 new 模型，框架自动提取（见 BaseCurdController 注释）
 *               · **Grid 不传 title**：自动读控制器的 $title（$grid->title() ?: $this->title()）
 *
 * 路由：默认**不写**进 curd_configs 配置表，只在宿主 config/route.php 用
 *       RouteControllerRegistry::registerMany(['/xxx' => XxxController::class]) 注册 ——
 *       这样改控制器里的 grid() 立即生效，不会被落库配置覆盖。
 *       `--register` 可自动追加（写入前备份 .bak）。
 *
 * 单一职责：本命令只负责「页面骨架（模型 + 控制器）」。操作按钮与 Excel 导入已拆成独立命令，
 * 需要时再对已生成的控制器追加，不再用一个命令堆一堆参数：
 *   php webman make:curd-action  （行级 / 批量操作）
 *   php webman make:curd-import  （Excel 导入）
 *
 * 命令注册：webman console 启动时自动扫描 plugin/curd/app/command 目录
 *           （见 vendor/webman/console/src/webman 的 plugin command 扫描），无需手工登记。
 */
#[AsCommand(
    name: 'make:curd',
    description: '快速生成后台 CURD 控制器（Grid + Form，字段来自数据库表；标题走 __() 翻译）'
)]
class MakeCurdCommand extends CurdScaffoldCommand
{
    /** 表单里不出现的自动维护字段 */
    protected const SYSTEM_FIELDS = ['created_at', 'updated_at', 'deleted_at'];

    /** 隐藏列（列表默认不展示） */
    protected const HIDDEN_COLUMNS = ['updated_at', 'deleted_at'];

    /** enum 标签颜色轮转 */
    protected const TAG_TYPES = ['primary', 'success', 'warning', 'danger', 'info'];

    protected function configure(): void
    {
        $this
            ->addOption('table', 't', InputOption::VALUE_REQUIRED, '数据库表名（必填），如 my_test')
            ->addOption('name', 'c', InputOption::VALUE_REQUIRED, '控制器类名，默认由表名推导（如 MyTestController）')
            ->addOption('model', 'm', InputOption::VALUE_REQUIRED, '模型类名，默认由表名推导（如 MyTest）')
            ->addOption('route', 'r', InputOption::VALUE_REQUIRED, '前端路由路径，默认 /表名（下划线→中划线），如 /my-test')
            ->addOption('title', null, InputOption::VALUE_REQUIRED, '页面标题，默认取表注释（无注释则用字段名推导的英文短语）')
            ->addOption('controller-path', 'C', InputOption::VALUE_REQUIRED, '控制器目录，默认 app/controller/admin/api')
            ->addOption('model-path', 'M', InputOption::VALUE_REQUIRED, '模型目录，默认 app/model')
            ->addOption('connection', 'd', InputOption::VALUE_REQUIRED, '数据库连接名，默认取插件配置 business_connection')
            ->addOption('register', null, InputOption::VALUE_NONE, '自动把路由注册写进 config/admin-route.php（写入前备份 .bak）')
            ->addOption('no-model', null, InputOption::VALUE_NONE, '只生成控制器，不生成模型文件')
            ->addOption('force', 'f', InputOption::VALUE_NONE, '同名文件已存在时覆盖（默认跳过并提示）');

        $this->setHelp(<<<'HELP'
<info>快速创建后台 CURD 控制器</info>

字段标题统一走 __() 翻译（等价 trans(..., [], 'fields')），语言文件位于宿主
<comment>resource/translations/{locale}/fields.php</comment>（domain 固定 'fields'，插件安装时已内置一份常用字段默认翻译）。

<comment>命名推导规则（与 CurdController::tableNameToModelName 一致）：</comment>
  my_test      → 模型 MyTest      控制器 MyTestController   路由 /my-test
  a_packages   → 模型 APackage    控制器 APackageController 路由 /packages
  admin_users  → 模型 AdminUser   控制器 AdminUserController 路由 /admin-users

<comment>常用示例：</comment>
  php webman make:curd --table=my_test
  php webman make:curd -t my_test --title=测试管理 --register
  php webman make:curd -t hf_goods -C app/controller/api -m HfGoods -f

<comment>指定数据库连接（多库/连接名不是默认值时）：</comment>
  -d, --connection   连接名 = config/database.php 里 connections 的键，默认取插件配置 business_connection
  php webman make:curd -t request_sms -d mysql_business

<comment>顺带生成操作按钮 / Excel 导入（已拆成独立命令，按需再加）：</comment>
  php webman make:curd-action -t hf_goods --name=audit:审核            行级按钮 → 控制器 actionAudit()
  php webman make:curd-action -t hf_goods --name=batch-on:批量上架 --batch  批量按钮 → actionBatchOn()
  php webman make:curd-import -t hf_goods                               Excel 导入 → actionImport()

<comment>路由注册：</comment>
  --register  优先写 config/admin-route.php（后台路由独立文件，插件自动加载）；
              没有该文件时写 config/route.php。写前备份 .bak，写后 php -l 自检。
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // ---------- 1. 表名（缺失时交互询问） ----------
        $table = trim((string)$input->getOption('table'));
        if ($table === '' && $input->isInteractive()) {
            $question = new Question('请输入数据库表名（如 my_test）: ');
            $table = trim((string)$this->getHelper('question')->ask($input, $output, $question));
        }
        if ($table === '') {
            $output->writeln('<error>缺少 --table 参数。用法：php webman make:curd --table=my_test</error>');
            return Command::FAILURE;
        }

        $connection = trim((string)$input->getOption('connection')) ?: CurdDb::business();

        // ---------- 2. 读取表结构 ----------
        try {
            $columns = $this->readColumns($connection, $table);
        } catch (\Throwable $e) {
            $output->writeln('<error>读取表结构失败（连接 ' . $connection . '）：' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
        if ($columns === []) {
            $output->writeln("<error>表 `{$table}` 不存在或没有任何字段（连接：{$connection}）。</error>");
            $output->writeln('<comment>提示：--connection 可指定连接名，默认取插件配置 business_connection。</comment>');
            return Command::FAILURE;
        }

        // ---------- 3. 推导命名 ----------
        $modelName      = trim((string)$input->getOption('model')) ?: $this->tableToModelName($table);
        $controllerName = trim((string)$input->getOption('name')) ?: $modelName . 'Controller';
        $routePath      = trim((string)$input->getOption('route')) ?: $this->tableToRoute($table);
        if (!str_starts_with($routePath, '/')) {
            $routePath = '/' . $routePath;
        }
        // 页面标题：--title 优先；否则用表名推导的英文短语（与字段标题同一套 key 约定，
        // 表注释只作为翻译建议，不当 key）
        $titleOption   = trim((string)$input->getOption('title'));
        $title         = $titleOption !== '' ? $titleOption : $this->humanize($table);
        $titleComment  = $titleOption === '' ? $this->readTableComment($connection, $table) : '';

        $controllerDir = trim((string)$input->getOption('controller-path')) ?: 'app/controller/admin/api';
        $modelDir      = trim((string)$input->getOption('model-path')) ?: 'app/model';

        $controllerNamespace = $this->dirToNamespace($controllerDir);
        $modelNamespace      = $this->dirToNamespace($modelDir);
        $controllerFqcn      = $controllerNamespace . '\\' . $controllerName;
        $modelFqcn           = $modelNamespace . '\\' . $modelName;

        $force      = (bool)$input->getOption('force');
        $withModel  = !(bool)$input->getOption('no-model');
        $created    = [];
        $skipped    = [];

        // ---------- 4. 模型文件 ----------
        if ($withModel) {
            $modelFile = base_path() . '/' . trim($modelDir, '/') . '/' . $modelName . '.php';
            if (is_file($modelFile) && !$force) {
                $skipped[] = $this->relative($modelFile);
            } else {
                $this->writeFile($modelFile, $this->buildModelCode($modelNamespace, $modelName, $table, $columns));
                $created[] = $this->relative($modelFile);
            }
        }

        // ---------- 5. 控制器文件 ----------
        $controllerFile = base_path() . '/' . trim($controllerDir, '/') . '/' . $controllerName . '.php';
        if (is_file($controllerFile) && !$force) {
            $skipped[] = $this->relative($controllerFile);
        } else {
            $this->writeFile($controllerFile, $this->buildControllerCode(
                $controllerNamespace,
                $controllerName,
                $modelFqcn,
                $modelName,
                $table,
                $title,
                $routePath,
                $columns
            ));
            $created[] = $this->relative($controllerFile);
        }

        // ---------- 6. 输出 ----------
        $output->writeln('');
        $output->writeln("<info>表：</info>{$table}  <info>连接：</info>{$connection}  <info>字段数：</info>" . count($columns));
        $output->writeln("<info>模型：</info>{$modelFqcn}");
        $output->writeln("<info>控制器：</info>{$controllerFqcn}");
        $output->writeln("<info>路由路径：</info>{$routePath}");
        $output->writeln("<info>页面标题：</info>{$title}");
        $output->writeln('');

        foreach ($created as $f) {
            $output->writeln("  <info>✓ 已生成</info> {$f}");
        }
        foreach ($skipped as $f) {
            $output->writeln("  <comment>· 已存在，跳过</comment> {$f} <comment>（要覆盖请加 --force）</comment>");
        }

        // ---------- 7. 路由注册 ----------
        $registerLine = "    '" . $routePath . "' => \\" . $controllerFqcn . "::class,";
        // 目标优先级：config/admin-route.php（标准位置）→ 项目根 admin-route.php（旧位置）
        //            → config/route.php（都没有时的老行为）
        $registerFile = $this->routeRegisterTarget();
        if ($input->getOption('register')) {
            // 目标就是 config/admin-route.php 但它不存在时，按模板创建一个（含空 registerMany 区块）
            if (!is_file($registerFile) && $registerFile === base_path() . '/config/admin-route.php') {
                $this->createAdminRouteFile($registerFile);
            }
            $result = $this->registerRoute($registerFile, $registerLine);
            if ($result === true) {
                $output->writeln('');
                $output->writeln('  <info>✓ 已写入路由注册</info> ' . $this->relative($registerFile)
                    . ' <comment>（原文件已备份为 .bak，写入后已 php -l 自检）</comment>');
            } else {
                $output->writeln('');
                $output->writeln('  <error>× 自动写入失败：' . $result . '</error>');
                $this->printRouteHint($output, $registerLine, $routePath);
            }
        } else {
            $this->printRouteHint($output, $registerLine, $routePath);
        }

        // 字段标题统一 __()，key 是英文短语：列出还没登记中文翻译的（不补也能用，只是显示英文）
        $missing = $this->missingTranslations($columns);
        // 页面标题也是 __() 的 key，表注释同样只作为翻译建议
        if ($titleComment !== ''
            && preg_match('/[\x{4e00}-\x{9fff}]/u', $titleComment)
            && !isset($this->registeredTranslations()[$title])) {
            $missing = [$title => $titleComment] + $missing;
        }
        if ($missing) {
            $output->writeln('');
            $output->writeln('  <comment>字段标题统一走 __()，key 用字段名推导的英文短语（未命中时原样显示，不影响使用）。</comment>');
            $output->writeln('  <comment>要让界面显示中文，把下面几行加到 resource/translations/zh_CN/fields.php：</comment>');
            foreach (array_slice($missing, 0, 6, true) as $key => $zh) {
                $output->writeln('    ' . $this->phpString($key) . ' => ' . $this->phpString($zh) . ',');
            }
            if (count($missing) > 6) {
                $output->writeln('    <comment>… 共 ' . count($missing) . ' 项（zh_CN 里已有的不重复列出）</comment>');
            }
        }

        $output->writeln('');
        $output->writeln('  <comment>下一步：</comment>');
        $output->writeln("    1) 后台「菜单管理」新建菜单，路由填 <info>{$routePath}</info>（或写进 menus 表）");
        $output->writeln('    2) 打开页面确认列表/搜索/表单');
        $output->writeln('    3) 行内编辑 / 复制列等增强写法见生成的控制器注释');
        $output->writeln('');

        return Command::SUCCESS;
    }

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
    // 代码生成
    // ============================================================

    /**
     * 生成控制器代码
     */
    protected function buildControllerCode(
        string $namespace,
        string $className,
        string $modelFqcn,
        string $modelShortName,
        string $table,
        string $title,
        string $routePath,
        array $columns
    ): string {
        // 模型与控制器同命名空间时省掉 use 行
        // 同名冲突（-c 传了与模型相同的类名）时给模型起别名，否则 use 与 class 撞名会致命错误
        $modelAlias = $modelShortName === $className ? $modelShortName . 'Model' : $modelShortName;
        $sameNamespace = strpos($modelFqcn, '\\') === false
            || substr($modelFqcn, 0, strrpos($modelFqcn, '\\')) === $namespace;
        if ($sameNamespace) {
            $modelUse = '';
            $modelAlias = $modelShortName; // 同命名空间无需 import，直接用短名
        } else {
            $modelUse = 'use ' . $modelFqcn
                . ($modelAlias !== $modelShortName ? ' as ' . $modelAlias : '')
                . ";\n";
        }

        // ===== 列表列 =====
        $gridLines = [];
        foreach ($columns as $col) {
            $gridLines[] = '        ' . $this->gridLine($col);
        }

        // ===== 搜索项 =====
        $filterLines = $this->filterLines($columns);
        $filterBlock = $filterLines
            ? "        \$grid->filter(function (Filter \$filter) {\n"
                . implode("\n", array_map(fn($l) => '            ' . $l, $filterLines))
                . "\n        });\n\n"
            : "        // 无自动搜索项：需要时自行加，如 \$grid->filter(fn(Filter \$f) => \$f->like('name', __('Name')));\n\n";

        // ===== 表单字段 =====
        $formLines = [];
        foreach ($columns as $col) {
            $line = $this->formLine($col);
            if ($line !== null) {
                $formLines[] = '            ' . $line;
            }
        }
        $formBlock = $formLines
            ? implode("\n", $formLines)
            : "            // 表里没有可编辑字段（除主键与时间戳外）";

        $hasSoftDelete = false;
        foreach ($columns as $col) {
            if ($col['field'] === 'deleted_at') {
                $hasSoftDelete = true;
                break;
            }
        }
        $softDeleteHint = $hasSoftDelete
            ? "        // 表有 deleted_at 列：模型已 use SoftDeletes，删除走软删除\n"
            : '';

        $titleExpr = '__(' . $this->phpString($title) . ')';

        return <<<PHP
<?php

namespace {$namespace};

{$modelUse}use plugin\curd\app\controller\base\BaseCurdController;
use plugin\curd\app\dsl\Filter;
use plugin\curd\app\dsl\Form;
use plugin\curd\app\dsl\Grid;

/**
 * 后台「{$title}」管理（由 `php webman make:curd --table={$table}` 生成，可自由修改）
 *
 * 表：{$table}    路由：{$routePath}
 *
 * ⚠️ 本控制器【不要】进 curd_configs 配置表，只在宿主路由文件注册路径 ——
 *    推荐放 config/admin-route.php（插件自动加载，与 config/route.php 分开；
 *    文件内是 return ['register' => function () { ... }]，路由写在闭包里）；
 *    没有该文件时放 config/route.php 也一样。这样改下面的 grid() 立即生效，
 *    不会被配置生成器保存的落库配置覆盖：
 *
 *   use plugin\\curd\\app\\RouteControllerRegistry;
 *   RouteControllerRegistry::registerMany([
 *       '{$routePath}' => \\{$namespace}\\{$className}::class,
 *   ]);
 *
 * 字段标题统一用 __()（等价 trans(..., [], 'fields')）——key 是字段名推导的英文短语
 * （request_order → __('Request order')），中文显示在宿主
 * resource/translations/zh_CN/fields.php 里配：'Request order' => '请求订单号'。
 * 未命中时原样返回英文，不影响使用。
 *
 * 常用增强写法：
 *   ->copy()                                     可复制列（复制按钮，复制的是 display() 之后的值）
 *   ->select([1 => '安卓', 2 => 'iOS'])           行内下拉编辑（自动加入可写字段白名单）
 *   ->editable('input')                          行内文本编辑
 *   ->map([...])->label([...])                   字典映射 + 颜色标签
 */
class {$className} extends BaseCurdController
{
    /**
     * 页面标题
     *
     * 统一走 __()（等价 trans(..., [], 'fields')）：中文在宿主
     * resource/translations/{locale}/fields.php 里按 key 增改，未命中时原样返回。
     * Grid 不传 title，自动读本方法。
     */
    protected function title(): string
    {
        return {$titleExpr};
    }

    protected function grid(): Grid
    {
        // 模型类由框架自动从 Grid 提取回填 \$modelClass，无需再声明
        \$grid = new Grid(new {$modelAlias}());
        \$grid->getModel()->orderByDesc('id');

        // ===== 列表列 =====
{$this->indent(implode("\n", $gridLines), 0)}

{$filterBlock}        // ===== 新增 / 编辑表单 =====
{$softDeleteHint}        \$grid->form(function (Form \$form) {
{$formBlock}
        });
        return \$grid;
    }
}

PHP;
    }

    /**
     * 生成模型代码
     */
    protected function buildModelCode(string $namespace, string $className, string $table, array $columns): string
    {
        // 基类优先用宿主自带的 app\model\BaseModel（多库宿主通常在这里指定业务连接），
        // 没有则用插件内置版；同命名空间时省掉 use 行。
        $baseFqcn = is_file(base_path() . '/app/model/BaseModel.php')
            ? 'app\\model\\BaseModel'
            : 'plugin\\curd\\app\\model\\BaseModel';
        $baseShort = 'BaseModel';
        $useLine = ($namespace === substr($baseFqcn, 0, -strlen('\\' . $baseShort)))
            ? ''
            : "use {$baseFqcn};\n";

        $hasSoftDelete = false;
        $hasTimestamp = false;
        foreach ($columns as $col) {
            if ($col['field'] === 'deleted_at') {
                $hasSoftDelete = true;
            }
            if ($col['field'] === 'created_at' || $col['field'] === 'updated_at') {
                $hasTimestamp = true;
            }
        }

        $softDeleteUse = $hasSoftDelete ? "use Illuminate\\Database\\Eloquent\\SoftDeletes;\n" : '';
        $softDeleteTrait = $hasSoftDelete ? "    use SoftDeletes;\n" : '';
        $timestampLine = $hasTimestamp
            ? "    // 表有 created_at/updated_at：由 BaseCurdController 自动维护\n"
            : "    // 表无 created_at/updated_at 列，保持 false 避免 Eloquent 报错\n    // public \$timestamps = false;\n";

        $header = $useLine . $softDeleteUse;
        $header = $header === '' ? '' : $header . "\n";

        return <<<PHP
<?php

namespace {$namespace};

{$header}/**
 * {$className} 模型（由 `php webman make:curd --table={$table}` 生成）
 *
 * 表：{$table}
 * 约定：宿主 app/model 下的模型会被 ModelRegistry::scanModels() 启动时自动登记，
 *       /api/curd/model/{$className} 即可直接解析到本类（无需手动注册）。
 */
class {$className} extends {$baseShort}
{
{$softDeleteTrait}
    /**
     * 数据表名
     */
    protected \$table = '{$table}';

{$timestampLine}}
PHP;
    }

    /**
     * 单个列表列 → DSL 一行
     */
    protected function gridLine(array $col): string
    {
        $expr = '$grid->column(' . $this->phpString($col['field']) . ', ' . $this->labelExpr($col) . ')';

        if ($col['autoIncrement']) {
            $expr .= "->width(80)->align('center')";
        } elseif ($col['base'] === 'enum' && $col['enumOptions']) {
            $expr .= '->map(' . $this->mapLiteral($col['enumOptions']) . ')'
                . '->label(' . $this->tagLiteral($col['enumOptions']) . ')->width(100)';
        } elseif (in_array($col['base'], ['datetime', 'timestamp'], true)) {
            // created_at / updated_at 不强制 ->datetime()：按原值展示（需要格式化时自己加 display）
            if (!in_array($col['field'], ['created_at', 'updated_at'], true)) {
                $expr .= '->datetime()->width(180)';
            }
        } elseif ($col['base'] === 'date') {
            $expr .= '->date()->width(120)';
        } elseif ($col['isBoolean']) {
            // 布尔列默认只读；想行内切换改成 ->switch()（会自动加入可写字段白名单）
            $expr .= '->width(90)';
        }

        if (in_array($col['field'], self::HIDDEN_COLUMNS, true)) {
            $expr .= '->hidden()';
        }

        return $expr . ';';
    }

    /**
     * 单个表单字段 → DSL 一行；返回 null 表示该字段不进表单
     */
    protected function formLine(array $col): ?string
    {
        if ($col['autoIncrement'] || in_array($col['field'], self::SYSTEM_FIELDS, true)) {
            return null;
        }

        $prop  = $this->phpString($col['field']);
        $label = $this->labelExpr($col);
        $base  = $col['base'];

        if ($base === 'enum' && $col['enumOptions']) {
            $line = "\$form->select({$prop}, {$label})->options(" . $this->mapLiteral($col['enumOptions']) . ')';
        } elseif ($base === 'set' && $col['enumOptions']) {
            $line = "\$form->multipleSelect({$prop}, {$label})->options(" . $this->mapLiteral($col['enumOptions']) . ')';
        } elseif ($col['isBoolean']) {
            $line = "\$form->switch({$prop}, {$label})";
        } elseif ($base === 'json') {
            $line = "\$form->json({$prop}, {$label})";
        } elseif (in_array($base, ['text', 'tinytext', 'mediumtext', 'longtext'], true)) {
            $line = "\$form->textarea({$prop}, {$label})";
        } elseif (in_array($base, ['int', 'integer', 'bigint', 'smallint', 'mediumint', 'tinyint',
            'decimal', 'numeric', 'float', 'double', 'real', 'bit'], true)) {
            $line = "\$form->number({$prop}, {$label})";
        } elseif ($base === 'date') {
            $line = "\$form->date({$prop}, {$label})";
        } elseif (in_array($base, ['datetime', 'timestamp'], true)) {
            $line = "\$form->datetime({$prop}, {$label})";
        } else {
            $line = "\$form->text({$prop}, {$label})";
        }

        if (!$col['nullable'] && !$col['hasDefault']) {
            $line .= '->required()';
        }

        return $line . ';';
    }

    /**
     * 自动搜索项：前 3 个字符串列 like + 前 2 个 enum 列 select
     *
     * @return string[]
     */
    protected function filterLines(array $columns): array
    {
        $lines = [];
        $like = 0;
        $select = 0;
        foreach ($columns as $col) {
            if ($col['autoIncrement'] || in_array($col['field'], self::SYSTEM_FIELDS, true)) {
                continue;
            }
            if (in_array($col['base'], ['varchar', 'char'], true) && $like < 3) {
                $lines[] = '$filter->like(' . $this->phpString($col['field']) . ', ' . $this->labelExpr($col) . ');';
                $like++;
            } elseif ($col['base'] === 'enum' && $col['enumOptions'] && $select < 2) {
                $lines[] = '$filter->select(' . $this->phpString($col['field']) . ', ' . $this->labelExpr($col)
                    . ')->options(' . $this->mapLiteral($col['enumOptions']) . ');';
                $select++;
            }
        }
        return $lines;
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
        $text = trim((string)preg_replace('/\s+/', ' ', str_replace(['-', '_'], ' ', $name)));
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
     * 字段标题表达式：统一包 __()，key 用「字段名推导的英文短语」
     *
     * request_order → __('Request order')，而不是数据库里的中文注释。
     * 这样 key 与表无关、可复用，且和内置 fields.php 的 'Created at' => '创建时间' 约定一致：
     *   - 未命中翻译时 trans() 原样返回 key → 界面显示英文，但随时可补翻译；
     *   - 中文显示由宿主 resource/translations/zh_CN/fields.php 提供：
     *       'Request order' => '请求订单号',
     */
    protected function labelExpr(array $col): string
    {
        return '__(' . $this->phpString($this->labelKey($col)) . ')';
    }

    /**
     * 取字段的翻译 key（优先英文短语，老数据结构回退到 label）
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
     * 只用于生成后提示，不影响代码生成；没有中文注释的字段不提示（英文本身可读）。
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
            // 只有注释含中文时才值得提示（英文 key 不写翻译也能直接显示）
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
     *
     * ⚠️ 必须 return 数组（webman 会把 config/ 下的 .php 当配置加载），
     *    路由注册放进 'register' 闭包，由插件在路由加载阶段执行。
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
     * - 文件里已有 RouteControllerRegistry::registerMany([...]) → 往数组末尾插一行；
     * - 没有该区块（比如刚建的空 admin-route.php）→ 追加一段完整注册（用 FQCN，不依赖 use）。
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

        // 匹配 registerMany([...]) 区块：m[3] 是闭合 ] 前的换行+缩进，m[4] 是 ]);
        // 用它推导新行的缩进，避免插进去的行破坏原有排版（config/admin-route.php 里区块在闭包内，缩进更深）
        if (preg_match('/(RouteControllerRegistry::registerMany\(\s*\[)(.*?)(\n[ \t]*)(\]\s*\);)/s', $code, $m)) {
            $body = rtrim($m[2]);
            $hasItems = trim($body) !== '';
            if ($hasItems) {
                $body = rtrim($body, ',') . ',';
            }
            $closeIndent = str_replace(["\r", "\n"], '', $m[3]);  // 闭合 ] 那行的缩进（纯空格）
            $itemIndent = $closeIndent . '    ';                  // 数组元素比闭合 ] 多一级
            $replacement = $m[1] . $body . "\n" . $itemIndent . trim($registerLine) . "\n" . $closeIndent . $m[4];
            $newCode = str_replace($m[0], $replacement, $code);
        } else {
            // 没有现成区块：追加一段（FQCN 写法，避免 use 出现在文件中部）
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

        // 语法自检：写坏了立刻回滚，避免把宿主搞崩
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
