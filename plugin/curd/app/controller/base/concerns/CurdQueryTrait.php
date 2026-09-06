<?php
namespace plugin\curd\app\controller\base\concerns;

use plugin\curd\app\CurdDb;
use support\Db;

/**
 * CURD 查询构造：表字段元信息、搜索匹配推断、软删除、时间戳/创建人自动填充
 *
 * 从 BaseCurdController 抽取，保持方法签名与行为完全一致。
 * 仅依赖 config 层提供 model()/table()，与具体的增删改逻辑解耦。
 */
trait CurdQueryTrait
{
    /**
     * 业务库连接
     */
    protected function db()
    {
        return CurdDb::businessDb();
    }

    /**
     * 模型连接名（默认业务库）
     */
    protected function modelConnection(): string
    {
        $model = $this->resolvedModel();
        if ($model !== '' && class_exists($model)) {
            $conn = (new $model())->getConnectionName();
            return $conn ?: CurdDb::business();
        }
        return CurdDb::business();
    }

    /**
     * 统一查询入口：优先 Eloquent 模型，否则 query builder
     */
    protected function query()
    {
        $grid = $this->resolveGrid();
        if ($grid) {
            return $grid->getModel();
        }

        $model = $this->resolvedModel();
        if ($model !== '' && class_exists($model)) {
            return (new $model())->newQuery();
        }
        return $this->db()->table($this->table());
    }

    /**
     * 表列名（缓存）
     */
    protected function tableColumns(): array
    {
        static $cache = [];
        $table = $this->table();
        if (!isset($cache[$table])) {
            $result = $this->db()->select("SHOW COLUMNS FROM `{$table}`");
            $cache[$table] = array_map(fn($r) => $r->Field, $result);
        }
        return $cache[$table];
    }

    /**
     * 表字段类型映射（缓存）：prop => 小写 DB 类型，如 ['name' => 'varchar(64)']
     * 用于搜索匹配方式推断：字符串类 → 模糊，其余 → 精确
     */
    protected function tableColumnTypes(): array
    {
        static $cache = [];
        $table = $this->table();
        if (!isset($cache[$table])) {
            $conn = ($this->resolvedModel() !== '' && class_exists($this->resolvedModel()))
                ? $this->modelConnection()
                : CurdDb::business();
            $result = Db::connection($conn)->select("SHOW COLUMNS FROM `{$table}`");
            $map = [];
            foreach ($result as $r) {
                $map[$r->Field] = strtolower($r->Type);
            }
            $cache[$table] = $map;
        }
        return $cache[$table];
    }

    /**
     * 搜索配置（决定搜索匹配方式 like/eq）
     * 子类业务控制器默认取 search()；动态 CURD 重写为从 curd_configs 读取
     */
    protected function searchConfig(): array
    {
        return $this->search();
    }

    /**
     * 搜索字段映射：prop => 搜索配置项
     */
    protected function searchFieldMap(): array
    {
        $map = [];
        foreach ($this->searchConfig() as $item) {
            if (isset($item['prop'])) {
                $map[$item['prop']] = $item;
            }
        }
        return $map;
    }

    /**
     * 解析搜索字段的匹配方式
     * 优先级：①搜索配置显式 match（like/eq）②搜索框类型（input/textarea→模糊，其余→精确）
     *        ③无搜索配置时按 DB 字段类型（字符类模糊，其余精确）
     */
    protected function resolveMatchMode(string $key, ?array $searchItem, string $dbType): string
    {
        // ① 配置显式指定优先
        if ($searchItem && isset($searchItem['match'])) {
            return $searchItem['match'] === 'like' ? 'like' : 'eq';
        }
        // ② 按搜索框类型
        $type = $searchItem['type'] ?? null;
        if ($type === 'input' || $type === 'textarea') {
            return 'like';
        }
        if ($type !== null) {
            return 'eq';
        }
        // ③ 按 DB 字段类型（字符类模糊，其余精确）
        if (preg_match('/^(varchar|char|text|tinytext|mediumtext|longtext|enum|set)/', $dbType)) {
            return 'like';
        }
        return 'eq';
    }

    /**
     * 是否支持软删除
     */
    protected function hasSoftDelete(): bool
    {
        return in_array('deleted_at', $this->tableColumns(), true);
    }

    /**
     * 自动时间戳
     */
    protected function applyTimestamps(array &$data, bool $isCreate): void
    {
        $columnMap = array_flip($this->tableColumns());
        $now = date('Y-m-d H:i:s');
        if ($isCreate && isset($columnMap['created_at']) && empty($data['created_at'])) {
            $data['created_at'] = $now;
        }
        if (isset($columnMap['updated_at'])) {
            $data['updated_at'] = $now;
        }
    }

    /**
     * 自动填充创建人：表存在创建人字段且未传值时，写入当前登录用户 ID
     *
     * 支持常见命名：created_uid / created_user / created_by / creator_id（按序匹配第一个存在的列）
     * 仅对整型列填充，避免 varchar 类字段（如存用户名的文本列）被误写为数字 ID。
     * 客户端已显式传值时不覆盖（前端 Form::hidden()->default() 或业务钩子优先）。
     */
    protected function applyCreatedUid(array &$data, \support\Request $request): void
    {
        if (empty($request->user) || empty($request->user->id)) {
            return;
        }
        $columnMap = array_flip($this->tableColumns());
        $types = $this->tableColumnTypes();
        foreach (['created_uid', 'created_user', 'created_by', 'creator_id'] as $column) {
            if (!isset($columnMap[$column])) {
                continue;
            }
            // 已显式传值则不覆盖
            if (array_key_exists($column, $data) && $data[$column] !== '' && $data[$column] !== null) {
                return;
            }
            if (!str_contains($types[$column] ?? '', 'int')) {
                continue;
            }
            $data[$column] = (int)$request->user->id;
            return;
        }
    }
}
