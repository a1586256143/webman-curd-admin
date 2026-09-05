<?php

namespace plugin\crud\app\schema\blocks;

use plugin\crud\app\schema\SchemaNode;

/**
 * 数值展示 → el-statistic
 * value 支持 {{key.path}} 占位符（整串占位且为数字时自动转 number）。
 */
class Statistic extends SchemaNode
{
    public function __construct()
    {
        $this->type = 'statistic';
    }

    /** 标题 */
    public function title(string $title): static
    {
        return $this->set('title', $title);
    }

    /** 数值（字符串/数字/{{占位符}}） */
    public function value($value): static
    {
        return $this->set('value', $value);
    }

    /** 前缀（如 ¥） */
    public function prefix(string $prefix): static
    {
        return $this->set('prefix', $prefix);
    }

    /** 后缀 */
    public function suffix(string $suffix): static
    {
        return $this->set('suffix', $suffix);
    }

    /** 小数位 */
    public function precision(int $precision): static
    {
        return $this->set('precision', $precision);
    }

    /** 千分位分隔符（默认逗号） */
    public function groupSeparator(string $groupSeparator): static
    {
        return $this->set('groupSeparator', $groupSeparator);
    }

    /** 数值样式（对象，透传给 value-style） */
    public function valueStyle(array $style): static
    {
        return $this->set('valueStyle', $style);
    }
}
