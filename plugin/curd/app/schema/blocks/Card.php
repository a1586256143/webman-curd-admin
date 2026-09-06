<?php

namespace plugin\curd\app\schema\blocks;

use plugin\curd\app\schema\SchemaNode;

/**
 * 卡片 → el-card（header 走 EP 命名插槽，渲染器特殊处理；正文放 children）
 */
class Card extends SchemaNode
{
    use HasBlocks;

    public function __construct()
    {
        $this->type = 'card';
    }

    /** 卡片标题（渲染为 el-card 的 header 区） */
    public function header(string $header): static
    {
        return $this->set('header', $header);
    }

    /** 阴影：always / hover / never */
    public function shadow(string $shadow): static
    {
        return $this->set('shadow', $shadow);
    }
}
