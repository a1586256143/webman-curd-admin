<?php
namespace plugin\crud\app\controller;

use plugin\crud\app\CrudDb;
use support\Request;

class MenuController
{
    /**
     * 获取菜单列表（树形结构，仅可见，按用户权限过滤）
     *
     * 规则：
     *  - 菜单 permission 字段为空 → 所有人可见
     *  - 菜单 permission 非空 → 需要用户拥有 {permission}:list 权限才显示
     *  - admin 角色（拥有 *:* 权限）→ 看到所有菜单
     */
    public function index(Request $request)
    {
        $menus = CrudDb::adminTable('menus')
            ->where('visible', 1)
            ->where('type', '<>', 3) // type=3 为「按钮/操作权限」，不出现在侧边栏
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->toArray();

        // 先构建完整菜单树，再递归按权限过滤。
        // 不能先过滤平铺菜单，否则用户只拥有子菜单权限时，父级模块会因为
        // parent_id 找不到而无法构建出来。
        $tree = $this->buildTree($menus);
        $tree = $this->filterTreeByPermission($tree);

        return json(['code' => 200, 'msg' => 'success', 'data' => $tree]);
    }

    /**
     * 递归按权限过滤菜单树。
     *
     * 规则：
     * 1. 叶子菜单：permission 为空则公开；否则需要拥有 permission:list。
     * 2. 父级模块：只要过滤后仍有可见子菜单，就保留父级，即使父级自身没有权限。
     * 3. 父级自身有权限且没有子菜单时，也可以作为菜单项保留。
     * 4. 没有任何可见子菜单、且自身也无权限的模块，整个模块隐藏。
     */
    private function filterTreeByPermission(array $tree): array
    {
        $result = [];

        foreach ($tree as $node) {
            $children = $this->filterTreeByPermission($node['children'] ?? []);
            $hasPermission = $this->hasMenuPermission($node);
            $hasChildren = !empty($children);

            // 子菜单有权限时，保留当前父级模块并只返回有权限的子菜单。
            // 当前节点本身有权限时，即使没有子节点，也保留它。
            if ($hasPermission || $hasChildren) {
                if ($hasChildren) {
                    $node['children'] = $children;
                } else {
                    unset($node['children']);
                }
                $result[] = $node;
            }
        }

        return $result;
    }

    /**
     * 判断单个菜单节点自身是否可见。
     *
     * 规则：
     *  - permission 为空 → 公开菜单（叶子或无子节点时由调用方决定可见性）。
     *  - permission 非空 → 用户持有该 slug 权限，或持有其任意「后代」权限即视为可见。
     *    例如菜单 permission = crud.CompanyFuWuDan，只要用户拥有
     *    crud.CompanyFuWuDan（容器）或 crud.CompanyFuWuDan.list / .add 等任一粒度权限，
     *    该菜单即显示。这实现了「勾了 n 级权限，n-1 级（父）菜单自动显示」。
     */
    private function hasMenuPermission(array $menu): bool
    {
        $permission = trim((string)($menu['permission'] ?? ''));
        if ($permission === '') {
            // 无权限标识的叶子/模块作为公开菜单保留。
            // 父级最终是否展示仍由 filterTreeByPermission 的子树结果决定。
            return empty($menu['children']);
        }

        return $this->userHoldsPermissionOrDescendant($permission);
    }

    /**
     * 用户是否持有某权限 slug，或其任意后代权限（按 '.' 分段前缀匹配，避免
     * crud.Company 误匹配 crud.CompanyFuWuDan 这类情况）。
     *
     * 例：slug = crud.CompanyFuWuDan
     *   - 直接命中：can('crud.CompanyFuWuDan','list') 为真（含 admin 通配）。
     *   - 后代命中：用户任一权限 obj 的分段是 slug 分段的严格超集
     *     （如 crud.CompanyFuWuDan.list / .add / .export）。
     */
    private function userHoldsPermissionOrDescendant(string $slug): bool
    {
        // 1. 直接命中（can 已包含 casbin 通配 / admin 放行）
        if (can($slug, 'list')) {
            return true;
        }

        // 2. 后代命中：用户任一权限 obj 的分段以 slug 分段为前缀（且更长）
        $slugSegs = explode('.', $slug);
        $perms = current_user_permissions();
        foreach ($perms as $p) {
            $obj = (string)($p['obj'] ?? '');
            if ($obj === '') {
                continue;
            }
            $objSegs = explode('.', $obj);
            if (count($objSegs) <= count($slugSegs)) {
                continue;
            }
            $match = true;
            foreach ($slugSegs as $i => $seg) {
                if (($objSegs[$i] ?? null) !== $seg) {
                    $match = false;
                    break;
                }
            }
            if ($match) {
                return true;
            }
        }

        return false;
    }

    /**
     * 获取所有菜单（管理界面用，包含隐藏的，不过滤权限）
     */
    public function all(Request $request)
    {
        $menus = CrudDb::adminTable('menus')
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->toArray();

        $tree = $this->buildTree($menus);

        return json(['code' => 200, 'msg' => 'success', 'data' => $tree]);
    }

    /**
     * 添加菜单
     */
    public function add(Request $request)
    {
        $data = $request->post();
        $now  = date('Y-m-d H:i:s');

        $type = (int)($data['type'] ?? 1);
        $id = CrudDb::adminTable('menus')->insertGetId([
            'parent_id' => $data['parent_id'] ?? 0,
            'title'      => $data['title']      ?? '',
            'icon'       => $data['icon']       ?? '',
            'path'       => $type === 3 ? '' : ($data['path'] ?? ''),
            'permission' => $data['permission'] ?? '',
            'sort'       => $data['sort']       ?? 0,
            'type'       => $type,
            'visible'    => $data['visible']    ?? 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return json(['code' => 200, 'msg' => '添加成功', 'data' => ['id' => $id]]);
    }

    /**
     * 更新菜单
     */
    public function update(Request $request)
    {
        $data = $request->post();
        $id   = $data['id'] ?? 0;

        if (!$id) {
            return json(['code' => 400, 'msg' => 'ID不能为空']);
        }

        $update = [];
        foreach (['parent_id', 'title', 'icon', 'path', 'permission', 'sort', 'type', 'visible'] as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = $data[$field];
            }
        }
        if ((int)($update['type'] ?? CrudDb::adminTable('menus')->where('id', $id)->value('type')) === 3) {
            $update['path'] = '';
        }
        $update['updated_at'] = date('Y-m-d H:i:s');

        CrudDb::adminTable('menus')->where('id', $id)->update($update);

        return json(['code' => 200, 'msg' => '更新成功']);
    }

    /**
     * 删除菜单（同时删除子菜单）
     */
    public function delete(Request $request)
    {
        $id = $request->post('id');

        if (!$id) {
            return json(['code' => 400, 'msg' => 'ID不能为空']);
        }

        $this->deleteRecursive($id);

        return json(['code' => 200, 'msg' => '删除成功']);
    }

    /**
     * 递归删除菜单及其子菜单
     */
    private function deleteRecursive($id)
    {
        // 必须先取子节点，再删除当前节点；先删除会导致按 parent_id 查不到后代。
        $children = CrudDb::adminTable('menus')->where('parent_id', $id)->pluck('id')->toArray();
        foreach ($children as $childId) {
            $this->deleteRecursive($childId);
        }
        CrudDb::adminTable('role_permission')->where('permission_id', $id)->delete();
        CrudDb::adminTable('menus')->where('id', $id)->delete();
    }

    /**
     * 构建树形结构
     * $items: array of stdClass objects
     */
    private function buildTree(array $items, $parentId = 0)
    {
        $tree = [];
        foreach ($items as $item) {
            // $item 是 stdClass 对象
            if ($item->parent_id == $parentId) {
                $children = $this->buildTree($items, $item->id);
                $node = [
                    'id'         => $item->id,
                    'parent_id'  => $item->parent_id,
                    'title'      => $item->title,
                    'icon'       => $item->icon,
                    'path'       => $item->path,
                    'permission' => $item->permission ?? '',
                    'sort'       => $item->sort,
                    'type'       => $item->type,
                    'visible'    => $item->visible,
                ];
                if (!empty($children)) {
                    $node['children'] = $children;
                }
                $tree[] = $node;
            }
        }
        return $tree;
    }

}
