# 排错速查与 FAQ

按现象查。**第一条永远是**：配置和 PHP 改动都需要 `restart`（webman 常驻内存，热改不生效）。

---

## 1. 安装 / 初始化

| 现象 | 原因 / 解法 |
|---|---|
| 装完 `plugin/curd` 不存在 | 自动拷贝未触发（宿主 `composer.json` 缺 `post-package-install` scripts 或 `support/Plugin.php`）。补 scripts 后 `composer dump-autoload && composer update amcolin/webman-curd-admin`；或直接 `cp -r vendor/amcolin/webman-curd-admin/plugin/curd plugin/curd` |
| `Failed to audit installed packages` 红字 | audit 硬编码访问 `packagist.org` 被墙，**`exit 0`、不影响安装**。用包内 wrapper 消除：`alias composer='<包目录>/scripts/composer.sh'` |
| 向导页面显示「系统已安装」 | `runtime/curd-installed.lock` 存在。重装 = 删锁 + 清库后刷新向导页 |
| `/api/curd/config?route_path=/my-test` 返回 404「配置不存在」 | 没跑安装向导 / `install.php`，`curd_configs` 种子未插。执行初始化流程，或在后台「配置生成器」手动生成该页面 |
| `/api/auth/captcha` 返回「验证码生成失败：Class "Webman\Captcha\..." not found」 | 宿主缺 `webman/captcha`（包已 require，旧宿主补装）或缺 `ext-gd` / `ext-mbstring`。不想要验证码：`'captcha_enabled' => false` 后重启 |
| 报 `Class "support\Redis" not found` | 缺 `webman/redis`：`composer require webman/redis`。（包已 require；Redis 不可用时 Auth / RBAC / AuthCheck / AdminController 已 `try/catch` 降级直查 DB，不影响登录鉴权） |
| 「测试管理」菜单 / `my_test` 表不出现 | 老项目没跑过新版 install。执行一次 `php plugin/curd/install.php`（幂等，补表 + 补菜单）；不想要示例就跳过，不影响安装结果 |
| 业务表没建出来 | `--business-sql` 没传（默认只装认证面 8 张表），或路径不是相对宿主根。多文件可重复传或逗号分隔 |
| 装完登录报 `1045 Access denied` | Web 向导场景已**自动 `SIGUSR1` 平滑 reload**，一般不会出现；Windows / supervisor 等**信号不可用**环境按页面提示手动 `php start.php restart`（worker 配置在启动期固化）。CLI 安装后同样重启一次 |

---

## 2. 启动 / 运行

| 现象 | 原因 / 解法 |
|---|---|
| 改 PHP 类 / 常量 / 配置后不生效 | webman 常驻内存：reload 不重读类文件，改 PHP 要 `restart`（改 `.env` / config 可用 `SIGUSR1` 平滑 reload） |
| 端口不是 8787 | webman 2.x 端口在 `config/process.php` 的 `listen`，改这里再重启 |
| 后台页面 404 / 静态资源 404 | /app/curd/` 没被正确反代（Nginx 见 [升级 · 部署 · 发布](15-升级与生产部署.md)），或 `page_base` 与前端 `VITE_BASE_PATH` 不一致（两者必须相同，改完要重建前端） |
| SPA 深链（`/app/curd/dashboard`）404 | 反代没把未知路径回退到 `index.php`（`PageController` 只负责把请求交给 `index.html`，前面得有 try_files 兜住） |
| 换了端口打开登录页，账号没自动回填 | `localStorage` 按**源**隔离（`127.0.0.1:18082` ≠ `127.0.0.1:8787`），属正常现象 |

---

## 3. 菜单 / 权限

| 现象 | 原因 / 解法 |
|---|---|
| 「配置生成器」菜单不见了 / 直连被弹回首页 / 接口 403「配置生成器已关闭（当前为生产模式）」 | **预期行为**：`generator_enabled` 未配置时跟随 `APP_DEBUG`，生产（`false`）自动关闭。开发期要用就 `APP_DEBUG=true`，或显式 `'generator_enabled' => true` 后 `restart`。详见 [配置参考 §4.5](03-配置参考.md) |
| 403 只看到「编号」看不到原因 | **设计如此**（不泄露权限结构）。拿编号 grep `runtime/logs/webman-*.log` 看 `reason` |
| 403 日志里 `reason=unresolved-permission` | 不是没权限，是**路径推导不出 `obj:act`**：控制器没进 `RouteControllerRegistry`，或菜单 `path` 与注册 key 不一致 |
| 勾了「一键生成权限」却没生成 | `path` 不是已注册的 CURD 页 → 推导不出 `curd.{Model}`。接口响应 `data.warning` 会提示，手填「权限标识」后重试 |
| 「快速创建权限」报重名 400 | 该 `permission` 已存在（含之前一键生成过的）。换个后缀名，或去菜单树里找已有节点 |
| `/api/admin/*` 对新角色一律 403 | 开了 `admin_require_permission`。`admin` 角色有 `p,admin,*,*` 不受影响；其余角色需在「角色管理」勾选，或写 `casbin_rule`：`INSERT INTO casbin_rule (ptype,v0,v1,v2) VALUES ('p','yunying','admin','users');` |
| 关掉权限后前端还显示无权限 | 前端要读响应里的 `permission_enabled` 字段自行处理；插件只负责后端放行与字段透传 |
| 登录后仍进 `/dashboard`（没走配置的 `home_page`） | 按顺序查：①`config/curd-admin.php` 的 `home_page` 改了但没 `restart`；②前端是旧产物（登录跳转在前端，需重新构建部署）；③浏览器里是**旧书签 / 历史**（`login?redirect=...` 这类旧值已被忽略）；④从侧边栏点了「首页」菜单（那是菜单数据，与 `home_page` 无关）；⑤`localStorage.admin_home_page` 是旧值且 `/api/config/site` 请求失败（看 Network） |
| 登录后落点跟「上次被拦住的那一页」不一致 | **刻意如此**：登录一律跳 `home_page`，不再回跳原页（`?redirect=` 已彻底不参与） |

---

## 4. 页面 / DSL

| 现象 | 原因 / 解法 |
|---|---|
| 新 Schema 页面 404 | `PageRegistry::register` 没写（或没 `restart`）；页面名与 `name` 不一致 |
| props 不生效 | 键不在前端注册表白名单 → 被丢弃。查 `componentRegistry.js` 对应 type 的 props |
| 页面显示 `[未注册节点: xxx]` | type 拼错，或前端漏登记 |
| collapse 不展开 / 展开错乱 | 展开态由渲染器托管：用 `->active([...])` 设初始 name，item 需有 `->name()` 才能精确命中 |
| `*-item` 没显示 | 条目必须**直接**挂在对应容器 children，不能夹在中间组件里 |
| `statistic` 显示 NaN / 空 | 整串占位取不到值时**保留原文**；确认数据接口返回数字、key 路径写对（`{{stats.amount}}` 是 `data.amount`） |
| `box` 图标不显示（空白方块） | `->icon('xxx')` 必须是 EP 图标名（`View` / `Money` / `User`…）；写错时 `getIconComponent` 回退 `Monitor`，连图标位都空则查 `utils/icons` |
| `line-chart-stat` 显示「暂无数据」 | `data` 取不到值（占位符未替换 / JSON 解析失败 / 数组里全是非数字）→ 走空态。查 dataApi 是否返回**数组**、key 路径是否写对 |
| `line-chart-stat` 折线贴底 / 全 0 | 数据本身全 0（如订单表为空），不是 bug；用 `->data([...])` 静态数组可快速自查渲染 |
| 图表宽度不随侧边栏折叠变化 | 节点组件用 `ResizeObserver` 监听容器，正常会自动重绘；若嵌在 `display:none` 的 tab 里初始化，切回时会重绘一次 |
| Action 按钮不出现 | `->show(fn)` 返回了 false；或 `->batch(true)` 的按钮在批量工具栏（需勾选行） |
| 点按钮报「Action xxx 未实现 handle」 | 链式 `$grid->action(...)` 应回退到控制器 `action{Name}()`。①控制器方法名没对上（`batch-on` → `actionBatchOn`）；②插件 `CurdActionsTrait` 是旧版（用 `method_exists` 判断，恒为 true）—— 升级插件后 `restart` |
| `.vue` 页面报「不支持导入模块 xxx」 | 运行时编译只放行 `vue` / `element-plus` / `@element-plus/icons-vue`；发请求改用 `inject('request')` |
| `.vue` 页面 `<style lang="scss">` 报错 | 浏览器端无预处理器，只能用纯 CSS（`scoped` 也会降级为全局，class 加唯一前缀） |
| 导出报「本次将导出 N 条，超过单次导出上限 M 条」 | `export_max_rows` 限制（默认 100000）。调大该键，或缩小筛选范围；前端把超限当**错误提示**而不是下载文件 |
| `Filter::dateRange(...)->default(...)` 配了但搜索框是空的 | ①前端产物是旧的（默认值由前端写入搜索框，**必须重建部署前端**）；②`default` 写在 `$filter->between(...)` 之后又链了别的会改类型的调用，确认最终 `type` 是 `daterange`/`datetimerange`/`date`（其它类型原样透传，不报错）；③区间给了单个**具体日期**时两端同值（不是「该日期至今」），要「至今」用相对表达式（`'-7d'`）或显式 `[$start, 'today']`。核对接口返回：`GET /api/curd/config?route_path=/xxx` 里 `search[].default` 应是**已求值的具体日期** |
| 点式字段（`config.api_url`）编辑不回显 / 列表为空 | ①展开范围 = grid 点式列 ∪ 表单点式字段 ∪ **详情里显式声明的点式字段** ∪ 动态配置点式 prop，确认字段在任一处的 prop 带 `.` 且**根字段名一致**（`config` vs `configs` 不互通）；②根字段存的不是合法 JSON（手写数据时注意引号）；③前端产物旧（字面键写入是前端行为，改后端也要重建前端）。点式对**任意字段类型**都成立（select/switch/datetime…），与类型无关 |
| JSON 组件（`$form->json()`）保存后是 `[object Object]` 或行没回显 | ①前端产物旧（`serializeJsonRows` 序列化 + `parseJsonValueToRows` 回显都在前端）；②库里该列被存成了双层编码字符串（`"{\"a\":1}"`）——查是否自定义钩子又 `json_encode` 了一次；③编辑回显单行原文 = 库里值不是合法 JSON，被当纯文本保留 |
| 点式字段合并后其它子键丢了 | 合并基础值优先级 = 请求显式根字段 > 编辑读库现值 > 空数组；编辑时没带 `id` 对应的行数据（如自定义入口绕过 `update()`）会拿不到现值。确认走的是插件 `add()/update()` 主链路 |
| 点式字段（`config.xxx`）**明明有值**仍提示「不能为空」、提交被卡住 | 两类原因：①前端产物旧（取值一致性靠前端「字面键 + 嵌套路径」双写，改后端也要重建部署前端）；②**给校验规则多挂了键（最常见是 async-validator 的 `transform`）** —— EP 只剥掉 `trigger`，async-validator 只在「剥掉 `message` 后**恰好只剩 `required`**」时才用 required 校验器，**多一个键就退回 `validators['string']`** → 数字/布尔值被判「不是字符串」→ 报错文案仍用规则的 `message` → 看着就是「不能为空」（这解释了为什么**字符串字段正常、数字/开关/下拉字段必错**）。最小复现：`{required:true,message:'M'}` → PASS；`{required:true,message:'M',transform:()=>30}` → FAIL；显式 `type:'number'` → PASS。**修法：规则只保留 `{required,message,trigger}`**，取值一致性交给前端 `setFieldValue()` 双写 + `initFormData()/handleEdit()` 末尾 `syncDotNested()` 镜像（lodash `get(formData,'config.x')` 命中字面键即取到真值） |
| 查看详情里看不到表单里的字段（点式 `config.xxx` 也一样） | 详情默认 = **列表列 ∪ 表单字段**（按 `prop` 去重，列在前），所以表单里声明的字段本该出现。仍看不到时：①该字段是「仅新增」字段（`modeOnly === 'add'`，如创建时的密码）或标了 `hidden` —— 设计上不进详情；②控制器**显式调用过 `$grid->setDetail([...])`**，此时以显式声明为准、不再并入表单字段，把缺的字段补进 `setDetail()` 即可；③前端产物旧。详情是只读视图：`switch` 显示「开/关」文字、`select`/`radio` 显示选项文案、`image`/`date`/`datetime` 保留专用单元格 |
| 图片字段选不了 gif/webp，或提示「只允许上传 jpg / jpeg / png」 | 预期行为：`image` 字段只收 jpg/jpeg/png（前端 `accept` + 上传前硬校验，服务端 `/api/upload/image` 按真实 MIME 复核）。需要其它格式请改用通用 `file` 字段（`/api/upload`） |
| `$form->labelWidth()` / `contentWidth()` 设了没效果 | ①`contentWidth` 传纯数字（`400`）会输出 `max-width:400` 这种**无效 CSS** —— 新版已自动补 `px`，旧版请写 `'400px'`；②页面宿主没把这两个字段传下去（`dynamicCurd` 的 `configObj` 曾漏传 `labelWidth/labelPosition/contentWidth`，新版已修）；③前端产物旧（改后端也需重建前端）；④该页配置来自**落库动态页**（不执行 `Form` DSL，改控制器无效） |
| 报 `Undefined array key "locale"`（Translation.php） | 插件请求按 `request()->plugin` 读 `config('plugin.curd.translation')`，宿主缺该配置会因空数组缺 `locale` 报错。插件已内置 `plugin/curd/config/translation.php`（两段 path：先插件内置 `resource/translations`、后宿主同名文件），升级插件后 `restart` 一次即可；宿主**无需**自己写翻译配置 |
| `Call to undefined function xxx\__()` | 宿主的 `plugin/curd/app/functions.php` 是旧版（缺 `__()` 别名）。重新同步插件 `app/` 目录后 `restart`；`__()` 定义见 `plugin/curd/app/functions.php`（= `trans($id, [], 'fields')`） |
| `__()` 返回的是英文原句 | ①该 key 未收录：在宿主 `resource/translations/zh_CN/fields.php` 补一行（宿主 key 优先于插件内置）；②key 收录了但仍英文：确认 locale 是 `zh_CN`（插件 `config/translation.php` 的 `locale`，默认取宿主 `translation.locale`）；③确认走的是插件请求路径（`request()->plugin === 'curd'`，CLI 下无请求上下文时 `__()` 兜底返回原句，属预期） |
| 行内 `->select()` / `->copy()` 不生效 | ①前端产物旧：copy 单元格与 select 行内提交由前端渲染，**必须重建部署前端**（`scripts/build-frontend.sh`）；②`->select()` 的 options 传关联数组 `[值=>文案]` 或二维 `[['value'=>…,'label'=>…]]`，传错结构时无选项；③copy 列优先级高于 display/tag 等其它单元格渲染，确认不是被更高优先级形态盖住（copy 本身就应盖住它们） |
| `display()` 里的 `<el-tag>` 没有样式（只剩文字） | `v-html` 是**原样塞 innerHTML**，Vue 不会把 `<el-tag>` 编译成组件 → 它只是浏览器的未知标签，Element Plus 的样式挂在 `.el-tag` **类名**上。三种写法：①内联样式最稳（见下）；②`<span class="el-tag el-tag--info">池</span>`（需该页已加载 el-tag 的样式分包）；③让框架渲染真组件：`->map()` + `->label()`（文案由字典决定，不能拼接额外数据时用不了） |
| `display()` 返回的 HTML 被当成源码显示 | ①**闭包返回 HTML 默认会自动检测**（`display_auto_html`，返回值含真实标签即按 HTML 渲染）——若仍显示源码，先确认前端产物是新的（需 `display_html_{prop}` 支持）；②显式 `->html(false)` 或宿主 `config/curd-admin.php` 的 `'display_auto_html' => false` 会关闭自动检测，此时必须 `->html()`；③字符串形式 `->display('<b>{name}</b>')` 始终自动 HTML；④`{prop}` 取当前行字段、`{value}` 取该列原始值，占位符写错会留空 |
| `help()` 里的 `@click="hello()"` 点了没反应 | ①`hello` 必须**全局可访问**：在 `setCustomJs()` 里用顶层 `function hello(){}` / `var` / `window.hello = …`（`const`/`let` 在顶层也算全局，但代码变更后重新注入可能报重复声明）；②customJs 内容变了才会重新注入，确认保存后刷新页面；③前端产物旧（事件委托是新逻辑）；④修饰符写在**属性名**上（`@click.stop="fn()"`），不是值里 |
| `curd is not defined` / `window.curd` 取不到 | ①前端产物旧（`window.curd` 由新前端注入）；②该页面没有配置 `customJs` 时不会构建 API，给页面加 `$grid->setCustomJs('…')` 即可；③旧名 `window.__curd` 仍兼容 |

---

## 5. 认证 / 登录

| 现象 | 原因 / 解法 |
|---|---|
| 自定义登录没生效 | `login_handler` / `auth_provider` 类名写错会**静默回退内置**；改完需 `restart`，并确认类能被自动加载（PSR-4 路径 / 命名空间） |
| 自定义登录返回 null 却想给明确错误 | `login()` 只能返回身份数组或 `null`；想区分「用户不存在」「密码错」可在 `null` 前用日志 / 监控区分，响应统一是 401 |
| 登录成功但 `/api/auth/me` 报用户不存在 | `login()` 的 `id` 与 `resolveUser($id)` 取数来源不一致，核对两边用的是同一张表同一列 |
| 自己实现 `auth_provider` 加状态判定，结果「能访问接口但 `/me` 状态不对」 | 覆盖时漏了三个入口之一（`login` / `identity` / `resolveUser`），**漏了不报错**。改用 `auth_state_guard` 收口到一处 |
| `auth_state_guard` 配了却不生效 | ①类名写错 / 类不存在 → `stateGuard()` 返回 `null` 静默跳过；②没 `restart`（配置在启动期读取）；③配的是 `auth_provider` 而非 `auth_state_guard`。排查：`php -r "var_dump(class_exists('\\\\app\\\\auth\\\\MyGuard'));"` |
| 登录一直提示「验证码错误」「请输入验证码」 | ①宿主要求验证码但自定义登录页没送 `captcha_key` / `captcha_code`（不想用就 `'captcha_enabled' => false` 后重启）；②前端产物是旧的（不含验证码字段）→ 重新构建部署；③`/api/auth/captcha` 报 500（缺 `webman/captcha` 或 `ext-gd`） |
| 验证码位置只有一行「点击获取」，没有图片 | `/api/auth/captcha` 没返回有效 key，看响应 `msg`：ext-gd 未装 / 字体缺失 / Redis 不可用；点那一行可重试 |
| 验证码输入框整块不显示（后端却仍要验证码） | `captcha_enabled=false` 已生效，或 `/api/config/site` 请求失败（前端兜底不显示）。后端返回的错误里带 `data.captcha=true`，前端会**自动展开并拉图**，重试一次即可 |
| 全员都登不进去，且验证码一直失败 | Redis 挂了（验证码明文存 Redis，一次性）。应急把 `'captcha_enabled' => false` 后重启放行 |
| 账号能登录，但过一会儿请求全 403「账号已被禁用」 | 配了 `auth_state_guard`，其业务判定返回了 `false`（`AuthCheck` 每请求都调，实时生效、无缓存）。查 `PlatformStatusGuard` 之类的实现与业务库状态；注意钩子内要做 **fail-open**（异常时放行 + 记日志） |

---

## 6. 接口加密

| 现象 | 原因 / 解法 |
|---|---|
| 所有 `/api` 返回 400「请求解密失败」 | 前端产物用的公钥与宿主私钥不匹配。**公钥每宿主一份**（`config/keys/api_rsa_public.pem`），别把 A 宿主的产物拷到 B 宿主；用 `build-frontend.sh` 重开（它会校验产物里真的带上了目标宿主的公钥） |
| 「我明明没开加密，怎么要解密」 | 开关是**显式**的：只有 `VITE_API_ENCRYPT=true` 才开。若产物里搜得到 `X-Encrypt-Data`，说明这批 dist 是加密版构建的 |
| 改了 `VITE_API_ENCRYPT` 没反应 | 该变量是**构建期内联**，不是运行期配置，改完必须重新 `npm run build` 并部署产物 |
| 手动构建时换行写不对 | `VITE_API_RSA_PUBLIC_KEY` 里的换行要写成字面 `\n` |
| 上传 / 导出也想加密 | 不需要，也不会加密：`multipart` 上传、非 JSON 响应（CSV 导出）、`OPTIONS` 预检、非 `/api` 路径**始终明文** |

---

## 7. 异常兜底 / 日志

| 现象 | 原因 / 解法 |
|---|---|
| 线上接口提示「服务内部错误（编号 xxxxxxxx）」 | 后端有未捕获异常（多为 SQL 报错 / 字段不存在）。`grep <编号> runtime/logs/webman.log` 直接看到异常类、SQL 与绑定 |
| 线上接口只提示「Request failed with status code 500」，没有编号 | 该路由**没走**插件异常处理器。插件路由应自动生效（`plugin/curd/config/exception.php`）；宿主自己的 `/api` 控制器需把 `config/exception.php` 的 `''` 键指向 `CurdExceptionHandler` |
| 线上接口直接返回堆栈 / 表结构 | 模式还判定为「本地」：插件侧看 `.env` 的 `APP_DEBUG`，宿主自己控制器还要看宿主 `config/app.php` 的 `debug`（两者**取交集**，任一为 `false` 即线上） |
| 按编号 grep 日志只看到半条 | 日志被 `trace_str` 的换行撑成多行（webman 默认 `allowInlineLineBreaks=true`）。新版已把换行收敛为 `\|`、并把 `sql` / `bindings` 排在堆栈之前，一条异常恒为一行；用到旧版 `CurdExceptionHandler` 就重新同步一次插件 |
| 想在中间件里统一捕获接口异常 | **做不到**：webman 的中间件链每层内层都先 try/catch 再转 Response，外层拿不到控制器异常。只能用异常处理器 |

---

## 8. 升级 / 部署

| 现象 | 原因 / 解法 |
|---|---|
| `composer update` 后宿主 `plugin/curd` 没变 | **预期行为**：安装器只在目录不存在时拷贝（保护本地改动）。要同步新版文件用 `scripts/sync-plugin.sh`（先备份再重拷） |
| 不确定宿主侧有没有本地改动 | 升级前跑 `scripts/check-plugin-overrides.sh <宿主项目根>`：`0` = 无改动，`1` = 发现改动 |
| 升级后新表 / 新菜单缺失 | 升级后跑一次 `php plugin/curd/install.php`（幂等补种子）与 `php plugin/curd/migrate.php`（增量迁移） |
| `Route conflict` 异常 | 老版本插件没用冲突检测。现版本统一走 `curd_route()`：注册前检测同 method + path，已注册则跳过、保留宿主实现。宿主自注册过 `/api/admin/*` 等不受影响 |
| 与宿主路由冲突怎么办 | 同上 —— **宿主优先**，插件让位。存量项目装插件行为不变 |
| 插件自带 config 覆盖了 `config/plugin/curd/curd.php` | 正常：插件以「根目录应用插件」分发，webman 配置加载顺序是 `config/` 先、`plugin/*/config` 后。调参请写宿主根 `config/curd-admin.php` |

---

## 9. FAQ

### 为什么配置写在 `config/curd-admin.php` 而不是 `.env`？

插件自带 config 会覆盖 `config/plugin/curd/curd.php`；宿主根 `config/curd-admin.php`
由安装器生成、**顶层同名键覆盖内置默认**，是唯一推荐调参入口。
数据库连接仍走 `.env` 的 `DB_*` 键。

> 注意：`CURD_*` 环境变量已**全部移除**，`config/curd.php` 与 `config/admin.php`
> 也已**不再读取**（2026-09-19 合并）。改这两个地方一律无效果。

### 为什么还保留 `mysql_business` 连接（默认指向同一个库）？

- **历史原因**：插件按「认证库 / 业务库」分离设计，`business_connection=mysql_business`
  是默认的业务模型 CURD 连接名。改这一处会动大量业务代码、破坏向后兼容。
- **单库模式**（默认）：`mysql_business.database` 回退到认证库名 → 两个连接**指向同一库**，零开销。
- **想分库**：在 `config/curd-admin.php` 的 `database.business_db` 填不同库名。
- **不要它**：删掉 `config/database.php` 里 `'mysql_business' => [...]` 整块，
  业务代码里的 `Db::connection('mysql_business')` 改成 `mysql`，或在模型里
  `protected $connection = 'mysql';`。

### `composer audit` 红字能彻底消除吗？

包内无法彻底消除（composer 没有全局 config 关闭 audit），但提供**零侵入 wrapper**。

- **原理**：`composer require/update` 末尾会跑 `composer audit`，硬编码访问 `packagist.org`
  的 `security-advisories` 端点（不走国内镜像）；不可达时就报红字，但命令本身 `exit 0`。
- **根治**：让 `packagist.org` 可达（全局代理或 hosts）。
- **包内方案**：

```bash
./scripts/composer.sh update                          # 临时用一次
echo "alias composer='$(pwd)/scripts/composer.sh'" >> ~/.zshrc && source ~/.zshrc   # 永久
```

### 插件的路由前缀和命名空间是什么？

- 后端 API `/api/*`、内置前端 `/app/curd/*`；
- 命名空间 `plugin\curd\app\*`；配置读取 `config('plugin.curd.*')`；
- 表 `curd_configs`（页面 DSL 配置）、`admin_users`、`admin_tokens`、`roles`、
  `admin_role_user`、`role_permission`、`casbin_rule`、`menus`。

### `composer update` 会把我改好的配置覆盖掉吗？

**会覆盖代码，但保留密钥。** 自 2026-10-15 起，`plugin/curd` 已存在时 `composer update` 会**整目录删除后从包内重拷（覆盖同步）**，宿主在 `plugin/curd` 下的本地改动会被抹掉；唯一保留的是 `plugin/curd/config/keys/*.pem` 加密密钥（删目录时保留、拷包时排除）。
宿主 `config/curd-admin.php` 仍是「已存在即 return」不覆盖；示例控制器 / 模型已存在也跳过。

---

## 10. 命令行（`make:curd`）

| 现象 | 原因 / 解法 |
|---|---|
| `php webman list` 里**没有** `make:curd` | 插件版本 < 2026-10-19，且 `plugin/curd` 是**软链**挂载（composer path 仓库开发）。升级到本版即可（插件已内置显式注册） |
| 出现的是**英文描述**的旧版（`Generate a CURD controller for a table`） | 宿主 `app/command/` 里还留着早期拷贝的命令，且插件的没注册上（同上）。删掉宿主那份，或升级插件 |
| `make:crud` 报「已经存在」/ 生成的代码风格不对 | **别混用**：`make:crud` 是 `webman/console` 自带的通用生成器（Model + Controller + Validator），与本插件无关；本插件是 `make:curd`（u 在前） |

**为什么软链会吃掉命令**（原理，出问题时好排查）：

`webman/console` 的 `Command::installCommands()` 用 `getRealPath()` 拿真实路径，再做字符串前缀裁剪拼类名：

```php
$relativePath = str_replace(str_replace('/', '\\', $path . '\\'), '', str_replace('/', '\\', $file->getRealPath()));
```

`plugin/curd` 是软链时 `getRealPath()` 解析到软链目标（如 `../../company/webman-curd-admin/plugin/curd`），
前缀对不上 → 拼出的类名是乱的 → `class_exists` 失败后**静默 `continue`**（不报错，所以毫无线索）。

**解法**：插件在 `plugin/curd/config/app.php` 里显式声明了 `command`，走
`Command::createCommandInstance()` 直接按类名反射，不经过路径拼接，天然免疫软链：

```php
'command' => [
    \plugin\curd\app\command\MakeCurdCommand::class,
],
```

> 老版本宿主想临时救急：在宿主新建 `config/plugin/curd/app.php`，内容为
> `return ['enable' => true, 'command' => [\plugin\curd\app\command\MakeCurdCommand::class]];`
> —— 效果相同（插件内置后就不需要了，两边同名注册时后者覆盖前者，无副作用）。

唯一的例外是宿主 `config/database.php`：如果它还是 webman 的**占位模板**
（内容里有 `your_database` / `your_username`），会被备份成 `database.php.webman-db.bak`
后用 env 驱动的模板替换。已经改成自定义配置的则不动。

完整边界表见 [升级 · 部署 · 发布](15-升级与生产部署.md) §1.1。

代价是硬币的另一面：**`composer update` 也不会把新版同步进 `plugin/curd`** ——
升级要另外跑 `scripts/sync-plugin.sh`，确认版本看的是 `plugin/curd`，而不是 `vendor/`。
