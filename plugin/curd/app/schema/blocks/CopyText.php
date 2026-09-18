<?php

namespace plugin\curd\app\schema\blocks;

use plugin\curd\app\schema\SchemaNode;

/**
 * 可复制文本 → 前端 CopyTextNode（等宽展示 + 一键复制按钮）
 *
 * 用于 api_key / 密钥 / 回调地址 / 签名盐等「长串且需要复制」的展示场景。
 * 与 text 的区别：text 只是普通说明文字，本节点带复制交互与可选的脱敏开关。
 *
 * 用法：
 *   $card->copyText('{{info.api_key}}', 'Api Key');
 *   $card->copyText('{{info.callback}}', '回调地址')->mask(true);
 *
 * value 支持 {{key.path}} 占位符（页面 dataApi 注入）。
 * 复制实现前端优先 navigator.clipboard，失败降级 execCommand，
 * 因此 http 非 localhost 环境同样可用。
 */
class CopyText extends SchemaNode
{
    public function __construct()
    {
        $this->type = 'copy-text';
    }

    /** 文本内容（支持 {{key.path}} 占位符） */
    public function value(string $value): static
    {
        return $this->set('value', $value);
    }

    /** 标签（展示在文本上方的说明，可省略） */
    public function label(string $label): static
    {
        return $this->set('label', $label);
    }

    /** 等宽字体（默认 true，密钥类保持开启更易核对） */
    public function mono(bool $mono = true): static
    {
        return $this->set('mono', $mono);
    }

    /**
     * 默认脱敏展示（只留首尾若干位，配「眼睛」按钮切换明文）
     * 适合密钥出现在公共屏幕的场景；默认关闭。
     */
    public function mask(bool $mask = true): static
    {
        return $this->set('mask', $mask);
    }

    /** 脱敏时保留的首位字符数（默认 4） */
    public function maskHead(int $n): static
    {
        return $this->set('maskHead', $n);
    }

    /** 脱敏时保留的末位字符数（默认 4） */
    public function maskTail(int $n): static
    {
        return $this->set('maskTail', $n);
    }
}
