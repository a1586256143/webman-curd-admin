/**
 * highlight.js — 文档站代码高亮（构建期内联，零运行时依赖）
 *
 * 单独成文件的原因：这些正则里有大量反斜杠，直接写在 build_docs.cjs 的
 * 模板字符串里会被 JS 先吃一遍（`\/` 变成 `/` 直接截断正则字面量），
 * 拆成真实 .js 文件后由构建器读取并内联进 index.html，转义只有一层。
 *
 * 只做「够用」的着色：注释 / 字符串 / 数字 / 关键字 / 函数名 / PHP 变量。
 */
(function () {
  'use strict';

  var KEYWORDS = {
    php: 'namespace use class extends implements public private protected static function return new const ' +
      'if else elseif foreach for while switch case default break continue echo print null true false ' +
      'try catch throw finally as fn match readonly enum declare global isset unset empty instanceof ' +
      'clone yield abstract final interface trait',
    js: 'const let var function return if else for while do new class extends async await try catch throw ' +
      'import export from default typeof instanceof null true false undefined this of in delete void',
    json: 'true false null',
    bash: 'if then else elif fi for do done while case esac function echo export local return exit cd ls rm ' +
      'cp mv mkdir cat grep sed awk curl php npm node composer webman git source set',
    sql: 'select insert update delete from where values into create table if not exists primary key not null ' +
      'default order by group limit join left right inner outer on as alter add column index unique drop ' +
      'exists count sum avg and or set engine charset comment int varchar text datetime timestamp decimal ' +
      'tinyint bigint',
    html: ''
  };

  // 哪些语言有 # 行注释（bash 用 #；php 的 # 已废弃且和 #[Attribute] 冲突，不启用）
  var HASH_COMMENT = { bash: 1 };

  function escHtml(s) {
    return s.replace(/[&<>]/g, function (c) {
      return c === '&' ? '&amp;' : (c === '<' ? '&lt;' : '&gt;');
    });
  }

  /** 从 <code class="language-php"> 里取语言名 */
  function langOf(el) {
    if (!el || !el.className) return '';
    var m = /(?:^|\s)language-([\w+#-]+)/.exec(el.className);
    return m ? m[1].toLowerCase() : '';
  }

  /** HTML：注释 / 标签名 / 属性名 / 属性值 */
  function highlightHtml(text) {
    var re = /(<!--[\s\S]*?-->)|(<\/?)([\w-]+)((?:[^>"']|"[^"]*"|'[^']*')*)(\/?>)/g;
    var out = '', last = 0, m;
    while ((m = re.exec(text))) {
      out += escHtml(text.slice(last, m.index));
      if (m[1]) {
        out += '<span class="tok-com">' + escHtml(m[1]) + '</span>';
      } else {
        out += '<span class="tok-op">' + escHtml(m[2]) + '</span>';
        out += '<span class="tok-tag">' + escHtml(m[3]) + '</span>';
        out += highlightAttrs(m[4]);
        out += '<span class="tok-op">' + escHtml(m[5]) + '</span>';
      }
      last = re.lastIndex;
    }
    return out + escHtml(text.slice(last));
  }

  function highlightAttrs(s) {
    var re = /([\w:-]+)(\s*=\s*)("[^"]*"|'[^']*')/g;
    var out = '', last = 0;
    s.replace(re, function (all, name, eq, val, idx) {
      out += escHtml(s.slice(last, idx)) +
        '<span class="tok-attr">' + escHtml(name) + '</span>' +
        escHtml(eq) +
        '<span class="tok-str">' + escHtml(val) + '</span>';
      last = idx + all.length;
      return all;
    });
    return out + escHtml(s.slice(last));
  }

  /**
   * 六组：注释 / 字符串 / PHP 变量 / 数字 / 函数名 / 单词（再判关键字）
   * 用 new RegExp 拼装，避免正则字面量的转义叠加。
   */
  function buildRe(lang) {
    var bt = String.fromCharCode(96);   // 反引号
    var parts = [
      // 1 注释：/* */ 、// 、# （bash）、-- （sql）
      '\\/\\*[\\s\\S]*?\\*\\/|\\/\\/[^\\n]*' +
      (HASH_COMMENT[lang] ? '|#[^\\n]*' : '') +
      (lang === 'sql' ? '|--[^\\n]*' : ''),
      // 2 字符串：'…' / "…" / `…`（不跨行）
      "'(?:\\\\.|[^'\\\\\\n])*'" +
      '|"(?:\\\\.|[^"\\\\\\n])*"' +
      '|' + bt + '(?:\\\\.|[^' + bt + '\\\\\\n])*' + bt,
      // 3 PHP 变量
      '\\$[A-Za-z_]\\w*',
      // 4 数字
      '\\b\\d+(?:\\.\\d+)?\\b',
      // 5 函数名（后面跟括号）
      '\\b[A-Za-z_]\\w*(?=\\s*\\()',
      // 6 单词
      '\\b[A-Za-z_]\\w*\\b'
    ];
    return new RegExp('(' + parts.join(')|(') + ')', 'g');
  }

  function highlightCode(codeEl) {
    if (!codeEl || codeEl.getAttribute('data-hl')) return;
    codeEl.setAttribute('data-hl', '1');
    var lang = langOf(codeEl);
    if (!lang || !(lang in KEYWORDS)) return;      // 未知语言：保持原样
    var text = codeEl.textContent;
    if (lang === 'html') { codeEl.innerHTML = highlightHtml(text); return; }

    var kw = {};
    KEYWORDS[lang].split(/\s+/).forEach(function (k) { if (k) kw[k.toLowerCase()] = 1; });
    var ci = (lang === 'sql');

    var re = buildRe(lang), out = '', last = 0, m;
    while ((m = re.exec(text))) {
      if (m.index > last) out += escHtml(text.slice(last, m.index));
      var cls = '';
      if (m[1]) cls = 'tok-com';
      else if (m[2]) cls = 'tok-str';
      else if (m[3]) cls = 'tok-var';
      else if (m[4]) cls = 'tok-num';
      else if (m[5]) cls = kw[m[5].toLowerCase()] ? 'tok-kw' : 'tok-fn';
      else if (m[6]) cls = kw[ci ? m[6].toLowerCase() : m[6]] ? 'tok-kw' : '';
      out += cls ? '<span class="' + cls + '">' + escHtml(m[0]) + '</span>' : escHtml(m[0]);
      last = re.lastIndex;
      if (m[0] === '') re.lastIndex++;             // 防零宽匹配死循环
    }
    out += escHtml(text.slice(last));
    codeEl.innerHTML = out;
  }

  /** 面板首次显示时才着色（15 篇一次全跑没必要） */
  function highlightPanel(panel) {
    if (!panel || panel.getAttribute('data-hl-done')) return;
    panel.setAttribute('data-hl-done', '1');
    var codes = panel.querySelectorAll('pre code');
    for (var i = 0; i < codes.length; i++) highlightCode(codes[i]);
  }

  window.__curdHl = { panel: highlightPanel, langOf: langOf };
})();
