#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
对比 index.php 与 world.php 筛选区的实际高度与间距
用 Chrome DevTools Protocol 实测（复用 verify_responsive.py 的 WS 实现，不引外部依赖）。

改 CSS 之前先量，别靠肉眼猜 —— 「更紧凑」要落到具体 px 上。
"""
import json
import os
import sys
import time
import urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from verify_responsive import WS, start_chrome, new_tab, close_tab, CHROME, PORT  # noqa

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


BASE = _base_url()

VIEWPORTS = [
    ("桌面 1440", 1440, 900, "desktop"),
    ("笔电 1366", 1366, 768, "desktop"),
    ("平板 834", 834, 1112, "mobile"),
    ("手机 390", 390, 844, "mobile"),
]

DESK_UA = ("Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
           "(KHTML, like Gecko) Chrome/120.0 Safari/537.36")
MOB_UA = ("Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) "
          "AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1")

JS = r"""
(() => {
  const q = s => document.querySelector(s);
  const box = el => el ? Math.round(el.getBoundingClientRect().height) : -1;
  const cs = (el, p) => el ? getComputedStyle(el)[p] : '-';
  const o = { url: location.pathname, scrollH: document.documentElement.scrollHeight };

  if (location.pathname.includes('index.php')) {
    const tog = q('.filter-toggle'), pn = q('.filter-panel');
    o.mode = 'panel';
    o.toggleH = box(tog);
    o.toggleVis = tog ? getComputedStyle(tog).display !== 'none' : false;
    o.panelH = box(pn);
    o.panelVis = pn ? getComputedStyle(pn).display !== 'none' : false;
    o.chipW = (() => { const c = q('.filter-panel .chip'); return c ? Math.round(c.getBoundingClientRect().height) : -1; })();
    o.bars = [...document.querySelectorAll('.filter-panel .filter-bar')].map(b => ({
      h: box(b), padT: cs(b,'paddingTop'), padB: cs(b,'paddingBottom'),
      marT: cs(b,'marginTop'), marB: cs(b,'marginBottom'), bdT: cs(b,'borderTopWidth'),
      chips: b.querySelectorAll('.chip').length,
    }));
    o.gap = o.bars.length >= 2 ? o.bars[1].h : -1;
  } else {
    const bars = [...document.querySelectorAll('.filter-bar')];
    o.mode = 'plain';
    o.bars = bars.map(b => ({
      h: box(b), padT: cs(b,'paddingTop'), padB: cs(b,'paddingBottom'),
      marT: cs(b,'marginTop'), marB: cs(b,'marginBottom'), bdT: cs(b,'borderTopWidth'),
      chips: b.querySelectorAll('.chip').length,
    }));
    if (bars.length >= 2) {
      const a = bars[0].getBoundingClientRect(), b = bars[bars.length-1].getBoundingClientRect();
      o.blockH = Math.round(b.bottom - a.top);
    }
  }
  return JSON.stringify(o);
})()
"""


def probe(ws, page, w, h, ua):
    ws.call('Emulation.setDeviceMetricsOverride',
            params={'width': w, 'height': h, 'deviceScaleFactor': 1,
                    'mobile': ua is MOB_UA})
    ws.call('Emulation.setUserAgentOverride', params={'userAgent': ua})
    ws.call('Page.enable')
    ws.call('Page.navigate', params={'url': BASE + page})
    for _ in range(24):
        time.sleep(0.25)
        r = ws.call("Runtime.evaluate", params={"expression": JS, "returnByValue": True})
        v = r.get('result', {}).get('value')
        if v and '"bars"' in v and '"h":0' not in v:
            break
    return json.loads(ws.call("Runtime.evaluate", params={
        "expression": JS, "returnByValue": True})['result']['value'])


def show(tag, d):
    if d.get('mode') == 'panel':
        print("  [%s] 折叠按钮=%s %spx   面板=%s %spx   chip高=%spx"
              % (tag, "显示" if d.get('toggleVis') else "隐藏", d.get('toggleH', -1),
                 "展开" if d.get('panelVis') else "折叠", d.get('panelH', -1), d.get('chipW', -1)))
        tot = d.get('toggleH', 0) if d.get('toggleVis') else 0
        for i, b in enumerate(d.get('bars', [])):
            print("      bar%d 高%4d  pad %s/%s  margin %s/%s  border %s  chip %d"
                  % (i+1, b['h'], b['padT'], b['padB'], b['marT'], b['marB'], b['bdT'], b['chips']))
            tot += b['h']
        print("      → 筛选区占位合计 %dpx   页面总高 %d" % (tot, d.get('scrollH', -1)))
    else:
        print("  [%s]" % tag)
        for i, b in enumerate(d.get('bars', [])):
            print("      bar%d 高%4d  pad %s/%s  margin %s/%s  border %s  chip %d"
                  % (i+1, b['h'], b['padT'], b['padB'], b['marT'], b['marB'], b['bdT'], b['chips']))
        print("      → 筛选区整块 %dpx   页面总高 %d" % (d.get('blockH', -1), d.get('scrollH', -1)))


def main():
    chrome = start_chrome()
    tid = None
    try:
        ws_url = new_tab()
        tid = ws_url.rsplit('/', 1)[-1]
        ws = WS(ws_url)
        for name, w, h, kind in VIEWPORTS:
            ua = MOB_UA if kind == 'mobile' else DESK_UA
            print("=" * 72)
            print("%s  %d×%d  (%s UA)" % (name, w, h, kind))
            show('index 首页', probe(ws, 'index.php', w, h, ua))
            show('world 世界', probe(ws, 'world.php', w, h, ua))
        ws.close()
    finally:
        if tid:
            close_tab(tid)
        chrome.terminate()
        try:
            chrome.wait(timeout=5)
        except Exception:
            chrome.kill()


if __name__ == '__main__':
    main()
