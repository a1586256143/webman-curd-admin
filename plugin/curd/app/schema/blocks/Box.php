<?php

namespace plugin\curd\app\schema\blocks;

use plugin\curd\app\schema\SchemaNode;

/**
 * 指标卡片（Box）→ 前端 BoxNode
 *
 * 仪表盘顶部那排「图标 + 大数字 + 副标题 + 右上角标记」的统计卡片。
 * 与 statistic 的区别：statistic 是 EP 的纯数值展示（单行），
 * Box 是带图标、副标题、周期标记的整块卡片，横向并排 4 个即经典仪表盘首屏。
 *
 * 用法：
 *   $row->col(6, function (Col $col) {
 *       $col->box('访问', '{{stats.visit}}')
 *           ->sub('访问总量', '{{stats.visit_total}}')
 *           ->icon('View')->color('blue')->tag('年');
 *   });
 *
 * title/value/sub* 均支持 {{key.path}} 占位符（页面 dataApi 注入）。
 * color 为配色名（blue/green/red/orange/purple/cyan），决定图标底色与标记配色。
 */
class Box extends SchemaNode
{
    public function __construct()
    {
        $this->type = 'box';
    }

    /** 主标题（卡片左上角说明文字，如「访问」） */
    public function title(string $title): static
    {
        return $this->set('title', $title);
    }

    /** 主数值（大号加粗数字，如 73240） */
    public function value($value): static
    {
        return $this->set('value', $value);
    }

    /** 副标题（主数值下方的小字说明，如「访问总量」） */
    public function subTitle(string $subTitle): static
    {
        return $this->set('subTitle', $subTitle);
    }

    /** 副标题数值（与 subTitle 拼成「访问总量 59163」） */
    public function subValue($subValue): static
    {
        return $this->set('subValue', $subValue);
    }

    /**
     * 一步设置副标题 + 数值（等价于 subTitle()->subValue()）
     *   ->sub('访问总量', '{{stats.visit_total}}')
     */
    public function sub(string $title, $value = null): static
    {
        $this->set('subTitle', $title);
        if ($value !== null) {
            $this->set('subValue', $value);
        }
        return $this;
    }

    /** Element Plus 图标名（如 View / Download / Money / User，见前端 utils/icons） */
    public function icon(string $icon): static
    {
        return $this->set('icon', $icon);
    }

    /** 配色名：blue / green / red / orange / purple / cyan（默认 blue） */
    public function color(string $color): static
    {
        return $this->set('color', $color);
    }

    /** 右上角周期标记（如「年」「月」「日」「周」；省略则不显示） */
    public function tag(?string $tag): static
    {
        return $this->set('tag', $tag);
    }

    /** 卡片高度（px，默认 108） */
    public function height(int $height): static
    {
        return $this->set('height', $height);
    }

    /** 主数值千分位分隔（默认 false，如 73240 → 73,240） */
    public function group(bool $group = true): static
    {
        return $this->set('group', $group);
    }
}
