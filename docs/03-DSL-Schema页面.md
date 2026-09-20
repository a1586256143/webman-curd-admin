# 后台 DSL · Schema 页面

> 展示型页面（首页 / 看板）：后端 PHP 声明组件树 → 前端 `SchemaRenderer` 递归渲染。
> **纯数据、无脚本**，改后端即生效、前端无需重新构建。
> 对应的 CURD 列表页 DSL 见[DSL · CURD 页面](04-DSL-CURD页面.md)，分层与文件归属见[webman-curd-admin · 总览与导航](00-总览与导航.md) 的 §3。

---

## 1. 一句话原理

一个页面 = 后端 PHP DSL 流式声明的一棵**组件树 JSON**，前端 `SchemaRenderer` 递归把它渲染成真实 Element Plus 页面。树节点统一为：

```json
{ "type": "card", "props": { "header": "今日概览" }, "children": [ … ] }
```

`type` 在**前后端注册表**里登记（后端 `plugin/curd/app/schema/blocks/` 的类，前端 `frontend/src/schema/componentRegistry.js`），`props` 走白名单透传。**Schema 是纯数据、无脚本**，比自定义 .vue 更可控。

## 2. 新建一个页面（三步）

**① 写页面类**（用户级文件放 `app/controller/admin/api/`，命名如 `XxxPage.php`）：

```php
<?php
namespace app\controller\admin\api;

use plugin\curd\app\schema\PageSchema;
use plugin\curd\app\schema\blocks\Col;
use plugin\curd\app\schema\blocks\Card;
use plugin\curd\app\schema\blocks\Row;

class DashPage
{
    public static function schema(): PageSchema
    {
        $page = new PageSchema('概览看板');
        $page->api('stats', '/api/dash/stats');            // ① 数据接口（可多个）
        $page->row(function (Row $row) {                   // ② 布局：24 栏栅格
            $row->col(12, function (Col $col) {
                $col->card('今日', function (Card $card) {
                    $card->statistic('充值额')->value('{{stats.amount}}')->prefix('¥');
                });
            });
            $row->col(12, function (Col $col) {
                $col->card('提示', function (Card $card) {
                    $card->alert('正常', '一切正常')->type('success')->closable(false);
                });
            });
        });
        return $page;
    }
}
```

**② 注册**（业务 `config/route.php` 顶部，一行）：

```php
PageRegistry::register('dash', [\app\controller\admin\api\DashPage::class, 'schema']);
```

**③ 建菜单**，`path` 填 `/custom-page/dash`：

```bash
php webman make:permission --name 概览看板 --slug dash-page --type 1 --path /custom-page/dash --icon DataBoard --sort 3
```

改完 `php start.php restart`（新类 + 注册生效），刷新即可看到页面。**已注册的参考实现**：`app/controller/admin/api/HomePage.php`（首页，name=`home`）。

> `.vue` 自定义页面是同一入口体系的另一形态：文件放 `app/custom/pages/demo.vue`，菜单 path `/custom-page/demo`，前端运行时编译渲染（复杂交互页用）。同名时 **Schema 注册表优先**于 .vue 文件。

## 3. 页面根 PageSchema

| 方法 | 说明 |
|---|---|
| `new PageSchema(?string $title)` | 页面根节点（type=`page`，不参与渲染，只承载 title/dataApi/body） |
| `->title(string)` | 页面标题 |
| `->api(string $key, string $url, string $method = 'get', array $params = [])` | 声明数据接口。渲染前前端并发请求，结果放进 `pageData[$key]`，供 `{{key.xxx}}` 注入 |
| `->row/col/card/collapse/timeline/descriptions/divider/alert/statistic/image/text/copyText/box/lineChartStat/node` | 顶层挂载（来自 `HasBlocks` trait，下面节点通用） |
| `->toArray()` | 输出 `{ title, dataApi, body }`，由 CustomPageController 下发 |

## 4. 布局块（可任意嵌套）

| type | 类 | 便捷方法 | 透传 prop（白名单） | 取值 |
|---|---|---|---|---|
| `row` | `Row` | `->gutter(int)` `->justify(str)` `->align(str)` | gutter/justify/align/wrap | justify: start/end/center/space-around/space-between/space-evenly；align: top/middle/bottom |
| `col` | `Col` | `->span(int 1-24)` `->offset(int)` `->moveRight(int)`(push) `->moveLeft(int)`(pull) `->responsive('md', 8)` | span/offset/push/pull/xs/sm/md/lg/xl | 响应式断点同 el-col |

子节点规则：`row` 装 `col`；`col` 纵向堆叠任意块。想缩进偏移用 `offset`，想调整顺序用 `moveRight/moveLeft`。

## 5. 容器块（能继续装子节点）

| type | 类 | 便捷方法 | 子节点规则 |
|---|---|---|---|
| `card` | `Card` | `->header(string)`（渲染到 EP 卡片 header 插槽） `->shadow('always'\|'hover'\|'never')` | children 进卡片正文 |
| `collapse` | `Collapse` | `->accordion(bool)` `->active(array $names)` | 只能 `->item(title, cb)` |
| `collapse-item` | `CollapseItem` | `->title(string)` `->name(string)` `->disabled(bool)` | 内容放 children（可再嵌 row/card/描述…） |
| `timeline` | `Timeline` | — | 只能 `->item(ts, cb)` |
| `timeline-item` | `TimelineItem` | `->timestamp(string)` `->placement('top'\|'bottom')` `->type('primary'\|'success'\|'warning'\|'danger'\|'info')` `->color(string)` `->hollow(bool)` | 内容放 children |
| `descriptions` | `Descriptions` | `->title(str)` `->column(int)` `->border(bool)` `->direction('horizontal'\|'vertical')` `->colon(bool)` `->size('large'\|'default'\|'small')` | 只能 `->item(label, content)` |
| `descriptions-item` | `DescriptionsItem` | `->label(str)` `->span(int)` `->width(mixed)` | 值内容放 children；`item()` 传字符串自动转 Text 子节点 |
| `divider` | `Divider` | `->content(string)`（中央文字） `->contentPosition('left'\|'center'\|'right')` `->direction('horizontal'\|'vertical')` `->borderStyle(str)` | 中央文字用 `content()`，子节点作默认插槽 |

条目类（`*-item`）**只能**作为对应容器（collapse/timeline/descriptions）的直接子节点，不可隔层——渲染器用单组件 `h()` 递归保证它们是父容器的直接相邻 VNode。

## 6. 展示块（叶子，靠 props 驱动）

| type | 类 | 便捷方法 | 说明 |
|---|---|---|---|
| `alert` | `Alert` | `->title(str)` `->description(str)` `->type('success'\|'info'\|'warning'\|'error')` `->closable(bool)` `->showIcon(bool)` `->center(bool)` `->effect('light'\|'dark')` | 提示条 |
| `statistic` | `Statistic` | `->title(str)` `->value($v)` `->prefix(str)` `->suffix(str)` `->precision(int)` `->groupSeparator(str)` `->valueStyle(array)` | 数值卡；value 整串 `{{}}` 且为数字时自动转 number |
| `box` | `Box` | `->title(str)` `->value($v)` `->sub(str, $v=null)` `->subTitle(str)` `->subValue($v)` `->icon(str)` `->color(str)` `->tag(str)` `->height(int=108)` `->group(bool=false)` | **指标卡片**：图标 + 大数字 + 副标题 + 右上角周期标记，仪表盘顶部并排四个的经典形态。`icon` 取 EP 图标名（`View`/`Money`/`User`/`Tickets`…，见前端 `utils/icons`）；`color` 取 `blue`/`green`/`red`/`orange`/`purple`/`cyan`，同时决定图标底色与标记配色 |
| `line-chart-stat` | `LineChartStat` | `->title(str)` `->subTitle(str)` `->data(array\|str)` `->categories(array\|str)` `->seriesName(str='业绩')` `->color(str='#36cfc9')` `->height(int=350)` `->smooth(bool=true)` `->showMax(bool=true)` `->showAverage(bool=true)` `->areaGradient(bool=true)` `->unit(str)` | **折线图统计卡**：卡片标题 + 子标题 + 渐变面积折线图（峰值气泡 + 平均值虚线）。`data` 走 echarts 按需注册（不引入全量），**取不到数据时显示「暂无数据」空态**而非空图 |
| `image` | `Image` | `->src(str)` `->fit('fill'\|'contain'\|'cover'\|'none'\|'scale-down')` `->alt(str)` `->lazy(bool)` `->previewSrcList(array)` `->previewTeleported(bool)` | 点击预览大图等 |
| `copy-text` | `CopyText` | `->value(str)` `->label(str)` `->mono(bool=true)` `->mask(bool=false)` `->maskHead(int=4)` `->maskTail(int=4)` | **可复制文本**：等宽展示 + 一键复制按钮，用于 api_key / 密钥 / 回调地址等长串；`->mask(true)` 默认打码，配眼睛按钮切换明文 |
| `text` | `Text` | `->value(str)`（也可 `new Text('内容')`） | 纯文本（渲染 span，非 EP 组件），说明文字用 |

**仪表盘组合示例**（`box` 一排四个 + `line-chart-stat` 整行，即典型后台首页首屏）：

```php
// 第一行：四个指标卡片
$page->row(function (Row $row) {
    $row->gutter(16);
    $row->col(6, function (Col $col) {
        $col->box('今日订单', '{{stats.today_count}}')
            ->sub('今日成功', '{{stats.today_success_count}}')
            ->icon('Tickets')->color('blue')->tag('日');
    });
    $row->col(6, function (Col $col) {
        $col->box('本月金额', '{{stats.month_amount}}')
            ->sub('本月成功单金额')
            ->icon('Money')->color('green')->tag('月');
    });
    // … 另两个同理（red / orange）
});

// 第二行：折线图统计卡（data / categories 均可直接传数组或占位符）
$page->row(function (Row $row) {
    $row->gutter(16);
    $row->col(24, function (Col $col) {
        $col->lineChartStat('订单趋势')
            ->subTitle('近 30 天成功单走势')
            ->data('{{stats.trend_counts}}')      // 数据接口返回数组 → 整串注入
            ->categories('{{stats.trend_days}}')
            ->seriesName('成功单')
            ->color('#36cfc9');
    });
});
```

> `data` 的两种写法：① `->data('{{stats.trend_counts}}')` 由 dataApi 注入（推荐）；
> ② `->data([20, 25, 30, …])` 直接写静态数组（适合演示或常量数据）。`categories` 省略时自动用 `1..n`。

## 7. 数据绑定：`{{key.path}}` 注入

1. 页面 `->api('stats', '/api/xxx')` 声明数据源；数据接口就是一个普通控制器方法，返回统一响应 `{code, msg, data}`，渲染前前端并发请求并取 **`data`** 作为 `pageData['stats']`。
2. 任一节点 props 的字符串里可用 `{{stats.today_amount}}` 占位：
   - **整串就是占位符**（如 `value: "{{stats.amount}}"`）：数据是数字 → 注入数字（statistic 需要）；是对象/数组 → 原样注入；取不到 → **保留原文**（避免渲染出 undefined）；
   - 占位符**嵌在长文本**里（如 `"¥{{stats.amount}} 元"`）→ 文本替换，取不到替换为空串。
3. **只做取值替换，不执行任何 JS**。单个 dataApi 失败不阻塞整页（该键数据为空，占位保留便于定位）。

> 参考数据接口：`SchemaPageDataController::home`（GET `/api/schema/home/stats`），返回 `today_amount / today_count / month_amount / pending_count / account_total / account_count / accounts[]`，与订单列表头部汇总条同口径。

## 8. props 白名单与安全

后端 block 的 `->方法()` 本质是往 `props` 里塞键（`SchemaNode::set`）。**前端渲染器只透传注册表白名单内的键**（`componentRegistry.js`），其余一律丢弃——避免脏属性打到 EP 组件上、也避免任意数据注入造成意外行为。三个兜底入口：

```php
$card->raw(['bodyStyle' => ['padding' => '12px']]); // SchemaNode::raw：一次合并多个 props
$img->set('initialIndex', 2);                        // SchemaNode::set：单个 prop（白名单外前端会忽略）
$node->node($anyNode);                               // 挂载任意已构造好的节点
```

## 9. 渲染链路（改后端即生效，无需构建前端）

```
菜单 /custom-page/<name>
  → GET /api/custom/page?name=<name>      (插件 CustomPageController)
  → PageRegistry::find(name) 有 → {format:'schema', schema:{…}}
      | 无 → app/custom/pages/<name>.vue 有 → {format:'vue', content, mtime}
  → 前端 CustomPageHost 按 format 分派：
      schema → 并发请求 dataApi → interpolateSchema 注入 → SchemaRenderer 递归渲染
      vue    → dynamicSfc 运行时编译 SFC 渲染
```

**渲染器三条特殊规则**（`SchemaRenderer.vue`）：
1. `card` 的 `header` 不是 prop，是 EP 命名插槽 → 渲染器把 `props.header` 转成 header 插槽；
2. `collapse` 的展开状态（v-model）由渲染器 WeakMap 自托管，初始取 `props.activeNames`，页面无需写交互代码；
3. 所有容器一律递归 children 到**默认插槽**，保证 `*-item` 是父容器的直接子 VNode（EP 结构要求）。

## 10. 扩展新组件（前后端各一步）

以加一个「链接按钮」为例：

1. **后端** `plugin/curd/app/schema/blocks/Button.php`：`class Button extends SchemaNode { __construct(){ $this->type='button'; } public function text($v){ return $this->set('text',$v); } }`（并可在 `HasBlocks::button()` 加便捷入口）；
2. **前端** `componentRegistry.js` 登记一行：`button: { component: ElButton, props: ['text','type','link', ...COMMON] }`。

渲染器零改动。当前已注册 type 全表 = 第 2.4~2.6 节列出的 20 个。

**component 也可以指向自定义 Vue 组件**（不限于 EP 组件）：`copy-text`、`box`、`line-chart-stat` 都是先例 ——
`componentRegistry.js` 里 `'copy-text': { component: CopyTextNode, props: [...] }`，
`CopyTextNode.vue` 自己负责渲染与交互（渲染器仍然只按 `h(component, props, slots)` 调用，
props 依旧走白名单）。复制这类交互因此可以纯前端实现，后端 block 只负责摆位置与传值。

其中 `line-chart-stat` 还证明了**图表也能内建**：echarts 在节点组件内按需注册
（`echarts/core` + LineChart/Grid/MarkPoint/MarkLine/Canvas，与 `views/dashboard` 共用同一份模块），
所以加一个图表块不会引入全量 echarts（产物里 echarts 独立成 chunk，约 460 KB / gzip 155 KB）。
真正需要「图表联动、多图 k 线、下钻筛选」这类交互时，仍建议走 `.vue` 页面。

## 11. `.vue` 自定义页面（`app/custom/pages/`）—— 怎么用、怎么访问

Schema 只适合「纯展示」。一旦要写复杂交互（自定义表格逻辑、图表联动、多步表单、第三方组件），就用 `.vue` 页面：

**① 放文件**：`<宿主根>/app/custom/pages/<name>.vue`。**文件名即页面名，无需注册**；支持子目录（`sub/foo.vue` → name=`sub/foo`）。目录可用 `config/plugin/curd/curd.php` 的 `vue_pages_dir` 改。

**② 建菜单**：`path` 填 `/custom-page/<name>`（与 Schema 页同一个入口体系）。

**③ 怎么访问**（三种都行）：

| 途径 | 地址 |
|---|---|
| 后台点菜单 | 前端 catch-all 路由命中 `/custom-page/<name>` |
| 直接开 URL | `http://<宿主域名>/app/curd/custom-page/<name>`（插件内置前端挂 `/app/curd/` 前缀，history 模式） |
| 调试接口 | `GET /api/custom/page?name=<name>` → `{format:'vue', content, mtime}`；`GET /api/custom/pages` 列全部页面 |

**运行时约束**（内容由浏览器端 `@vue/compiler-sfc` 现场编译，绕开了 Vite，因此有白名单）：

| 项 | 规则 |
|---|---|
| 结构 | `<template>` + `<script setup>`（或 `<script>`，**二选一，不能同时写**）+ `<style>` |
| 可 import 的模块 | **仅 3 个**：`vue`、`element-plus`、`@element-plus/icons-vue`。其它任意 specifier（含相对路径 `./x.js`、`axios`、`lodash`）编译期直接报「不支持导入模块」 |
| 发请求 | `const request = inject('request')` 拿统一加密请求服务（自动带 token + RSA/AES 信封，签名同 axios：`request.get/post`）；`inject('router')` / `inject('route')` 同理 |
| 组件 | `element-plus` 全量注册到应用，模板里 `<el-xxx>` 直接写，**不用 import 注册** |
| 样式 | **只支持纯 CSS**（`lang="scss"` 会报错）；`<style scoped>` 的 scoped 语义降级为**全局注入**（不做属性转写）→ 页面 class 请带唯一前缀防串样式 |
| 生效 | 改 `.vue` 文件**不用重新构建前端**，刷新页面即取最新（后端按 `mtime` 下发内容） |

参考实现：`app/custom/pages/demo.vue`（计数器 + `inject('request')` 调 `/api/config/site` + el-table）。

**两条路线怎么选**：

| | Schema 页面 | `.vue` 页面 |
|---|---|---|
| 写在哪 | 后端 PHP（`app/controller/admin/api/*Page.php`） | 前端文件（`app/custom/pages/*.vue`） |
| 能力 | 组件树 + 24 栏栅格 + `{{key.path}}` 数据注入，**无脚本** | 完整 Vue 3：响应式/异步/循环/自定义逻辑 |
| 谁维护 | 后端自己能加页面，零前端资产 | 前端同学 |
| 安全边界 | props 白名单，最可控 | 能跑任意页面逻辑（仍是登录后可访问） |

同名时 **Schema 注册表优先** 于同名 `.vue` 文件。

## 12. 完整示例：做一个「我的信息」页

需求：任何账号登录后只看到**自己**的 Api Key 与可用余额。**全程只写后端 PHP，前端零改动。**

**① 页面类** `<宿主>/app/controller/admin/api/MyInfoPage.php`

```php
class MyInfoPage
{
    public static function schema(): PageSchema
    {
        $page = new PageSchema('我的信息');
        $page->api('info', '/api/schema/my-info');        // 数据接口见 ②

        $page->row(function (Row $row) {                  // 24 栏栅格
            $row->gutter(16);
            $row->col(8, function (Col $col) {            // 左：余额
                $col->card('可用余额', function (Card $card) {
                    $card->statistic('当前可用余额')
                        ->value('{{info.amount}}')->prefix('¥')->precision(2);
                    $card->divider();
                    $card->descriptions(function (Descriptions $d) {
                        $d->column(1);
                        $d->item('登录账号', '{{info.username}}');
                        $d->item('关联账户', '{{info.account}}');
                    });
                });
            });
            $row->col(16, function (Col $col) {           // 右：账户资料
                $col->card('账户资料', function (Card $card) {
                    $card->descriptions(function (Descriptions $d) {
                        $d->border(true)->column(2);
                        $d->item('账户名称', '{{info.name}}');
                        $d->item('接入渠道', '{{info.channel_text}}');
                    });
                });
            });
        });

        $page->row(function (Row $row) {                  // 第二行：接口凭证
            $row->col(24, function (Col $col) {
                $col->card('接口凭证', function (Card $card) {
                    $card->copyText('{{info.api_key}}', 'Api Key');   // 等宽 + 一键复制
                    $card->alert(null, '{{info.notice}}')
                        ->type('{{info.notice_type}}')->closable(false);
                });
            });
        });

        return $page;
    }
}
```

**② 数据接口** `GET /api/schema/my-info`

```php
public function myInfo(Request $request)
{
    $user = $request->user;                               // AuthCheck 注入的登录用户
    $row  = HfPlatformAccount::query()
        ->where('account', (string)$user->username)       // 归属口径：account = 登录用户名
        ->where('status', 1)
        ->first();

    return json(['code' => 200, 'msg' => 'ok', 'data' => $row ? [
        'username'    => (string)$user->username,
        'account'     => (string)$row->account,
        'name'        => (string)$row->name,
        'api_key'     => (string)$row->api_key,
        'amount'      => round((float)$row->amount, 2),   // statistic 要数字
        'channel_text'=> '中核英科',
        'notice_type' => 'info',
        'notice'      => 'Api Key 是接口调用的唯一凭证，请勿泄露。',
    ] : [
        'username'    => (string)$user->username,
        'account'     => '—', 'name' => '—', 'api_key' => '—', 'amount' => 0,
        'channel_text'=> '—',
        'notice_type' => 'warning',
        'notice'      => '未找到与登录账号「' . $user->username . '」关联的平台账户，请联系管理员开通。',
    ]]);
}
```

**③ 注册两处**（宿主 `config/route.php`）

```php
// 顶部：页面声明（页面引擎在插件，业务侧只声明）
PageRegistry::register('my-info', [\app\controller\admin\api\page\MyInfoPage::class, 'schema']);

// /api 分组内：数据接口（自动带 AuthCheck + PermissionCheck）
Route::get('/schema/my-info', [\app\controller\admin\api\SchemaPageDataController::class, 'myInfo']);
```

**④ 建菜单**：菜单管理页新增一条，`path = /custom-page/my-info`（`php start.php restart` 后生效）。

**⑤ 访问**：`http://<宿主域名>/app/curd/custom-page/my-info`，或直接点侧边栏菜单。

两个设计要点：

- **Schema 没有逻辑分支**：页面结构固定，"有没有账户"由数据接口返回占位值 + `notice/notice_type` 表达。
  连 `alert` 的 `type` 都能用占位符（`->type('{{info.notice_type}}')`），所以同一套结构既能显示 info 提示，
  也能显示 warning 警告，页面代码完全不用改；
- **权限天然够用**：`/api/custom/` 与 `/api/schema/` 在 `PermissionCheck` 白名单内（仅要求登录），
  因此任何账号都能打开这一页、看到自己那条数据，**不需要额外配权限节点**。
