<?php
namespace plugin\crud\app\controller;

use plugin\crud\app\CrudDb;
use plugin\crud\app\rbac\Rbac;
use support\Request;

/**
 * 后台系统管理：用户 / 角色 / 权限
 *
 * 数据全部来自独立认证库（连接名 config('plugin.crud.crud.admin_connection')，默认 mysql），
 * 与业务库解耦。
 *
 * 注意：
 * - admin_users 只有 id,username,password,name,avatar,status,created_at,updated_at
 *   旧 inner.admin_users 的 email/phone/gender 等字段未迁移（认证表精简）
 * - roles / permissions 的唯一标识列是 slug
 * - 角色建立后自动写入 casbin 通配策略 p,slug,*,*，使其立即可用（与 rbac-init 一致）
 *
 * 路由：plugin/crud/config/route.php 注册 /api/admin/* 与 /api/config/site。
 * 若宿主项目自己注册了同名路由（宿主 config/route.php 加载在前），以宿主为准。
 */
class AdminController
{
    /**
     * 认证库连接
     */
    protected function db()
    {
        return CrudDb::adminDb();
    }

    /**
     * webman 的 ->get()->toArray() 返回的仍是 stdClass 对象数组，
     * 这里统一转成真正的关联数组，避免 "$u['id'] 当数组用" 报错。
     */
    protected function toArrays($collection)
    {
        return $collection->map(function ($row) {
            return (array)$row;
        })->toArray();
    }

    /**
     * 分页参数：兼容前端两种传法
     *  - 直传 ?page=1&size=10
     *  - 包裹 ?params[page]=1&params[size]=10
     */
    protected function pageParams(Request $request)
    {
        $params = $request->get('params', []);
        $params = is_array($params) ? $params : [];
        $page = isset($params['page']) ? (int)$params['page'] : (int)$request->get('page', 1);
        $size = isset($params['size']) ? (int)$params['size'] : (int)$request->get('size', 10);
        return [$page, $size];
    }

    // ============================================================
    // 个人中心
    // ============================================================

    /**
     * 站点信息（前端侧边栏/登录页/浏览器标签读取）
     * 免鉴权接口：登录页未登录时也要显示标题
     *
     * 配置来源：config('plugin.crud.crud.site') 优先；为空时回退 config('admin.site')，
     * 保证已按旧约定配置站点的宿主项目行为不变。
     */
    public function siteConfig(Request $request)
    {
        $site = config('plugin.crud.crud.site', []);
        $site = is_array($site) && $site ? $site : config('admin.site', []);
        $site = is_array($site) ? $site : [];
        return json([
            'code' => 200,
            'msg' => 'success',
            'data' => array_merge([
                'title'     => 'Webman Admin',
                'logo'      => 'Monitor',
                'logo_type' => 'icon',
                'copyright' => '',
                'favicon'   => '',
            ], $site),
        ]);
    }

    /**
     * 当前用户资料
     */
    public function profile(Request $request)
    {
        $user = $request->user;
        if (!$user) {
            return json(['code' => 401, 'msg' => '未登录']);
        }

        $fields = ['id', 'username', 'name', 'avatar', 'status', 'created_at', 'updated_at'];
        if ($this->hasColumn('email')) {
            $fields[] = 'email';
        }
        $profile = (array)$this->db()->table('admin_users')->where('id', $user->id)->first($fields);
        $profile['email'] = $profile['email'] ?? '';
        unset($profile['password']);

        return json(['code' => 200, 'msg' => 'success', 'data' => $profile]);
    }

    /**
     * 更新当前用户资料（用户名不可修改）
     */
    public function profileUpdate(Request $request)
    {
        $user = $request->user;
        if (!$user) {
            return json(['code' => 401, 'msg' => '未登录']);
        }

        $data = $request->post();
        $row = [];
        if (array_key_exists('name', $data)) {
            $row['name'] = trim((string)$data['name']);
        }
        if (array_key_exists('avatar', $data)) {
            $row['avatar'] = trim((string)$data['avatar']);
        }
        if (array_key_exists('email', $data) && $this->hasColumn('email')) {
            $row['email'] = trim((string)$data['email']);
        }
        if (!$row) {
            return json(['code' => 400, 'msg' => '没有需要更新的内容']);
        }

        $row['updated_at'] = date('Y-m-d H:i:s');
        $this->db()->table('admin_users')->where('id', $user->id)->update($row);
        return json(['code' => 200, 'msg' => '资料更新成功']);
    }

    /**
     * 修改当前用户密码
     */
    public function passwordUpdate(Request $request)
    {
        $user = $request->user;
        if (!$user) {
            return json(['code' => 401, 'msg' => '未登录']);
        }

        $oldPassword = (string)$request->post('old_password', '');
        $newPassword = (string)$request->post('new_password', '');
        $confirmPassword = (string)$request->post('confirm_password', '');
        if (!$oldPassword || !$newPassword || !$confirmPassword) {
            return json(['code' => 400, 'msg' => '请完整填写密码信息']);
        }
        if (strlen($newPassword) < 6) {
            return json(['code' => 400, 'msg' => '新密码至少需要 6 位']);
        }
        if ($newPassword !== $confirmPassword) {
            return json(['code' => 400, 'msg' => '两次输入的新密码不一致']);
        }
        if (!password_verify($oldPassword, $user->password)) {
            return json(['code' => 400, 'msg' => '原密码错误']);
        }

        $this->db()->table('admin_users')->where('id', $user->id)->update([
            'password' => password_hash($newPassword, PASSWORD_BCRYPT),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        // 密码变更后撤销该用户所有会话，避免旧 token 继续使用。
        $this->db()->table('admin_tokens')->where('admin_user_id', $user->id)->delete();
        try {
            \support\Redis::del('user_roles:' . $user->id);
        } catch (\Throwable $e) {
            // Redis 不可用时不影响密码已更新的结果。
        }
        return json(['code' => 200, 'msg' => '密码修改成功，请重新登录']);
    }

    protected function hasColumn(string $column): bool
    {
        try {
            return $this->db()->getSchemaBuilder()->hasColumn('admin_users', $column);
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ============================================================
    // 用户管理
    // ============================================================

    /**
     * 用户列表
     */
    public function users(Request $request)
    {
        $q = $this->db()->table('admin_users');

        if ($username = $request->get('username')) {
            $q->where('username', 'like', "%{$username}%");
        }
        if ($name = $request->get('name')) {
            $q->where('name', 'like', "%{$name}%");
        }
        if (($status = $request->get('status')) !== null && $status !== '') {
            $q->where('status', (int)$status);
        }

        [$page, $size] = $this->pageParams($request);
        $total = $q->count();
        $rows = $this->toArrays(
            $q->orderBy('id')
                ->forPage($page, $size)
                ->select('id', 'username', 'name', 'avatar', 'status', 'created_at', 'updated_at')
                ->get()
        );

        // 附加角色
        $ids = array_column($rows, 'id');
        $roleMap = [];
        if ($ids) {
            $links = $this->db()->table('admin_role_user')
                ->leftJoin('roles', 'roles.id', '=', 'admin_role_user.role_id')
                ->whereIn('admin_role_user.admin_user_id', $ids)
                ->select('admin_role_user.admin_user_id as uid', 'roles.id as rid', 'roles.name as rname', 'roles.slug as rslug')
                ->get();
            foreach ($links as $lk) {
                $lk = (array)$lk;
                $roleMap[$lk['uid']]['ids'][]   = (int)$lk['rid'];
                $roleMap[$lk['uid']]['names'][] = $lk['rname'];
            }
        }

        foreach ($rows as &$u) {
            $u['roles']      = $roleMap[$u['id']]['ids']   ?? [];
            $u['role_names'] = $roleMap[$u['id']]['names'] ?? [];
            $u['role_ids']   = $u['roles']; // 供编辑表单回显
        }

        return json(['code' => 200, 'msg' => 'success', 'data' => $rows, 'total' => $total]);
    }

    /**
     * 新增用户
     */
    public function userAdd(Request $request)
    {
        $data = $request->post();
        if (empty($data['username']) || empty($data['password'])) {
            return json(['code' => 400, 'msg' => '用户名和密码不能为空']);
        }
        $exists = $this->db()->table('admin_users')->where('username', $data['username'])->exists();
        if ($exists) {
            return json(['code' => 400, 'msg' => '用户名已存在']);
        }

        $now = date('Y-m-d H:i:s');
        $row = [
            'username'   => $data['username'],
            'password'   => password_hash($data['password'], PASSWORD_BCRYPT),
            'name'       => $data['name'] ?? '',
            'avatar'     => $data['avatar'] ?? '',
            'status'     => isset($data['status']) ? (int)$data['status'] : 1,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $uid = $this->db()->table('admin_users')->insertGetId($row);

        $this->syncUserRoles($uid, $data['role_ids'] ?? []);

        return json(['code' => 200, 'msg' => '新增成功', 'data' => ['id' => $uid]]);
    }

    /**
     * 更新用户
     */
    public function userUpdate(Request $request)
    {
        $id = (int)$request->post('id', 0);
        if (!$id) {
            return json(['code' => 400, 'msg' => '缺少用户ID']);
        }
        $data = $request->post();
        $row = [];
        if (array_key_exists('name', $data))    $row['name']    = $data['name'];
        if (array_key_exists('avatar', $data))  $row['avatar']  = $data['avatar'];
        if (array_key_exists('status', $data))  $row['status']  = (int)$data['status'];
        if (!empty($data['password']))           $row['password'] = password_hash($data['password'], PASSWORD_BCRYPT);

        if ($row) {
            $row['updated_at'] = date('Y-m-d H:i:s');
            $this->db()->table('admin_users')->where('id', $id)->update($row);
        }

        if (array_key_exists('role_ids', $data)) {
            $this->syncUserRoles($id, $data['role_ids'] ?? []);
        }

        return json(['code' => 200, 'msg' => '更新成功']);
    }

    /**
     * 删除用户（同时清理 token / 角色关联 / casbin g 规则）
     */
    public function userDelete(Request $request)
    {
        $id = $request->post('id');
        if (!$id) {
            return json(['code' => 400, 'msg' => '缺少用户ID']);
        }
        $this->db()->table('admin_users')->where('id', $id)->delete();
        $this->db()->table('admin_tokens')->where('admin_user_id', $id)->delete();
        $this->db()->table('admin_role_user')->where('admin_user_id', $id)->delete();
        try {
            Rbac::enforcer()->deleteRolesForUser((string)$id);
        } catch (\Throwable $e) { /* ignore */ }

        return json(['code' => 200, 'msg' => '删除成功']);
    }

    /**
     * 批量删除用户
     */
    public function userBatchDelete(Request $request)
    {
        $ids = $request->post('ids', []);
        if (!is_array($ids) || !$ids) {
            return json(['code' => 400, 'msg' => '缺少ID列表']);
        }
        foreach ($ids as $id) {
            $this->db()->table('admin_users')->where('id', $id)->delete();
            $this->db()->table('admin_tokens')->where('admin_user_id', $id)->delete();
            $this->db()->table('admin_role_user')->where('admin_user_id', $id)->delete();
            try {
                Rbac::enforcer()->deleteRolesForUser((string)$id);
            } catch (\Throwable $e) { /* ignore */ }
        }
        return json(['code' => 200, 'msg' => '批量删除成功']);
    }

    /**
     * 同步用户-角色关联 + casbin g 规则
     */
    protected function syncUserRoles($userId, array $roleIds)
    {
        $userId = (int)$userId;
        // 清除旧关联与 g 规则
        $this->db()->table('admin_role_user')->where('admin_user_id', $userId)->delete();
        try {
            Rbac::enforcer()->deleteRolesForUser((string)$userId);
        } catch (\Throwable $e) { /* ignore */ }

        $roleIds = array_unique(array_filter(array_map('intval', $roleIds)));
        if (!$roleIds) {
            return;
        }
        $slugs = $this->db()->table('roles')->whereIn('id', $roleIds)->pluck('slug')->toArray();
        $insert = [];
        foreach ($roleIds as $rid) {
            $insert[] = ['admin_user_id' => $userId, 'role_id' => $rid];
        }
        if ($insert) {
            $this->db()->table('admin_role_user')->insert($insert);
        }
        foreach ($slugs as $slug) {
            try {
                Rbac::enforcer()->addRoleForUser((string)$userId, $slug);
            } catch (\Throwable $e) { /* ignore */ }
        }
    }

    // ============================================================
    // 角色管理
    // ============================================================

    /**
     * 角色列表
     */
    public function roles(Request $request)
    {
        $q = $this->db()->table('roles');
        if ($name = $request->get('name')) {
            $q->where('name', 'like', "%{$name}%");
        }
        if ($slug = $request->get('slug')) {
            $q->where('slug', 'like', "%{$slug}%");
        }

        [$page, $size] = $this->pageParams($request);
        $total = $q->count();
        $rows = $this->toArrays(
            $q->orderBy('id')
                ->forPage($page, $size)
                ->get()
        );

        $ids = array_column($rows, 'id');
        $permMap = [];
        if ($ids) {
            $links = $this->db()->table('role_permission')
                ->leftJoin('menus', 'menus.id', '=', 'role_permission.permission_id')
                ->whereIn('role_permission.role_id', $ids)
                ->select('role_permission.role_id as rid', 'menus.id as pid', 'menus.title as pname')
                ->get();
            foreach ($links as $lk) {
                $lk = (array)$lk;
                $permMap[$lk['rid']]['ids'][]   = (int)$lk['pid'];
                $permMap[$lk['rid']]['names'][] = $lk['pname'];
            }
        }

        foreach ($rows as &$r) {
            $r['permissions'] = $permMap[$r['id']]['ids']   ?? [];
            $r['permission_names'] = $permMap[$r['id']]['names'] ?? [];
            $r['permission_ids'] = $r['permissions']; // 供编辑表单回显
        }

        return json(['code' => 200, 'msg' => 'success', 'data' => $rows, 'total' => $total]);
    }

    /**
     * 新增角色
     */
    public function roleAdd(Request $request)
    {
        $data = $request->post();
        if (empty($data['name']) || empty($data['slug'])) {
            return json(['code' => 400, 'msg' => '角色名称和标识不能为空']);
        }
        $slug = $data['slug'];
        if ($this->db()->table('roles')->where('slug', $slug)->exists()) {
            return json(['code' => 400, 'msg' => '角色标识已存在']);
        }
        $now = date('Y-m-d H:i:s');
        $rid = $this->db()->table('roles')->insertGetId([
            'name'       => $data['name'],
            'slug'       => $slug,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->syncRolePermissions($rid, $data['permission_ids'] ?? []);

        return json(['code' => 200, 'msg' => '新增成功', 'data' => ['id' => $rid]]);
    }

    /**
     * 更新角色
     */
    public function roleUpdate(Request $request)
    {
        $id = (int)$request->post('id', 0);
        if (!$id) {
            return json(['code' => 400, 'msg' => '缺少角色ID']);
        }
        $data = $request->post();
        $row = [];
        if (array_key_exists('name', $data)) $row['name'] = $data['name'];
        if (array_key_exists('description', $data)) $row['description'] = $data['description'];
        if ($row) {
            $row['updated_at'] = date('Y-m-d H:i:s');
            $this->db()->table('roles')->where('id', $id)->update($row);
        }
        if (array_key_exists('permission_ids', $data)) {
            $this->syncRolePermissions($id, $data['permission_ids'] ?? []);
        }
        return json(['code' => 200, 'msg' => '更新成功']);
    }

    /**
     * 删除角色（清理用户关联 / casbin g / p 规则）
     */
    public function roleDelete(Request $request)
    {
        $id = $request->post('id');
        if (!$id) {
            return json(['code' => 400, 'msg' => '缺少角色ID']);
        }
        $slug = $this->db()->table('roles')->where('id', $id)->value('slug');
        $this->db()->table('roles')->where('id', $id)->delete();
        $this->db()->table('admin_role_user')->where('role_id', $id)->delete();
        $this->db()->table('role_permission')->where('role_id', $id)->delete();
        if ($slug) {
            try {
                // 清除该角色下所有用户的 g 规则
                $userIds = $this->db()->table('admin_role_user')
                    ->where('role_id', $id)->pluck('admin_user_id')->toArray();
                // 上面已删除 admin_role_user，从 casbin 直接清 g
                $enforcer = Rbac::enforcer();
                foreach ($enforcer->getUsersForRole($slug) as $u) {
                    $enforcer->deleteRoleForUser($u, $slug);
                }
                $enforcer->deletePermission($slug, '*', '*');
            } catch (\Throwable $e) { /* ignore */ }
        }
        return json(['code' => 200, 'msg' => '删除成功']);
    }

    /**
     * 同步角色-权限关联 + casbin 策略
     *
     * 规则：
     *  - role_permission 表记录角色「显式勾选」了哪些权限（界面展示用，保持用户原始选择）。
     *    勾选管理容器（如 服务单管理）时 el-tree 会把其下操作路由一并写入 checkedKeys，
     *    取消某一个操作后该操作不会出现在勾选集合里 → 实现「精准到操作」的控制。
     *  - casbin_rule 表记录角色对哪些资源有操作权限（鉴权用）。
     *  - 向上展开：把勾选的权限 ID 连同其所有祖先（parent_id 链）的 slug 一起写入 casbin，
     *    即 勾选的操作路由 + 其所属管理容器 都会写入 p,roleSlug,permSlug,*。
     *    → 勾选容器即自动获得其下所有操作（因为它们都在勾选集合里）；
     *      单独取消某操作则只有其余操作 + 容器写入，被取消的操作无策略 → 403。
     *    → 容器 slug(crud.{Model}) 写入后，菜单 can(crud.{Model}) 可见。
     *  - 无权限时不写任何策略 → 该角色用户看不到任何受保护菜单
     */
    protected function syncRolePermissions($roleId, array $permIds)
    {
        $roleId = (int)$roleId;

        // 1. 更新关联表（仅存用户显式勾选的权限）
        $this->db()->table('role_permission')->where('role_id', $roleId)->delete();
        $permIds = array_unique(array_filter(array_map('intval', $permIds)));
        if ($permIds) {
            $insert = [];
            foreach ($permIds as $pid) {
                $insert[] = ['role_id' => $roleId, 'permission_id' => $pid];
            }
            $this->db()->table('role_permission')->insert($insert);
        }

        // 2. 同步 casbin 策略（选中节点 + 其所有祖先容器 向上展开）
        $slug = $this->db()->table('roles')->where('id', $roleId)->value('slug');
        if (!$slug) return;

        try {
            $enforcer = Rbac::enforcer();

            // 清除该角色旧的所有 p 策略（保留 g 角色继承不动）
            $oldPerms = $enforcer->getPermissionsForUser($slug);
            foreach ($oldPerms as $p) {
                $enforcer->removePolicy(...$p);
            }

            // 没有任何勾选 → 不写入任何策略（该角色无菜单可见）
            if (!$permIds) {
                return;
            }

            // 向上展开：选中节点 + 所有祖先容器 的 slug 集合
            $permSlugs = $this->collectPermissionSlugsUp($permIds);

            foreach ($permSlugs as $permSlug) {
                $enforcer->addPolicy($slug, $permSlug, '*');
            }
        } catch (\Throwable $e) { /* ignore */ }
    }

    /**
     * 把勾选的权限 ID 连同其所有祖先（parent_id 链）的 slug 收集起来。
     * 用于「勾选操作路由 → 自动带上其管理容器（菜单可见）」，且不会把未勾选的兄弟节点算进来。
     */
    protected function collectPermissionSlugsUp(array $ids): array
    {
        $ids = array_unique(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        $slugs = [];
        $visited = [];
        $queue = array_values($ids);
        while ($queue) {
            $id = (int)array_pop($queue);
            if ($id <= 0 || isset($visited[$id])) {
                continue;
            }
            $visited[$id] = true;
            $perm = $this->db()->table('menus')->where('id', $id)->first();
            if (!$perm) {
                continue;
            }
            if ($perm->permission !== '') {
                $slugs[$perm->permission] = true;
            }
            if ((int)$perm->parent_id > 0) {
                $queue[] = (int)$perm->parent_id;
            }
        }
        return array_keys($slugs);
    }

    /**
     * 把一组权限 ID 按 parent_id 递归展开为「自身 + 所有子孙」的完整 ID 集合。
     * 用于角色权限继承：勾选父节点即自动授予其下全部子节点。
     */
    protected function expandPermissionIds(array $ids): array
    {
        $ids = array_unique(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        $all = $ids;
        $frontier = $ids;
        // 最多递归 20 层，防止异常数据导致死循环
        for ($depth = 0; $depth < 20 && $frontier; $depth++) {
            $children = $this->db()->table('menus')
                ->whereIn('parent_id', $frontier)
                ->pluck('id')
                ->toArray();
            $children = array_map('intval', $children);
            $new = array_diff($children, $all);
            if (!$new) {
                break;
            }
            $all = array_merge($all, $new);
            $frontier = $new;
        }
        return array_values(array_unique($all));
    }

    // ============================================================
    // 权限已合并进 menus 表
    // 菜单(type=1/2) 与 操作权限(type=3 按钮) 统一在「菜单管理」页维护；
    // 角色授权树由 /api/menu/all 提供（见 MenuController::all）。
    // ============================================================

    // ============================================================
    // 远程下拉数据源（供表单 select 使用）
    // ============================================================

    /**
     * 角色选项 [{value:id, label:name}]
     */
    public function roleOptions(Request $request)
    {
        $rows = $this->toArrays($this->db()->table('roles')->orderBy('id')->get(['id', 'name']));
        $list = array_map(fn($r) => ['value' => $r['id'], 'label' => $r['name']], $rows);
        return json(['code' => 200, 'msg' => 'success', 'data' => $list]);
    }

    /**
     * 权限选项（菜单树选项）：由 /api/menu/all 提供，前端菜单管理页自用，无需后端冗余接口。
     */
}
