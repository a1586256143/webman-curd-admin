-- ============================================================
-- CURD 插件核心表结构（认证 + RBAC + 菜单 + CURD 配置）
--
-- 执行方式（推荐，幂等可重复执行）：
--   php plugin/curd/install.php
--   或调用 \plugin\curd\api\Install::install()
--
-- 种子数据（roles/menus/admin 账号/casbin 关联）由 Install.php 在
-- 对应表为空时插入，不在本文件 —— 便于 admin 密码用运行时 hash 生成。
--
-- 语句间用 --SPLIT-- 分隔（Install::runSqlFile 按此切分逐条执行）
-- ============================================================

CREATE TABLE IF NOT EXISTS `admin_users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(100) NOT NULL,
  `name` varchar(100) NOT NULL DEFAULT '',
  `avatar` varchar(255) NOT NULL DEFAULT '',
  `status` tinyint(4) NOT NULL DEFAULT '1' COMMENT '1启用 0禁用',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `admin_users_username_unique` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='后台管理员账号';
--SPLIT--

CREATE TABLE IF NOT EXISTS `admin_tokens` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `admin_user_id` int(11) NOT NULL,
  `token` varchar(64) NOT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `admin_tokens_token_unique` (`token`),
  KEY `admin_tokens_admin_user_id_index` (`admin_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='后台登录令牌(多会话)';
--SPLIT--

CREATE TABLE IF NOT EXISTS `roles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL COMMENT '角色名',
  `slug` varchar(50) NOT NULL COMMENT '角色标识 admin/manager',
  `description` varchar(200) NOT NULL DEFAULT '' COMMENT '角色描述',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `roles_slug_unique` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='角色';
--SPLIT--

CREATE TABLE IF NOT EXISTS `admin_role_user` (
  `admin_user_id` int(11) NOT NULL,
  `role_id` int(11) NOT NULL,
  PRIMARY KEY (`admin_user_id`,`role_id`),
  KEY `admin_role_user_admin_user_id_index` (`admin_user_id`),
  KEY `admin_role_user_role_id_index` (`role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='管理员-角色关联';
--SPLIT--

CREATE TABLE IF NOT EXISTS `role_permission` (
  `role_id` int(11) NOT NULL,
  `permission_id` int(11) NOT NULL,
  PRIMARY KEY (`role_id`,`permission_id`),
  KEY `role_permission_role_id_index` (`role_id`),
  KEY `role_permission_permission_id_index` (`permission_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='角色-权限关联(保留,实际鉴权走 casbin_rule)';
--SPLIT--

CREATE TABLE IF NOT EXISTS `casbin_rule` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ptype` varchar(20) NOT NULL COMMENT 'p策略/g角色继承',
  `v0` varchar(100) NOT NULL DEFAULT '',
  `v1` varchar(100) NOT NULL DEFAULT '',
  `v2` varchar(100) NOT NULL DEFAULT '',
  `v3` varchar(100) NOT NULL DEFAULT '',
  `v4` varchar(100) NOT NULL DEFAULT '',
  `v5` varchar(100) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `casbin_rule_ptype_v0_v1_index` (`ptype`,`v0`,`v1`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='casbin RBAC 策略';
--SPLIT--

CREATE TABLE IF NOT EXISTS `curd_configs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `table_name` varchar(100) NOT NULL COMMENT '数据表名',
  `title` varchar(100) NOT NULL COMMENT '页面标题',
  `page_name` varchar(100) NOT NULL COMMENT '页面名称',
  `route_path` varchar(255) DEFAULT NULL COMMENT '自定义路由路径',
  `config` json DEFAULT NULL COMMENT '配置内容（JSON）',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_table_name` (`table_name`),
  UNIQUE KEY `route_path` (`route_path`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='CURD页面配置表';
--SPLIT--

CREATE TABLE IF NOT EXISTS `menus` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `parent_id` int(11) NOT NULL DEFAULT '0' COMMENT '父级菜单ID，0为顶级',
  `title` varchar(50) NOT NULL COMMENT '菜单名称',
  `icon` varchar(50) DEFAULT '' COMMENT '图标名称（Element Plus icon name）',
  `path` varchar(200) DEFAULT '' COMMENT '路由路径',
  `component` varchar(200) DEFAULT '' COMMENT '前端组件路径（预留）',
  `permission` varchar(100) DEFAULT '' COMMENT '权限标识',
  `sort` int(11) NOT NULL DEFAULT '0' COMMENT '排序',
  `type` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1=菜单项，2=子菜单容器',
  `visible` tinyint(1) NOT NULL DEFAULT '1' COMMENT '是否显示',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_parent_id` (`parent_id`),
  KEY `idx_sort` (`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='菜单管理';
--SPLIT--

-- ============================================================
-- 后台示例表（MyTestController「测试管理」演示，随包开箱即用）
-- 数据：Install.php seedAll() 在表空时插 1 条示例
-- 菜单：seedAll() 幂等补「测试管理」（menus.path=/my-test）
-- 示例代码：宿主根 app/controller/admin/api/MyTestController.php + app/model/MyTest.php
--          + config/route.php 的 /my-test 注册行
-- 删除：去掉本段 SQL + 上述宿主根示例 + 菜单行即可
-- ============================================================
--SPLIT--

CREATE TABLE IF NOT EXISTS `my_test` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL DEFAULT '' COMMENT '名称',
  `remark` varchar(255) NOT NULL DEFAULT '' COMMENT '备注',
  `status` tinyint(4) NOT NULL DEFAULT '1' COMMENT '状态 1启用 0禁用',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='后台示例表（webman-curd-admin 测试管理演示，可删除）';
