#!/usr/bin/env php
<?php
/**
 * 接口加密密钥生成器（独立脚本，不需要 webman / composer）
 *
 * 用法（在宿主项目根目录执行）：
 *   php plugin/curd/keygen.php                 # 生成 RSA-2048 密钥对（已存在则跳过）
 *   php plugin/curd/keygen.php --force         # 强制重新生成一对（旧密文将无法解密）
 *   php plugin/curd/keygen.php --bits=4096     # 指定位数（默认 2048，最低 2048）
 *   php plugin/curd/keygen.php --show          # 只看状态与指纹，不生成
 *   php plugin/curd/keygen.php --print-public  # 额外打印公钥 PEM（要内嵌进前端时用）
 *
 * 输出位置（与 plugin/curd/config/crypto.php 的默认值一致）：
 *   plugin/curd/config/keys/api_rsa_private.pem   0600  私钥（**永不外发**，勿提交 git）
 *   plugin/curd/config/keys/api_rsa_public.pem    0644  公钥（可公开，前端运行时握手读取）
 *
 * 说明：
 *  - 正常安装时 **不需要手动执行本脚本**：
 *      · `composer require/update amcolin/webman-curd-admin` 结束时会自动跑本脚本
 *        （src/Install.php 的 ensureCryptoKeys()，宿主 post-package-install 钩子触发）；
 *      · `php plugin/curd/install.php` / Web 安装向导也会自动生成（Install::ensureKeys()）。
 *  - 本脚本用于：密钥丢失、换机迁移、想换一对新密钥、或想单独看看指纹。
 *  - 生成/更换密钥后，**已在运行的 webman 进程需要 restart**（私钥在进程内缓存）。
 *  - 私钥丢了不影响登录，只影响「已加密的请求」；换密钥后前端会自动重新握手，
 *    不需要重新打包前端（发行产物是 auto 模式）。
 *  - composer 安装阶段想跳过生成：`CURD_SKIP_KEYGEN=1 composer require ...`。
 */

if (PHP_SAPI !== 'cli') {
    // 允许被 Web 直接访问会变成「一键换密钥」的后门，必须拦住
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

require_once __DIR__ . '/app/CryptoKeys.php';

use plugin\curd\app\CryptoKeys;

// ---------- 参数解析 ----------
$opts = ['force' => false, 'show' => false, 'print-public' => false, 'bits' => 2048, 'help' => false];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--force' || $arg === '-f') {
        $opts['force'] = true;
    } elseif ($arg === '--show' || $arg === '-s') {
        $opts['show'] = true;
    } elseif ($arg === '--print-public') {
        $opts['print-public'] = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        $opts['help'] = true;
    } elseif (preg_match('/^--bits=(\d+)$/', $arg, $m)) {
        $opts['bits'] = (int)$m[1];
    } else {
        fwrite(STDERR, "未知参数：{$arg}\n用 --help 查看用法\n");
        exit(1);
    }
}

if ($opts['help']) {
    echo <<<TXT
用法: php plugin/curd/keygen.php [选项]

  --force, -f       强制重新生成一对（旧密文将无法解密）
  --bits=N          RSA 位数，默认 2048（建议 2048 或 4096）
  --show, -s        只显示当前状态与公钥指纹，不生成
  --print-public    额外打印公钥 PEM
  --help, -h        显示本帮助

TXT;
    exit(0);
}

// ---------- 显示状态 ----------
$status = CryptoKeys::status();
$line = str_repeat('-', 64);

echo "webman-curd-admin · 接口加密密钥\n{$line}\n";
echo '密钥目录 : ' . CryptoKeys::keysDir() . "\n";
echo '私钥     : ' . $status['private'] . ($status['private_exists'] ? '  [已存在]' : '  [缺失]') . "\n";
echo '公钥     : ' . $status['public'] . ($status['public_exists'] ? '  [已存在]' : '  [缺失]') . "\n";
echo '指纹     : ' . ($status['fingerprint'] !== '' ? $status['fingerprint'] : '（无）') . "\n";
echo '加密开关 : ' . ($status['enabled'] ? '开启' : '关闭') . "\n";
echo '公钥下发 : ' . ($status['handshake'] ? '允许（前端运行时握手）' : '禁止（前端只能用内嵌公钥）') . "\n";
echo "{$line}\n";

if ($opts['show']) {
    if ($opts['print-public'] && $status['fingerprint'] !== '') {
        echo CryptoKeys::publicKeyPem() . "\n";
    }
    exit($status['ok'] ? 0 : 1);
}

// ---------- 生成 ----------
$r = CryptoKeys::generate($opts['bits'], $opts['force']);

if (!$r['ok']) {
    fwrite(STDERR, "[ERROR] " . $r['message'] . "\n");
    exit(1);
}

if ($r['skipped']) {
    echo "[SKIP]  " . $r['message'] . "\n";
    echo "        指纹 " . $r['fingerprint'] . "\n";
    exit(0);
}

echo ($r['repaired'] ? '[FIX]   ' : '[OK]    ') . $r['message'] . "\n";
echo '        私钥 ' . $r['private'] . "  (0600)\n";
echo '        公钥 ' . $r['public'] . "  (0644)\n";
echo '        指纹 ' . $r['fingerprint'] . "\n";

if ($opts['print-public']) {
    echo "{$line}\n" . CryptoKeys::publicKeyPem() . "\n";
}

echo "{$line}\n";
echo "提示：\n";
echo "  1) 私钥不要提交 git、不要放进发布包，务必单独备份（丢了只能重新生成一对）；\n";
echo "  2) 服务已在运行时请 restart（私钥在进程内缓存，热更不会重新读盘）；\n";
echo "  3) 前端无需重新打包：发行产物是 auto 模式，启动时自动握手拿新公钥。\n";

exit(0);
