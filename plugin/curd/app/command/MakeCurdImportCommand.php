<?php

namespace plugin\curd\app\command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 给已生成的 CURD 控制器追加「Excel 导入」按钮
 *
 * 与 make:curd 分工：导入是独立能力，不再用 --import-action 塞进生成命令。
 *
 * 用法：
 *   php webman make:curd-import -t hf_goods                     # 默认 import / 导入
 *   php webman make:curd-import -t hf_goods --name=import-goods:导入商品
 *   php webman make:curd-import -c HfGoodsController --name=import:导入
 *
 * 做了两件事（幂等：方法已存在则跳过）：
 *   1) 在 grid() 里插入 $grid->importAction(...) 声明（工具栏按钮 + 文件弹窗）；
 *   2) 在类末尾追加 public function actionImport(Request $request) 方法骨架（excelRows 逐行处理）。
 * 写入前备份 .bak，写入后 php -l 自检，失败自动回滚。
 */
#[AsCommand(
    name: 'make:curd-import',
    description: '给已生成的 CURD 控制器追加 Excel 导入按钮'
)]
class MakeCurdImportCommand extends CurdScaffoldCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('table', 't', InputOption::VALUE_REQUIRED, '表名，用于定位控制器（与 --controller 二选一）')
            ->addOption('controller', 'c', InputOption::VALUE_REQUIRED, '控制器类名（短名或 FQCN），优先于 --table')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, '导入操作名[:按钮标题]，默认 import:导入')
            ->addOption('force', 'f', InputOption::VALUE_NONE, '方法已存在时也重新写入（默认跳过）');

        $this->setHelp(<<<'HELP'
<info>给已生成的 CURD 控制器追加 Excel 导入</info>

  php webman make:curd-import -t hf_goods
  → grid() 里插入 \$grid->importAction('import', '导入');
  → 追加 public function actionImport(Request \$request)，内部 \$rows = \$this->excelRows('file');

<comment>Excel 约定：</comment>
  首行为表头，列名与字段标签一致；excelRows() 返回 [{表头 => 值}, ...]。

<comment>定位控制器：</comment>
  --table 由表名推导（默认目录 app/controller/admin/api）；控制器在别处时用 --controller 指定。
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $table      = trim((string)$input->getOption('table'));
        $controller = trim((string)$input->getOption('controller'));
        $rawName    = trim((string)$input->getOption('name')) ?: 'import:导入';
        $force      = (bool)$input->getOption('force');

        [$name, $label] = $this->parseNameLabel($rawName);
        $name  = $name ?: 'import';
        $label = $label ?: '导入';

        $file = $this->resolveControllerFile($table, $controller);
        if ($file === null) {
            $output->writeln('<error>请用 --table 或 --controller 指定目标控制器。</error>');
            return Command::FAILURE;
        }
        if (!is_file($file)) {
            $output->writeln('<error>找不到控制器：' . $this->relative($file) . '</error>');
            $output->writeln('<comment>先用 make:curd 生成页面，或用 --controller 指定正确的类名 / FQCN。</comment>');
            return Command::FAILURE;
        }

        $method = $this->toActionMethod($name);
        $dsl    = "\$grid->importAction({$this->phpString($name)}, {$this->phpString($label)});";
        $methodCode = $this->buildImportMethod($method, $name, $label);

        $result = $this->injectIntoController($file, [$dsl], $methodCode, $method, $force);

        $output->writeln('');
        if (!$result['ok']) {
            $output->writeln('<error>× ' . $result['reason'] . '</error>');
            return Command::FAILURE;
        }

        $output->writeln('<info>✓ ' . $result['reason'] . '</info>');
        $output->writeln('  Excel 导入  <info>' . $name . '</info> → ' . $label);
        $output->writeln('  方法：public function ' . $method . '(Request $request)');
        if ($result['method_existed'] && !$force) {
            $output->writeln('  <comment>方法已存在，未改动（要重写加 --force）</comment>');
        }
        $output->writeln('');
        $output->writeln('  <comment>下一步：</comment>');
        $output->writeln('    1) 打开控制器，在 ' . $method . '() 里补逐行落库逻辑');
        $output->writeln('    2) <comment>php webman restart</comment>（PHP 类改动需重启）');
        $output->writeln('    3) 后台页面刷新，工具栏出现「' . $label . '」按钮');
        $output->writeln('');

        return Command::SUCCESS;
    }

    /**
     * 生成 action{Name}() 方法骨架（Excel 逐行导入）
     */
    protected function buildImportMethod(string $method, string $name, string $label): string
    {
        $labelPhp = $this->phpString($label);

        return <<<PHP
    /**
     * Excel 导入：{$label}
     *
     * 前端按钮由 grid() 里的 ->importAction('{$name}', {$labelPhp}) 声明。
     * excelRows('file') 返回 [{表头 => 值}, ...]，首行为表头、列名与字段标签一致。
     */
    public function {$method}(Request \$request)
    {
        \$rows = \$this->excelRows('file');
        if (\$rows === []) {
            return json(['code' => 400, 'msg' => '没有可导入的数据（检查表头是否与字段标签一致）']);
        }

        \$ok = 0;
        \$fail = [];
        foreach (\$rows as \$i => \$row) {
            try {
                // TODO: 逐行落库（示例）
                // YourModel::create([
                //     'name' => \$row['名称'] ?? '',
                // ]);

                \$ok++;
            } catch (\\Throwable \$e) {
                \$fail[] = '第 ' . (\$i + 2) . ' 行：' . \$e->getMessage();
            }
        }

        return json([
            'code' => \$ok > 0 ? 200 : 400,
            'msg'  => '导入完成：成功 ' . \$ok . ' 条' . (\$fail ? '，失败 ' . count(\$fail) . ' 条' : ''),
            'data' => ['ok' => \$ok, 'fail' => \$fail],
        ]);
    }
PHP;
    }
}
