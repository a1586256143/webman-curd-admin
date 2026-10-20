<?php
namespace plugin\curd\app;

/**
 * 接口加密密钥工具（CURD 插件）
 *
 * 两个职责：
 *  1) 给「前端握手接口」提供当前宿主的 RSA 公钥与其指纹；
 *  2) 生成 / 修复 / 检查密钥对（安装器、CLI 命令、独立脚本共用同一份实现）。
 *
 * 为什么需要「运行时下发公钥」
 * ------------------------------------------------------------
 * 每个部署（宿主）在安装时生成【各自独立】的 RSA 密钥对。早期做法是把公钥在构建期
 * 内联进前端产物，导致「同一份产物只能配同一个宿主」：换项目部署 → 后端 key 重新生成
 * → 前端手里的公钥对不上 → 所有 /api 400「请求解密失败」。
 *
 * 现在改为运行时握手：前端启动时 GET /api/config/crypto 拿公钥，同一份产物可部署到
 * 任意新项目。公钥本身是公开信息（等价于 HTTPS 证书），下发不降低安全性；私钥永不外发。
 *
 * 本类刻意**不依赖 webman**（只在 config() 存在时读配置，否则用插件默认路径），
 * 这样 plugin/curd/keygen.php 可以脱离框架直接跑：
 *     php plugin/curd/keygen.php
 */
class CryptoKeys
{
    /** 密钥文件名（与 config/crypto.php 的默认路径一致） */
    const PRIVATE_FILE = 'api_rsa_private.pem';
    const PUBLIC_FILE  = 'api_rsa_public.pem';

    /** 进程内缓存（避免每请求读盘 / 反复推导） */
    protected static ?string $publicPem = null;
    protected static ?string $fingerprint = null;

    // ============================================================
    // 路径
    // ============================================================

    /**
     * 密钥目录：优先 config('plugin.curd.crypto.private_key_path') 所在目录，
     * 否则插件默认 {plugin/curd}/config/keys
     */
    public static function keysDir(): string
    {
        $privatePath = (string)self::cfg('plugin.curd.crypto.private_key_path', '');
        if ($privatePath !== '') {
            return dirname($privatePath);
        }
        return dirname(__DIR__) . '/config/keys';
    }

    public static function privatePath(): string
    {
        $p = (string)self::cfg('plugin.curd.crypto.private_key_path', '');
        return $p !== '' ? $p : self::keysDir() . '/' . self::PRIVATE_FILE;
    }

    public static function publicPath(): string
    {
        $p = (string)self::cfg('plugin.curd.crypto.public_key_path', '');
        return $p !== '' ? $p : self::keysDir() . '/' . self::PUBLIC_FILE;
    }

    // ============================================================
    // 读取
    // ============================================================

    /**
     * 当前宿主公钥 PEM；拿不到返回空串
     */
    public static function publicKeyPem(): string
    {
        if (self::$publicPem !== null) {
            return self::$publicPem;
        }

        $pem = '';

        // 1. 优先读公钥文件
        $path = self::publicPath();
        if ($path !== '' && is_file($path)) {
            $raw = (string)@file_get_contents($path);
            if (strpos($raw, 'BEGIN PUBLIC KEY') !== false) {
                $pem = trim($raw);
            }
        }

        // 2. 回退：从私钥推导（公钥文件缺失时的兜底）
        if ($pem === '') {
            $pem = self::deriveFromPrivate();
        }

        return self::$publicPem = $pem;
    }

    /**
     * 公钥指纹：去掉 PEM 头尾与空白后取 sha256 前 16 位 hex。
     * 前端只用它做日志/排查（对不上说明产物里内嵌的旧公钥过期了）。
     */
    public static function fingerprint(): string
    {
        if (self::$fingerprint !== null) {
            return self::$fingerprint;
        }
        $body = self::normalize(self::publicKeyPem());
        return self::$fingerprint = $body === '' ? '' : substr(hash('sha256', $body), 0, 16);
    }

    /**
     * 后端加密总开关（config/plugin.curd/crypto.php enabled）
     */
    public static function enabled(): bool
    {
        return (bool)self::cfg('plugin.curd.crypto.enabled', true);
    }

    /**
     * 是否允许下发公钥（握手）
     */
    public static function handshakeEnabled(): bool
    {
        return (bool)self::cfg('plugin.curd.crypto.handshake', true);
    }

    /**
     * 清空进程内缓存（换 key 后调用；CLI / 调试用）
     */
    public static function flush(): void
    {
        self::$publicPem = null;
        self::$fingerprint = null;
    }

    // ============================================================
    // 生成 / 检查
    // ============================================================

    /**
     * 当前密钥状态（CLI 命令 / 排查用）
     *
     * @return array{private:string,private_exists:bool,public:string,public_exists:bool,
     *               fingerprint:string,enabled:bool,handshake:bool,ok:bool}
     */
    public static function status(): array
    {
        self::flush();
        $private = self::privatePath();
        $public  = self::publicPath();
        $fp = self::fingerprint();
        return [
            'private'        => $private,
            'private_exists' => is_file($private),
            'public'         => $public,
            'public_exists'  => is_file($public),
            'fingerprint'    => $fp,
            'enabled'        => self::enabled(),
            'handshake'      => self::handshakeEnabled(),
            'ok'             => $fp !== '',
        ];
    }

    /**
     * 生成（或修复）RSA 密钥对
     *
     * 幂等策略：
     *  - 两个文件都在且未 --force → 跳过（不破坏现有密文）
     *  - 只有私钥（公钥被删/漏同步）→ 只从私钥推导补出公钥，**不重新生成私钥**
     *  - 私钥缺失（公钥单独存在也没用）→ 重新生成一对
     *
     * @param int  $bits  RSA 位数（默认 2048；一般不要低于 2048）
     * @param bool $force 强制重新生成一对（旧密文将无法解密）
     * @return array{ok:bool,skipped:bool,repaired:bool,message:string,
     *               private:string,public:string,fingerprint:string}
     */
    public static function generate(int $bits = 2048, bool $force = false): array
    {
        $private = self::privatePath();
        $public  = self::publicPath();
        $dir     = dirname($private);

        $result = [
            'ok' => false, 'skipped' => false, 'repaired' => false, 'message' => '',
            'private' => $private, 'public' => $public, 'fingerprint' => '',
        ];

        if ($bits < 1024) {
            $result['message'] = "RSA 位数过小：{$bits}（请用 2048 或 4096）";
            return $result;
        }

        $hasPrivate = is_file($private);
        $hasPublic  = is_file($public);

        // 1. 已存在且不强制 → 跳过
        if ($hasPrivate && $hasPublic && !$force) {
            self::flush();
            $result['ok'] = true;
            $result['skipped'] = true;
            $result['fingerprint'] = self::fingerprint();
            $result['message'] = '密钥已存在，跳过生成（要重新生成请加 --force）';
            return $result;
        }

        if (!function_exists('openssl_pkey_new')) {
            $result['message'] = 'openssl 扩展不可用，无法生成 RSA 密钥';
            return $result;
        }

        // 2. 只有私钥 → 补公钥（不换私钥，现有部署继续可用）
        if ($hasPrivate && !$hasPublic && !$force) {
            $derived = self::deriveFromPrivate();
            if ($derived === '') {
                $result['message'] = '私钥存在但无法解析，请用 --force 重新生成一对';
                return $result;
            }
            if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
                $result['message'] = "无法创建目录：{$dir}";
                return $result;
            }
            if (@file_put_contents($public, $derived, LOCK_EX) === false) {
                $result['message'] = "写入失败：{$public}";
                return $result;
            }
            @chmod($public, 0644);
            self::flush();
            $result['ok'] = true;
            $result['repaired'] = true;
            $result['fingerprint'] = self::fingerprint();
            $result['message'] = '公钥缺失，已由私钥推导补出（私钥未变动）';
            return $result;
        }

        // 3. 正常生成一对
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            $result['message'] = "无法创建目录：{$dir}";
            return $result;
        }

        $res = @openssl_pkey_new([
            'private_key_bits' => $bits,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($res === false) {
            $result['message'] = 'openssl_pkey_new 失败（openssl 配置异常？）';
            return $result;
        }

        $privatePem = '';
        if (!@openssl_pkey_export($res, $privatePem)) {
            $result['message'] = '私钥导出失败';
            return $result;
        }
        $details = @openssl_pkey_get_details($res);
        $publicPem = is_array($details) ? (string)($details['key'] ?? '') : '';
        if (strpos($publicPem, 'BEGIN PUBLIC KEY') === false) {
            $result['message'] = '公钥导出失败';
            return $result;
        }

        if (@file_put_contents($private, $privatePem, LOCK_EX) === false) {
            $result['message'] = "写入失败：{$private}";
            return $result;
        }
        @chmod($private, 0600);

        if (@file_put_contents($public, $publicPem, LOCK_EX) === false) {
            $result['message'] = "写入失败：{$public}";
            return $result;
        }
        @chmod($public, 0644);

        self::flush();
        $result['ok'] = true;
        $result['fingerprint'] = self::fingerprint();
        $result['message'] = "RSA-{$bits} 密钥对已生成";
        return $result;
    }

    // ============================================================
    // 内部
    // ============================================================

    /**
     * 读配置（无 webman 环境时回退默认值，保证本类可独立运行）
     */
    protected static function cfg(string $key, $default = null)
    {
        return function_exists('config') ? config($key, $default) : $default;
    }

    /**
     * 由私钥推导公钥 PEM
     */
    protected static function deriveFromPrivate(): string
    {
        $path = self::privatePath();
        if ($path === '' || !is_file($path)) {
            return '';
        }
        $privatePem = (string)@file_get_contents($path);
        if ($privatePem === '' || strpos($privatePem, 'PRIVATE KEY') === false) {
            return '';
        }
        $res = @openssl_pkey_get_private($privatePem);
        if ($res === false) {
            return '';
        }
        $details = @openssl_pkey_get_details($res);
        $public = is_array($details) ? (string)($details['key'] ?? '') : '';
        return strpos($public, 'BEGIN PUBLIC KEY') !== false ? trim($public) : '';
    }

    /**
     * 归一化 PEM：去掉头尾标记与所有空白，只留 base64 体（指纹计算用）
     */
    protected static function normalize(string $pem): string
    {
        if ($pem === '') {
            return '';
        }
        return (string)preg_replace('/\s+/', '', preg_replace('/-----[^-]+-----/', '', $pem));
    }
}
