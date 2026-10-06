#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
筛选折叠的功能验证（两页通用）
- 按钮显隐随视口
- 点击能展开/收起
- 摘要与角标反映当前 .chip.on
- 桌面→窄屏复位
"""
import json
import os
import sys
import time
import urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from verify_responsive import WS, start_chrome, new_tab, close_tab  # noqa

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
DESK = ("Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
        "(KHTML, like Gecko) Chrome/120.0 Safari/537.36")
MOB = ("Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) "
       "AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1")

PROBE = r"""
(() => {
  const w = document.querySelector('.filter-wrap');
  const b = w && w.querySelector('.filter-toggle');
  const p = w && w.querySelector('.filter-panel');
  if (!w || !b || !p) return JSON.stringify({err: '结构缺失'});
  const cs = getComputedStyle(b);
  return JSON.stringify({
    toggleShown: cs.display !== 'none',
    toggleH: Math.round(b.getBoundingClientRect().height),
    panelShown: getComputedStyle(p).display !== 'none',
    panelH: Math.round(p.getBoundingClientRect().height),
    open: w.classList.contains('open'),
    aria: b.getAttribute('aria-expanded'),
    summary: (w.querySelector('.ft-summary') || {}).textContent || '',
    count: (w.querySelector('.ft-count') || {}).textContent || '',
    countHidden: (w.querySelector('.ft-count') || {}).hidden,
    hasFilter: b.classList.contains('has-filter'),
  });
})()
"""

CLICK = r"""
(() => {
  const b = document.querySelector('.filter-toggle');
  if (b) b.click();
  return JSON.stringify({clicked: !!b});
})()
"""


def ev(ws, js):
    # PROBE / CLICK 自身已返回 JSON 字符串，不要再包一层 JSON.stringify
    return json.loads(ws.call("Runtime.evaluate",
                             params={"expression": js,
                                     "returnByValue": True})['result']['value'])


def go(ws, url, w, h, ua):
    ws.call('Emulation.setDeviceMetricsOverride',
            params={'width': w, 'height': h, 'deviceScaleFactor': 1, 'mobile': ua is MOB})
    ws.call('Emulation.setUserAgentOverride', params={'userAgent': ua})
    ws.call('Page.enable')
    ws.call('Page.navigate', params={'url': url})
    time.sleep(2.5)


CASES = [
    ("首页 · 无筛选", BASE + "index.php", DESK),
    ("首页 · 唐+军事", BASE + "index.php?dynasty=tang&cat=war", DESK),
    ("首页 · 手机+已选", BASE + "index.php?dynasty=tang&cat=war", MOB),
    ("世界 · 无筛选", BASE + "world.php", DESK),
    ("世界 · 欧洲+医学", BASE + "world.php?region=europe&cat=medicine", DESK),
    ("世界 · 手机+已选", BASE + "world.php?region=europe&cat=medicine", MOB),
]

fails = []


def chk(cond, msg):
    if not cond:
        fails.append(msg)
        print('     !! ' + msg)


def main():
    chrome = start_chrome()
    tid = None
    try:
        ws = WS(new_tab())
        tid = ws.call("Target.getTargets") and None
        for name, url, ua in CASES:
            mob = ua is MOB
            go(ws, url, 390 if mob else 1440, 844 if mob else 900, ua)
            d = ev(ws, PROBE)
            print("\n%s  @%s" % (name, "390 移动" if mob else "1440 桌面"))
            if d.get('err'):
                print('     !! ' + d['err']); fails.append(name + ' 结构缺失'); continue
            print("     按钮=%s %spx   面板=%s %spx   open=%s aria=%s"
                  % ("显示" if d['toggleShown'] else "隐藏", d['toggleH'],
                     "展开" if d['panelShown'] else "折叠", d['panelH'], d['open'], d['aria']))
            print("     摘要「%s」 角标=%s 隐藏=%s" % (d['summary'], d['count'], d['countHidden']))
            if mob:
                chk(d['toggleShown'], name + ' 移动端按钮应显示')
                chk(d['toggleH'] > 0 and d['toggleH'] < 80, name + ' 移动端按钮高度异常 %s' % d['toggleH'])
                chk(not d['panelShown'], name + ' 移动端面板初始应折叠')
                chk(d['aria'] == 'false', name + ' 移动端 aria-expanded 应为 false')
                # 点击展开
                ev(ws, CLICK)
                time.sleep(0.5)
                d2 = ev(ws, PROBE)
                chk(d2['panelShown'], name + ' 点击后应展开')
                chk(d2['panelH'] > 50, name + ' 点击后面板应有高度，实测 %s' % d2['panelH'])
                chk(d2['aria'] == 'true', name + ' 点击后 aria 应为 true')
                print("     点击后 → 面板 %spx  open=%s" % (d2['panelH'], d2['open']))
            else:
                chk(not d['toggleShown'], name + ' 桌面端按钮应隐藏')
                chk(d['panelShown'], name + ' 桌面端面板应常开')
            # 摘要正确性
            if '?' in url:
                chk(d['summary'] and d['summary'] != '全部朝代 · 全部主题',
                    name + ' 摘要应反映已选条件，实测「%s」' % d['summary'])
                chk(not d['countHidden'] and d['count'] == '2',
                    name + ' 角标应为 2，实测「%s」hidden=%s' % (d['count'], d['countHidden']))
                chk(d['hasFilter'], name + ' 按钮应有 has-filter 类')
            else:
                chk('全部' in d['summary'], name + ' 无筛选时摘要应含「全部」，实测「%s」' % d['summary'])
                chk(d['countHidden'], name + ' 无筛选时角标应隐藏')

        # 桌面 → 窄屏 复位
        print("\n桌面 → 窄屏 复位检查")
        go(ws, BASE + "index.php?dynasty=tang", 1440, 900, DESK)
        ev(ws, CLICK)
        go(ws, BASE + "index.php?dynasty=tang", 390, 844, MOB)
        d = ev(ws, PROBE)
        chk(not d['open'], '拉宽再变窄后应复位收起')
        print("     复位后 open=%s aria=%s" % (d['open'], d['aria']))
        ws.close()
    finally:
        chrome.terminate()
        try:
            chrome.wait(timeout=5)
        except Exception:
            chrome.kill()
    print("\n" + "=" * 60)
    print("失败 %d 项" % len(fails))
    for f in fails:
        print("  - " + f)
    return 1 if fails else 0


if __name__ == '__main__':
    sys.exit(main())
