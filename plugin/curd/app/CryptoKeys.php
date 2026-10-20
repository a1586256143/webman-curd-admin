<?php
namespace plugin\curd\app;

/**
 * 接口加密密钥工具（CURD 插件）
 *
 * 职责：给「前端握手接口」提供当前宿主的 RSA 公钥与其指纹。
 *
 * 为什么需要它
 * ------------------------------------------------------------
 * 每个部署（宿主）在安装时生成【各自独立】的 RSA 密钥对。早期做法是把公钥在构建期
 * 内联进前端产物，导致「同一份产物只能配同一个宿主」：换项目部署 → 后端 key 重新生成
 * → 前端手里的公钥对不上 → 所有 /api 400「请求解密失败」。
 *
 * 现在改为运行时握手：前端启动时 GET /api/config/crypto 拿公钥，同一份产物可部署到
 * 任意新项目。公钥本身是公开信息（等价于 HTTPS 证书），下发不降低安全性。
 *
 * 公钥来源（按优先级）：
 *  1. config('plugin.curd.crypto.public_key_path') 指向的 api_rsa_public.pem
 *  2. 由私钥现场推导（openssl_pkey_get_details）——公钥文件被删/漏同步时也能工作
 */
class CryptoKeys
{
    /** 进程内缓存（避免每请求读盘 / 反复推导） */
    protected static ?string $publicPem = null;
    protected static ?string $fingerprint = null;

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
        $path = (string)config('plugin.curd.crypto.public_key_path', '');
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
        return (bool)config('plugin.curd.crypto.enabled', true);
    }

    /**
     * 是否允许下发公钥（握手）
     */
    public static function handshakeEnabled(): bool
    {
        return (bool)config('plugin.curd.crypto.handshake', true);
    }

    /**
     * 清空进程内缓存（换 key 后调用；CLI / 调试用）
     */
    public static function flush(): void
    {
        self::$publicPem = null;
        self::$fingerprint = null;
    }

    // ------------------------------------------------------------

    /**
     * 由私钥推导公钥 PEM
     */
    protected static function deriveFromPrivate(): string
    {
        $path = (string)config('plugin.curd.crypto.private_key_path', '');
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
