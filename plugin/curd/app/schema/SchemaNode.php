<?php

namespace plugin\curd\app\schema;

/**
 * Schema 节点基类
 *
 * 所有「页面展示组件」的 PHP 表达，最终统一 toArray() 输出为
 * 前端 SchemaRenderer 可渲染的 JSON：{ type, props?, children? }。
 *
 * 说明：
 *  - type    = 组件注册表中的节点类型（row/col/card/statistic/...），前端白名单驱动渲染；
 *  - props   = 传给 Element Plus 组件的属性（键名走 EP 的 camelCase）；
 *  - children= 子节点（组件套组件：col 里放 card，card 里放 statistic/divider/alert...）。
 *  - props 里的字符串支持 {{key.path}} 占位符，渲染前由页面 dataApi 数据注入替换。
 */
abstract class SchemaNode
{
    protected string $type = '';
    protected array $props = [];
    protected array $children = [];

    public function set(string $key, $value): static
    {
        $this->props[$key] = $value;
        return $this;
    }

    /** 一次合并多个 props */
    public function raw(array $props): static
    {
        $this->props = array_merge($this->props, $props);
        return $this;
    }

    /** 挂载子节点 */
    public function push(SchemaNode $child): static
    {
        $this->children[] = $child;
        return $this;
    }

    /** 序列化为前端 Schema JSON */
    public function toArray(): array
    {
        $arr = ['type' => $this->type];
        if ($this->props) {
            $arr['props'] = $this->props;
        }
        if ($this->children) {
            $arr['children'] = array_map(static fn (SchemaNode $c) => $c->toArray(), $this->children);
        }
        return $arr;
    }
}
