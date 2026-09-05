<?php
namespace plugin\crud\app\controller\base;

use plugin\crud\app\controller\base\concerns\CrudActionsTrait;
use plugin\crud\app\controller\base\concerns\CrudConfigTrait;
use plugin\crud\app\controller\base\concerns\CrudListTrait;
use plugin\crud\app\controller\base\concerns\CrudQueryTrait;

/**
 * 通用 CRUD 控制器基类
 *
 * 子类只需继承并实现少量配置方法，即可获得完整 CRUD 页面 + 接口：
 *
 *   class PackageController extends BaseCrudController
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
 *   - CrudConfigTrait  : 配置定义 + Grid DSL 助手 + 事件钩子
 *   - CrudQueryTrait   : 表字段元信息、搜索匹配推断、软删除、时间戳/创建人填充
 *   - CrudListTrait    : 列表查询 + 前端配置输出 + display/href 后处理
 *   - CrudActionsTrait : 校验、增删改、批量删除、CSV 导出
 */
abstract class BaseCrudController
{
    use CrudConfigTrait;
    use CrudQueryTrait;
    use CrudListTrait;
    use CrudActionsTrait;

    /**
     * 数据模型类声明（可选，推荐）
     *
     * 子类声明后，ModelRegistry::scanControllers() 启动扫描时只做反射读取，
     * 不会再执行 grid()。grid() 是业务代码（可能用 request()/current_user_id()），
     * 在 worker 启动阶段无请求上下文，执行会抛错导致控制器↔模型关联注册失败。
     *
     *   protected string $modelClass = \app\model\CompanySettleDan::class;
     *
     * 未声明时保持旧行为：扫描阶段调用 getGridModel()（执行 grid()）兜底。
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
}
