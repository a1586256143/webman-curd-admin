<?php

namespace plugin\curd\app\schema\blocks;

use plugin\curd\app\schema\SchemaNode;

/**
 * 分割线 → el-divider
 * 中间文字以 Text 子节点承载（el-divider 的默认插槽内容）。
 */
class Divider extends SchemaNode
{
    use HasBlocks;

    public function __construct()
    {
        $this->type = 'divider';
    }

    /** 分割线中央文字 */
    public function content(string $content): static
    {
        $this->push(new Text($content));
        return $this;
    }

    /** 文字位置：left / center / right */
    public function contentPosition(string $contentPosition): static
    {
        return $this->set('contentPosition', $contentPosition);
    }

    /** 方向：horizontal / vertical */
    public function direction(string $direction): static
    {
        return $this->set('direction', $direction);
    }

    /** 边框样式（css border-style 值） */
    public function borderStyle(string $borderStyle): static
    {
        return $this->set('borderStyle', $borderStyle);
    }
}
