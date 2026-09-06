<?php
namespace plugin\crud\app\controller;

use plugin\crud\app\controller\base\BaseCrudController;

/**
 * 后台「测试管理」示例控制器（webman-curd-admin 开箱即用演示）
 *
 * 以标准的继承式 BaseCrudController 写法，对 my_test 表提供完整后台 CRUD：
 *   - 列表 / 搜索 / 新增 / 编辑 / 删除 / 批量删除 / 导出（全部由基类实现）
 *   - 页面入口：后台左侧菜单「测试管理」（menus 表 seedAll 写入，path=/my-test）
 *   - 配置接口：/api/crud/config?route_path=/my-test（RouteControllerRegistry 命中本类）
 *   - 数据接口：/api/crud/model/MyTest（route.php 已挂 AuthCheck + PermissionCheck 鉴权）
 *
 * 写法要点（新业务控制器照抄即可）：
 *   - model() 返回模型类 → table() 自动取表名（my_test）
 *   - columns() 列表列、search() 搜索项、formFields() 表单字段、rules() 校验规则
 *   - 需要业务逻辑时重写钩子：createdBefore/createdAfter/updateBefore/
 *     updateAfter/deleteBefore/deleteAfter/batchDeleteBefore/batchDeleteAfter
 *
 * 正式环境不需要本示例时，可整组删除：
 *   本控制器 + model/MyTest.php + bootstrap.php「内置示例」注册段
 *   + menus「测试管理」菜单（删行）+ my_test 表
 */
class MyTestController extends BaseCrudController
{
    /**
     * 关联模型类（供 ModelRegistry 反射关联，见 bootstrap.php 注册段）
     */
    protected string $modelClass = \plugin\crud\app\model\MyTest::class;

    /**
     * 页面标题
     */
    protected string $title = '测试管理';

    /**
     * 数据模型类（table() 自动取其表名 my_test）
     */
    protected function model(): string
    {
        return \plugin\crud\app\model\MyTest::class;
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
