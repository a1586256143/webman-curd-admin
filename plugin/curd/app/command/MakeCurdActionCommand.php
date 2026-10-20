<?php

namespace plugin\curd\app\command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 给已生成的 CURD 控制器追加「行级 / 批量操作」按钮
 *
 * 与 make:curd 分工：make:curd 只生成页面骨架；操作按钮按需用本命令追加，
 * 不再让一个命令堆 --action / --bat-action 一堆参数。
 *
 * 用法：
 *   php webman make:curd-action -t hf_goods --name=audit:审核          # 行级按钮
 *   php webman make:curd-action -t hf_goods --name=batch-on:批量上架 --batch  # 批量按钮
 *   php webman make:curd-action -c HfGoodsController --name=audit:审核     # 指定控制器
 *
 * 做了两件事（幂等：方法已存在则跳过）：
 *   1) 在 grid() 里插入 $grid->action(...) / $grid->batAction(...) 声明；
 *   2) 在类末尾追加 public function action{Name}(Request $request) 方法骨架。
 * 写入前备份 .bak，写入后 php -l 自检，失败自动回滚。
 */
#[AsCommand(
    name: 'make:curd-action',
    description: '给已生成的 CURD 控制器追加行级 / 批量操作按钮（--batch 为批量）'
)]
class MakeCurdActionCommand extends CurdScaffoldCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('table', 't', InputOption::VALUE_REQUIRED, '表名，用于定位控制器（与 --controller 二选一）')
            ->addOption('controller', 'c', InputOption::VALUE_REQUIRED, '控制器类名（短名或 FQCN），优先于 --table')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, '操作名[:按钮标题]，如 audit:审核（必填）')
            ->addOption('batch', null, InputOption::VALUE_NONE, '生成批量操作（勾选行后可用，请求带 ids）')
            ->addOption('force', 'f', InputOption::VALUE_NONE, '方法已存在时也重新写入（默认跳过）');

        $this->setHelp(<<<'HELP'
<info>给已生成的 CURD 控制器追加操作按钮</info>

<comment>行级按钮：</comment>
  php webman make:curd-action -t hf_goods --name=audit:审核
  → grid() 里插入 \$grid->action('audit', '审核')->icon('Edit')->confirm('...')->handler('audit');
  → 追加 public function actionAudit(Request \$request)

<comment>批量按钮：</comment>
  php webman make:curd-action -t hf_goods --name=batch-on:批量上架 --batch
  → \$grid->batAction('batch-on', '批量上架')->confirm('...')->handler('batch-on');
  → 追加 public function actionBatchOn(Request \$request)（请求带 ids）

<comment>定位控制器：</comment>
  --table 由表名推导（默认目录 app/controller/admin/api）；控制器在别处时用 --controller 指定。
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $table      = trim((string)$input->getOption('table'));
        $controller = trim((string)$input->getOption('controller'));
        $rawName    = trim((string)$input->getOption('name'));
        $batch      = (bool)$input->getOption('batch');
        $force      = (bool)$input->getOption('force');

        if ($rawName === '') {
            $output->writeln('<error>缺少 --name 参数。用法：php webman make:curd-action -t hf_goods --name=audit:审核</error>');
            return Command::FAILURE;
        }
        [$name, $label] = $this->parseNameLabel($rawName);
        if ($name === '') {
            $output->writeln('<error>--name 的操作名不能为空。</error>');
            return Command::FAILURE;
        }

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
        $dsl    = $batch
            ? "\$grid->batAction({$this->phpString($name)}, {$this->phpString($label)})->confirm({$this->phpString('确定执行「' . $label . '」吗？')})->handler({$this->phpString($name)});"
            : "\$grid->action({$this->phpString($name)}, {$this->phpString($label)})->icon('Edit')->confirm({$this->phpString('确定执行「' . $label . '」吗？')})->handler({$this->phpString($name)});";

        $methodCode = $this->buildActionMethod($method, $name, $label, $batch);

        $result = $this->injectIntoController($file, [$dsl], $methodCode, $method, $force);

        $output->writeln('');
        if (!$result['ok']) {
            $output->writeln('<error>× ' . $result['reason'] . '</error>');
            return Command::FAILURE;
        }

        $output->writeln('<info>✓ ' . $result['reason'] . '</info>');
        $output->writeln('  类型：' . ($batch ? '批量操作' : '行级操作') . '  <info>' . $name . '</info> → ' . $label);
        $output->writeln('  方法：public function ' . $method . '(Request $request)');
        if ($result['method_existed'] && !$force) {
            $output->writeln('  <comment>方法已存在，未改动（要重写加 --force）</comment>');
        }
        $output->writeln('');
        $output->writeln('  <comment>下一步：</comment>');
        $output->writeln('    1) 打开控制器，在 ' . $method . '() 里补业务逻辑');
        $output->writeln('    2) <comment>php webman restart</comment>（PHP 类改动需重启）');
        $output->writeln('    3) 后台页面刷新，按钮即出现（行级在操作列 / 批量在勾选后的工具栏）');
        $output->writeln('');

        return Command::SUCCESS;
    }

    /**
     * 生成 action{Name}() 方法骨架
     */
    protected function buildActionMethod(string $method, string $name, string $label, bool $batch): string
    {
        $labelPhp = $this->phpString($label);

        if ($batch) {
            return <<<PHP
    /**
     * 批量操作：{$label}
     *
     * 前端按钮由 grid() 里的 ->batAction('{$name}', {$labelPhp}) 声明，随请求携带 ids。
     * 可用：\$request->post('ids') 为勾选行主键数组。
     */
    public function {$method}(Request \$request)
    {
        \$ids = (array)\$request->post('ids', []);
        \$ids = array_values(array_filter(array_map('intval', \$ids)));
        if (\$ids === []) {
            return json(['code' => 400, 'msg' => '请先勾选数据']);
        }

        // TODO: 批量业务逻辑（示例：按 \$ids 批量改状态）
        // YourModel::whereIn('id', \$ids)->update(['status' => 1]);

        return json(['code' => 200, 'msg' => '已处理 ' . count(\$ids) . ' 条']);
    }
PHP;
        }

        return <<<PHP
    /**
     * 行级操作：{$label}
     *
     * 前端按钮由 grid() 里的 ->action('{$name}', {$labelPhp}) 声明，这里是后端处理入口。
     * 可用：\$request->post('id') 为当前行主键；弹窗表单字段同样走 post。
     */
    public function {$method}(Request \$request)
    {
        \$id = (int)\$request->post('id');
        if (\$id <= 0) {
            return json(['code' => 400, 'msg' => '缺少 id']);
        }

        // TODO: 业务逻辑（示例：按 id 改状态）
        // \$row = YourModel::find(\$id);
        // \$row->status = 1;
        // \$row->save();

        return json(['code' => 200, 'msg' => '操作成功']);
    }
PHP;
    }
}
