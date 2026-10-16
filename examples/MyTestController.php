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
 * ⚠️ 本页面**不要**进 curd_configs 配置表，只在宿主 config/route.php 注册路径：
 *    RouteControllerRegistry::registerMany(['/my-test' => MyTestController::class]);
 *    否则配置生成器保存的落库配置会盖住这里 grid() 的改动（改了不生效）。
 *
 * 本示例演示的能力：
 *   1) 模型与标题都「不用写两遍」：Grid 里 new 模型 → 框架自动回填 $modelClass；
 *      Grid 不传 title → 自动读本控制器的 $title（见下方 grid() 首行注释）。
 *   2) 字段标题统一 __()（trans 别名，domain=fields）：中文在宿主
 *      resource/translations/{locale}/fields.php 里按 key 增改，未命中时原样返回英文短语。
 *   3) 点式字段：$form->text('config.api_url') —— 把 JSON 里的固定键提升为独立表单控件。
 *      编辑时后端解 JSON 取 api_url 回显；保存时把散落的点式键合并回 config 列（JSON 存储），
 *      表单里没出现的键原样保留。列表列同样支持点式：$grid->column('config.api_url', __('Api url'))。
 *      **点式写法对任意字段方法都成立**（select / radio / switch / datetime / number / tags ...）。
 *   4) copy() 可复制列：->copy() 在单元格旁加复制按钮，复制的是 display()/map() 之后的显示值。
 *   5) select() 行内下拉编辑：等价 editable('select', ['options' => $options])，
 *      改完即存（走当前控制器 update 接口），字段自动进入可写字段白名单，不必再写进 form()。
 *   6) JSON 键值编辑器：$form->json('extra', __('Extra')) —— 用户自由增删键值对。
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
     * 页面标题
     *
     * grid() 里的 Grid 不传第二个参数时，框架取的就是本属性
     * （CurdListTrait: 'title' => $grid->title() ?: $this->title()）。
     * 想走翻译可改成方法形式：protected function title(): string { return __('My test'); }
     */
    protected string $title = '测试管理';

    private const STATUS_OPTIONS = [0 => '禁用', 1 => '启用'];
    private const PLATFORM_OPTIONS = [1 => '微信', 2 => '支付宝', 3 => '银联'];
    private const PLATFORM_LABELS = [1 => 'success', 2 => 'primary', 3 => 'warning'];

    protected function grid(): Grid
    {
        // ① 模型：直接 new 进 Grid —— 框架会自动把它回填到 $modelClass，无需再单独声明该属性；
        // ② 标题：不传第二个参数 —— 自动读本控制器的 $title（想覆盖才写 new Grid($model, '标题')）
        $grid = new Grid(new MyTest());
        $grid->getModel()->orderByDesc('id');

        // ===== 列表列 =====
        // 标题统一 __()：未命中翻译时原样返回 key，不会报错
        $grid->column('id', __('ID'))->width(80)->align('center');
        $grid->column('name', __('Name'));
        // 点式列 + copy()：单元格右侧出现复制按钮，复制的是显示值（含 display()/prefix()/suffix() 结果）
        $grid->column('config.api_url', __('Api url'))->copy();
        // 点式列 + 字典 + 颜色标签
        $grid->column('config.platform', __('Access platform'))
            ->map(self::PLATFORM_OPTIONS)->label(self::PLATFORM_LABELS)->width(100);
        // 行内下拉编辑：等价 editable('select', ['options' => $options])，改完即存
        $grid->column('status', __('Status'))->select(self::STATUS_OPTIONS)->width(120);
        $grid->column('created_at', __('Created at'))->datetime()->width(180);
        $grid->column('updated_at', __('Updated at'))->datetime()->hidden();

        // ===== 搜索 =====
        $grid->filter(function (Filter $filter) {
            $filter->like('name', __('Name'));
            $filter->select('status', __('Status'))->options(self::STATUS_OPTIONS);
        });

        // ===== 编辑弹窗 =====
        $grid->form(function (Form $form) {
            // 表单布局：标签宽度 + 内容区(右侧元素区域)最大宽度，按需调整
            $form->labelWidth(140)->contentWidth('480px');

            $form->text('name', __('Name'))->required();
            // 点式字段：读写 config 列（JSON）里的固定键，任意字段方法均可用
            $form->text('config.api_url', __('Api url'))->required()
                ->placeholder('https://api.example.com');
            $form->text('config.api_token', __('Api token'));
            $form->number('config.timeout', __('Timeout seconds'))->min(1)->max(600);
            // 点式 + 下拉：提交数字选项值，JSON 内存数字
            $form->select('config.platform', __('Access platform'))->options(self::PLATFORM_OPTIONS)->default(2);
            // 点式 + 开关：提交布尔值
            $form->switch('config.enabled', __('Enable switch'));
            // 点式 + 日期时间：提交 'Y-m-d H:i:s' 字符串
            $form->datetime('config.sync_at', __('Sync at'));
            // JSON 键值编辑器：整列自由增删，提交时序列化为 JSON 字符串
            $form->json('extra', __('Extra'))
                ->help('键值对形式保存到 extra 列（JSON），值按字符串存储');
            $form->textarea('remark', __('Remark'));
            $form->radio('status', __('Status'), self::STATUS_OPTIONS)->default(1);
        });

        return $grid;
    }
}
