-- ============================================================
-- webman-crud · 业务库建表 + 种子示例模板
-- 用法：php plugin/crud/install.php --business-sql=plugin/crud/install-business.sql
-- 说明：
--   1. 文件为「项目专有」，不随 huafei/webman-crud 包分发，请自行维护。
--   2. 业务库连接名走 --business-connection（默认 config business_connection）。
--   3. 每个 DDL 块用 --SPLIT-- 分隔；全部 IF NOT EXISTS，可重复执行（幂等）。
--   4. 业务菜单 / 权限可一并在此 INSERT（见文末示例），实现新环境「一次安装即带菜单」。
-- ============================================================

-- --SPLIT-- 表示上一段 DDL 结束（不可写成纯注释）

CREATE TABLE IF NOT EXISTS `goods` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL DEFAULT '' COMMENT '商品名',
  `price` decimal(10,2) NOT NULL DEFAULT '0.00',
  `status` tinyint NOT NULL DEFAULT 1 COMMENT '1=上架 0=下架',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品表（示例）';
--SPLIT--
CREATE TABLE IF NOT EXISTS `orders` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `goods_id` bigint unsigned NOT NULL DEFAULT 0,
  `amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='订单表（示例）';
--SPLIT--

-- ============================================================
-- 业务菜单 + 权限种子（可选）
-- 仅当 menus 表为空时插入「业务模块」菜单，避免覆盖插件自带基础菜单。
-- 下面这段可直接追加进你的 install-business.sql（在下个 --SPLIT-- 之后）。
-- 注意：示例用 INSERT IGNORE，重复执行不会报错；父菜单请用真实 id 关联。
-- ============================================================
--
-- INSERT IGNORE INTO `menus` (`id`, `parent_id`, `title`, `icon`, `path`, `permission`, `sort`, `type`, `visible`, `created_at`, `updated_at`)
-- VALUES (10, 2, '商品管理', 'Goods', '/goods', 'biz.goods.manage', 10, 1, 1, NOW(), NOW());
--
-- --SPLIT--
--
-- -- 给「运营」角色授予商品管理权限（casbin：p 角色 资源 动作）
-- INSERT IGNORE INTO `casbin_rule` (`ptype`, `v0`, `v1`, `v2`, `v3`, `v4`, `v5`)
-- VALUES ('p', 'yunying', 'biz.goods.manage', '*', '', '', '');
