/* ==========================================================================
   前端交互脚本
   - 时间轴滚动进场动画
   - 时间轴节点定位（URL hash 高亮）
   - 分类色带联动
   ========================================================================== */

(function () {
  'use strict';

  // ------------------------------------------------------------------------
  // 1. 时间轴进场动画
  // ------------------------------------------------------------------------
  var rows = document.querySelectorAll('.tl-row, .dyn-head');
  if (rows.length && 'IntersectionObserver' in window) {
    // 初始隐藏由 CSS 控制（见 timeline.css .tl-row 动画段）
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (e.isIntersecting) {
          e.target.classList.add('in');
          io.unobserve(e.target);
        }
      });
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.02 });
    rows.forEach(function (r) { io.observe(r); });
  } else {
    rows.forEach(function (r) { r.classList.add('in'); });
  }

  // ------------------------------------------------------------------------
  // 2. URL hash 定位到某个世界大事并高亮
  // ------------------------------------------------------------------------
  if (location.hash) {
    var target = document.querySelector(location.hash);
    if (target) {
      setTimeout(function () {
        target.scrollIntoView({ behavior: 'smooth', block: 'center' });
        target.style.transition = 'box-shadow .3s, border-color .3s';
        target.style.borderColor = '#b23a2c';
        target.style.boxShadow = '0 0 0 3px rgba(178,58,44,.14)';
        setTimeout(function () {
          target.style.borderColor = '';
          target.style.boxShadow = '';
        }, 2200);
      }, 120);
    }
  }

  // ------------------------------------------------------------------------
  // 3. 键盘快捷键：左右方向键切换上一/下一节点
  // ------------------------------------------------------------------------
  var prevLink = document.querySelector('.pager a.prev');
  var nextLink = document.querySelector('.pager a.next');
  if (prevLink || nextLink) {
    document.addEventListener('keydown', function (e) {
      // 输入框内不触发
      var t = e.target;
      if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.isContentEditable)) return;
      if (e.key === 'ArrowLeft' && prevLink)  location.href = prevLink.href;
      if (e.key === 'ArrowRight' && nextLink) location.href = nextLink.href;
    });
  }

  // ------------------------------------------------------------------------
  // 4. 搜索框：/ 键快速聚焦
  // ------------------------------------------------------------------------
  var searchInputs = document.querySelectorAll('input[type="search"]');
  if (searchInputs.length) {
    document.addEventListener('keydown', function (e) {
      var t = e.target;
      if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA')) return;
      if (e.key === '/' && searchInputs[0]) {
        e.preventDefault();
        searchInputs[0].focus();
      }
    });
  }

  // ------------------------------------------------------------------------
  // 5. 世界大事详情页：定位到关联的中国节点时高亮
  // ------------------------------------------------------------------------
  document.querySelectorAll('.world-item').forEach(function (item) {
    item.addEventListener('mouseenter', function () {
      item.style.borderColor = '#d3ccbd';
    });
    item.addEventListener('mouseleave', function () {
      item.style.borderColor = '';
    });
  });
})();
