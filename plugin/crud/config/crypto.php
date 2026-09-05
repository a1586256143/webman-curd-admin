<?php
/**
 * 接口加解密配置（CRUD 插件核心能力，读取路径 config('plugin.crud.crypto.*')）
 *
 * 方案：RSA(2048) + AES-256-CBC 混合加密（信封加密）
 *  - 客户端每次请求随机生成 32 字符 AES 密钥，用 RSA 公钥加密后随信封上送
 *  - 数据体用该 AES 密钥加密；服务端用 RSA 私钥解出 AES 密钥，再解数据体
 *  - 响应用同一把请求 AES 密钥回加密（信封无 RSA 段）
 * 信封格式：ENC1:<base64(rsa(aesKey))>.<base64(iv)>.<base64(cipher)>（响应无第一段）
 *
 * 归属：中间件类 plugin/crud/app/middleware/ApiCrypto.php、
 *       私钥 plugin/crud/config/keys/api_rsa_private.pem 与本文件同属插件；
 *       全局注册点在主 config/middleware.php 的 '@' 组（跨 app 与 plugin 生效）。
 *
 * 开关：.env 中 API_ENCRYPT=false 可整体关闭（前后端需保持一致）
 */
return [
    'enabled' => env('API_ENCRYPT', true),

    // RSA 私钥文件（PKCS#8 PEM，对应前端内嵌的公钥）
    'private_key_path' => __DIR__ . '/keys/api_rsa_private.pem',
];
