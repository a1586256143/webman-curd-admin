<?php
namespace plugin\curd\app\command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 把 AI 开发技能发布到**宿主项目根**，让 AI 工具能扫到。
 *
 * 背景：本插件是 composer 包，安装后代码落在宿主 plugin/curd（以及 vendor 里的包体），
 * 而 AI 工具（WorkBuddy / Cursor / Codex …）只扫**工作区根目录**：
 *   - WorkBuddy 项目级技能：{项目根}/.workbuddy-ai/skills/{name}/SKILL.md
 *   - Codex / Cursor 等：{项目根}/AGENTS.md
 * 所以包里自带的技能默认「在 vendor 里、扫不到」。本命令把它复制到项目根，并维护 AGENTS.md 托管块。
 *
 * 用法：
 *   php webman curd:publish-skill            # 发布技能 + 写入/更新 AGENTS.md（幂等）
 *   php webman curd:publish-skill --force    # 覆盖宿主已存在的技能文件（升级后同步最新手册）
 *   php webman curd:publish-skill --no-agents# 只发布技能目录，不动 AGENTS.md
 *   php webman curd:publish-skill --dry-run  # 只看会做什么，不落盘
 */
#[AsCommand(
    name: 'curd:publish-skill',
    description: '把 AI 开发技能发布到宿主项目根（.workbuddy-ai/skills + AGENTS.md），让 AI 工具能扫到'
)]
class PublishSkillCommand extends Command
{
    protected const SKILL_NAME = 'webman-curd-admin-dev';
    protected const MARK_BEGIN = '<!-- webman-curd-admin:skill:begin';
    protected const MARK_END = '<!-- webman-curd-admin:skill:end -->';

    protected function configure(): void
    {
        $this
            ->addOption('force', 'f', InputOption::VALUE_NONE, '覆盖宿主已存在的技能文件（默认跳过）')
            ->addOption('no-agents', null, InputOption::VALUE_NONE, '只发布技能目录，不写/更新宿主根 AGENTS.md')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, '只打印将要做什么，不落盘');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $root = self::projectRoot();
        $source = dirname(__DIR__, 2) . '/skills/' . self::SKILL_NAME . '/SKILL.md';
        $targetDir = $root . '/.workbuddy-ai/skills/' . self::SKILL_NAME;
        $target = $targetDir . '/SKILL.md';
        $agents = $root . '/AGENTS.md';
        $dry = (bool)$input->getOption('dry-run');

        $output->writeln('<comment>webman-curd-admin · 发布 AI 开发技能</comment>');
        $output->writeln(str_repeat('-', 64));
        $output->writeln('宿主根   : ' . $root);
        $output->writeln('技能源   : ' . $source);
        $output->writeln('技能目标 : ' . $target);
        $output->writeln(str_repeat('-', 64));

        if (!is_file($source)) {
            $output->writeln('<error>[ERROR] 找不到技能源文件：' . $source . '</error>');
            $output->writeln('        当前插件副本过旧（不含 skills/），请先升级插件：composer update amcolin/webman-curd-admin');
            return Command::FAILURE;
        }

        // 1) 技能文件
        $exists = is_file($target);
        if ($exists && !$input->getOption('force')) {
            $output->writeln('<comment>[SKIP]  技能已存在（加 --force 覆盖）：' . $target . '</comment>');
        } else {
            if (!$dry) {
                if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
                    $output->writeln('<error>[ERROR] 无法创建目录：' . $targetDir . '</error>');
                    return Command::FAILURE;
                }
                if (!@copy($source, $target)) {
                    $output->writeln('<error>[ERROR] 复制失败：' . $source . ' -> ' . $target . '</error>');
                    return Command::FAILURE;
                }
            }
            $output->writeln('<info>[OK]    ' . ($exists ? '已覆盖技能' : '已发布技能') . '</info>');
        }

        // 2) AGENTS.md（托管块，保留用户其余内容）
        if (!$input->getOption('no-agents')) {
            $block = self::agentsBlock();
            if ($dry) {
                $output->writeln('<comment>[DRY]    将写入/更新 AGENTS.md 托管块：' . $agents . '</comment>');
            } else {
                $output->writeln('<info>[OK]    AGENTS.md ' . self::upsertAgents($agents, $block) . '</info>');
            }
        }

        $output->writeln(str_repeat('-', 64));
        $output->writeln('完成。用 AI 工具打开本项目根目录即可扫到技能：');
        $output->writeln('  - WorkBuddy 自动发现 .workbuddy-ai/skills/；');
        $output->writeln('  - Cursor / Codex / Claude Code 读根目录 AGENTS.md。');
        $output->writeln('重复执行幂等；升级插件后加 --force 再跑一次即可同步最新手册。');

        return Command::SUCCESS;
    }

    /**
     * 宿主项目根：优先 webman base_path()，其次当前工作目录，最后按包位置回退推导
     */
    protected static function projectRoot(): string
    {
        if (function_exists('base_path')) {
            return rtrim((string)base_path(), '/');
        }
        $cwd = getcwd();
        if ($cwd) {
            return rtrim($cwd, '/');
        }
        // 包位于 vendor/amcolin/webman-curd-admin/plugin/curd/app/command → 上 7 级到项目根
        return dirname(__DIR__, 7);
    }

    /**
     * 写进宿主 AGENTS.md 的托管块（首尾带标记，便于下次原地替换）
     */
    protected static function agentsBlock(): string
    {
        $rel = '.workbuddy-ai/skills/' . self::SKILL_NAME . '/SKILL.md';
        return self::MARK_BEGIN . '（auto-managed：由 curd:publish-skill 维护，手动改动会被覆盖） -->' . "\n"
            . "## webman-curd-admin 开发（AI 技能）\n\n"
            . "本项目的 AI 开发手册在 `{$rel}`，先读它再动手。核心约定：\n\n"
            . "- 命令在**宿主根目录**跑：`make:curd` / `make:curd-action` / `make:curd-import` / `curd:crypto-key`\n"
            . "- `--name` 没有短写；改 PHP 类 / 路由 / config 后 `php webman restart`\n"
            . "- 插件源码在 `plugin/curd/`；密钥在 `plugin/curd/config/keys/`（勿提交 git）\n"
            . "- 升级插件后重跑 `php webman curd:publish-skill --force` 同步最新手册\n\n"
            . self::MARK_END;
    }

    /**
     * 写入/替换 AGENTS.md 里的托管块（保留文件其余内容）
     * @return string 动作描述（已新建 / 已追加托管块 / 已更新托管块）
     */
    protected static function upsertAgents(string $file, string $block): string
    {
        if (!is_file($file)) {
            $dir = dirname($file);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            @file_put_contents($file, "# AGENTS.md\n\n" . $block . "\n");
            return '已新建';
        }
        $content = (string)@file_get_contents($file);
        $b = strpos($content, self::MARK_BEGIN);
        $e = strpos($content, self::MARK_END);
        if ($b !== false && $e !== false && $e > $b) {
            $content = substr($content, 0, $b) . $block . substr($content, $e + strlen(self::MARK_END));
            @file_put_contents($file, $content);
            return '已更新托管块';
        }
        @file_put_contents($file, rtrim($content) . "\n\n" . $block . "\n");
        return '已追加托管块';
    }
}
