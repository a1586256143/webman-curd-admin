<?php
namespace plugin\curd\app\middleware;

use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

/**
 * 接口加解密中间件（RSA + AES-256-CBC 混合信封加密）——CURD 插件核心能力
 *
 * 请求：前端把全部参数（GET query + POST body 合并）序列化为 JSON，
 *       用随机 AES 密钥加密，AES 密钥再用 RSA 公钥加密，整体上送：
 *         - POST/PUT/DELETE → body 信封 { "data": "ENC1:<rsa(aesKey)>.<iv>.<cipher>" }
 *         - GET            → header 信封 X-Encrypt-Data: "ENC1:<rsa(aesKey)>.<iv>.<cipher>"
 *                           （浏览器 XHR 规范会丢弃 GET 请求的 body，故 GET 走 header）
 *       本中间件解密后把参数注回 $request->data['get'] / data['post']，
 *       控制器照常用 $request->input() / post() / get() 读取，无感知。
 *
 * 响应：用请求所携带的同一把 AES 密钥把 JSON 响应体整体回加密，
 *       输出 { "data": "ENC1:<iv>.<cipher>" }（无 RSA 段，前端持钥可解）。
 *
 * 豁免（自动判断，无需配置）：
 *  - OPTIONS 预检、非 /api 路径
 *  - 请求体不是 ENC1 信封（明文请求照常透传，向后兼容）
 *  - 响应非 application/json（如 CSV 导出文件流、上传等）
 *  - 未携带加密请求（无 AES 密钥）时响应保持明文
 *  - GET /api/config/crypto（握手接口：前端还没有公钥，天然无信封 → 明文下发公钥）
 *
 * 密钥轮换友好：解密失败时回 X-Crypto-Key-Mismatch 头，前端据此重新握手并重试一次，
 * 所以「换宿主 / 重装生成新密钥对」不需要重新构建前端产物。
 *
 * 归属：本类 + plugin/curd/config/crypto.php + plugin/curd/config/keys/ 密钥文件
 *       共同构成插件的加解密能力，由主 config/middleware.php 的 '@' 全局中间件组注册
 *       （跨 app 与 plugin 生效）。
 *
 * 开关（默认【开启】，dev / prod 一致；显式关闭才不加密）：
 *   宿主 config/curd-admin.php 的 'api_encrypt'（最高优先级）
 *   → 否则 .env 的 API_ENCRYPT（不配 = true）
 *   见 plugin/curd/config/crypto.php；关闭后本中间件完全透传。
 *   前端发行产物是 auto 模式：握手拿到 enabled=false 会自动退回明文，无需重新打包。
 */
class ApiCrypto implements MiddlewareInterface
{
    /**
     * 信封前缀标记
     */
    const PREFIX = 'ENC1:';

    /**
     * GET 请求信封的 header 名（浏览器 XHR 会丢弃 GET body，密文走 header）
     */
    const GET_HEADER = 'x-encrypt-data';

    /**
     * 解密失败标记头（响应）：告知前端「你手里的公钥不对」
     * 前端收到后调用 resetCryptoKey() 重新握手（GET /api/config/crypto）并重试一次，
     * 使「换宿主 / 重装生成新密钥对」后无需重新构建前端产物。
     */
    const MISMATCH_HEADER = 'X-Crypto-Key-Mismatch';

    /**
     * 缓存的私钥 PEM 内容（进程内静态缓存，避免每请求读盘）
     */
    protected static ?string $privateKeyPem = null;

    public function process(Request $request, callable $handler): Response
    {
        // 预检与站内静态/页面路径不处理
        // 注意：$request->path() 返回带前导斜杠（如 /api/config/site），必须匹配 '/api' 前缀
        if ($request->method() === 'OPTIONS' || !str_starts_with($request->path(), '/api')) {
            return $handler($request);
        }

        // 加密总开关关闭：完全透传（前后端需同步关闭）
        if (!config('plugin.curd.crypto.enabled')) {
            return $handler($request);
        }

        $aesKey = null;

        // ---------- 1. 请求解密 ----------
        // 信封来源：POST 走 body {data:ENC1}；GET 走 header X-Encrypt-Data（XHR 丢弃 GET body）
        $envelope = $this->extractEnvelope($request);
        if ($envelope !== null) {
            try {
                [$plain, $aesKey] = $this->decryptRequestEnvelope(substr($envelope, strlen(self::PREFIX)));

                // 触发框架对 query / body 的懒解析（parseGet / parsePost），再整体覆盖
                $origGet = (array)$request->get();
                $origPost = $request->isPost() ? (array)$request->post() : [];
                unset($origPost['data']); // 防止信封字段残留

                // 解密参数优先级最高：覆盖同名明文参数
                $merged = array_merge($origGet, $origPost, $plain);

                // workerman Request 的 $data 是 protected，用反射注回
                self::injectRequestData($request, ['get' => $merged, 'post' => $merged]);
            } catch (\Throwable $e) {
                // 解密失败 = 前端手里的公钥与本机私钥不配对（换宿主 / 重装生成新 key /
                // 产物内嵌的是旧公钥）。打上标记头，前端据此重新握手拿公钥并自动重试一次。
                $resp = json(['code' => 400, 'msg' => '请求解密失败，请刷新页面重试']);
                return $resp->withHeader(self::MISMATCH_HEADER, '1');
            }
        }

        // 密钥挂到请求上，供响应回加密使用
        $request->apiCryptoKey = $aesKey;

        // ---------- 2. 放行业务处理 ----------
        $response = $handler($request);

        // ---------- 3. 响应回加密 ----------
        if ($aesKey !== null) {
            $contentType = (string)($response->getHeader('Content-Type') ?? '');
            if (stripos($contentType, 'application/json') !== false) {
                $body = $response->rawBody();
                $decoded = json_decode($body, true);
                // 只加密合法 JSON 对象/数组（防止对异常体二次包裹）
                if (is_array($decoded)) {
                    $encrypted = $this->encryptResponse(
                        json_encode($decoded, JSON_UNESCAPED_UNICODE),
                        $aesKey
                    );
                    $newBody = json_encode(['data' => self::PREFIX . $encrypted], JSON_UNESCAPED_UNICODE);
                    $response->withBody($newBody);
                    // 注意：不要手动设置 Content-Length！
                    // workerman Response::__toString 序列化时会无条件追加 Content-Length，
                    // 若 headers 里已存在（手动设过）会输出两条 CL → 浏览器判非法响应直接拒绝
                    // （Duplicate Content-Length / ERR_RESPONSE_HEADERS_MULTIPLE_CONTENT_LENGTH）。
                    // 明文 json 响应从不设 CL，全靠序列化基于最终 body 长度自动追加，此处同理。
                }
            }
        }

        return $response;
    }

    /**
     * 从请求中提取信封字符串（无信封返回 null）
     * 顺序：body {data:ENC1:...} → header X-Encrypt-Data
     */
    protected function extractEnvelope(Request $request): ?string
    {
        $raw = $request->rawBody();
        if ($raw !== '') {
            $body = json_decode($raw, true);
            if (is_array($body)
                && isset($body['data'])
                && is_string($body['data'])
                && strpos($body['data'], self::PREFIX) === 0) {
                return $body['data'];
            }
        }
        $header = $request->header(self::GET_HEADER, '');
        if (is_string($header) && strpos($header, self::PREFIX) === 0) {
            return $header;
        }
        return null;
    }

    /**
     * 解密请求信封
     * 格式：<base64(rsa(aesKey))>.<base64(iv)>.<base64(cipher)>
     *
     * @return array{0: array, 1: string} [解密后的参数数组, AES 密钥]
     * @throws \RuntimeException 解密失败时抛出
     */
    public function decryptRequestEnvelope(string $payload): array
    {
        $parts = explode('.', $payload);
        if (count($parts) !== 3) {
            throw new \RuntimeException('信封格式错误');
        }

        [$rsaB64, $ivB64, $cipherB64] = $parts;

        // 1. RSA 私钥解出 AES 密钥（jsencrypt 使用 PKCS#1 v1.5 填充）
        $rsaRaw = base64_decode($rsaB64, true);
        $aesKey = '';
        if ($rsaRaw === false || strlen($rsaRaw) !== 256
            || !openssl_private_decrypt($rsaRaw, $aesKey, $this->privateKeyPem(), OPENSSL_PKCS1_PADDING)) {
            throw new \RuntimeException('AES 密钥解密失败');
        }
        if ($aesKey === '' || !ctype_xdigit($aesKey)) {
            throw new \RuntimeException('AES 密钥非法');
        }

        // 2. AES-256-CBC 解出 JSON 参数
        $plain = $this->aesDecrypt($cipherB64, $ivB64, $aesKey);
        $data = json_decode($plain, true);
        if (!is_array($data)) {
            throw new \RuntimeException('参数 JSON 非法');
        }

        return [$data, $aesKey];
    }

    /**
     * 加密响应（复用请求 AES 密钥）
     * 返回格式：<base64(iv)>.<base64(cipher)>
     */
    public function encryptResponse(string $plain, string $aesKey): string
    {
        $iv = random_bytes(16);
        $cipher = openssl_encrypt($plain, 'aes-256-cbc', $aesKey, OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) {
            throw new \RuntimeException('响应加密失败');
        }
        return base64_encode($iv) . '.' . base64_encode($cipher);
    }

    /**
     * AES-256-CBC 解密
     */
    protected function aesDecrypt(string $cipherB64, string $ivB64, string $aesKey): string
    {
        $cipherRaw = base64_decode($cipherB64, true);
        $ivRaw = base64_decode($ivB64, true);
        if ($cipherRaw === false || $ivRaw === false || strlen($ivRaw) !== 16) {
            throw new \RuntimeException('密文格式错误');
        }
        $plain = openssl_decrypt($cipherRaw, 'aes-256-cbc', $aesKey, OPENSSL_RAW_DATA, $ivRaw);
        if ($plain === false) {
            throw new \RuntimeException('数据解密失败');
        }
        return $plain;
    }

    /**
     * 私钥 PEM（进程内缓存）
     */
    protected function privateKeyPem(): string
    {
        if (self::$privateKeyPem === null) {
            $path = config('plugin.curd.crypto.private_key_path');
            $pem = is_file($path) ? file_get_contents($path) : '';
            if ($pem === '' || !strpos($pem, 'PRIVATE KEY')) {
                throw new \RuntimeException('RSA 私钥不存在或无效: ' . $path);
            }
            self::$privateKeyPem = $pem;
        }
        return self::$privateKeyPem;
    }

    /**
     * 反射注入 workerman Request 的 protected $data
     * （必须在控制器读取 get/post 之前执行，注入后框架的懒解析不再触发）
     */
    protected static function injectRequestData(Request $request, array $data): void
    {
        static $prop = null;
        if ($prop === null) {
            $prop = new \ReflectionProperty(\Workerman\Protocols\Http\Request::class, 'data');
            $prop->setAccessible(true);
        }
        $current = $prop->getValue($request);
        $prop->setValue($request, array_merge($current, $data));
    }
}
