<?php

namespace plugin\curd\app\schema;

use plugin\curd\app\schema\blocks\HasBlocks;

/**
 * 页面 Schema（页面根）
 *
 * 一个页面 = title + dataApi（页面级数据接口，渲染前请求）+ body（组件树）。
 * body 顶层通常是 row/col 栅格；任意层级都支持组件套组件。
 *
 * 用法：
 *   $page = new PageSchema('首页');
 *   $page->api('stats', '/api/schema/home/stats');          // 数据绑定：dataApi
 *   $page->row(function (Row $row) { ... });                 // 栅格布局
 *   return $page;                                            // 控制器/页面文件返回后 toArray()
 */
class PageSchema extends SchemaNode
{
    use HasBlocks;

    protected array $dataApi = [];

    public function __construct(?string $title = null)
    {
        $this->type = 'page';
        if ($title !== null) {
            $this->title($title);
        }
    }

    /** 页面标题 */
    public function title(string $title): static
    {
        return $this->set('title', $title);
    }

    /**
     * 声明一个页面级数据接口（渲染前由前端并发请求，结果以 {{key.path}} 注入）。
     *
     * @param string $key    数据键（占位符前缀，如 'stats' → {{stats.today_amount}}）
     * @param string $url    接口路径（走统一加密请求通道）
     * @param string $method 请求方法（默认 GET）
     * @param array  $params 固定查询参数（仅 GET 生效时随密文一起上送）
     */
    public function api(string $key, string $url, string $method = 'get', array $params = []): static
    {
        $item = ['url' => $url];
        if (strtolower($method) !== 'get') {
            $item['method'] = strtolower($method);
        }
        if ($params) {
            $item['params'] = $params;
        }
        $this->dataApi[$key] = $item;
        return $this;
    }

    public function toArray(): array
    {
        return [
            'title'   => $this->props['title'] ?? '',
            'dataApi' => $this->dataApi,
            'body'    => array_map(static fn (SchemaNode $c) => $c->toArray(), $this->children),
        ];
    }
}
