<?php
namespace plugin\curd\app\dsl\component;

/**
 * 原始 HTML 组件（弹窗 / 行内展开里用）
 *
 *   new Html('<div class="tip">任意 HTML，<b>不转义</b>渲染</div>')
 *
 * ⚠️ 内容直接 v-html 渲染：只放自己拼的可信内容，不要拼用户输入。
 */
class Html extends Component
{
    protected string $html;

    public function __construct(string $html = '')
    {
        $this->html = $html;
    }

    public function toArray(): array
    {
        return ['type' => 'html', 'html' => $this->html];
    }
}
