<?php
/**
 * mysql_business 连接参考片段（随包附带，仅供拷贝参考 —— 不是独立配置文件）。
 *
 * 用途：宿主已有自定义 config/database.php 但缺少 mysql_business 连接时，
 * 把下方 return 里的 'mysql_business' => [ ... ] 整块拷进宿主 config/database.php
 * 的 'connections' 数组中即可。
 *
 * 通常你不需要手动做：src/Install.php 检测到宿主 config/database.php 缺失或为
 * webman/database 占位模板时，会自动生成一份 env 驱动的完整配置（含 mysql 与
 * mysql_business）。【单库架构】：mysql_business 与 mysql 指向同一 DB_NAME
 * （认证库与业务库同一库），不存在独立的业务库名。
 *
 * 对应 .env 键：DB_HOST / DB_PORT / DB_NAME / DB_USER / DB_PASSWORD
 */
return [
    'mysql_business' => [
        'driver'    => 'mysql',
        'host'      => env('DB_HOST', '127.0.0.1'),
        'port'      => env('DB_PORT', '3306'),
        'database'  => env('DB_NAME', 'webman_crud'),
        'username'  => env('DB_USER', 'root'),
        'password'  => env('DB_PASSWORD', ''),
        'charset'   => 'utf8mb4',
        'collation' => 'utf8mb4_general_ci',
        'prefix'    => '',
        'strict'    => true,
        'engine'    => null,
    ],
];
