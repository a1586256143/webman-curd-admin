# migrations/ 使用说明

业务表结构演进机制，由 `plugin/crud/migrate.php` 驱动。

## 文件命名

```
V{版本号}__{描述}.sql
```

- 版本号：纯数字，按升序执行（V1 → V2 → V10）
- 示例：`V1__create_goods.sql`、`V2__add_goods_stock.sql`、`V10__index_orders.sql`

## 编写规则

- 一条文件内可含多条 DDL，用 `--SPLIT--` 单独成行分隔
- 已发布版本**只追加、不修改**；改结构请新建更高版本号的文件
- 优先用 `ALTER TABLE ... ADD COLUMN IF NOT EXISTS`（MySQL 8.0.30+）或先判断再改，保证可重复安全
- 不要删除/重命名已执行文件，否则会重复执行

## 执行

```bash
# 在 webman 宿主根目录
php plugin/crud/migrate.php                      # 跑全部未执行迁移
php plugin/crud/migrate.php --connection=mysql  # 指定连接
php plugin/crud/migrate.php --dry-run           # 预检
```

## 跟踪

已应用记录进 `{连接库}._crud_migrations`（version 唯一约束，幂等）。重跑只会执行新文件。
