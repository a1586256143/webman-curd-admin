<?php
namespace plugin\curd\app\dsl\component;

/**
 * 表格组件（弹窗 / 行内展开里用）
 *
 * 用法：
 *   new Table(['id' => 'ID', 'name' => '名称'], [
 *       ['id' => 1, 'name' => 'A'],
 *       ['id' => 2, 'name' => 'B'],
 *   ])
 *
 *   // 列可以用数组细配（width / align / minWidth / fixed / formatter?）
 *   new Table([
 *       ['prop' => 'id',   'label' => 'ID',   'width' => 80, 'align' => 'center'],
 *       ['prop' => 'name', 'label' => '名称', 'minWidth' => 160],
 *   ], $rows, ['stripe' => true, 'maxHeight' => 320])
 *
 * options 直接透传给前端 el-table：
 *   border / stripe / size / maxHeight / emptyText / showHeader ...
 */
class Table extends Component
{
    protected array $columns;
    protected array $rows;
    protected array $options;

    /**
     * @param array $columns 列定义：['prop' => '标题'] 或 [['prop'=>'..','label'=>'..','width'=>..], ...]
     * @param array $rows    行数据：数组行 / 模型行（自动 toArray）
     * @param array $options el-table 属性
     */
    public function __construct(array $columns, array $rows = [], array $options = [])
    {
        $this->columns = $columns;
        $this->rows = $rows;
        $this->options = $options;
    }

    public function toArray(): array
    {
        return array_merge([
            'type' => 'table',
            'columns' => $this->normalizeColumns(),
            'rows' => $this->normalizeRows(),
        ], $this->options);
    }

    /**
     * ['prop' => '标题'] → [['prop' => 'prop', 'label' => '标题']]
     * 已是数组形式的列原样保留（补齐 prop/label）
     */
    protected function normalizeColumns(): array
    {
        $out = [];
        foreach ($this->columns as $key => $value) {
            if (is_array($value)) {
                $prop = (string)($value['prop'] ?? $key);
                $out[] = array_merge(['prop' => $prop, 'label' => (string)($value['label'] ?? $prop)], $value);
                continue;
            }
            $out[] = ['prop' => (string)$key, 'label' => (string)$value];
        }
        return $out;
    }

    /**
     * 行数据归一化：模型 → toArray()，对象 → (array)，其余原样
     */
    protected function normalizeRows(): array
    {
        $out = [];
        foreach ($this->rows as $row) {
            if (is_object($row) && method_exists($row, 'toArray')) {
                $out[] = $row->toArray();
            } elseif (is_object($row)) {
                $out[] = (array)$row;
            } elseif (is_array($row)) {
                $out[] = $row;
            } else {
                $out[] = ['value' => $row];
            }
        }
        return $out;
    }
}
