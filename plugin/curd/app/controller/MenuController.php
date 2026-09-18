<?php
namespace plugin\curd\app\controller;

use plugin\curd\app\CurdDb;
use plugin\curd\app\model\CurdConfigs;
use plugin\curd\app\ModelRegistry;
use plugin\curd\app\RouteControllerRegistry;
use support\Request;

class MenuController
{
    /**
     * 标准 CURD 操作 → 权限节点（与宿主 make:menu-node 命令保持同一套约定）：
     * 容器节点 slug = curd.{Model}，操作节点 slug = curd.{Model}.{op}
     */
    protected const CURD_OPS = [
        'list'         => '列表',
        'add'          => '新增',
        'update'       => '编辑',
        'delete'       => '删除',
        'batch-delete' => '批量删除',
        'export'       => '导出',
    ];
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
        $menus = CurdDb::adminTable('menus')
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
     *    例如菜单 permission = curd.CompanyFuWuDan，只要用户拥有
     *    curd.CompanyFuWuDan（容器）或 curd.CompanyFuWuDan.list / .add 等任一粒度权限，
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
     * curd.Company 误匹配 curd.CompanyFuWuDan 这类情况）。
     *
     * 例：slug = curd.CompanyFuWuDan
     *   - 直接命中：can('curd.CompanyFuWuDan','list') 为真（含 admin 通配）。
     *   - 后代命中：用户任一权限 obj 的分段是 slug 分段的严格超集
     *     （如 curd.CompanyFuWuDan.list / .add / .export）。
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
        $menus = CurdDb::adminTable('menus')
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->toArray();

        $tree = $this->buildTree($menus);

        return json(['code' => 200, 'msg' => 'success', 'data' => $tree]);
    }

    /**
     * 添加菜单
     *
     * 除表单字段外还支持：
     *   gen_permissions = 1            勾选「一键生成权限」：在该菜单下自动生成操作权限节点（type=3）
     *   permission_ops  = list,add,... 要生成哪些操作（不传 = 全部标准操作）
     * 权限前缀（slug base）取值顺序：
     *   ① 表单里的「权限标识」
     *   ② 按路由路径自动推导 curd.{Model}（宿主注册的 CURD 控制器 / 配置生成器存的页面）
     * 推导不出来时不生成，并在响应里给出 warning（提示手填权限标识）。
     */
    public function add(Request $request)
    {
        $data = $request->post();
        $now  = date('Y-m-d H:i:s');

        $type = (int)($data['type'] ?? 1);
        $path = $type === 3 ? '' : ($data['path'] ?? '');

        $gen = filter_var($data['gen_permissions'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $base = trim((string)($data['permission'] ?? ''));
        $warning = '';

        // 勾了生成但没填权限标识 → 按路由自动推导（同时回填到菜单自身，与生成器/命令约定一致）
        if ($gen && $base === '') {
            $base = $this->suggestPermissionBase($path);
            if ($base === '') {
                $warning = '未能从路径识别到 CURD 模型，未生成权限节点；请手填「权限标识」后重试';
            }
        }

        $id = CurdDb::adminTable('menus')->insertGetId([
            'parent_id' => $data['parent_id'] ?? 0,
            'title'      => $data['title']      ?? '',
            'icon'       => $data['icon']       ?? '',
            'path'       => $path,
            'permission' => $base,
            'sort'       => $data['sort']       ?? 0,
            'type'       => $type,
            'visible'    => $data['visible']    ?? 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $created = [];
        if ($gen && $base !== '' && $type !== 3) {
            $created = $this->generatePermissionNodes((int)$id, $base, $this->normalizeOps($data['permission_ops'] ?? []));
        }

        return json([
            'code' => 200,
            'msg'  => $warning === '' ? '添加成功' : '添加成功（' . $warning . '）',
            'data' => ['id' => $id, 'permission' => $base, 'permissions' => $created, 'warning' => $warning],
        ]);
    }

    /**
     * 权限前缀推导建议（新建菜单勾选「一键生成权限」/ 行内「快速创建权限」时前端即时预览）
     *
     *   GET /api/menu/permission/suggest?path=/hf-goods
     *   GET /api/menu/permission/suggest?parent_id=7      ← 与 permissionQuick 同一套规则
     *
     * 传 parent_id 时按该菜单节点推导（permissionBaseOf），保证「预览 = 实际写入」；
     * 否则按路由路径推导（suggestPermissionBase）。
     *
     * @return \support\Response { base: 'curd.HfGoods', ops: [{op,title,permission}] }
     */
    public function permissionSuggest(Request $request)
    {
        $parentId = (int)$request->get('parent_id', 0);

        if ($parentId > 0) {
            $parent = CurdDb::adminTable('menus')->where('id', $parentId)->first();
            $base   = $parent ? $this->permissionBaseOf($parent) : '';
        } else {
            $base = $this->suggestPermissionBase((string)$request->get('path', ''));
        }

        return json(['code' => 200, 'msg' => 'ok', 'data' => [
            'base' => $base,
            'ops'  => $this->opsPreview($base),
        ]]);
    }

    /**
     * 快速创建权限节点（菜单管理行内按钮，只需要输入权限名）
     * POST /api/menu/permission/quick
     *   parent_id 所属菜单 id（必填）
     *   name      权限名（必填，如「审核」「audit」）；同时作为节点标题
     *   slug      权限标识后缀（可选，留空按权限名生成：ASCII 转小写-连字符，中文原样）
     *
     * 生成规则：permission = {父级权限前缀}.{slug}
     *   父级前缀优先取父菜单自身的 permission（如 curd.HfGoods），
     *   其次取父菜单已有子权限节点的公共前缀，最后按父菜单路径推导。
     */
    public function permissionQuick(Request $request)
    {
        $parentId = (int)$request->post('parent_id', 0);
        $name     = trim((string)$request->post('name', ''));
        $slugIn   = trim((string)$request->post('slug', ''));

        if ($parentId <= 0) {
            return json(['code' => 400, 'msg' => '请先选择所属菜单']);
        }
        if ($name === '') {
            return json(['code' => 400, 'msg' => '请输入权限名']);
        }

        $parent = CurdDb::adminTable('menus')->where('id', $parentId)->first();
        if (!$parent) {
            return json(['code' => 400, 'msg' => '所属菜单不存在']);
        }
        if ((int)$parent->type === 3) {
            return json(['code' => 400, 'msg' => '不能在权限节点下再建权限，请选择菜单或容器']);
        }

        $base = $this->permissionBaseOf($parent);
        $slug = $slugIn !== '' ? $this->normalizeSlug($slugIn) : $this->normalizeSlug($name);

        $permission = $base === '' ? $slug : $base . '.' . $slug;

        if (CurdDb::adminTable('menus')->where('permission', $permission)->exists()) {
            return json(['code' => 400, 'msg' => '权限标识已存在：' . $permission]);
        }

        $now = date('Y-m-d H:i:s');
        $id  = CurdDb::adminTable('menus')->insertGetId([
            'parent_id'  => $parentId,
            'title'      => $name,
            'icon'       => '',
            'path'       => '',
            'permission' => $permission,
            'sort'       => 0,
            'type'       => 3,
            'visible'    => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return json(['code' => 200, 'msg' => '权限已创建：' . $permission, 'data' => [
            'id'         => $id,
            'title'      => $name,
            'permission' => $permission,
        ]]);
    }

    /**
     * 批量生成操作权限节点；已存在的 slug 跳过
     *
     * @param array $ops 需要生成的操作 key（空数组 = 全部标准操作）
     * @return array 实际新增的节点列表
     */
    protected function generatePermissionNodes(int $parentId, string $base, array $ops): array
    {
        $now     = date('Y-m-d H:i:s');
        $created = [];

        foreach (self::CURD_OPS as $op => $title) {
            if ($ops && !in_array($op, $ops, true)) {
                continue;
            }
            $permission = $base . '.' . $op;
            if (CurdDb::adminTable('menus')->where('permission', $permission)->exists()) {
                continue;
            }
            $id = CurdDb::adminTable('menus')->insertGetId([
                'parent_id'  => $parentId,
                'title'      => $title,
                'icon'       => '',
                'path'       => '',
                'permission' => $permission,
                'sort'       => 0,
                'type'       => 3,   // 按钮/操作权限，不出现在侧边栏
                'visible'    => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $created[] = ['id' => $id, 'title' => $title, 'permission' => $permission];
        }

        return $created;
    }

    /**
     * 操作项预览（前端展示「将生成这些权限」）
     */
    protected function opsPreview(string $base): array
    {
        $out = [];
        foreach (self::CURD_OPS as $op => $title) {
            $out[] = [
                'op'         => $op,
                'title'      => $title,
                'permission' => $base === '' ? $op : $base . '.' . $op,
            ];
        }
        return $out;
    }

    /**
     * 归一化前端传来的操作清单：'list,add' / ['list','add'] / '' → 数组（空 = 全部）
     */
    protected function normalizeOps($raw): array
    {
        if (is_string($raw)) {
            $raw = array_filter(array_map('trim', explode(',', $raw)));
        }
        if (!is_array($raw) || !$raw) {
            return [];
        }
        $allow = array_keys(self::CURD_OPS);
        return array_values(array_intersect(array_map('strval', $raw), $allow));
    }

    /**
     * 从路由路径推导权限前缀：curd.{Model}
     *
     *   ① 宿主注册的 CURD 控制器（RouteControllerRegistry：route_path → 控制器 → model() → 模型短名）
     *   ② 配置生成器存库的页面（curd_configs.route_path → table_name → 模型短名）
     * 两条都取不到返回 ''（由调用方提示手填权限标识）。
     */
    protected function suggestPermissionBase(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        $path = '/' . ltrim(explode('?', $path)[0], '/');

        // ① 控制器注册表
        try {
            $controller = RouteControllerRegistry::get($path);
            if ($controller && class_exists($controller)) {
                $model = $this->modelShortName($controller);
                if ($model !== '') {
                    return 'curd.' . $model;
                }
            }
        } catch (\Throwable $e) {
            // 忽略，继续兜底
        }

        // ② 配置生成器写入 curd_configs 的页面
        try {
            $row = CurdConfigs::firstByRoutePath($path);
            if ($row) {
                $table = (string)($row->table_name ?? '');
                if ($table !== '') {
                    $model = ModelRegistry::modelByTable($table) ?: ModelRegistry::tableNameToModelName($table);
                    if ($model !== '') {
                        return 'curd.' . $model;
                    }
                }
            }
        } catch (\Throwable $e) {
            // 忽略
        }

        return '';
    }

    /**
     * 已有菜单节点的权限前缀：自身 permission → 子权限节点公共前缀 → 按路径推导
     */
    protected function permissionBaseOf($menu): string
    {
        $self = trim((string)($menu->permission ?? ''));
        if ($self !== '') {
            return $self;
        }

        // 子节点里已经有 curd.X.list 这类粒度权限 → 取公共前缀（兼容历史数据）
        $children = CurdDb::adminTable('menus')->where('parent_id', $menu->id)->pluck('permission')->toArray();
        $dotted = array_values(array_filter(array_map(
            fn($p) => (string)$p,
            $children
        ), fn($p) => str_contains($p, '.')));
        if ($dotted) {
            $first = explode('.', $dotted[0]);
            array_pop($first);
            if ($first) {
                return implode('.', $first);
            }
        }

        return $this->suggestPermissionBase((string)($menu->path ?? ''));
    }

    /**
     * 权限标识后缀归一化：ASCII 转小写 + 空格/下划线转连字符；中文等非 ASCII 原样保留
     */
    protected function normalizeSlug(string $raw): string
    {
        $s = trim($raw);
        if ($s === '') {
            return '';
        }
        $s = preg_replace('/[\s_]+/u', '-', $s);
        $s = preg_replace('/[^\p{L}\p{N}.\-]/u', '', (string)$s);
        // 纯 ASCII 部分统一小写（中文不受影响）
        return preg_replace_callback('/[A-Za-z0-9.\-]+/', fn($m) => strtolower($m[0]), (string)$s);
    }

    /**
     * 反射控制器取模型短名（与宿主 make:menu-node 的 resolveModelShort 同思路，但更耐操）
     *
     * 取值顺序（前两步都不实例化控制器、不执行任何业务代码）：
     *   ① ModelRegistry 启动期扫描登记的「模型 ↔ 控制器」映射
     *   ② 控制器声明的 protected string $modelClass = Xxx::class（宿主普遍用这种写法）
     *   ③ 控制器 model() 返回的模型类
     *   ④ Grid 里的模型实例（兜底，会执行 grid()，可能有查询）
     */
    protected function modelShortName(string $controllerClass): string
    {
        // ① 注册表反查（scanControllers 已在启动期登记）
        try {
            foreach (ModelRegistry::all() as $name => $class) {
                if (ModelRegistry::controller($name) === $controllerClass) {
                    return (string)$name;
                }
            }
        } catch (\Throwable $e) {
            // 忽略
        }

        try {
            $ref = new \ReflectionClass($controllerClass);

            // ② 声明式 $modelClass
            $defaults = $ref->getDefaultProperties();
            $declared = $defaults['modelClass'] ?? '';
            if (is_string($declared) && $declared !== '' && class_exists($declared)) {
                return basename(str_replace('\\', '/', $declared));
            }

            // ③ model() 返回模型类名
            if ($ref->hasMethod('model')) {
                $method = $ref->getMethod('model');
                $method->setAccessible(true);
                $model = $method->invoke($ref->newInstanceWithoutConstructor());
                if (is_object($model)) {
                    return (new \ReflectionClass($model))->getShortName();
                }
                if (is_string($model) && $model !== '') {
                    return basename(str_replace('\\', '/', $model));
                }
            }

            // ④ Grid 模型实例
            if ($ref->hasMethod('getGridModel')) {
                $instance = $ref->newInstanceWithoutConstructor();
                $gridModel = $instance->getGridModel();
                if (is_object($gridModel)) {
                    return (new \ReflectionClass($gridModel))->getShortName();
                }
            }
        } catch (\Throwable $e) {
            // 忽略，返回空由调用方提示手填
        }

        return '';
    }

    /**
     * 更新菜单
     *
     * 与 add() 一致，也支持 gen_permissions（对已建好的菜单补生成操作权限节点）：
     * 勾选后再存一次即可，已存在的权限会自动跳过（幂等）。
     */
    public function update(Request $request)
    {
        $data = $request->post();
        $id   = $data['id'] ?? 0;

        if (!$id) {
            return json(['code' => 400, 'msg' => 'ID不能为空']);
        }

        $row = CurdDb::adminTable('menus')->where('id', $id)->first();
        if (!$row) {
            return json(['code' => 400, 'msg' => '菜单不存在']);
        }

        $update = [];
        foreach (['parent_id', 'title', 'icon', 'path', 'permission', 'sort', 'type', 'visible'] as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = $data[$field];
            }
        }
        $type = (int)($update['type'] ?? $row->type);
        if ($type === 3) {
            $update['path'] = '';
        }

        // 一键生成权限：权限标识为空时先按路径推导并回填，再补生成缺失的操作节点
        $gen     = filter_var($data['gen_permissions'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $created = [];
        $warning = '';
        if ($gen && $type !== 3) {
            $base = trim((string)($update['permission'] ?? $row->permission ?? ''));
            if ($base === '') {
                $base = $this->suggestPermissionBase((string)($update['path'] ?? $row->path ?? ''));
                if ($base !== '') {
                    $update['permission'] = $base;
                }
            }
            if ($base === '') {
                $warning = '未能从路径识别到 CURD 模型，未生成权限节点；请手填「权限标识」后重试';
            } else {
                $created = $this->generatePermissionNodes(
                    (int)$id,
                    $base,
                    $this->normalizeOps($data['permission_ops'] ?? [])
                );
            }
        }

        $update['updated_at'] = date('Y-m-d H:i:s');

        CurdDb::adminTable('menus')->where('id', $id)->update($update);

        $msg = '更新成功';
        if ($warning !== '') {
            $msg .= '（' . $warning . '）';
        } elseif ($created) {
            $msg .= '，已生成 ' . count($created) . ' 个权限节点';
        }

        return json(['code' => 200, 'msg' => $msg, 'data' => ['permissions' => $created, 'warning' => $warning]]);
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
        $children = CurdDb::adminTable('menus')->where('parent_id', $id)->pluck('id')->toArray();
        foreach ($children as $childId) {
            $this->deleteRecursive($childId);
        }
        CurdDb::adminTable('role_permission')->where('permission_id', $id)->delete();
        CurdDb::adminTable('menus')->where('id', $id)->delete();
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
