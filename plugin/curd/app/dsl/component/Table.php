<?php
namespace plugin\curd\app\dsl\component;

/**
 * 表格组件（弹窗 / 整行展开里用）
 *
 * 用法一（推荐）：键值对，键 = 行数据字段名
 *   new Table(['id' => 'ID', 'name' => '名称'], [
 *       ['id' => 1, 'name' => 'A'],
 *       ['id' => 2, 'name' => 'B'],
 *   ])
 *
 * 用法二：纯标题列表 —— 标题按顺序对齐行数据的键（取各行键的首次出现顺序）
 *   new Table(['标题', '标题2'], [
 *       ['xxx' => '111', 'xxx2' => '113'],
 *       ['xxx' => '1222', 'xxx2' => '112'],
 *   ])
 *   等价于 ['xxx' => '标题', 'xxx2' => '标题2']；标题多于数据键时多出的列留空
 *
 * 用法三：列数组细配（width / align / minWidth / fixed ...）
 *   new Table([
 *       ['prop' => 'id',   'label' => 'ID',   'width' => 80, 'align' => 'center'],
 *       ['prop' => 'name', 'label' => '名称', 'minWidth' => 160],
 *   ], $rows, ['stripe' => true, 'maxHeight' => 320])
 *
 * 行数据也可以是纯列表（按位置与列对齐）：[[1, 'A'], [2, 'B']]
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
     * @param array $columns ['prop' => '标题'] / ['标题', '标题2']（纯标题） / [['prop'=>'..','label'=>'..'], ...]
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
        $columns = $this->normalizeColumns();
        $rows = $this->isLabelList() ? $this->rekeyListRows($columns) : $this->normalizeRows();

        return array_merge([
            'type' => 'table',
            'columns' => $columns,
            'rows' => $rows,
        ], $this->options);
    }

    /**
     * 是否「纯标题列表」写法：连续整数键 + 全是标量（数组形式的细配列不算）
     */
    protected function isLabelList(): bool
    {
        if ($this->columns === [] || array_values($this->columns) !== $this->columns) {
            return false;
        }
        foreach ($this->columns as $value) {
            if (is_array($value) || !is_scalar($value)) {
                return false;
            }
        }
        return true;
    }

    /**
     * ['prop' => '标题'] → [['prop' => 'prop', 'label' => '标题']]
     * 纯标题列表 → 标题按顺序对齐「各行字符串键的首次出现顺序」，
     * 标题不够时补 prop 作 label，保证数据列不丢
     * 已是数组形式的列原样保留（补齐 prop/label）
     */
    protected function normalizeColumns(): array
    {
        if ($this->isLabelList()) {
            $keys = $this->rowKeyOrder();
            $count = max(count($this->columns), count($keys));
            $labels = array_values($this->columns);
            $out = [];
            for ($i = 0; $i < $count; $i++) {
                $prop = $keys[$i] ?? ('col' . $i);
                $out[] = ['prop' => $prop, 'label' => (string)($labels[$i] ?? $prop)];
            }
            return $out;
        }

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
     * 收集各行字符串键的首次出现顺序（纯标题写法用来对位列）
     */
    protected function rowKeyOrder(): array
    {
        $keys = [];
        foreach ($this->normalizeRows() as $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach ($row as $k => $_) {
                if (is_string($k) && !in_array($k, $keys, true)) {
                    $keys[] = $k;
                }
            }
        }
        return $keys;
    }

    /**
     * 纯标题写法下归一行：纯列表行按位置对齐到列 prop（[[1,'A'],[2,'B']] → [['col0'=>1,'col1'=>'A'],...]）；
     * 字符串键行保持原样（列 prop 已对齐其键序）
     */
    protected function rekeyListRows(array $columns): array
    {
        $props = array_column($columns, 'prop');
        $out = [];
        foreach ($this->normalizeRows() as $row) {
            if (!is_array($row) || array_values($row) !== $row) {
                $out[] = $row;
                continue;
            }
            $new = [];
            foreach ($row as $i => $value) {
                $new[$props[$i] ?? ('col' . $i)] = $value;
            }
            $out[] = $new;
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
