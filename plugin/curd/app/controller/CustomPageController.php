<?php
namespace plugin\curd\app\controller;

use plugin\curd\app\schema\PageRegistry;
use support\Request;

/**
 * 自定义页面接口（插件通用能力）
 *
 * 页面分两类，按 name 解析顺序：先查插件 PageRegistry（PHP Schema 页面，
 * 返回 format=schema 的组件树 JSON），再查宿主业务 .vue 文件目录
 * <宿主根>/app/custom/pages/<name>.vue（返回 format=vue 的源码文本）。
 *
 *  - Schema 页面：业务侧用 PageRegistry::register() 声明（页面类通常放
 *    业务 app/controller/admin/api 下，如 HomePage），前端 SchemaRenderer
 *    递归渲染（组件套组件 + 24 栏栅格 + dataApi 数据注入）。
 *  - Vue 页面：业务 app/custom/pages/*.vue，前端 dynamicSfc.js 运行时编译渲染。
 *
 * 访问 /custom-page/<name> 由前端 catch-all 路由命中并加载。
 *
 * 路由（GET /api/custom/pages、GET /api/custom/page）注册在插件
 * plugin/curd/config/route.php，随插件自动加载；/api/custom/* 仅登录即可。
 */
class CustomPageController
{
    /**
     * 宿主业务 .vue 页面目录约定：<项目根>/app/custom/pages
     * （.vue 属于业务资产，插件只负责读取分发）
     */
    private function pageDir(): string
    {
        // 宿主业务 .vue 页面目录约定，随 config/plugin/curd/curd.php 可调
        return (string)config('plugin.curd.curd.vue_pages_dir', base_path() . '/app/custom/pages');
    }

    /**
     * 页面清单 GET /api/custom/pages
     * 返回相对 name 数组（不带 .vue），按路径排序，便于调试/选择器展示。
     */
    public function pages(Request $request)
    {
        $files = $this->scanPages($this->pageDir());
        return json(['code' => 200, 'msg' => 'ok', 'data' => $files]);
    }

    /**
     * 页面内容 GET /api/custom/page?name=home
     * 返回 data：Schema 页面 { format:'schema', schema:{...} }；.vue 页面 { format:'vue', content, mtime }。
     * 解析顺序：PageRegistry 注册表 → 宿主 app/custom/pages/*.vue 文件；都找不到返回 404。
     */
    public function content(Request $request)
    {
        $name = (string)$request->get('name', '');
        $name = trim($name, '/');
        if ($name === '' || !preg_match('#^[A-Za-z0-9_][A-Za-z0-9_\-/]*$#', $name)) {
            return json(['code' => 400, 'msg' => '页面名不合法（仅允许字母数字下划线横线斜杠）']);
        }

        // ① Schema 页面（业务侧经 PageRegistry::register 声明，如 home → HomePage::schema()）
        $schemaPage = PageRegistry::find($name);
        if ($schemaPage !== null) {
            return json(['code' => 200, 'msg' => 'ok', 'data' => [
                'name'   => $name,
                'format' => 'schema',
                'schema' => $schemaPage->toArray(),
            ]]);
        }

        // ② .vue 文件页面（宿主业务目录 <根>/app/custom/pages/<name>.vue）
        $dir = realpath($this->pageDir());
        $file = realpath($this->pageDir() . '/' . $name . '.vue');
        if ($dir === false || $file === false || !str_starts_with($file, $dir . DIRECTORY_SEPARATOR)) {
            return json(['code' => 404, 'msg' => '自定义页面不存在: ' . $name]);
        }
        if (!is_file($file)) {
            return json(['code' => 404, 'msg' => '自定义页面不存在: ' . $name]);
        }

        $content = file_get_contents($file);
        if ($content === false) {
            return json(['code' => 500, 'msg' => '读取页面文件失败']);
        }

        return json(['code' => 200, 'msg' => 'ok', 'data' => [
            'name'    => $name,
            'format'  => 'vue',
            'content' => $content,
            'mtime'   => filemtime($file),
        ]]);
    }

    /**
     * 递归扫描目录下的 .vue 文件，返回相对 name（去 .vue 后缀）
     */
    private function scanPages(string $dir): array
    {
        $out = [];
        if (!is_dir($dir)) {
            return $out;
        }
        $items = scandir($dir);
        foreach ($items as $it) {
            if ($it === '.' || $it === '..' || str_starts_with($it, '.')) {
                continue;
            }
            $full = $dir . '/' . $it;
            if (is_dir($full)) {
                foreach ($this->scanPages($full) as $sub) {
                    $out[] = $it . '/' . $sub;
                }
            } elseif (is_file($full) && str_ends_with($it, '.vue')) {
                $out[] = substr($it, 0, -4);
            }
        }
        sort($out, SORT_STRING);
        return $out;
    }
}
