#!/usr/bin/env node
/**
 * build_docs.cjs — Markdown 文档站生成器（自包含单 HTML）
 *
 * 用法：
 *   NODE_PATH=<managed node workspace>/node_modules node build_docs.cjs
 *   （如 NODE_PATH=/Users/xinyou2026/.workbuddy/binaries/node/workspace/node_modules）
 *
 * 功能：
 *   - 扫描本目录的上级 docs/ 下所有 *.md（多份文档自动并入左侧栏目，按文件名排序）
 *   - 每个 md 的第一个 H1 作为该文档标题；H2/H3(及以上) 生成嵌套目录树
 *   - 输出 docs/index.html：自包含（CSS/JS/内容全部内嵌），无网络依赖，双击即可打开
 *   - 左侧栏目树：文档分组 + 章节嵌套 + 点击跳转 + 滚动高亮 + 关键字过滤
 *   - 站内互链接管：正文里指向同目录其它 *.md 的链接自动变成「切面板」跳转，
 *     不必再依赖浏览器整页跳转（外链与 http(s) URL 原样保留）
 *
 * 约定：
 *   - 新增文档：把 NN-主题.md 放进 docs/，重跑本脚本即可并入左侧栏目（编号决定顺序）
 *   - 文档之间互相引用请写标准 Markdown 链接：[标题](NN-主题.md)
 *   - 排除：docs/tools/ 目录内的文件不会被扫描
 */
const fs = require('fs');
const path = require('path');
const { marked } = require('marked');

const DOCS_DIR = path.resolve(__dirname, '..'); // docs/
const OUT_FILE = path.join(DOCS_DIR, 'index.html');
// 代码高亮器单独成文件（正则里反斜杠太多，塞进模板字符串会被转义吃掉），构建期内联
const HIGHLIGHT_JS = fs.readFileSync(path.join(__dirname, 'highlight.js'), 'utf8');

// ---------- 标题树数据 ----------
const headings = []; // 当前文档的标题（每次 parse 前清空）
let headingCounter = 0; // 跨文档全局自增，保证锚点 id 全站唯一

/** 从行内 tokens 递归收集纯文本（剥掉 `code`、**bold**、[link]() 等标记） */
function inlineText(tokens) {
  let s = '';
  for (const t of tokens || []) {
    if (t.type === 'text' || t.type === 'codespan' || t.type === 'escape') {
      s += t.text || '';
    } else if (t.type === 'html') {
      // 标题内一般无 html，跳过
    } else if (Array.isArray(t.tokens) && t.tokens.length) {
      s += inlineText(t.tokens);
    } else if (typeof t.text === 'string') {
      s += t.text;
    }
  }
  return s.replace(/\s+/g, ' ').trim();
}

// marked 全局单例：只注册一次 renderer，标题注入稳定 id 并收集目录
marked.use({
  renderer: {
    heading(token) {
      const seq = ++headingCounter;
      const id = 'h-' + seq;
      const text = inlineText(token.tokens || []);
      headings.push({ depth: token.depth, text, id });
      const inner = this.parser ? this.parser.parseInline(token.tokens) : (token.text || '');
      return '<h' + token.depth + ' id="' + id + '">' + inner + '</h' + token.depth + '>\n';
    }
  }
});

/** 渲染一份 md，返回 { html, headings } */
function renderDoc(raw) {
  headings.length = 0;
  const html = marked.parse(raw);
  return { html, headings: headings.slice() };
}

// ---------- 扫描并渲染 ----------
// 栏目分区：按文件名前缀编号归组，并决定阅读顺序
//   00-09 入门与指南 → 10-19 使用指南 → 20-29 API 参考
const SECTIONS = [
  { key: 'start', name: '入门与指南', test: (n) => n >= 0 && n <= 9 },
  { key: 'guide', name: '使用指南', test: (n) => n >= 10 && n <= 19 },
  { key: 'api', name: 'API 参考', test: (n) => n >= 20 && n <= 29 },
];
const sectionOf = (num) => SECTIONS.find((s) => s.test(num)) || SECTIONS[SECTIONS.length - 1];
const fileNum = (f) => {
  const m = path.basename(f, '.md').match(/^(\d+)/);
  return m ? parseInt(m[1], 10) : 999;
};

const files = fs.readdirSync(DOCS_DIR)
  .filter((f) => f.endsWith('.md') && !['tools'].includes(f))
  .sort((a, b) => {
    const na = fileNum(a), nb = fileNum(b);
    const sa = SECTIONS.indexOf(sectionOf(na));
    const sb = SECTIONS.indexOf(sectionOf(nb));
    if (sa !== sb) return sa - sb;
    if (na !== nb) return na - nb;
    return a.localeCompare(b, 'zh-CN');
  });

if (files.length === 0) {
  console.error('docs 目录下没有 .md 文件，退出。');
  process.exit(1);
}

// 文件名（去扩展名）→ 文档序号；供正文互链改写用
const docJump = {};
files.forEach((f, i) => { docJump[path.basename(f, '.md')] = { index: i }; });

/**
 * 标题文本 → 锚点归一化键
 * 去掉所有非字母 / 数字 / 汉字字符（含空格、标点、`-`），用于把作者手写的
 * `#3-引入插件三选一` 与标题 `## 3. 引入插件（三选一）` 对上号。
 */
const slugKey = (s) => String(s).toLowerCase().replace(/[^\p{L}\p{N}]/gu, '');

/**
 * 把渲染结果里指向同目录其它 *.md 的链接换成交互式跳转锚点
 * （只处理站内相对路径；http(s):// 等带协议的外链原样保留）
 * 带 `#小节` 的链接会解析成目标文档里的标题 id，切面板后直接定位过去。
 */
function linkifyInternal(html) {
  return html.replace(/href="([^"]*)"/g, (whole, raw) => {
    if (/^[a-zA-Z][a-zA-Z0-9+.-]*:/.test(raw)) return whole; // 外链 / mailto …
    let href = raw;
    try { href = decodeURIComponent(href); } catch (e) { /* 编码异常则原样匹配 */ }
    const cut = href.indexOf('#');
    const pathPart = cut === -1 ? href : href.slice(0, cut);
    const anchor = cut === -1 ? '' : href.slice(cut + 1);
    if (!/\.md$/i.test(pathPart)) return whole;
    const base = path.basename(pathPart.replace(/\\/g, '/'), '.md');
    const hit = docJump[base];
    if (!hit) return whole;
    let extra = '';
    if (anchor) {
      const id = (docAnchors[base] || {})[slugKey(anchor)];
      if (id) extra = ' data-doc-anchor="' + id + '"';
    }
    return 'href="#" class="doc-link" data-doc-jump="' + hit.index + '"' + extra;
  });
}

const docs = [];
for (const file of files) {
  const abs = path.join(DOCS_DIR, file);
  const raw = fs.readFileSync(abs, 'utf8');
  const { html, headings: hd } = renderDoc(raw);
  const stat = fs.statSync(abs);

  let title = path.basename(file, '.md');
  const h1 = hd.find((h) => h.depth === 1);
  if (h1 && h1.text) title = h1.text;

  docs.push({
    file,
    title,
    section: sectionOf(fileNum(file)).key,
    sectionName: sectionOf(fileNum(file)).name,
    mtime: stat.mtime.toLocaleString('zh-CN', { hour12: false }),
    headings: hd.filter((h) => h.depth >= 2), // h1 作为文档标题已在左侧分组显示
    allHeadings: hd,                          // 锚点解析用（含 h1）
    html,                                     // 先留原始 HTML，等所有文档收齐再改写互链
  });
}

// 每篇文档：锚点键 → 标题 id（同名取第一个）
const docAnchors = {};
for (const d of docs) {
  const base = path.basename(d.file, '.md');
  const map = {};
  for (const h of d.allHeadings) {
    const k = slugKey(h.text);
    if (k && !(k in map)) map[k] = h.id;
  }
  docAnchors[base] = map;
}

// 统一改写互链 —— 必须等所有文档收齐，否则前向引用拿不到目标锚点
for (const d of docs) d.html = linkifyInternal(d.html);

// ---------- 页面模板 ----------
const PAGE_TITLE = 'webman-curd-admin 文档';
const STYLE = `
:root{
  --accent:#2563eb; --accent-soft:#eff6ff; --border:#e5e7eb; --text:#1f2937; --muted:#6b7280;
  --aside-w:300px; --header-h:56px; --code-bg:#23272f; --code-fg:#c8ccd4;
}
*{box-sizing:border-box}
html,body{margin:0;padding:0}
body{font:14px/1.75 -apple-system,BlinkMacSystemFont,"PingFang SC","Hiragino Sans GB","Microsoft YaHei","Helvetica Neue",Arial,sans-serif;color:var(--text);background:#f6f7f9}
a{color:var(--accent);text-decoration:none}
a:hover{text-decoration:underline}

/* ===== 顶栏 ===== */
.site-header{position:sticky;top:0;z-index:50;height:var(--header-h);display:flex;align-items:center;gap:12px;
  padding:0 16px;background:#fff;border-bottom:1px solid var(--border)}
.site-title{font-size:16px;font-weight:700;display:flex;align-items:center;gap:8px;min-width:0;flex:0 1 auto}
.site-title .brand{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.site-title .logo{width:22px;height:22px;border-radius:6px;background:var(--accent);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;flex-shrink:0}
.doc-count{font-size:12px;color:var(--muted);background:#f3f4f6;border-radius:999px;padding:2px 10px;white-space:nowrap;flex-shrink:0}
.head-links{display:flex;align-items:center;gap:14px;flex-shrink:0;font-size:13px;color:#4b5563}
.head-links a{color:#4b5563}
.head-links a:hover{color:var(--accent);text-decoration:none}
.search-box{flex:1 1 auto;min-width:0;max-width:380px;margin-left:auto;position:relative}
.search-box input{width:100%;min-width:0;height:34px;border:1px solid var(--border);border-radius:8px;padding:0 12px 0 34px;font-size:13px;outline:none;background:#fff;color:var(--text)}
.search-box input:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(37,99,235,.12)}
.search-box .s-ico{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#9ca3af;font-size:13px;pointer-events:none}

/* ===== 主体布局 ===== */
.layout{display:flex;min-height:calc(100vh - var(--header-h))}

/* ===== 左侧栏目 ===== */
.sidebar{width:var(--aside-w);flex-shrink:0;background:#fff;border-right:1px solid var(--border);overflow-y:auto;position:sticky;top:var(--header-h);height:calc(100vh - var(--header-h));padding:12px 8px 40px}
.tree-empty{padding:16px 12px;color:#9ca3af;font-size:13px;display:none}
.doc-group{margin-bottom:4px}
.doc-title{display:flex;align-items:center;gap:8px;padding:7px 10px;border-radius:6px;cursor:pointer;font-weight:700;font-size:13.5px;line-height:1.45;color:var(--text);user-select:none}
.doc-title:hover{background:#f3f4f6}
.doc-title .dot{width:7px;height:7px;border-radius:50%;background:#cbd5e1;flex-shrink:0;transition:background .15s}
.doc-title .tname{flex:1;min-width:0;word-break:break-word}
.doc-title .chev{flex-shrink:0;width:12px;height:12px;color:#9ca3af;transition:transform .18s ease}
.doc-group:not(.collapsed) .doc-title .chev{transform:rotate(90deg)}
.doc-group.active .doc-title{color:var(--accent)}
.doc-group.active .doc-title .dot{background:var(--accent)}
/* 折叠：非当前文档默认收起（laravel-admin 风格），点标题展开 */
.doc-group.collapsed .tree{display:none}
.sidebar.searching .doc-group .tree{display:block}
.tree{list-style:none;margin:2px 0 4px;padding:0}
.tree li{margin:0}
.tree a{display:block;padding:5px 10px 5px 26px;border-radius:6px;color:#4b5563;font-size:13px;border-left:2px solid transparent;cursor:pointer;line-height:1.5}
.tree a:hover{background:#f3f4f6;color:var(--text);text-decoration:none}
.tree a.active{color:var(--accent);background:var(--accent-soft);border-left-color:var(--accent);font-weight:600}
.tree ul{list-style:none;margin:0;padding:0}
.tree .lvl3 a{padding-left:42px;font-size:12.5px;color:#6b7280}
.tree .lvl3 a.active{color:var(--accent)}
.tree .lvl4 a{padding-left:58px;font-size:12px;color:#9ca3af}
.sidebar-foot{margin-top:16px;padding:10px 12px;font-size:11px;color:#c0c4cc;border-top:1px dashed #eee}

/* ===== 右侧内容 ===== */
.content{flex:1;min-width:0;padding:26px 36px 80px}
.doc-panel{display:none;max-width:1160px;margin:0 auto;background:#fff;border:1px solid var(--border);border-radius:12px;padding:32px 44px 46px;box-shadow:0 1px 2px rgba(0,0,0,.03)}
.doc-panel.active{display:block}
.panel-meta{display:flex;align-items:center;gap:10px;margin-bottom:20px;padding-bottom:14px;border-bottom:1px solid #f0f1f3;color:#9ca3af;font-size:12px;flex-wrap:wrap}
.panel-meta .chip{background:#f3f4f6;border-radius:6px;padding:2px 10px;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:11px;color:#6b7280}
.back-top{position:fixed;right:26px;bottom:30px;width:40px;height:40px;border-radius:10px;background:var(--accent);color:#fff;border:none;font-size:18px;cursor:pointer;box-shadow:0 6px 16px rgba(37,99,235,.35);display:none;z-index:60}
.back-top.show{display:block}

/* ===== 正文排版 ===== */
.doc-body h1{font-size:27px;margin:0 0 6px;line-height:1.4;letter-spacing:.2px}
.doc-body h2{font-size:20px;margin:36px 0 12px;padding-bottom:8px;border-bottom:1px solid #eef0f3;line-height:1.5}
.doc-body h3{font-size:16px;margin:26px 0 9px;color:#111827;line-height:1.55}
.doc-body h4{font-size:14.5px;margin:20px 0 8px;color:#374151}
.doc-body p{margin:10px 0}
.doc-body ul,.doc-body ol{margin:10px 0;padding-left:26px}
.doc-body li{margin:4px 0}
.doc-body strong{font-weight:700}
.doc-body a{word-break:break-all}
.doc-body a.doc-link{border-bottom:1px dashed currentColor;word-break:normal}
.doc-body a.doc-link::after{content:" →";font-size:11px;opacity:.55}
.doc-body td a.doc-link::after,.doc-body th a.doc-link::after{content:""}
.doc-body blockquote{margin:14px 0;padding:10px 16px;background:#fffbeb;border-left:4px solid #f59e0b;border-radius:0 8px 8px 0;color:#78350f}
.doc-body blockquote p{margin:4px 0}
.doc-body hr{border:none;border-top:1px solid var(--border);margin:26px 0}
.doc-body img{max-width:100%}
.doc-body code{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,"Courier New",monospace;font-size:12.5px;background:#f2f4f8;color:#d6336c;padding:2px 6px;border-radius:4px}
.doc-body pre{background:var(--code-bg);color:var(--code-fg);border-radius:8px;padding:14px 16px;overflow-x:auto;line-height:1.65;margin:14px 0;tab-size:4}
.doc-body pre code{background:none;color:inherit;padding:0;font-size:12.8px;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,"Courier New",monospace}
.doc-body table{border-collapse:collapse;width:100%;margin:14px 0;font-size:13px;display:block;overflow-x:auto}
.doc-body th,.doc-body td{border:1px solid #e5e7eb;padding:7px 12px;text-align:left;vertical-align:top}
.doc-body th{background:#f8fafc;font-weight:700;white-space:nowrap}
.doc-body tr:nth-child(even) td{background:#fafbfc}
.doc-body [id^="h-"]{scroll-margin-top:72px}

/* ===== 代码高亮（One Dark 近似） ===== */
.tok-com{color:#7f8c98;font-style:italic}
.tok-str{color:#98c379}
.tok-num{color:#d19a66}
.tok-kw{color:#c678dd}
.tok-fn{color:#61afef}
.tok-var{color:#e06c75}
.tok-tag{color:#e06c75}
.tok-attr{color:#d19a66}
.tok-op{color:#56b6c2}

/* ===== 栏目分区 ===== */
.section-title{display:flex;align-items:center;gap:6px;margin:16px 6px 4px;padding:2px 6px;font-size:11.5px;font-weight:800;letter-spacing:.6px;color:#6b7280}
.section-title .sec-ico{width:13px;height:13px;flex-shrink:0;color:#9ca3af}
.section-title:first-child{margin-top:2px}

/* ===== 代码块复制 / 语言标签 ===== */
.code-wrap{position:relative}
.code-wrap pre{margin:14px 0}
.code-copy{position:absolute;top:8px;right:8px;border:1px solid rgba(255,255,255,.18);background:rgba(255,255,255,.08);color:#cbd5e1;
  font-size:11px;padding:3px 9px;border-radius:6px;cursor:pointer;opacity:0;transition:opacity .15s;z-index:2}
.code-lang{position:absolute;top:9px;right:56px;font-size:10.5px;letter-spacing:.6px;text-transform:uppercase;color:#6b7280;opacity:0;transition:opacity .15s;z-index:2;pointer-events:none}
.code-wrap:hover .code-copy,.code-wrap:hover .code-lang{opacity:1}
.code-copy:hover{background:rgba(255,255,255,.18)}
.code-copy.done{color:#86efac;border-color:rgba(134,239,172,.4)}

/* ===== 上一篇 / 下一篇 ===== */
.panel-nav{display:flex;gap:12px;margin-top:34px;padding-top:18px;border-top:1px solid #f0f1f3}
.panel-nav button{flex:1;min-width:0;display:flex;flex-direction:column;gap:2px;padding:10px 14px;border:1px solid var(--border);
  background:#fff;border-radius:10px;cursor:pointer;text-align:left;font:inherit;color:var(--text)}
.panel-nav button:hover{border-color:var(--accent);background:var(--accent-soft)}
.panel-nav .dir{font-size:11px;color:#9ca3af}
.panel-nav .name{font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.panel-nav .next{margin-left:auto;text-align:right;align-items:flex-end}
.panel-nav button:disabled{opacity:.35;cursor:not-allowed}

/* ===== 移动端抽屉 ===== */
.menu-btn{display:none;width:36px;height:36px;border:1px solid var(--border);background:#fff;border-radius:8px;cursor:pointer;
  font-size:16px;line-height:1;color:var(--text);flex-shrink:0}
.sidebar-mask{display:none;position:fixed;inset:var(--header-h) 0 0 0;background:rgba(15,23,42,.35);z-index:40}

@media (max-width:1100px){
  :root{--aside-w:260px}
  .content{padding:20px 22px 70px}
  .doc-panel{padding:24px 26px 38px}
}

@media (max-width:860px){
  :root{--header-h:52px}
  .menu-btn{display:inline-flex;align-items:center;justify-content:center}
  .site-title{font-size:14px}
  .doc-count,.head-links{display:none}
  .search-box{max-width:none;margin-left:0}
  .layout{display:block}
  .sidebar{position:fixed;top:var(--header-h);left:0;bottom:0;width:84%;max-width:330px;height:auto;max-height:none;
    transform:translateX(-102%);transition:transform .22s ease;z-index:45;border-right:1px solid var(--border);
    box-shadow:0 12px 30px rgba(15,23,42,.18);padding:10px 8px 40px}
  body.nav-open .sidebar{transform:translateX(0)}
  body.nav-open .sidebar-mask{display:block}
  .content{padding:14px 12px 60px}
  .doc-panel{padding:18px 15px 32px;border-radius:10px}
  .doc-body h1{font-size:22px}
  .doc-body h2{font-size:18px;margin:26px 0 10px}
  .doc-body h3{font-size:15px}
  .doc-body pre{font-size:12px;padding:12px}
  /* 触屏没有 hover，复制按钮 / 语言标签常显 */
  .code-copy{opacity:1}
  .code-lang{opacity:1;right:52px}
  /* 窄屏表格：首列不换行（否则中文被挤成竖排），其余照常换行，整表横向滚动 */
  .doc-body table{font-size:12.5px}
  .doc-body td:first-child{white-space:nowrap}
  .doc-body th,.doc-body td{padding:6px 9px}
  .panel-nav{flex-direction:column}
  .panel-nav .next{margin-left:0;text-align:left;align-items:flex-start}
  .back-top{right:14px;bottom:18px;width:36px;height:36px}
}

@media (max-width:420px){
  .site-header{padding:0 10px;gap:8px}
  .search-box input{height:32px;font-size:12.5px}
}
`;

const SCRIPT = `
(function () {
  var DATA = window.__DOCS_DATA__ || [];
  var aside = document.getElementById('tree');
  var panels = document.getElementById('panels');
  var treeEmpty = document.getElementById('treeEmpty');
  var backTop = document.getElementById('backTop');

  // 分区小图标（描边风格，跟 laravel-admin 的分组图标一个意思）
  var SECTION_ICONS = {
    start: 'M4 5.5A2.5 2.5 0 0 1 6.5 3H19v15H6.5A2.5 2.5 0 0 0 4 20.5zM19 18v3H6.5A2.5 2.5 0 0 1 4 18.5',
    guide: 'M12 3l9 4.5-9 4.5-9-4.5zM3 12.5l9 4.5 9-4.5M3 17l9 4.5 9-4.5',
    api: 'M9 7l-5 5 5 5M15 7l5 5-5 5'
  };

  // ---------- 渲染左侧栏目树（DOM 构建，天然成树/自动转义） ----------
  function buildSidebar() {
    aside.innerHTML = '';
    if (treeEmpty) aside.appendChild(treeEmpty); // 清空会带走「未找到匹配栏目」提示，重新挂回
    var lastSection = null;
    for (var i = 0; i < DATA.length; i++) {
      var doc = DATA[i];
      if (doc.section !== lastSection) {
        var st = document.createElement('div');
        st.className = 'section-title';
        var path = SECTION_ICONS[doc.section];
        if (path) {
          var ico = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
          ico.setAttribute('class', 'sec-ico');
          ico.setAttribute('viewBox', '0 0 24 24');
          ico.setAttribute('aria-hidden', 'true');
          ico.innerHTML = '<path d="' + path + '" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>';
          st.appendChild(ico);
        }
        var stxt = document.createElement('span');
        stxt.textContent = doc.sectionName || '';
        st.appendChild(stxt);
        aside.appendChild(st);
        lastSection = doc.section;
      }
      var group = document.createElement('div');
      group.className = 'doc-group';
      group.setAttribute('data-doc', i);

      var title = document.createElement('div');
      title.className = 'doc-title';
      title.setAttribute('data-doc', i);
      var dot = document.createElement('span');
      dot.className = 'dot';
      title.appendChild(dot);
      var tname = document.createElement('span');
      tname.className = 'tname';
      tname.textContent = doc.title;
      title.appendChild(tname);
      // 折叠箭头（当前文档展开，其余收起；点标题切换）
      var chev = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
      chev.setAttribute('class', 'chev');
      chev.setAttribute('viewBox', '0 0 24 24');
      chev.setAttribute('aria-hidden', 'true');
      chev.innerHTML = '<path d="M9 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>';
      title.appendChild(chev);
      title.title = doc.file;   // 悬停看源文件名（面板顶部也有「来源」）
      group.appendChild(title);

      // 嵌套目录树：rootUl 挂 2 级；3/4 级挂到最近的上级 li 下（惰性建 ul）
      var rootUl = document.createElement('ul');
      rootUl.className = 'tree';
      var lastByDepth = { 1: rootUl };
      for (var j = 0; j < doc.headings.length; j++) {
        var h = doc.headings[j];
        if (h.depth <= 1) continue;
        var li = document.createElement('li');
        var lvlClass = h.depth >= 4 ? 'lvl4' : (h.depth === 3 ? 'lvl3' : 'lvl2');
        li.className = lvlClass;
        var a = document.createElement('a');
        a.setAttribute('data-doc', i);
        a.setAttribute('data-target', h.id);
        a.textContent = h.text;
        li.appendChild(a);
        // 挂载点：向上找最近已存在的上一级
        var d = h.depth - 1;
        while (d >= 1 && !lastByDepth[d]) d--;
        var parent = d < 1 ? rootUl : lastByDepth[d];
        if (parent !== rootUl) {
          if (!parent._ul) {
            parent._ul = document.createElement('ul');
            parent.appendChild(parent._ul);
          }
          parent._ul.appendChild(li);
        } else {
          rootUl.appendChild(li);
        }
        lastByDepth[h.depth] = li;
      }
      if (rootUl.children.length) group.appendChild(rootUl);
      aside.appendChild(group);
    }
  }

  // ---------- 渲染右侧内容面板（DOM 构建，文本自动转义） ----------
  function buildPanels() {
    panels.innerHTML = '';
    for (var i = 0; i < DATA.length; i++) {
      var d = DATA[i];

      var sec = document.createElement('section');
      sec.className = 'doc-panel';
      sec.setAttribute('data-doc', i);

      var meta = document.createElement('div');
      meta.className = 'panel-meta';
      meta.appendChild(document.createTextNode('来源：'));
      var fnameChip = document.createElement('span');
      fnameChip.className = 'chip';
      fnameChip.textContent = d.file;
      meta.appendChild(fnameChip);
      meta.appendChild(document.createTextNode(' '));
      var timeChip = document.createElement('span');
      timeChip.className = 'chip';
      timeChip.textContent = '更新于 ' + d.mtime;
      meta.appendChild(timeChip);

      var body = document.createElement('div');
      body.className = 'doc-body';
      // 内容来自构建器渲染的 markdown HTML，受信源（仅扫描 docs/ 下文件）
      body.innerHTML = d.html;

      sec.appendChild(meta);
      sec.appendChild(body);

      // 代码块复制按钮
      var pres = body.querySelectorAll('pre');
      for (var p = 0; p < pres.length; p++) {
        var pre = pres[p];
        if (pre.parentNode && pre.parentNode.classList && pre.parentNode.classList.contains('code-wrap')) continue;
        var wrap = document.createElement('div');
        wrap.className = 'code-wrap';
        pre.parentNode.insertBefore(wrap, pre);
        wrap.appendChild(pre);
        var btn = document.createElement('button');
        btn.className = 'code-copy';
        btn.type = 'button';
        btn.textContent = '复制';
        var lang = window.__curdHl.langOf(pre.querySelector('code'));
        if (lang) {
          var tag = document.createElement('span');
          tag.className = 'code-lang';
          tag.textContent = lang;
          wrap.appendChild(tag);
        }
        (function (b, src) {
          b.addEventListener('click', function () {
            var txt = src.innerText || src.textContent || '';
            var done = function () {
              b.textContent = '已复制';
              b.classList.add('done');
              setTimeout(function () { b.textContent = '复制'; b.classList.remove('done'); }, 1400);
            };
            if (navigator.clipboard && window.isSecureContext) {
              navigator.clipboard.writeText(txt).then(done, fallback);
            } else { fallback(); }
            function fallback() {
              var ta = document.createElement('textarea');
              ta.value = txt; ta.style.position = 'fixed'; ta.style.opacity = '0';
              document.body.appendChild(ta); ta.select();
              try { document.execCommand('copy'); done(); } catch (e) { /* ignore */ }
              ta.remove();
            }
          });
        })(btn, pre);
        wrap.appendChild(btn);
      }

      // 上一篇 / 下一篇
      var nav = document.createElement('div');
      nav.className = 'panel-nav';
      var prev = document.createElement('button');
      prev.type = 'button';
      prev.className = 'prev';
      prev.innerHTML = '<span class="dir">← 上一篇</span><span class="name"></span>';
      prev.querySelector('.name').textContent = i > 0 ? DATA[i - 1].title : '已经是第一篇';
      prev.disabled = i === 0;
      if (i > 0) prev.addEventListener('click', function (idx) { return function () { goDoc(idx); }; }(i - 1));
      var next = document.createElement('button');
      next.type = 'button';
      next.className = 'next';
      next.innerHTML = '<span class="dir">下一篇 →</span><span class="name"></span>';
      next.querySelector('.name').textContent = i < DATA.length - 1 ? DATA[i + 1].title : '已经是最后一篇';
      next.disabled = i === DATA.length - 1;
      if (i < DATA.length - 1) next.addEventListener('click', function (idx) { return function () { goDoc(idx); }; }(i + 1));
      nav.appendChild(prev);
      nav.appendChild(next);
      sec.appendChild(nav);

      panels.appendChild(sec);
    }
  }

  // ---------- 激活文档 ----------
  var currentDoc = 0;
  function setActiveDoc(idx) {
    currentDoc = idx;
    var ps = panels.querySelectorAll('.doc-panel');
    for (var i = 0; i < ps.length; i++) ps[i].classList.toggle('active', i === idx);
    var gs = aside.querySelectorAll('.doc-group');
    for (var j = 0; j < gs.length; j++) {
      var on = j === idx;
      gs[j].classList.toggle('active', on);
      // 只展开当前文档的目录树，其余收起（侧栏一屏能看全）
      gs[j].classList.toggle('collapsed', !on);
    }
    if (ps[idx]) window.__curdHl.panel(ps[idx]);
  }

  // ---------- 移动端抽屉 ----------
  function openNav() { document.body.classList.add('nav-open'); }
  function closeNav() { document.body.classList.remove('nav-open'); }

  function goDoc(idx) {
    if (idx < 0 || idx >= DATA.length) return;
    setActiveDoc(idx);
    markActive(null);
    window.scrollTo({ top: 0, behavior: 'smooth' });
    closeNav();
  }

  // ---------- 跳转锚点 ----------
  function jump(targetId) {
    var el = document.getElementById(targetId);
    if (!el) return;
    var panel = el.closest('.doc-panel');
    if (panel) {
      var idx = parseInt(panel.getAttribute('data-doc'), 10);
      if (idx !== currentDoc) setActiveDoc(idx);
    }
    closeNav();
    requestAnimationFrame(function () {
      el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  }

  // ---------- 事件绑定 ----------
  aside.addEventListener('click', function (e) {
    var a = e.target.closest('a[data-target]');
    if (a) { e.preventDefault(); jump(a.getAttribute('data-target')); markActive(a); closeNav(); return; }
    var t = e.target.closest('.doc-title');
    if (t) {
      var idx = parseInt(t.getAttribute('data-doc'), 10);
      var grp = t.parentNode;
      if (idx === currentDoc) {
        // 点当前文档标题 = 折叠 / 展开它（laravel-admin 式手风琴）
        grp.classList.toggle('collapsed');
        return;
      }
      setActiveDoc(idx);
      window.scrollTo({ top: 0, behavior: 'smooth' });
      closeNav();
    }
  });

  function markActive(a) {
    var prev = aside.querySelector('a.active');
    if (prev) prev.classList.remove('active');
    if (a) a.classList.add('active');
  }

  // ---------- 正文里的站内互链：切换到对应文档面板 ----------
  panels.addEventListener('click', function (e) {
    var a = e.target.closest('a.doc-link');
    if (!a) return;
    e.preventDefault();
    var idx = parseInt(a.getAttribute('data-doc-jump'), 10);
    if (isNaN(idx) || idx < 0 || idx >= DATA.length) return;
    var anchor = a.getAttribute('data-doc-anchor');
    if (anchor) { jump(anchor); return; }   // 带小节锚点 → 切面板 + 定位
    setActiveDoc(idx);
    markActive(null);
    window.scrollTo({ top: 0, behavior: 'smooth' });
    closeNav();
  });

  // 滚动高亮当前阅读标题
  var ticking = false;
  function onScroll() {
    if (ticking) return;
    ticking = true;
    requestAnimationFrame(function () {
      ticking = false;
      var panel = panels.querySelector('.doc-panel.active');
      if (!panel) return;
      var heads = panel.querySelectorAll('.doc-body [id^="h-"]');
      var cur = null;
      for (var i = 0; i < heads.length; i++) {
        var r = heads[i].getBoundingClientRect();
        if (r.top <= 90) cur = heads[i]; else break;
      }
      if (cur) {
        var a = aside.querySelector('a[data-target="' + cur.id + '"]');
        markActive(a);
      }
      backTop.classList.toggle('show', (window.pageYOffset || document.documentElement.scrollTop) > 480);
    });
  }
  window.addEventListener('scroll', onScroll, { passive: true });

  backTop.addEventListener('click', function () {
    window.scrollTo({ top: 0, behavior: 'smooth' });
  });

  // ---------- 关键字过滤（只过滤栏目树） ----------
  var q = document.getElementById('q');
  q.addEventListener('input', function () {
    var kw = q.value.trim().toLowerCase();
    // 搜索时临时展开所有分组（.searching 覆盖折叠），清空后恢复手风琴状态
    aside.classList.toggle('searching', !!kw);
    var links = aside.querySelectorAll('.tree a');
    var groups = aside.querySelectorAll('.doc-group');
    var any = false;
    for (var i = 0; i < links.length; i++) {
      var li = links[i].closest('li');
      var hit = !kw || links[i].textContent.toLowerCase().indexOf(kw) !== -1;
      li.style.display = hit ? '' : 'none';
      if (hit) any = true;
    }
    for (var g = 0; g < groups.length; g++) {
      var vis = groups[g].querySelector('.tree li');
      groups[g].style.display = vis && vis.style.display !== 'none' ? '' : 'none';
      if (kw && !any) { /* 文档级命中也显示 */ }
    }
    // 若文档标题命中但章节没命中，展开全部章节
    if (kw) {
      for (var gg = 0; gg < groups.length; gg++) {
        var titleHit = groups[gg].querySelector('.doc-title').textContent.toLowerCase().indexOf(kw) !== -1;
        var items = groups[gg].querySelectorAll('.tree li');
        if (titleHit) { for (var k = 0; k < items.length; k++) items[k].style.display = ''; any = true; }
        else { var has = false; for (var m = 0; m < items.length; m++) { if (items[m].style.display !== 'none') has = true; } groups[gg].style.display = has ? '' : 'none'; }
      }
    }
    treeEmpty.style.display = any ? 'none' : 'block';
  });

  // ---------- 初始化 ----------
  document.getElementById('totalDocs').textContent = DATA.length + ' 篇';
  buildSidebar();
  buildPanels();
  setActiveDoc(0);

  // 移动端抽屉开关
  var menuBtn = document.getElementById('menuBtn');
  var mask = document.getElementById('sidebarMask');
  if (menuBtn) menuBtn.addEventListener('click', function () { document.body.classList.toggle('nav-open'); });
  if (mask) mask.addEventListener('click', closeNav);

  // 搜索框快捷键：/ 聚焦，Esc 清空
  document.addEventListener('keydown', function (e) {
    if (e.key === '/' && document.activeElement !== q) { e.preventDefault(); q.focus(); }
    if (e.key === 'Escape' && document.activeElement === q) { q.value = ''; q.dispatchEvent(new Event('input')); q.blur(); }
  });
})();
`;

function buildHtml() {
  const dataJson = JSON.stringify(docs).replace(/</g, '\\u003c');
  const html = `<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>${PAGE_TITLE}</title>
<style>${STYLE}</style>
</head>
<body>
<header class="site-header">
  <button class="menu-btn" id="menuBtn" type="button" aria-label="目录">&#9776;</button>
  <div class="site-title"><span class="logo">文</span><span class="brand">${PAGE_TITLE}</span><span class="doc-count" id="totalDocs"></span></div>
  <div class="search-box">
    <span class="s-ico">&#128269;</span>
    <input id="q" type="text" placeholder="搜索栏目（按 / 聚焦）" autocomplete="off">
  </div>
  <nav class="head-links">
    <a href="https://github.com/a1586256143/webman-curd-admin" target="_blank" rel="noopener">GitHub</a>
    <a href="https://github.com/a1586256143/webman-curd-admin/blob/main/INSTALL.md" target="_blank" rel="noopener">INSTALL</a>
  </nav>
</header>
<div class="layout">
  <aside class="sidebar" id="tree">
    <div class="tree-empty" id="treeEmpty">未找到匹配栏目</div>
  </aside>
  <div class="sidebar-mask" id="sidebarMask"></div>
  <main class="content" id="panels"></main>
</div>
<button class="back-top" id="backTop" title="回到顶部">&#8593;</button>
<script>window.__DOCS_DATA__ = ${dataJson};</script>
<script>${HIGHLIGHT_JS}</script>
<script>${SCRIPT}</script>
</body>
</html>`;
  return html;
}

// 简易自校验：树结构在浏览器端生成，这里只保证输出与文件写入
fs.writeFileSync(OUT_FILE, buildHtml(), 'utf8');
console.log('docs 扫描:', files.length, '篇');
for (const d of docs) {
  console.log('  -', d.file, '|', d.title, '|', d.headings.length, '节');
}
console.log('输出:', OUT_FILE, '(' + (fs.statSync(OUT_FILE).size / 1024).toFixed(1) + ' KB)');
