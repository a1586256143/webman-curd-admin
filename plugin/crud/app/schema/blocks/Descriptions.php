<?php

namespace plugin\crud\app\schema\blocks;

use Closure;
use plugin\crud\app\schema\SchemaNode;

/**
 * 描述列表 → el-descriptions
 * 子节点只能通过 ->item(label, content) 生成（对应 el-descriptions-item）。
 */
class Descriptions extends SchemaNode
{
    use HasBlocks;

    public function __construct()
    {
        $this->type = 'descriptions';
    }

    /** 列表标题 */
    public function title(string $title): static
    {
        return $this->set('title', $title);
    }

    /** 每行列数 */
    public function column(int $column): static
    {
        return $this->set('column', $column);
    }

    /** 是否显示边框 */
    public function border(bool $border = true): static
    {
        return $this->set('border', $border);
    }

    /** 排列方向：horizontal / vertical */
    public function direction(string $direction): static
    {
        return $this->set('direction', $direction);
    }

    /** 是否显示冒号 */
    public function colon(bool $colon = true): static
    {
        return $this->set('colon', $colon);
    }

    /** 尺寸：large / default / small */
    public function size(string $size): static
    {
        return $this->set('size', $size);
    }

    /**
     * 追加一个描述项
     *
     * @param string          $label   字段名
     * @param string|Closure|null $content 字段值（文本）或自定义内容构建闭包
     */
    public function item(string $label, string|Closure|null $content = null, ?Closure $cb = null): DescriptionsItem
    {
        if ($content instanceof Closure) {
            $cb = $content;
            $content = null;
        }
        $n = new DescriptionsItem();
        $n->label($label);
        if (is_string($content) && $content !== '') {
            $n->push(new Text($content));
        }
        if ($cb) {
            $cb($n);
        }
        $this->push($n);
        return $n;
    }
}
