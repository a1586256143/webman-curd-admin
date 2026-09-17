<?php

namespace app\controller\admin\api;

use plugin\curd\app\controller\base\BaseCurdController;
use support\Request;

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

    /**
     * 自定义操作（行级按钮）：confirm 二次确认 / form 弹窗表单 两种类型示例
     * inline(true) 直接显示在行内；不设则收进「更多」下拉
     */
    protected function actions(): array
    {
        return [
            [
                'name' => 'toggle',
                'label' => '启用/禁用',
                'icon' => 'Switch',
                'type' => 'warning',
                'actionType' => 'confirm',          // 二次确认后调接口
                'field' => 'id',
                'inline' => true,
                'confirm' => '确认切换该记录的启用状态吗？',
                'confirmType' => 'warning',
                'api' => '/api/curd/model/MyTest/action/toggle',
                'successMsg' => '状态已切换',
            ],
            [
                'name' => 'remark',
                'label' => '快捷备注',
                'icon' => 'EditPen',
                'type' => 'primary',
                'actionType' => 'form',             // 弹窗表单，确认后提交
                'field' => 'id',
                'inline' => true,
                'api' => '/api/curd/model/MyTest/action/remark',
                'dialogTitle' => '快捷备注',
                'dialogWidth' => '480px',
                'successMsg' => '备注已保存',
                'formFields' => [
                    ['prop' => 'remark', 'label' => '备注', 'type' => 'textarea', 'required' => true, 'span' => 24],
                ],
            ],
            [
                'name' => 'import',
                'label' => '导入',
                'icon' => 'Upload',
                'type' => 'success',
                'actionType' => 'form',             // excel 字段：本地选文件 → multipart 直传 → 服务端解析
                'global' => true,                   // 工具栏全局按钮
                'api' => '/api/curd/model/MyTest/action/import',
                'dialogTitle' => '导入数据',
                'dialogWidth' => '520px',
                'successMsg' => '导入完成',
                'formFields' => [
                    ['prop' => 'import_file', 'label' => '选择文件', 'type' => 'excel', 'required' => true, 'span' => 24],
                    ['prop' => 'skip_exists', 'label' => '跳过已存在', 'type' => 'switch', 'span' => 24],
                ],
            ],
        ];
    }

    /**
     * 行级 confirm 操作：切换启用状态（POST /api/curd/model/MyTest/action/toggle）
     */
    public function actionToggle(Request $request)
    {
        $row = \app\model\MyTest::find($request->post('id'));
        if (!$row) {
            return json(['code' => 404, 'msg' => '记录不存在']);
        }
        $row->status = $row->status == 1 ? 0 : 1;
        $row->save();
        return json(['code' => 200, 'msg' => 'success']);
    }

    /**
     * 行级 form 操作：保存快捷备注（POST /api/curd/model/MyTest/action/remark）
     */
    public function actionRemark(Request $request)
    {
        $remark = trim((string)$request->post('remark', ''));
        if ($remark === '') {
            return json(['code' => 400, 'msg' => '备注不能为空']);
        }
        $row = \app\model\MyTest::find($request->post('id'));
        if (!$row) {
            return json(['code' => 404, 'msg' => '记录不存在']);
        }
        $row->remark = $remark;
        $row->save();
        return json(['code' => 200, 'msg' => 'success']);
    }

    /**
     * 全局 form 操作：导入 Excel（POST /api/curd/model/MyTest/action/import）
     *
     * excel 字段由框架自动解析（multipart 直传，不走 /api/upload）：
     *   $this->excelRows('import_file')  → 首行表头的关联数组行集
     *   $this->excelInfo('import_file')  → ['count' => n, 'file_name' => 'xx.xlsx', ...]
     * （若用 Action 类写法，则 handle 内 $this->excelRows(...) 同名方法取用）
     */
    public function actionImport(Request $request)
    {
        $skipExists = (bool)$request->post('skip_exists');
        $rows = $this->excelRows('import_file');
        if (empty($rows)) {
            return json(['code' => 400, 'msg' => '请选择并上传有效的 xlsx/csv 文件（首行为表头）']);
        }

        $count = 0;
        foreach ($rows as $row) {
            $name = trim((string)($row['名称'] ?? ''));
            if ($name === '') {
                continue; // 名称必填，空行跳过
            }
            if ($skipExists && \app\model\MyTest::where('name', $name)->exists()) {
                continue;
            }
            $m = new \app\model\MyTest();
            $m->name = $name;
            $m->remark = trim((string)($row['备注'] ?? ''));
            $m->status = 1;
            $m->save();
            $count++;
        }

        $info = $this->excelInfo('import_file');
        return json(['code' => 200, 'msg' => '导入完成：解析 ' . $info['count'] . ' 行，入库 ' . $count . ' 条']);
    }
}
