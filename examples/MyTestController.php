<?php

namespace app\controller\admin\api;

use app\model\MyTest;
use plugin\curd\app\controller\base\BaseCurdController;
use plugin\curd\app\dsl\Filter;
use plugin\curd\app\dsl\Form;
use plugin\curd\app\dsl\Grid;

/**
 * 后台「测试管理」示例控制器（开箱演示：你的第一个 CURD 后台页面）
 *
 * 位置约定：示例放在宿主根 app/controller/admin/api 下（与业务控制器同级），
 * 由 ModelRegistry::scanControllers() 自动识别（继承 BaseCurdController 即登记），
 * 并自动与同名模型（app\model\MyTest）关联——无需手动注册。
 *
 * 以 Grid DSL 链式写法对 my_test 表提供完整后台 CURD：
 *   - 列表 / 搜索 / 新增 / 编辑 / 删除 / 批量删除 / 导出（全部由基类实现）
 *   - 页面入口：后台左侧菜单「测试管理」（menus 表 seedAll 写入，path=/my-test）
 *   - 配置接口：/api/curd/config?route_path=/my-test（RouteControllerRegistry 命中本类）
 *   - 数据接口：/api/curd/model/MyTest（route.php 已挂 AuthCheck + PermissionCheck 鉴权）
 *
 * 本示例重点演示两个 JSON 列用法（my_test.config / my_test.extra 均为 text 列）：
 *   1) 点式字段：$form->text('config.api_url') —— 把 JSON 里的固定键提升为独立表单控件。
 *      编辑时后端解 JSON 取 api_url 回显；保存时把散落的点式键合并回 config 列（JSON 存储），
 *      表单里没出现的键原样保留。列表列同样支持点式：$grid->column('config.api_url', '接口地址')。
 *      **点式写法对任意字段方法都成立**（select / radio / switch / datetime / number / tags ...），
 *      前端按字面键提交与回显，后端统一合并/解码，与字段类型无关。
 *   2) JSON 键值编辑器：$form->json('extra', '扩展配置') —— 用户自由增删键值对，
 *      提交时序列化为 JSON 字符串存入 extra 列。
 *
 * 路由注册（宿主根 config/route.php）：把 /my-test 映射到本类即可，见该文件示例区。
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
    protected string $modelClass = MyTest::class;

    /**
     * 页面标题
     */
    protected string $title = '测试管理';

    private const STATUS_OPTIONS = [0 => '禁用', 1 => '启用'];
    private const STATUS_LABELS = [0 => 'danger', 1 => 'success'];
    private const PLATFORM_OPTIONS = [1 => '微信', 2 => '支付宝', 3 => '银联'];

    protected function grid(): Grid
    {
        $grid = new Grid(new MyTest(), $this->title);
        $grid->getModel()->orderByDesc('id');

        // ===== 列表列 =====
        $grid->column('id', 'ID')->width(80)->align('center');
        $grid->column('name', '名称');
        // 点式列：config 列存 JSON，直接展示其中的 api_url 键（后端解 JSON 后注入行数据）
        // 点式列同样支持 ->map()/->datetime() 等全部列方法
        $grid->column('config.api_url', '接口地址');
        $grid->column('config.platform', '接入平台')->map(self::PLATFORM_OPTIONS)->width(100);
        $grid->column('status', '状态')
            ->map(self::STATUS_OPTIONS)->label(self::STATUS_LABELS)->width(90);
        $grid->column('created_at', '创建时间')->datetime()->width(180);
        $grid->column('updated_at', '更新时间')->datetime()->hidden();

        // ===== 搜索 =====
        $grid->filter(function (Filter $filter) {
            $filter->like('name', '名称');
            $filter->select('status', '状态')->options(self::STATUS_OPTIONS);
        });

        // ===== 编辑弹窗 =====
        $grid->form(function (Form $form) {
            // 表单布局：标签宽度 + 内容区(右侧元素区域)最大宽度，按需调整
            $form->labelWidth(140)->contentWidth('480px');
            $form->text('name', '名称')->required();
            // 点式字段：读写 config 列（JSON）里的固定键，任意字段方法均可用
            $form->text('config.api_url', '接口地址')->required()
                ->placeholder('https://api.example.com');
            $form->text('config.api_token', '接口令牌');
            $form->number('config.timeout', '超时(秒)')->min(1)->max(600);
            // 点式 + 下拉：提交数字选项值，JSON 内存数字
            $form->select('config.platform', '接入平台')->options(self::PLATFORM_OPTIONS)->default(2);
            // 点式 + 开关：提交布尔值
            $form->switch('config.enabled', '启用开关');
            // 点式 + 日期时间：提交 'Y-m-d H:i:s' 字符串
            $form->datetime('config.sync_at', '同步时间');
            // JSON 键值编辑器：整列自由增删，提交时序列化为 JSON 字符串
            $form->json('extra', '扩展配置')
                ->help('键值对形式保存到 extra 列（JSON），值按字符串存储');
            $form->textarea('remark', '备注');
            $form->radio('status', '状态', self::STATUS_OPTIONS)->default(1);
        });

        return $grid;
    }
}
