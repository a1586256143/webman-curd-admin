<?php

namespace plugin\curd\app\schema\blocks;

use plugin\curd\app\schema\SchemaNode;

/**
 * 纯文本节点（渲染为 span，非 EP 组件）
 * 用于卡片正文、描述项、时间线条目等处的说明文字。
 */
class Text extends SchemaNode
{
    /**
     * @param string $value 文本内容（可选，等价于构造后调用 value()）
     */
    public function __construct(string $value = '')
    {
        $this->type = 'text';
        if ($value !== '') {
            $this->value($value);
        }
    }

    /** 文本内容（支持 {{key.path}} 占位符） */
    public function value(string $value): static
    {
        return $this->set('value', $value);
    }
}
