<?php
namespace plugin\crud\app\controller\base;

use plugin\crud\app\model\CrudConfigs;

/**
 * 动态 CRUD 控制器
 *
 * 通配路由 /api/crud/model/{model} 的实现载体：
 * 把 URL 解析出的模型类注入到 BaseCrudController，复用基类全部能力
 * （index/add/update/delete/batchDelete/export/config + 业务钩子）。
 *
 * 与继承式业务控制器（如 PackageController）的区别：
 * - 业务控制器：手写 grid()/columns()/钩子，配置完全自控
 * - 本类：模型动态指定，配置走 crud_configs 落库配置（前端生成器保存的）
 *
 * 用法（由 CrudController 内部创建，无需手动实例化）：
 *   $crud = DynamicCrudController::forModel(\app\model\APackage::class);
 *   $crud->index($request);
 */
class DynamicCrudController extends BaseCrudController
{
    /**
     * 动态指定的模型类全名
     */
    protected string $modelClass = '';

    /**
     * 工厂：以指定模型类创建实例
     */
    public static function forModel(string $modelClass): static
    {
        $instance = new static();
        $instance->modelClass = $modelClass;
        return $instance;
    }

    /**
     * 动态模型（覆盖基类 model()）
     */
    protected function model(): string
    {
        return $this->modelClass;
    }

    /**
     * 搜索配置：从 crud_configs 落库配置读取（含每字段 match 覆盖）
     */
    protected function searchConfig(): array
    {
        try {
            $row = CrudConfigs::firstByTableName($this->table());
            if ($row && !empty($row->config)) {
                $cfg = json_decode($row->config, true);
                if (!empty($cfg['search']) && is_array($cfg['search'])) {
                    return $cfg['search'];
                }
            }
        } catch (\Throwable $e) {
            // 配置缺失则回退为空（搜索按 DB 字段类型推断匹配方式）
        }
        return [];
    }
}
