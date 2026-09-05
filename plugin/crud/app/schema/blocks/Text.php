<?php

namespace plugin\crud\app\schema\blocks;

use plugin\crud\app\schema\SchemaNode;

/**
 * 纯文本节点（渲染为 span，非 EP 组件）
 * 用于卡片正文、描述项、时间线条目等处的说明文字。
 */
class Text extends SchemaNode
{
    public function __construct()
    {
        $this->type = 'text';
    }

    /** 文本内容（支持 {{key.path}} 占位符） */
    public function value(string $value): static
    {
        return $this->set('value', $value);
    }
}
