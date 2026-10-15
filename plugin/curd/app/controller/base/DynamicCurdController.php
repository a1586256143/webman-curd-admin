<?php
namespace plugin\curd\app\controller\base;

use plugin\curd\app\model\CurdConfigs;

/**
 * 动态 CURD 控制器
 *
 * 通配路由 /api/curd/model/{model} 的实现载体：
 * 把 URL 解析出的模型类注入到 BaseCurdController，复用基类全部能力
 * （index/add/update/delete/batchDelete/export/config + 业务钩子）。
 *
 * 与继承式业务控制器（如 PackageController）的区别：
 * - 业务控制器：手写 grid()/columns()/钩子，配置完全自控
 * - 本类：模型动态指定，配置走 curd_configs 落库配置（前端生成器保存的）
 *
 * 用法（由 CurdController 内部创建，无需手动实例化）：
 *   $curd = DynamicCurdController::forModel(\app\model\APackage::class);
 *   $curd->index($request);
 */
class DynamicCurdController extends BaseCurdController
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
     * 搜索配置：从 curd_configs 落库配置读取（含每字段 match 覆盖）
     */
    protected function searchConfig(): array
    {
        try {
            $row = CurdConfigs::firstByTableName($this->table());
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

    /**
     * 落库配置里的点式 prop（列 + 表单字段，形如 config.api_url）
     * 供列表接口展开用：根字段解 JSON + 字面 key 扁平值（见 CurdListTrait::expandDotColumns）
     */
    public function dynamicDotProps(): array
    {
        $props = [];
        $collect = function ($prop) use (&$props) {
            $prop = (string)$prop;
            if ($prop !== '' && str_contains($prop, '.')) {
                $props[$prop] = true;
            }
        };
        try {
            $row = CurdConfigs::firstByTableName($this->table());
            if ($row && !empty($row->config)) {
                $cfg = json_decode($row->config, true);
                $items = array_merge(
                    (array)($cfg['columns'] ?? []),
                    (array)($cfg['formFields'] ?? []),
                    (array)($cfg['addFields'] ?? []),
                    (array)($cfg['editFields'] ?? [])
                );
                foreach ($items as $item) {
                    $collect($item['prop'] ?? '');
                }
                // 详情里显式声明的点式字段（含分组写法 [{group, fields:[...]}]）
                foreach ($this->collectDetailProps((array)($cfg['detail'] ?? [])) as $prop) {
                    $collect($prop);
                }
            }
        } catch (\Throwable $e) {
            // 配置缺失：返回空
        }
        return array_keys($props);
    }
}
