<?php

namespace plugin\curd\app\schema\blocks;

use plugin\curd\app\schema\SchemaNode;

/**
 * 折叠项 → el-collapse-item（仅作为 collapse 的子节点，内容放 children）
 */
class CollapseItem extends SchemaNode
{
    use HasBlocks;

    public function __construct()
    {
        $this->type = 'collapse-item';
    }

    /** 折叠项标题 */
    public function title(string $title): static
    {
        return $this->set('title', $title);
    }

    /** 唯一标识（用于 active 列表；缺省由 el-collapse 按索引处理） */
    public function name(string $name): static
    {
        return $this->set('name', $name);
    }

    /** 是否禁用展开 */
    public function disabled(bool $disabled = true): static
    {
        return $this->set('disabled', $disabled);
    }
}
