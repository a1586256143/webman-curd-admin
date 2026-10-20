<?php
/**
 * 接口加解密配置（CURD 插件核心能力，读取路径 config('plugin.curd.crypto.*')）
 *
 * 方案：RSA(2048) + AES-256-CBC 混合加密（信封加密）
 *  - 客户端每次请求随机生成 32 字符 AES 密钥，用 RSA 公钥加密后随信封上送
 *  - 数据体用该 AES 密钥加密；服务端用 RSA 私钥解出 AES 密钥，再解数据体
 *  - 响应用同一把请求 AES 密钥回加密（信封无 RSA 段）
 * 信封格式：ENC1:<base64(rsa(aesKey))>.<base64(iv)>.<base64(cipher)>（响应无第一段）
 *
 * 归属：中间件类 plugin/curd/app/middleware/ApiCrypto.php、
 *       私钥 plugin/curd/config/keys/api_rsa_private.pem 与本文件同属插件；
 *       全局注册点在主 config/middleware.php 的 '@' 组（跨 app 与 plugin 生效）。
 *
 * 开关（默认【开启】，dev / prod 一致；显式关闭才不加密）：
 *   1. 宿主 config/curd-admin.php 的 'api_encrypt'（最高优先级，写 false 即整体关闭）
 *   2. 否则读 .env 的 API_ENCRYPT（不配 = true；写 false 关闭）
 *   关闭后中间件完全透传：明文请求照常，密文请求不再解密（前端 auto 模式会同步退回明文）。
 */

// 宿主配置优先；未配置（null）时回退 .env
$curdHostEncrypt = config('curd-admin.api_encrypt');
$curdEncryptEnabled = $curdHostEncrypt === null
    ? env('API_ENCRYPT', true)
    : $curdHostEncrypt;

return [
    'enabled' => (bool)$curdEncryptEnabled,

    // RSA 私钥文件（PKCS#8 PEM）
    'private_key_path' => __DIR__ . '/keys/api_rsa_private.pem',

    // RSA 公钥文件（安装时生成；缺失时由私钥现场推导，不影响使用）
    'public_key_path' => __DIR__ . '/keys/api_rsa_public.pem',

    // 是否允许 GET /api/config/crypto 把公钥下发给前端（握手）
    //  - true（默认）：前端产物不绑定宿主密钥，部署到任意新项目都能自洽
    //  - false：接口只回 enabled 状态、不回公钥，前端只能用它构建时内嵌的公钥
    // 公钥本身是公开信息（等价于 HTTPS 的证书），下发不降低安全性。
    'handshake' => env('API_CRYPTO_HANDSHAKE', true),
];
