<?php
/**
 * mysql_business 连接模板（huafei/webman-crud 随包附带）
 *
 * 复制以下数组块，追加到宿主项目 config/database.php 的 `connections` 中。
 * 包默认 CRUD_BUSINESS_CONNECTION=mysql_business，业务表（install-business.sql /
 * migrate.php）会写到这个连接；认证/管理面 8 张表默认走 CRUD_ADMIN_CONNECTION=mysql。
 *
 * 要点：
 *   - 与认证库同实例时，host/port/username/password 复用，仅 database 用 DB_BUSINESS_NAME；
 *   - 此文件不参与自动加载，仅作拷贝参考；改完 config/database.php 即生效。
 */

'mysql_business' => [
    'driver'      => 'mysql',
    'host'        => env('DB_HOST', '127.0.0.1'),
    'port'        => env('DB_PORT', '3306'),
    'database'    => env('DB_BUSINESS_NAME', 'my_business'),
    'username'    => env('DB_USER', 'root'),
    'password'    => env('DB_PASSWORD', ''),
    'charset'     => 'utf8mb4',
    'collation'   => 'utf8mb4_general_ci',
    'prefix'      => '',
    'strict'      => true,
    'engine'      => null,
    'options'     => [
        \PDO::ATTR_EMULATE_PREPARES => false,
    ],
],
