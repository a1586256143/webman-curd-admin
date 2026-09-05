<?php

namespace plugin\crud\app\schema\blocks;

use plugin\crud\app\schema\SchemaNode;

/**
 * 栅格行 → el-row（24 栏制，与 Element Plus 对齐）
 * 子节点约定为 col（可任意层嵌套，实现大布局套小布局）。
 */
class Row extends SchemaNode
{
    use HasBlocks;

    public function __construct()
    {
        $this->type = 'row';
    }

    /** 栅格间隔（px） */
    public function gutter(int $gutter): static
    {
        return $this->set('gutter', $gutter);
    }

    /** 水平排列方式：start/end/center/space-around/space-between/space-evenly */
    public function justify(string $justify): static
    {
        return $this->set('justify', $justify);
    }

    /** 垂直对齐方式：top/middle/bottom */
    public function align(string $align): static
    {
        return $this->set('align', $align);
    }
}
