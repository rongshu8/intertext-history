/**
 * 筛选面板折叠（首页 / 世界大事页共用）
 * ---------------------------------------------------------------------------
 * 窄屏（≤900px）收成一行「筛选」按钮，点开才展开；桌面端按钮隐藏、面板恒开。
 * 桌面/窄屏的切换由 CSS media query 负责，本脚本只管状态与摘要。
 *
 * 为什么摘要从 DOM 读而不是由 PHP 注入：
 *   原来 index.php 是用 PHP 变量拼 `picks` 数组的，world.php 就得把
 *   $region / $catF 再拼一遍，两个页面各写一份几乎相同的脚本。
 *   面板里「当前选中的 chip」本来就带 .on 类，直接读 DOM 即可，
 *   页面新增筛选项也不用改 JS。
 *
 * 依赖的类名（勿随意改名）：
 *   .filter-wrap > .filter-toggle > .ft-icon / .ft-text / .ft-summary / .ft-count
 *   #filterPanel 内的 a.chip.on
 */
(function () {
  var wrap = document.querySelector('.filter-wrap');
  if (!wrap) return;
  var btn  = wrap.querySelector('.filter-toggle');
  var panel = wrap.querySelector('.filter-panel');
  if (!btn) return;

  var sum = wrap.querySelector('.ft-summary');
  var cnt = wrap.querySelector('.ft-count');

  function labelOf(chip) {
    // 取 chip 内的可见文字，忽略纯装饰的圆点 / emoji 之外内容
    var t = (chip.textContent || '').replace(/\s+/g, ' ').trim();
    return t;
  }

  function refresh() {
    if (!panel) return;
    var on = panel.querySelectorAll('a.chip.on');
    var picks = [];
    for (var i = 0; i < on.length; i++) {
      var lb = labelOf(on[i]);
      if (lb && lb !== '全部') picks.push(lb);
    }
    if (!sum) return;
    if (picks.length) {
      sum.textContent = picks.join(' · ');
      btn.classList.add('has-filter');
      if (cnt) { cnt.textContent = String(picks.length); cnt.hidden = false; }
    } else {
      sum.textContent = panel.getAttribute('data-empty-hint') || '全部';
      btn.classList.remove('has-filter');
      if (cnt) { cnt.textContent = ''; cnt.hidden = true; }
    }
  }

  function setOpen(open) {
    wrap.classList.toggle('open', open);
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
  }

  btn.addEventListener('click', function () {
    setOpen(!wrap.classList.contains('open'));
  });

  // 从窄屏拉宽到桌面时复位收起，保证下次回到窄屏是干净状态
  var mq = window.matchMedia('(min-width: 901px)');
  if (mq.addEventListener) {
    mq.addEventListener('change', function (e) { if (e.matches) setOpen(false); });
  } else if (mq.addListener) {
    mq.addListener(function (e) { if (e.matches) setOpen(false); });
  }

  // 点了面板里的 chip 后自动收起，让用户立刻看到筛选结果
  if (panel) {
    panel.addEventListener('click', function (e) {
      var chip = e.target.closest ? e.target.closest('a.chip') : null;
      if (chip && window.matchMedia('(max-width: 900px)').matches) setOpen(false);
    });
  }

  refresh();
  // 浏览器前进/后退回到带筛选参数的 URL 时，chip 的 .on 会变，摘要要跟着变
  window.addEventListener('pageshow', refresh);
})();
