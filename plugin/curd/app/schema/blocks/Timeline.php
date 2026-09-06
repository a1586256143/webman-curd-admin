<?php

namespace plugin\curd\app\schema\blocks;

use Closure;
use plugin\curd\app\schema\SchemaNode;

/**
 * 时间线 → el-timeline
 * 子节点只能通过 ->item(ts, cb) 生成（对应 el-timeline-item）。
 */
class Timeline extends SchemaNode
{
    use HasBlocks;

    public function __construct()
    {
        $this->type = 'timeline';
    }

    /** 追加一个时间线条目 */
    public function item(?string $timestamp = null, ?Closure $cb = null): TimelineItem
    {
        $n = new TimelineItem();
        if ($timestamp !== null) {
            $n->timestamp($timestamp);
        }
        if ($cb) {
            $cb($n);
        }
        $this->push($n);
        return $n;
    }
}
