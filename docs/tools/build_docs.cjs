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
 *
 * 约定：
 *   - 新增文档：把 xxx.md 放进 docs/，重跑本脚本即可并入左侧栏目
 *   - 排除：docs/tools/ 目录内的文件不会被扫描
 */
const fs = require('fs');
const path = require('path');
const { marked } = require('marked');

const DOCS_DIR = path.resolve(__dirname, '..'); // docs/
const OUT_FILE = path.join(DOCS_DIR, 'index.html');

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
const files = fs.readdirSync(DOCS_DIR)
  .filter((f) => f.endsWith('.md') && !['tools'].includes(f))
  .sort((a, b) => a.localeCompare(b, 'zh-CN'));

if (files.length === 0) {
  console.error('docs 目录下没有 .md 文件，退出。');
  process.exit(1);
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
    mtime: stat.mtime.toLocaleString('zh-CN', { hour12: false }),
    headings: hd.filter((h) => h.depth >= 2), // h1 作为文档标题已在左侧分组显示
    html,
  });
}

// ---------- 页面模板 ----------
const PAGE_TITLE = '后台 DSL 文档';
const STYLE = `
:root{
  --accent:#2563eb; --accent-soft:#eff6ff; --border:#e5e7eb; --text:#1f2937; --muted:#6b7280;
  --aside-w:300px; --header-h:56px; --code-bg:#0f172a; --code-fg:#e2e8f0;
}
*{box-sizing:border-box}
html,body{margin:0;padding:0}
body{font:14px/1.75 -apple-system,BlinkMacSystemFont,"PingFang SC","Hiragino Sans GB","Microsoft YaHei","Helvetica Neue",Arial,sans-serif;color:var(--text);background:#f6f7f9}
a{color:var(--accent);text-decoration:none}
a:hover{text-decoration:underline}

/* ===== 顶栏 ===== */
.site-header{position:sticky;top:0;z-index:50;height:var(--header-h);display:flex;align-items:center;gap:16px;
  padding:0 20px;background:#fff;border-bottom:1px solid var(--border)}
.site-title{font-size:16px;font-weight:700;white-space:nowrap;display:flex;align-items:center;gap:8px}
.site-title .logo{width:22px;height:22px;border-radius:6px;background:var(--accent);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:12px;font-weight:800}
.doc-count{font-size:12px;color:var(--muted);background:#f3f4f6;border-radius:999px;padding:2px 10px;white-space:nowrap}
.search-box{flex:1;max-width:380px;margin-left:auto;position:relative}
.search-box input{width:100%;height:34px;border:1px solid var(--border);border-radius:8px;padding:0 12px 0 34px;font-size:13px;outline:none;background:#fff;color:var(--text)}
.search-box input:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(37,99,235,.12)}
.search-box .s-ico{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#9ca3af;font-size:13px;pointer-events:none}

/* ===== 主体布局 ===== */
.layout{display:flex;min-height:calc(100vh - var(--header-h))}

/* ===== 左侧栏目 ===== */
.sidebar{width:var(--aside-w);flex-shrink:0;background:#fff;border-right:1px solid var(--border);overflow-y:auto;position:sticky;top:var(--header-h);height:calc(100vh - var(--header-h));padding:12px 8px 40px}
.tree-empty{padding:16px 12px;color:#9ca3af;font-size:13px;display:none}
.doc-group{margin-bottom:10px}
.doc-title{display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:8px;cursor:pointer;font-weight:700;font-size:14px;color:var(--text);user-select:none}
.doc-title:hover{background:#f3f4f6}
.doc-title .dot{width:8px;height:8px;border-radius:50%;background:var(--accent);flex-shrink:0}
.doc-title .fname{margin-left:auto;font-size:11px;font-weight:400;color:#9ca3af;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:110px}
.doc-group.active .doc-title{background:var(--accent-soft);color:var(--accent)}
.tree{list-style:none;margin:2px 0 0;padding:0}
.tree li{margin:0}
.tree a{display:block;padding:5px 10px 5px 26px;border-radius:6px;color:#374151;font-size:13px;border-left:2px solid transparent;cursor:pointer}
.tree a:hover{background:#f3f4f6;text-decoration:none}
.tree a.active{color:var(--accent);background:var(--accent-soft);border-left-color:var(--accent);font-weight:600}
.tree ul{list-style:none;margin:0;padding:0}
.tree .lvl3 a{padding-left:44px;font-size:12.5px;color:#6b7280}
.tree .lvl3 a.active{color:var(--accent)}
.tree .lvl4 a{padding-left:62px;font-size:12px;color:#9ca3af}
.sidebar-foot{margin-top:16px;padding:10px 12px;font-size:11px;color:#c0c4cc;border-top:1px dashed #eee}

/* ===== 右侧内容 ===== */
.content{flex:1;min-width:0;padding:28px 40px 80px}
.doc-panel{display:none;max-width:1020px;margin:0 auto;background:#fff;border:1px solid var(--border);border-radius:12px;padding:34px 44px 48px;box-shadow:0 1px 3px rgba(0,0,0,.04)}
.doc-panel.active{display:block}
.panel-meta{display:flex;align-items:center;gap:10px;margin-bottom:22px;padding-bottom:16px;border-bottom:1px solid #f0f1f3;color:#9ca3af;font-size:12px;flex-wrap:wrap}
.panel-meta .chip{background:#f3f4f6;border-radius:6px;padding:2px 10px;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:11px;color:#6b7280}
.back-top{position:fixed;right:26px;bottom:30px;width:40px;height:40px;border-radius:10px;background:var(--accent);color:#fff;border:none;font-size:18px;cursor:pointer;box-shadow:0 6px 16px rgba(37,99,235,.35);display:none;z-index:60}
.back-top.show{display:block}

/* ===== 正文排版 ===== */
.doc-body h1{font-size:27px;margin:0 0 6px;line-height:1.4;letter-spacing:.2px}
.doc-body h2{font-size:20px;margin:38px 0 14px;padding:8px 0 8px 12px;border-left:4px solid var(--accent);background:linear-gradient(90deg,var(--accent-soft),transparent 70%);border-radius:0 8px 8px 0}
.doc-body h3{font-size:16px;margin:28px 0 10px;color:#111827;border-bottom:1px dashed #e5e7eb;padding-bottom:6px}
.doc-body h4{font-size:14.5px;margin:22px 0 8px;color:#374151}
.doc-body p{margin:10px 0}
.doc-body ul,.doc-body ol{margin:10px 0;padding-left:26px}
.doc-body li{margin:4px 0}
.doc-body strong{font-weight:700}
.doc-body a{word-break:break-all}
.doc-body blockquote{margin:14px 0;padding:10px 16px;background:#fffbeb;border-left:4px solid #f59e0b;border-radius:0 8px 8px 0;color:#78350f}
.doc-body blockquote p{margin:4px 0}
.doc-body hr{border:none;border-top:1px solid var(--border);margin:26px 0}
.doc-body img{max-width:100%}
.doc-body code{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,"Courier New",monospace;font-size:12.5px;background:#eef1f6;color:#be185d;padding:2px 6px;border-radius:5px}
.doc-body pre{background:var(--code-bg);color:var(--code-fg);border-radius:10px;padding:14px 16px;overflow-x:auto;line-height:1.65;margin:14px 0}
.doc-body pre code{background:none;color:inherit;padding:0;font-size:12.8px}
.doc-body table{border-collapse:collapse;width:100%;margin:14px 0;font-size:13px;display:block;overflow-x:auto}
.doc-body th,.doc-body td{border:1px solid #e5e7eb;padding:7px 12px;text-align:left;vertical-align:top}
.doc-body th{background:#f8fafc;font-weight:700;white-space:nowrap}
.doc-body tr:nth-child(even) td{background:#fafbfc}
.doc-body [id^="h-"]{scroll-margin-top:72px}

@media (max-width:860px){
  .layout{flex-direction:column}
  .sidebar{width:100%;position:static;height:auto;max-height:40vh;border-right:none;border-bottom:1px solid var(--border)}
  .content{padding:18px}
  .doc-panel{padding:20px 18px}
  .search-box{max-width:none}
}
`;

const SCRIPT = `
(function () {
  var DATA = window.__DOCS_DATA__ || [];
  var aside = document.getElementById('tree');
  var panels = document.getElementById('panels');
  var treeEmpty = document.getElementById('treeEmpty');
  var backTop = document.getElementById('backTop');

  // ---------- 渲染左侧栏目树（DOM 构建，天然成树/自动转义） ----------
  function buildSidebar() {
    aside.innerHTML = '';
    for (var i = 0; i < DATA.length; i++) {
      var doc = DATA[i];
      var group = document.createElement('div');
      group.className = 'doc-group';
      group.setAttribute('data-doc', i);

      var title = document.createElement('div');
      title.className = 'doc-title';
      title.setAttribute('data-doc', i);
      var dot = document.createElement('span');
      dot.className = 'dot';
      title.appendChild(dot);
      title.appendChild(document.createTextNode(doc.title));
      var fname = document.createElement('span');
      fname.className = 'fname';
      fname.textContent = doc.file;
      title.appendChild(fname);
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
    for (var j = 0; j < gs.length; j++) gs[j].classList.toggle('active', j === idx);
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
    requestAnimationFrame(function () {
      el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  }

  // ---------- 事件绑定 ----------
  aside.addEventListener('click', function (e) {
    var a = e.target.closest('a[data-target]');
    if (a) { e.preventDefault(); jump(a.getAttribute('data-target')); markActive(a); return; }
    var t = e.target.closest('.doc-title');
    if (t) { var idx = parseInt(t.getAttribute('data-doc'), 10); setActiveDoc(idx); window.scrollTo({ top: 0, behavior: 'smooth' }); }
  });

  function markActive(a) {
    var prev = aside.querySelector('a.active');
    if (prev) prev.classList.remove('active');
    if (a) a.classList.add('active');
  }

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
  <div class="site-title"><span class="logo">文</span>${PAGE_TITLE}<span class="doc-count" id="totalDocs"></span></div>
  <div class="search-box">
    <span class="s-ico">&#128269;</span>
    <input id="q" type="text" placeholder="过滤左侧栏目…" autocomplete="off">
  </div>
</header>
<div class="layout">
  <aside class="sidebar" id="tree">
    <div class="tree-empty" id="treeEmpty">未找到匹配栏目</div>
  </aside>
  <main class="content" id="panels"></main>
</div>
<button class="back-top" id="backTop" title="回到顶部">&#8593;</button>
<script>window.__DOCS_DATA__ = ${dataJson};</script>
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
