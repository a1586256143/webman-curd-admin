<?php

namespace plugin\curd\app\schema\blocks;

use plugin\curd\app\schema\SchemaNode;

/**
 * 时间线条目 → el-timeline-item（仅作为 timeline 的子节点，内容放 children）
 */
class TimelineItem extends SchemaNode
{
    use HasBlocks;

    public function __construct()
    {
        $this->type = 'timeline-item';
    }

    /** 时间戳文案 */
    public function timestamp(string $timestamp): static
    {
        return $this->set('timestamp', $timestamp);
    }

    /** 时间戳位置：top / bottom */
    public function placement(string $placement): static
    {
        return $this->set('placement', $placement);
    }

    /** 节点类型：primary / success / warning / danger / info */
    public function type(string $type): static
    {
        return $this->set('type', $type);
    }

    /** 自定义节点颜色 */
    public function color(string $color): static
    {
        return $this->set('color', $color);
    }

    /** 空心节点 */
    public function hollow(bool $hollow = true): static
    {
        return $this->set('hollow', $hollow);
    }
}
