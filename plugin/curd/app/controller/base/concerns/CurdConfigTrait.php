<?php
namespace plugin\curd\app\controller\base\concerns;

use plugin\curd\app\dsl\Grid;

/**
 * CURD 配置定义 + Grid DSL 助手 + 事件钩子
 *
 * 从 BaseCurdController 抽取，保持方法签名与行为完全一致。
 * 子类在配置方法（columns/grid/formFields...）中定义前端展示，
 * 在钩子（createdBefore/createdAfter...）中挂载业务。
 */
trait CurdConfigTrait
{
    /**
     * 请求级缓存：已构建的 Grid DSL 实例
     * 一次列表请求会多次读取 grid（config/display/href/action 等），
     * 每次都重新执行 grid() 会重复触发远程请求、字典查询等副作用。
     */
    private ?Grid $gridCache = null;
    private bool $gridResolved = false;

    /**
     * 获取 Grid DSL 实例（当前控制器实例内只构建一次）
     * 业务控制器仍然实现 grid()；框架内部统一走本方法读配置。
     */
    protected function resolveGrid(): ?Grid
    {
        if (!$this->gridResolved) {
            $this->gridResolved = true;
            $this->gridCache = $this->grid();
        }
        return $this->gridCache;
    }

    /**
     * 数据模型类（推荐）：返回全限定类名，如 \app\model\APackage::class
     * 实现后 table() 自动从模型取，CURD 全程 Eloquent ORM
     */
    protected function model(): string
    {
        return '';
    }

    /**
     * 获取当前 CURD 实际使用的模型：优先 Grid::model()，否则回退到控制器 model()。
     */
    protected function resolvedModel(): string
    {
        $grid = $this->resolveGrid();
        if ($grid && $grid->getModelInstance()) {
            return get_class($grid->getModelInstance());
        }
        return $this->model();
    }

    /**
     * 字段标题多语言翻译助手
     *
     * 翻译文件位于 resource/translations/{locale}/fields.php，
     * 文件以「文件名」作为 domain（fields），故统一传入 'fields'。
     * 未命中时 symfony 会原样返回 key，安全降级。
     *
     *   $this->tl('field.status')  // zh_CN => 状态, en => Status
     *   $this->tl('状态')          // zh_CN => 状态, en => Status（中文原文作 key）
     *
     * 子类 DSL 中也可对传入的中文标题调用：$this->col('status', $this->tl('状态'))
     */
    protected function tl(string $key): string
    {
        return trans($key, [], 'fields');
    }

    /**
     * 数据表名
     * 若子类实现了 model()，自动从模型取表名；
     * 否则子类必须直接实现 table()（向后兼容旧写法）
     */
    protected function table(): string
    {
        $model = $this->resolvedModel();
        if ($model !== '' && class_exists($model)) {
            return (new $model())->getTable();
        }
        // 子类未实现 model() 也未实现 table() → 抛错提示
        throw new \RuntimeException(static::class . ' 必须实现 model() 或 table()');
    }

    /**
     * 页面标题
     */
    protected function title(): string
    {
        if (property_exists($this, 'title') && is_string($this->title) && $this->title !== '') {
            return $this->title;
        }
        return $this->table();
    }

    /**
     * Grid DSL（推荐）：laravel-admin 风格链式构建
     * 子类重写此方法返回 Grid 对象后，columns()/search()/formFields() 等数组方法可省略
     *
     *   protected function grid(): \plugin\curd\app\dsl\Grid
     *   {
     *       return (new \plugin\curd\app\dsl\Grid('包管理'))
     *           ->column('id', 'ID')->width(80)
     *           ->column('status', '状态')->map([1 => '开启', 0 => '关闭'])->label([1 => 'success', 0 => 'danger'])
     *           ->filter(fn($f) => $f->like('title', '标题')->select('status', '状态')->options([1 => '开启', 0 => '关闭']))
     *           ->form(fn($f) => $f->text('title', '标题')->required()->select('status', '状态')->options([1 => '开启', 0 => '关闭']));
     *   }
     */
    protected function grid(): ?\plugin\curd\app\dsl\Grid
    {
        return null;
    }

    /**
     * 列表列配置
     */
    protected function columns(): array
    {
        return [];
    }

    /**
     * 搜索配置
     */
    protected function search(): array
    {
        return [];
    }

    /**
     * 表单字段配置
     */
    protected function formFields(): array
    {
        return [];
    }

    /**
     * 详情页字段配置（留空默认取全部列）
     */
    protected function detail(): array
    {
        return [];
    }

    /**
     * 表单验证规则（留空自动按表单字段推断）
     * 格式：['field' => 'required|max:100|integer', ...]
     */
    protected function rules(): array
    {
        return [];
    }

    /**
     * 功能开关
     */
    protected function options(): array
    {
        return [
            'add' => true,
            'edit' => true,
            'delete' => true,
            'view' => true,
            'export' => true,
            'batchDelete' => true,
        ];
    }

    /**
     * 自定义操作配置（数组写法；Grid DSL 模式下用 $grid->action() 代替）
     */
    protected function actions(): array
    {
        return [];
    }

    /**
     * 弹窗宽度
     */
    protected function dialogWidth(): string
    {
        return '600px';
    }

    /**
     * 自定义 API 路由前缀（默认 /api/curd/{table} 通配）
     * 可返回：['list'=>'/api/package/index', 'add'=>'/api/package/add', ...]
     */
    protected function api(): array
    {
        return [];
    }

    // ============================================================
    // 列配置辅助方法
    // ============================================================

    protected function col(string $prop, string $label, array $options = []): array
    {
        return array_merge(['prop' => $prop, 'label' => $label], $options);
    }

    protected function searchInput(string $prop, string $label, array $options = []): array
    {
        return array_merge(['prop' => $prop, 'label' => $label, 'type' => 'input'], $options);
    }

    protected function searchSelect(string $prop, string $label, array $map, array $options = []): array
    {
        $item = ['prop' => $prop, 'label' => $label, 'type' => 'select'];
        $opts = [['value' => '', 'label' => '全部']];
        foreach ($map as $value => $text) {
            $opts[] = ['value' => $value, 'label' => $text];
        }
        $item['options'] = $opts;
        return array_merge($item, $options);
    }

    protected function searchDateRange(string $prop, string $label, array $options = []): array
    {
        return array_merge(['prop' => $prop, 'label' => $label, 'type' => 'daterange'], $options);
    }

    /**
     * 远程下拉（搜索 + 表单 + 列表通用）
     * 数据源走 /api/options/{table}
     */
    protected function remote(string $prop, string $label, string $sourceTable, string $valueKey = 'id', string $labelKey = 'name', array $options = []): array
    {
        return array_merge([
            'prop' => $prop,
            'label' => $label,
            'remote' => true,
            'remoteTable' => $sourceTable,
            'valueKey' => $valueKey,
            'labelKey' => $labelKey,
        ], $options);
    }

    protected function formInput(string $prop, string $label, array $options = []): array
    {
        return array_merge(['prop' => $prop, 'label' => $label, 'type' => 'input'], $options);
    }

    protected function formTextarea(string $prop, string $label, array $options = []): array
    {
        return array_merge(['prop' => $prop, 'label' => $label, 'type' => 'textarea'], $options);
    }

    protected function formNumber(string $prop, string $label, array $options = []): array
    {
        return array_merge(['prop' => $prop, 'label' => $label, 'type' => 'number'], $options);
    }

    protected function formSelect(string $prop, string $label, array $map, array $options = []): array
    {
        $opts = [];
        foreach ($map as $value => $text) {
            $opts[] = ['value' => $value, 'label' => $text];
        }
        return array_merge(['prop' => $prop, 'label' => $label, 'type' => 'select', 'options' => $opts], $options);
    }

    protected function formRadio(string $prop, string $label, array $map, array $options = []): array
    {
        $opts = [];
        foreach ($map as $value => $text) {
            $opts[] = ['value' => $value, 'label' => $text];
        }
        return array_merge(['prop' => $prop, 'label' => $label, 'type' => 'radio', 'options' => $opts], $options);
    }

    protected function formSwitch(string $prop, string $label, array $options = []): array
    {
        return array_merge(['prop' => $prop, 'label' => $label, 'type' => 'switch', 'activeValue' => 1, 'inactiveValue' => 0], $options);
    }

    /**
     * 字典列（列表展示 tag + 颜色）
     */
    protected function dictColumn(string $prop, string $label, array $map, array $options = []): array
    {
        $displayMap = [];
        foreach ($map as $value => $conf) {
            if (is_array($conf)) {
                $displayMap[$value] = ['label' => $conf['label'] ?? $value, 'type' => $conf['type'] ?? 'primary'];
            } else {
                $displayMap[$value] = ['label' => $conf, 'type' => 'primary'];
            }
        }
        return array_merge([
            'prop' => $prop,
            'label' => $label,
            'type' => 'tag',
            'map' => $displayMap,
        ], $options);
    }

    // ============================================================
    // 事件钩子（子类重写做业务）
    // ============================================================

    /**
     * 新增前（可修改 $data）
     */
    protected function createdBefore(array &$data): void {}

    /**
     * 新增后
     */
    protected function createdAfter(int $id, array $data): void {}

    /**
     * 更新前（可修改 $data）
     */
    protected function updateBefore(int $id, array &$data): void {}

    /**
     * 更新后
     */
    protected function updateAfter(int $id, array $data): void {}

    /**
     * 删除前（可阻止删除：throw 异常或返回 false）
     */
    protected function deleteBefore(int $id, array $row): void {}

    /**
     * 删除后
     */
    protected function deleteAfter(int $id): void {}

    /**
     * 批量删除前
     */
    protected function batchDeleteBefore(array $ids): void {}

    /**
     * 批量删除后
     */
    protected function batchDeleteAfter(array $ids): void {}
}
