<?php
namespace plugin\curd\app\controller\base;

use plugin\curd\app\controller\base\concerns\CurdActionsTrait;
use plugin\curd\app\controller\base\concerns\CurdConfigTrait;
use plugin\curd\app\controller\base\concerns\CurdListTrait;
use plugin\curd\app\controller\base\concerns\CurdQueryTrait;
use support\Request;

/**
 * 通用 CURD 控制器基类
 *
 * 子类只需继承并实现少量配置方法，即可获得完整 CURD 页面 + 接口：
 *
 *   class PackageController extends BaseCurdController
 *   {
 *       protected string $table = 'a_packages';
 *       protected string $title = '包管理';
 *
 *       protected function columns(): array
 *       {
 *           return [
 *               $this->col('id', 'ID', ['width' => 80]),
 *               $this->col('title', '标题'),
 *           ];
 *       }
 *
 *       // 事件钩子（可选，做业务逻辑）
 *       protected function createdAfter(int $id, array $data): void { ... }
 *       protected function updateBefore(int $id, array &$data): void { ... }
 *       protected function deleteAfter(int $id): void { ... }
 *   }
 *
 * 功能：index/add/update/delete/batchDelete/export + 软删除 + 自动时间戳 + 表单验证
 *
 * 内部实现按职责拆分为 4 个 concern trait（见 ./concerns/）：
 *   - CurdConfigTrait  : 配置定义 + Grid DSL 助手 + 事件钩子
 *   - CurdQueryTrait   : 表字段元信息、搜索匹配推断、软删除、时间戳/创建人填充
 *   - CurdListTrait    : 列表查询 + 前端配置输出 + display/href 后处理
 *   - CurdActionsTrait : 校验、增删改、批量删除、CSV 导出
 */
abstract class BaseCurdController
{
    use CurdConfigTrait;
    use CurdQueryTrait;
    use CurdListTrait;
    use CurdActionsTrait;

    /**
     * 数据模型类声明（可选）
     *
     * 【推荐写法】不再声明本属性：在 grid() 里直接 new 模型实例，
     * 框架构建 Grid 时自动提取模型类回填到这里（resolveGrid 做的）：
     *
     *   protected function grid(): \plugin\curd\app\dsl\Grid
     *   {
     *       return (new \plugin\curd\app\dsl\Grid(new \app\model\MyTest()))
     *           ->column('id', 'ID')->width(80)...
     *   }
     *
     * 仍显式声明也完全兼容：ModelRegistry::scanControllers() 启动扫描时
     * 优先反射读本属性（getDefaultProperties 不实例化、不执行 grid()，
     * grid() 里用到 request() 等请求上下文的控制器请保留声明）。
     *
     *   protected string $modelClass = \app\model\CompanySettleDan::class;
     *
     * 未声明时：运行期由 resolveGrid() 从 Grid 提取；启动扫描期兜底执行
     * getGridModel()（会执行 grid()，需保证启动期安全）。
     */
    protected string $modelClass = '';

    /**
     * 获取 Grid 配置中的模型实例，供 ModelRegistry 等框架组件解析专属控制器。
     */
    public function getGridModel(): ?object
    {
        $grid = $this->resolveGrid();
        return $grid ? $grid->getModelInstance() : null;
    }

    /**
     * 级联下拉通用分发（GET /api/curd/model/{model}/cascade/{method}）
     *
     * 由 CurdController::cascade 转发至此。按命名约定白名单：只允许调用
     * 业务控制器上 cascade{Xxx} 前缀的公开方法（如 cascadeMoneys），
     * 杜绝借道调用 add/delete 等其它方法。
     *
     * 业务控制器示例：
     *   public function cascadeMoneys(Request $request)
     *   {
     *       $platformId = (int)$request->input('platform', 0);
     *       // …… 查询选项 ……
     *       return json(['code' => 200, 'data' => [['value' => 10, 'label' => '10元'], ...]]);
     *   }
     */
    public function cascadeOptions(string $method, Request $request)
    {
        $real = 'cascade' . str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $method)));
        if (!preg_match('/^cascade[A-Z]/', $real) || !method_exists($this, $real)) {
            return json(['code' => 404, 'msg' => "级联数据源 {$method} 未实现：请在 " . static::class . " 中定义 {$real}(Request \$request)"]);
        }
        return $this->$real($request);
    }
}
