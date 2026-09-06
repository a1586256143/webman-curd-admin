# 后台 CURD 示例（examples/）

给使用方看的示例文件：安装本包后，安装器会把下面两个文件**幂等落位**到宿主项目根目录
（已存在则跳过，可自由修改/删除）：

| 文件 | 落位目标 | 作用 |
|---|---|---|
| `MyTestController.php` | `{项目}/app/controller/admin/api/MyTestController.php` | 后台「测试管理」示例控制器（继承 BaseCurdController） |
| `MyTest.php` | `{项目}/app/model/MyTest.php` | 配套示例模型（my_test 表） |

## 示例能跑通依赖的链路

1. **表**：`my_test` 表由安装向导 / `plugin/curd/install.sql` 自动创建；
   安装成功后在 `menus` 自动种「测试管理」菜单（`path=/my-test`）。
2. **模型/控制器登记**：宿主根 `app/model` 与 `app/controller/admin/api`
   会被插件 `ModelRegistry` 启动时自动扫描（`scanModels()` / `scanControllers()`），
   继承 `BaseCurdController` 的控制器自动与同名模型关联——示例无需手动注册。
3. **路由注册（唯一需要手写的一步）**：在宿主 `config/route.php` 加一行（或加入
   `registerMany([...])` 数组）：

```php
RouteControllerRegistry::register('/my-test', \app\controller\admin\api\MyTestController::class);
```

## 写法要点（新业务控制器照抄）

- 控制器：继承 `BaseCurdController`（业务项目可再继承自己封装的基类，如
  `BaseAdminController`）；`$modelClass` 指向模型、`$title` 为页面标题；
  `columns()/search()/formFields()/rules()` 定义列表/搜索/表单/校验；
  业务钩子：`createdBefore/createdAfter/updateBefore/updateAfter/deleteBefore/deleteAfter/...`
- 模型：`$table` 声明表名即可；连接默认走插件基类（默认库），
  业务表在业务库时继承宿主 `app\model\BaseModel` 或显式 `protected $connection = 'mysql_business';`

## 删除示例

正式项目不需要时整组删除：

1. `app/controller/admin/api/MyTestController.php` + `app/model/MyTest.php`
2. `config/route.php` 中的 `/my-test` 注册行
3. `menus` 表「测试管理」菜单行
4. `my_test` 表
