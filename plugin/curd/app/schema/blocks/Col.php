<?php

namespace plugin\curd\app\schema\blocks;

use plugin\curd\app\schema\SchemaNode;

/**
 * 栅格列 → el-col（span 取值 1~24）
 * 子节点纵向堆叠（card/statistic/divider/alert...）。
 */
class Col extends SchemaNode
{
    use HasBlocks;

    public function __construct()
    {
        $this->type = 'col';
    }

    /** 占格数（1~24） */
    public function span(int $span): static
    {
        return $this->set('span', $span);
    }

    /** 左侧偏移格数 */
    public function offset(int $offset): static
    {
        return $this->set('offset', $offset);
    }

    /** 向右移动格数（对应 el-col 的 push 属性） */
    public function moveRight(int $push): static
    {
        return $this->set('push', $push);
    }

    /** 向左移动格数（对应 el-col 的 pull 属性） */
    public function moveLeft(int $pull): static
    {
        return $this->set('pull', $pull);
    }

    /** 响应式断点（如 xs=12 / md=8，同 el-col） */
    public function responsive(string $breakpoint, $val): static
    {
        return $this->set($breakpoint, $val);
    }
}
