<?php

namespace plugin\crud\app\schema\blocks;

use Closure;
use plugin\crud\app\schema\SchemaNode;

/**
 * 折叠面板 → el-collapse
 * 子节点只能通过 ->item(title, cb) 生成（对应 el-collapse-item）。
 */
class Collapse extends SchemaNode
{
    use HasBlocks;

    public function __construct()
    {
        $this->type = 'collapse';
    }

    /** 手风琴模式（同时只展开一项） */
    public function accordion(bool $accordion = true): static
    {
        return $this->set('accordion', $accordion);
    }

    /** 默认展开项的 name 列表（不设则全部折叠） */
    public function active(array $names): static
    {
        return $this->set('activeNames', $names);
    }

    /** 追加一个折叠项 */
    public function item(string $title, ?Closure $cb = null): CollapseItem
    {
        $n = new CollapseItem();
        $n->title($title);
        if ($cb) {
            $cb($n);
        }
        $this->push($n);
        return $n;
    }
}
