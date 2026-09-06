<?php
/**
 * CURD 配置注册引导程序
 *
 * 使用约定：
 *   1. 路由正常手动注册（RouteControllerRegistry::register），新增页面加一行即可
 *   2. 模型文件建在 app/model/ 下，ModelRegistry::scanModels() 启动时自动扫描登记，
 *      无需逐个手动注册 —— 这就是"扫描 model"：把目录里的模型类自动登记到注册表，
 *      这样 URL 传模型名（如 APackage）时能直接解析到模型类、拿到表名、走 ORM。
 *      （即使不扫描，ModelRegistry::resolve() 也有 PSR-4 动态发现 + 表名反推兜底）
 *   3. URL 同时兼容模型名（APackage）与表名（a_packages）两种写法
 *
 * 使用：/api/curd/model/APackage、/api/options/model/AdminUser
 */

use plugin\curd\app\ModelRegistry;
use plugin\curd\app\RouteControllerRegistry;

// ============================================================
// 模型/控制器扫描路径约定（随 config/plugin/curd/curd.php 可调）
// 宿主开发期默认指向宿主 app/model 与 app/controller/*/api，
// 新项目可改配置指向自己的模型目录；未配置则沿用上述默认。
// ============================================================
ModelRegistry::setModelPath(
    (string)config('plugin.curd.curd.model_dir', base_path() . '/app/model'),
    (string)config('plugin.curd.curd.model_namespace', 'app\model')
);

// 注册路由 → 控制器（继承 BaseCurdController 的控制器，前端 /xxx 动态拿配置）
// 后台示例「测试管理」(MyTest) 已按宿主业务控制器姿势放在宿主根：
//   app/controller/admin/api/MyTestController.php + app/model/MyTest.php，
//   路由注册在宿主根 config/route.php（RouteControllerRegistry::registerMany），
//   模型/控制器关联由下方 scanModels()/scanControllers() 自动完成。

// ============================================================
// ModelRegistry：扫描 app/model 目录，把模型类自动登记到注册表
// 模型文件建好即自动生效，无需手动注册
// ============================================================
ModelRegistry::scanModels();

// 扫描 app/controller/api 目录，把继承 BaseCurdController 的业务控制器
// 登记为模型关联（如 PackageController ↔ APackage）。
// 通配路由 /api/curd/model/{model} 命中该关联时直接复用业务控制器实例，
// 其 grid 配置与业务钩子（createdAfter 等）天然生效。
ModelRegistry::scanControllers();

// ============================================================
// 动态解析白名单（安全收紧）：
// 未在 app/model 下建模型文件的表，只能通过「模型名反推」访问的前提是
// 该表已在 curd_configs 登记过 CURD 配置（配置生成器保存后即可访问）。
// 启动时把已登记的表名加载进白名单，杜绝猜模型名访问任意表。
// 若 DB 不可用（如初始化阶段），白名单为空 → 动态解析全部关闭，属预期行为。
// ============================================================
try {
    ModelRegistry::allowDynamicTables(
        \plugin\curd\app\model\CurdConfigs::on(\plugin\curd\app\CurdDb::admin())
            ->pluck('table_name')->all()
    );
} catch (\Throwable $e) {
    // 忽略：DB 不可用时不加载白名单，动态解析保持关闭
}

// 后续新增模型：文件放进 app/model/ 即自动登记
// 后续新增页面：控制器建好后，上面加一行 RouteControllerRegistry::register 即可
