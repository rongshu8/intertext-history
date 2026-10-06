"""
移动端专项实测：筛选折叠 + 时间轴对齐 + 卡片减量。

用 CDP 在多个手机/平板视口下测量真实渲染的盒子几何，
**只看数值不看截图** —— 错位是几何问题，量化才可靠。

测什么：
  1. 筛选面板在窄屏是否折叠（display:none）
  2. 折叠按钮的命中区尺寸（应 ≥44px 高）
  3. 时间轴主轴圆点与卡片顶端的垂直偏差（错位的量化指标）
  4. 单行时间轴高度（太高=没法看）
  5. 世界大事卡数量（是否按 UA 减量）
  6. 横向溢出
"""
import base64
import json
import os
import subprocess
import sys
import time

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

CHROME = r'C:\Program Files\Google\Chrome\Application\chrome.exe'
PORT = 9555
def _base_url():
    """
    被测站点地址。走命令行参数或环境变量 BASE，**不写死默认值**。

    这个脚本要开源分发，硬编码域名等于把作者的生产站点
    连带暴露在公开仓库里，而且别人clone 下来第一件事就是
    往别人的站上打测试请求。
    """
    if len(sys.argv) > 1 and sys.argv[1].startswith('http'):
        return sys.argv[1].rstrip('/') + '/'
    env = os.environ.get('BASE', '').rstrip('/')
    if not env:
        raise SystemExit(
            '请指定被测站点：\n'
            '  python %s https://your-site.example.com/\n'
            '  或 export BASE=https://your-site.example.com/ 后再运行'
            % os.path.basename(sys.argv[0]))
    return env + '/'


BASE = _base_url().rstrip("/")

VIEWPORTS = [
    ('iPhone SE  320×568',  320,  568, 2, True),
    ('iPhone 14  390×844',  390,  844, 3, True),
    ('iPhone PM  430×932',  430,  932, 3, True),
    ('iPad      768×1024', 768, 1024, 2, True),
    ('iPad Pro  834×1194', 834, 1194, 2, True),
    ('桌面     1440×900', 1440, 900, 1, False),
]

MOBILE_UA = ('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) '
             'AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1')

MEASURE_JS = r'''
(() => {
  const vw = window.innerWidth;
  const docW = document.documentElement.scrollWidth;

  // --- 1) 筛选面板折叠状态 ---
  const panel = document.getElementById('filterPanel');
  const toggle = document.getElementById('filterToggle');
  const panelShown = panel ? getComputedStyle(panel).display !== 'none' : null;
  const toggleShown = toggle ? getComputedStyle(toggle).display !== 'none' : null;
  let toggleBox = null;
  if (toggle && toggleShown) {
    const r = toggle.getBoundingClientRect();
    toggleBox = { w: Math.round(r.width), h: Math.round(r.height) };
  }

  // --- 2) 时间轴圆点与卡片顶端的对齐偏差（核心指标）---
  // 对每一行：圆点中心 Y 减去 该行第一张卡片的顶端 Y
  const rows = Array.from(document.querySelectorAll('.tl-row')).slice(0, 8);
  const align = [], alignYear = [];
  let yearBottom = 0, titleTop = 0;
  rows.forEach(row => {
    const node = row.querySelector('.tl-node');
    const card = row.querySelector('.tl-cards .ev-card');
    const year = row.querySelector('.tl-year-in');
    if (!node || !card) return;
    const nr = node.getBoundingClientRect();
    const cr = card.getBoundingClientRect();
    // 指标 A：主轴圆点锚点 vs 卡片顶端
    //   窄屏下卡片为了给绝对定位的年份标签让位而有 margin-top，
    //   所以这里允许 ≤30px 的固定偏移（正是让位高度）。
    align.push(Math.round(nr.top - cr.top));
    // 指标 B：卡片内年份标签 vs 卡片内标题
    //   —— 这才是真正该检查的。年份标签本身有 margin-bottom 与卡片 padding，
    //   所以偏差不为 0 是设计意图；要验的是「不重叠」且「不超出卡片」。
    if (year && getComputedStyle(year).display !== 'none') {
      const yr = year.getBoundingClientRect();
      const title = row.querySelector('.tl-cards .ev-title');
      if (title) {
        const tr = title.getBoundingClientRect();
        // 年份底边 应 ≤ 标题顶边（不重叠）；标题顶边 - 年份顶边 的距离（应 5~24px）
        alignYear.push(Math.round(yr.top - cr.top));
        yearBottom = yr.bottom;
        titleTop = tr.top;
      }
    }
  });

  // --- 3) 单行高度 ---
  const heights = rows.map(r => Math.round(r.getBoundingClientRect().height));
  const maxH = heights.length ? Math.max.apply(null, heights) : 0;

  // --- 4) 每行卡片数与隐藏情况 ---
  const firstRow = rows[0];
  let cardsPerRow = 0, visibleWorld = 0;
  // 中国节点卡与同期世界大事必须**都可见**。
  // 曾因 left/right 同为 grid-row:1 而重叠，导致中国节点卡被压掉 ——
  // 光看「卡片总数」发现不了，必须分别检查两侧。
  let cnVisible = 0, worldVisible = 0;
  if (firstRow) {
    const all = firstRow.querySelectorAll('.ev-card');
    cardsPerRow = all.length;
    all.forEach(c => {
      if (getComputedStyle(c).display === 'none') return;
      visibleWorld++;
      if (c.closest('.tl-cards.left')) cnVisible++;
      if (c.closest('.tl-cards.right')) worldVisible++;
    });
  }
  // 中国节点卡的实际可见性（多行验证，避免单行偶然）
  let cnRowsVisible = 0, rowsTotal = 0;
  rows.forEach(row => {
    const cn = row.querySelector('.tl-cards.left .ev-card');
    if (!cn) return;
    rowsTotal++;
    const r = cn.getBoundingClientRect();
    if (r.width > 0 && r.height > 0) cnRowsVisible++;
  });

  // --- 5) 是否还看到摘要正文（窄屏应隐藏）---
  const sums = Array.from(document.querySelectorAll('.tl-row .ev-sum'));
  let visibleSum = 0;
  sums.slice(0, 10).forEach(s => {
    if (getComputedStyle(s).display !== 'none') visibleSum++;
  });

  // --- 6) 年份是否竖排（窄屏应为横排）---
  // 年份已移入卡片（.tl-year-in），窄屏可见、桌面隐藏
  const yearIn = document.querySelector('.tl-year-in');
  const yearAxis = document.querySelector('.tl-node');
  const yearInShown = yearIn ? getComputedStyle(yearIn).display !== 'none' : false;
  const yearAxisShown = yearAxis ? getComputedStyle(yearAxis).display !== 'none' : false;
  const yearMode = yearIn ? getComputedStyle(yearIn).writingMode : '';

  // --- 7) 触控目标过小 ---
  let smallTargets = 0;
  document.querySelectorAll('a, button, .btn, .chip').forEach(el => {
    const r = el.getBoundingClientRect();
    if (r.width === 0 || r.height === 0) return;
    if (r.height < 28) smallTargets++;
  });

  return JSON.stringify({
    vw, docW,
    overflow: docW > vw + 1,
    overBy: Math.max(docW, document.body.scrollWidth) - vw,
    panelShown, toggleShown, toggleBox,
    alignDeviation: align,
    alignYear: alignYear,
    overlap: (yearBottom > 0) ? Math.round(yearBottom - titleTop) : 0,
    maxRowHeight: maxH,
    avgRowHeight: heights.length ? Math.round(heights.reduce((a,b)=>a+b,0)/heights.length) : 0,
    cardsPerRow, visibleWorld, visibleSum,
    cnVisible, worldVisible, cnRowsVisible, rowsTotal,
    yearMode: yearMode,
    yearInShown, yearAxisShown,
    smallTargets,
  });
})()
'''


def run():
    import verify_responsive as vr
    vr.PORT = PORT

    profile = os.path.join(os.environ.get('TEMP', '/tmp'), 'cdp_mobile')
    proc = subprocess.Popen([
        CHROME, f'--remote-debugging-port={PORT}', f'--user-data-dir={profile}',
        '--headless=new', '--disable-gpu', '--no-sandbox', '--disable-dev-shm-usage',
        '--hide-scrollbars', '--ignore-certificate-errors',
        '--window-size=1440,900', 'about:blank',
    ], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

    import urllib.request
    for _ in range(50):
        try:
            urllib.request.urlopen(f'http://127.0.0.1:{PORT}/json/version', timeout=1).read()
            break
        except Exception:
            time.sleep(0.4)
    else:
        proc.kill()
        print('Chrome 启动失败')
        return 1

    try:
        for name, w, h, dsf, is_mobile in VIEWPORTS:
            url = vr.new_tab()
            tid = url.rsplit('/', 1)[-1]
            ws = vr.WS(url)
            ws.call('Page.enable')
            ws.call('Runtime.enable')
            if is_mobile:
                ws.call('Emulation.setUserAgentOverride', {'userAgent': MOBILE_UA})
            else:
                ws.call('Emulation.setUserAgentOverride', {'userAgent': ''})
            ws.call('Emulation.setDeviceMetricsOverride', {
                'width': w, 'height': h, 'deviceScaleFactor': dsf, 'mobile': is_mobile,
            })
            ws.call('Page.navigate', {'url': BASE + '/index.php'})
            time.sleep(2.2)
            for _ in range(6):
                r = ws.call('Runtime.evaluate', {
                    'expression': 'document.readyState', 'returnByValue': True})
                if r.get('result', {}).get('value') in ('interactive', 'complete'):
                    break
                time.sleep(0.4)
            time.sleep(0.8)

            res = ws.call('Runtime.evaluate', {'expression': MEASURE_JS, 'returnByValue': True})
            d = json.loads(res['result']['value'])

            print()
            print('─' * 68)
            print('%s' % name)
            print('─' * 68)

            # 筛选
            if is_mobile:
                exp_panel = '折叠(隐藏)' if not d['panelShown'] else '展开(可见)'
                exp_toggle = '显示' if d['toggleShown'] else '隐藏'
                print('  筛选面板    %s   [期望折叠]' % exp_panel)
                print('  折叠按钮    %s   %s' % (exp_toggle, d['toggleBox'] or ''))
                if d['toggleBox'] and d['toggleBox']['h'] < 40:
                    print('  ⚠ 按钮高度 %dpx 偏小（建议 ≥44px）' % d['toggleBox']['h'])
            else:
                print('  筛选面板    %s   [期望展开]' % ('展开(可见)' if d['panelShown'] else '折叠(隐藏)'))
                print('  折叠按钮    %s   [期望隐藏]' % ('显示' if d['toggleShown'] else '隐藏'))

            # 时间轴对齐
            # 年份标签与卡片顶端必须严格对齐（这是用户看到的「错位」）
            ydev = d.get('alignYear') or []
            ov = d.get('overlap', 0)
            if ydev:
                print('  年份与标题重叠  %dpx   %s' % (
                    ov, '✓ 无重叠' if ov <= 0 else '✗ 重叠'))
                if ov > 0:
                    print('    逐行年份偏移: %s' % ydev)
            # 圆点相对卡片顶端的偏移应等于给年份标签留的高度
            devs = d['alignDeviation']
            if devs and d['yearAxisShown']:
                avg = sum(devs) / len(devs)
                mx = max(abs(x) for x in devs)
                print('  圆点-卡片偏差  平均 %.0fpx  最大 %dpx' % (avg, mx))

            print('  单行高度    平均 %dpx  最大 %dpx' % (d['avgRowHeight'], d['maxRowHeight']))
            print('  首行卡片    总 %d / 可见 %d（中国节点 %d + 世界大事 %d）' % (
                d['cardsPerRow'], d['visibleWorld'], d['cnVisible'], d['worldVisible']))
            print('  中国节点卡  %d/%d 行可见   %s' % (
                d['cnRowsVisible'], d['rowsTotal'],
                '✓' if d['cnRowsVisible'] == d['rowsTotal'] and d['rowsTotal'] > 0 else '✗ 有行被压掉'))
            print('  摘要正文    可见 %d 处   %s' % (
                d['visibleSum'], '✓ 已隐藏' if d['visibleSum'] == 0 else '(桌面端正常显示)'))
            exp_year = '卡内' if is_mobile else '主轴'
            got_year = '卡内' if d['yearInShown'] else ('主轴' if d['yearAxisShown'] else '未显示')
            print('  年份位置    %s   [期望 %s]  %s' % (
                got_year, exp_year, '✓' if got_year == exp_year else '✗'))
            if d['yearMode']:
                print('  年份写法    %s   %s' % (
                    d['yearMode'], '✓ 横排' if 'vertical' not in d['yearMode'] else '(竖排)'))
            print('  横向溢出    %s' % ('✗ +%dpx' % d['overBy'] if d['overflow'] else '✓ 无'))
            print('  触控目标    %s' % ('✗ %d 个过小' % d['smallTargets'] if d['smallTargets'] else '✓ 无过小'))

            ws.close()
            vr.close_tab(tid)
    finally:
        try:
            proc.terminate(); proc.wait(timeout=5)
        except Exception:
            proc.kill()

    print()
    print('=' * 68)
    return 0


if __name__ == '__main__':
    sys.exit(run())
