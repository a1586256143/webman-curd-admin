<?php

namespace plugin\curd\app\schema\blocks;

use plugin\curd\app\schema\SchemaNode;

/**
 * 提示条 → el-alert
 */
class Alert extends SchemaNode
{
    public function __construct()
    {
        $this->type = 'alert';
    }

    /** 标题 */
    public function title(string $title): static
    {
        return $this->set('title', $title);
    }

    /** 辅助说明文字 */
    public function description(string $description): static
    {
        return $this->set('description', $description);
    }

    /** 主题：success / info / warning / error */
    public function type(string $type): static
    {
        return $this->set('type', $type);
    }

    /** 是否可关闭 */
    public function closable(bool $closable = true): static
    {
        return $this->set('closable', $closable);
    }

    /** 是否显示图标 */
    public function showIcon(bool $showIcon = true): static
    {
        return $this->set('showIcon', $showIcon);
    }

    /** 文字是否居中 */
    public function center(bool $center = true): static
    {
        return $this->set('center', $center);
    }

    /** 风格：light / dark */
    public function effect(string $effect): static
    {
        return $this->set('effect', $effect);
    }
}
