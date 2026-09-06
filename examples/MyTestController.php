<?php

namespace app\controller\admin\api;

use plugin\curd\app\controller\base\BaseCurdController;

/**
 * 后台「测试管理」示例控制器（开箱演示：你的第一个 CURD 后台页面）
 *
 * 位置约定：示例放在宿主根 app/controller/admin/api 下（与业务控制器同级），
 * 由 ModelRegistry::scanControllers() 自动识别（继承 BaseCurdController 即登记），
 * 并自动与同名模型（app\model\MyTest）关联——无需手动注册。
 *
 * 以标准的继承式 BaseCurdController 写法，对 my_test 表提供完整后台 CURD：
 *   - 列表 / 搜索 / 新增 / 编辑 / 删除 / 批量删除 / 导出（全部由基类实现）
 *   - 页面入口：后台左侧菜单「测试管理」（menus 表 seedAll 写入，path=/my-test）
 *   - 配置接口：/api/curd/config?route_path=/my-test（RouteControllerRegistry 命中本类）
 *   - 数据接口：/api/curd/model/MyTest（route.php 已挂 AuthCheck + PermissionCheck 鉴权）
 *
 * 路由注册（宿主根 config/route.php）：把 /my-test 映射到本类即可，见该文件示例区。
 *
 * 写法要点（新业务控制器照抄即可）：
 *   - 若你的项目在 BaseCurdController 之上有自己封装的业务基类（如 BaseAdminController），
 *     直接继承它即可，本示例为通用性直接继承引擎基类
 *   - model() 返回模型类 → table() 自动取表名（my_test）
 *   - columns() 列表列、search() 搜索项、formFields() 表单字段、rules() 校验规则
 *   - 需要业务逻辑时重写钩子：createdBefore/createdAfter/updateBefore/
 *     updateAfter/deleteBefore/deleteAfter/batchDeleteBefore/batchDeleteAfter
 *
 * 正式环境不需要本示例时，可整组删除：
 *   本控制器 + app/model/MyTest.php + config/route.php 的 /my-test 注册行
 *   + menus「测试管理」菜单（删行）+ my_test 表
 */
class MyTestController extends BaseCurdController
{
    /**
     * 关联模型类（供 ModelRegistry 反射关联）
     */
    protected string $modelClass = \app\model\MyTest::class;

    /**
     * 页面标题
     */
    protected string $title = '测试管理';

    /**
     * 数据模型类（table() 自动取其表名 my_test）
     */
    protected function model(): string
    {
        return \app\model\MyTest::class;
    }

    /**
     * 列表列
     */
    protected function columns(): array
    {
        return [
            $this->col('id', 'ID', ['width' => 80, 'align' => 'center']),
            $this->col('name', '名称'),
            $this->col('remark', '备注'),
            $this->dictColumn('status', '状态', [1 => '启用', 0 => '禁用']),
            $this->col('created_at', '创建时间', ['width' => 170]),
            $this->col('updated_at', '更新时间', ['width' => 170]),
        ];
    }

    /**
     * 搜索项
     */
    protected function search(): array
    {
        return [
            $this->searchInput('name', '名称'),
            $this->searchSelect('status', '状态', [1 => '启用', 0 => '禁用']),
        ];
    }

    /**
     * 表单字段
     */
    protected function formFields(): array
    {
        return [
            $this->formInput('name', '名称', ['placeholder' => '请输入名称']),
            $this->formTextarea('remark', '备注', ['placeholder' => '请输入备注']),
            $this->formSelect('status', '状态', [1 => '启用', 0 => '禁用']),
        ];
    }

    /**
     * 校验规则
     */
    protected function rules(): array
    {
        return [
            'name' => 'required|max:100',
        ];
    }
}
