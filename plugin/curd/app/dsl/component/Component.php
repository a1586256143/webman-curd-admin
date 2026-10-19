<?php
namespace plugin\curd\app\dsl\component;

/**
 * 单元格内容组件基类（modal / expand 里展示的内容）
 *
 * 设计目标：服务端用 PHP 描述「弹窗/展开里要显示什么」，序列化成 JSON 交给前端渲染，
 * 前端零业务代码。内置三种：
 *
 *   new Html('<b>任意 HTML</b>')                       // 直接渲染 HTML
 *   new Table(['id' => 'ID', 'name' => '名称'], $rows)  // 表格
 *   new Card(['订单号' => 'A001', '金额' => 100], '订单详情')  // 键值卡片
 *
 * 返回值统一为 ['type' => 'html|table|card', ...]，前端 ComponentRenderer.vue 按 type 分发。
 */
abstract class Component
{
    /**
     * 序列化为前端可渲染的数组
     */
    abstract public function toArray(): array;

    /**
     * 把各种「内容」归一化成组件数组（供 Column::modal/expand 的返回值解析）
     *
     * 支持：Component 实例 / ['type'=>...] 数组 / 字符串（当 HTML）/ null
     */
    public static function resolve($content): ?array
    {
        if ($content === null || $content === '') {
            return null;
        }
        if ($content instanceof self) {
            return $content->toArray();
        }
        if (is_array($content)) {
            return isset($content['type']) ? $content : null;
        }
        if (is_scalar($content)) {
            return ['type' => 'html', 'html' => (string)$content];
        }
        return null;
    }
}
