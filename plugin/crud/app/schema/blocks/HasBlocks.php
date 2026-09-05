<?php

namespace plugin\crud\app\schema\blocks;

use Closure;
use plugin\crud\app\schema\SchemaNode;

/**
 * 块构建器：凡是「能包含子节点」的节点（Row/Col/Card/Collapse/Timeline/
 * Descriptions/Divider/PageSchema）都挂上本 trait，获得统一的挂载入口。
 *
 * 用法示例：
 *   $col->card('标题', function (Card $card) {
 *       $card->statistic('今日充值')->value('{{stats.amount}}')->prefix('¥');
 *       $card->divider();
 *   });
 */
trait HasBlocks
{
    /** 挂载任意已构造好的节点 */
    public function node(SchemaNode $node): SchemaNode
    {
        $this->push($node);
        return $node;
    }

    /** 栅格行 */
    public function row(?Closure $cb = null): Row
    {
        $n = new Row();
        if ($cb) {
            $cb($n);
        }
        $this->push($n);
        return $n;
    }

    /** 栅格列（24 栏制） */
    public function col(int $span = 24, ?Closure $cb = null): Col
    {
        $n = new Col();
        $n->span($span);
        if ($cb) {
            $cb($n);
        }
        $this->push($n);
        return $n;
    }

    /** 卡片（header 走 EP 命名插槽，由前端渲染器特殊处理） */
    public function card(?string $header = null, ?Closure $cb = null): Card
    {
        $n = new Card();
        if ($header !== null) {
            $n->header($header);
        }
        if ($cb) {
            $cb($n);
        }
        $this->push($n);
        return $n;
    }

    /** 折叠面板（子节点用 ->item(title, cb) 追加） */
    public function collapse(?Closure $cb = null): Collapse
    {
        $n = new Collapse();
        if ($cb) {
            $cb($n);
        }
        $this->push($n);
        return $n;
    }

    /** 时间线（子节点用 ->item(ts, cb) 追加） */
    public function timeline(?Closure $cb = null): Timeline
    {
        $n = new Timeline();
        if ($cb) {
            $cb($n);
        }
        $this->push($n);
        return $n;
    }

    /** 描述列表（子节点用 ->item(label, content) 追加） */
    public function descriptions(?Closure $cb = null): Descriptions
    {
        $n = new Descriptions();
        if ($cb) {
            $cb($n);
        }
        $this->push($n);
        return $n;
    }

    /** 分割线（content 为分割线中央文字） */
    public function divider(?string $content = null): Divider
    {
        $n = new Divider();
        if ($content !== null) {
            $n->content($content);
        }
        $this->push($n);
        return $n;
    }

    /** 提示条 */
    public function alert(?string $title = null, ?string $description = null): Alert
    {
        $n = new Alert();
        if ($title !== null) {
            $n->title($title);
        }
        if ($description !== null) {
            $n->description($description);
        }
        $this->push($n);
        return $n;
    }

    /** 数值展示 */
    public function statistic(?string $title = null): Statistic
    {
        $n = new Statistic();
        if ($title !== null) {
            $n->title($title);
        }
        $this->push($n);
        return $n;
    }

    /** 图片 */
    public function image(string $src = ''): Image
    {
        $n = new Image();
        if ($src !== '') {
            $n->src($src);
        }
        $this->push($n);
        return $n;
    }

    /** 纯文本节点 */
    public function text(string $value = ''): Text
    {
        $n = new Text();
        if ($value !== '') {
            $n->value($value);
        }
        $this->push($n);
        return $n;
    }
}
