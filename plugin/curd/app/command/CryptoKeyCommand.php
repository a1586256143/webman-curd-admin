<?php
namespace plugin\curd\app\command;

use plugin\curd\app\CryptoKeys;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 接口加密密钥：生成 / 修复 / 查看
 *
 * 用法：
 *   php webman curd:crypto-key                 # 生成 RSA-2048 密钥对（已存在则跳过）
 *   php webman curd:crypto-key --force         # 强制重新生成一对（旧密文将无法解密）
 *   php webman curd:crypto-key --bits=4096
 *   php webman curd:crypto-key --show          # 只看状态与指纹
 *   php webman curd:crypto-key --print-public  # 额外打印公钥 PEM
 *
 * 正常安装不需要手动跑：install.php / Web 安装向导 / 升级（Install::update）都会自动生成。
 * 本命令用于密钥丢失、换机迁移、主动换钥。
 *
 * 与 plugin/curd/keygen.php 共用同一份实现（app/CryptoKeys.php），区别只是：
 * 本命令走 webman console（能读宿主配置），keygen.php 是脱离框架的独立脚本。
 */
#[AsCommand(
    name: 'curd:crypto-key',
    description: '生成/检查接口加密 RSA 密钥对（默认 2048 位，已存在则跳过；--force 重新生成）'
)]
class CryptoKeyCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('force', 'f', InputOption::VALUE_NONE, '强制重新生成一对（旧密文将无法解密）')
            ->addOption('bits', null, InputOption::VALUE_REQUIRED, 'RSA 位数，默认 2048（建议 2048 / 4096）', '2048')
            ->addOption('show', 's', InputOption::VALUE_NONE, '只显示当前状态与公钥指纹，不生成')
            ->addOption('print-public', null, InputOption::VALUE_NONE, '额外打印公钥 PEM（要内嵌进前端时用）');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $status = CryptoKeys::status();

        $output->writeln('<comment>webman-curd-admin · 接口加密密钥</comment>');
        $output->writeln(str_repeat('-', 64));
        $output->writeln('密钥目录 : ' . CryptoKeys::keysDir());
        $output->writeln('私钥     : ' . $status['private'] . ($status['private_exists'] ? '  [已存在]' : '  [缺失]'));
        $output->writeln('公钥     : ' . $status['public'] . ($status['public_exists'] ? '  [已存在]' : '  [缺失]'));
        $output->writeln('指纹     : ' . ($status['fingerprint'] !== '' ? $status['fingerprint'] : '（无）'));
        $output->writeln('加密开关 : ' . ($status['enabled'] ? '开启' : '关闭'));
        $output->writeln('公钥下发 : ' . ($status['handshake'] ? '允许（前端运行时握手）' : '禁止（前端只能用内嵌公钥）'));
        $output->writeln(str_repeat('-', 64));

        if ($input->getOption('show')) {
            if ($input->getOption('print-public') && $status['fingerprint'] !== '') {
                $output->writeln(CryptoKeys::publicKeyPem());
            }
            return $status['ok'] ? Command::SUCCESS : Command::FAILURE;
        }

        $bits = (int)$input->getOption('bits');
        $r = CryptoKeys::generate($bits, (bool)$input->getOption('force'));

        if (!$r['ok']) {
            $output->writeln('<error>[ERROR] ' . $r['message'] . '</error>');
            return Command::FAILURE;
        }

        if ($r['skipped']) {
            $output->writeln('<comment>[SKIP]  ' . $r['message'] . '</comment>');
            $output->writeln('        指纹 ' . $r['fingerprint']);
            return Command::SUCCESS;
        }

        $output->writeln(($r['repaired'] ? '<comment>[FIX]   ' : '<info>[OK]    ') . $r['message'] . ($r['repaired'] ? '</comment>' : '</info>'));
        $output->writeln('        私钥 ' . $r['private'] . '  (0600)');
        $output->writeln('        公钥 ' . $r['public'] . '  (0644)');
        $output->writeln('        指纹 ' . $r['fingerprint']);

        if ($input->getOption('print-public')) {
            $output->writeln(str_repeat('-', 64));
            $output->writeln(CryptoKeys::publicKeyPem());
        }

        $output->writeln(str_repeat('-', 64));
        $output->writeln('提示：');
        $output->writeln('  1) 私钥不要提交 git / 不要进发布包，务必单独备份；');
        $output->writeln('  2) 服务已在运行时请 <comment>php webman restart</comment>（私钥在进程内缓存）；');
        $output->writeln('  3) 前端无需重新打包：发行产物是 auto 模式，启动时自动握手拿新公钥。');

        return Command::SUCCESS;
    }
}
