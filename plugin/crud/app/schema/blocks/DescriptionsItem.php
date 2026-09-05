<?php

namespace plugin\crud\app\schema\blocks;

use plugin\crud\app\schema\SchemaNode;

/**
 * 描述项 → el-descriptions-item（仅作为 descriptions 的子节点）
 * 值内容放 children（文本/组件均可）。
 */
class DescriptionsItem extends SchemaNode
{
    use HasBlocks;

    public function __construct()
    {
        $this->type = 'descriptions-item';
    }

    /** 字段名 */
    public function label(string $label): static
    {
        return $this->set('label', $label);
    }

    /** 该项占几列 */
    public function span(int $span): static
    {
        return $this->set('span', $span);
    }

    /** 该项列宽 */
    public function width($width): static
    {
        return $this->set('width', $width);
    }
}
