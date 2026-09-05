<?php
namespace plugin\crud\app\controller;

use plugin\crud\app\CrudDb;
use plugin\crud\app\ModelRegistry;
use support\Request;

/**
 * 通用远程下拉数据源
 * 供前端 select 字段动态加载选项（remote select）
 *
 * 用法：
 *   GET /api/options/model/{model}?value=id&label=name&q=关键字&ids=1,2,3&limit=20
 *
 * {model} 是 Eloquent 模型类短名（如 AdminUser / APackage），
 * 由 ModelRegistry 白名单解析，全程 ORM 查询，杜绝前端探查任意表。
 *
 * 参数：
 *   value 值字段（默认 id）
 *   label 显示字段（默认 name）
 *   q     关键字（在 label 字段上 like 搜索）
 *   ids   指定 id 列表（编辑回显时按 id 拉取 label），逗号分隔
 *   limit 返回条数（默认 20）
 *
 * 返回：{code:200, data:[{value:1,label:'xx'}, ...]}
 */
class OptionsController
{
    /**
     * 解析 model → 模型类，白名单校验
     */
    protected function resolveModel(string $model): ?string
    {
        if ($model === '') {
            return null;
        }
        return ModelRegistry::resolve($model);
    }

    /**
     * 取模型连接名
     */
    protected function modelConnection(string $modelClass): string
    {
        try {
            $conn = (new $modelClass())->getConnectionName();
            return $conn ?: CrudDb::business();
        } catch (\Throwable $e) {
            return CrudDb::business();
        }
    }

    /**
     * 获取下拉选项（Eloquent ORM）
     */
    public function index($model, Request $request)
    {
        $modelClass = $this->resolveModel($model);
        if ($modelClass === null) {
            return json(['code' => 404, 'msg' => "模型 {$model} 未注册"]);
        }

        $valueField = $request->get('value', 'id');
        $labelField = $request->get('label', 'name');
        $q          = $request->get('q', '');
        $ids        = $request->get('ids', '');
        $limit      = min((int)$request->get('limit', 20), 200);

        // 白名单：字段必须存在于该表（防注入）
        $columns = $this->getTableColumns($modelClass);
        if (!in_array($valueField, $columns, true) || !in_array($labelField, $columns, true)) {
            return json(['code' => 400, 'msg' => '字段不存在：value/label 必须是该表的真实列']);
        }

        $query = $modelClass::on($this->modelConnection($modelClass))->newQuery();

        // 软删除过滤
        if (in_array('deleted_at', $columns, true)) {
            $query->whereNull('deleted_at');
        }

        // 关键字搜索（label 字段模糊匹配）
        if ($q !== '') {
            $query->where($labelField, 'like', "%{$q}%");
        }

        // 指定 id（编辑回显）
        if ($ids !== '') {
            $idList = array_filter(array_map('trim', explode(',', $ids)), fn($v) => $v !== '');
            if ($idList) {
                $query->whereIn($valueField, $idList);
            }
        }

        $rows = $query->limit($limit)->get()->toArray();

        $options = [];
        foreach ($rows as $row) {
            $value = $row[$valueField] ?? null;
            $label = $row[$labelField] ?? null;
            // label 为空时降级显示 value
            if ($label === null || $label === '') {
                $label = $value;
            }
            $options[] = [
                'value' => $value,
                'label' => (string)$label,
            ];
        }

        return json(['code' => 200, 'msg' => 'success', 'data' => $options]);
    }

    /**
     * 获取表列名（通过模型连接）
     */
    protected function getTableColumns(string $modelClass): array
    {
        try {
            $model = new $modelClass();
            $table = $model->getTable();
            $connection = $model->getConnectionName() ?: CrudDb::business();
            $result = \support\Db::connection($connection)->select("SHOW COLUMNS FROM `{$table}`");
            return array_map(fn($row) => $row->Field, $result);
        } catch (\Throwable $e) {
            return [];
        }
    }
}
