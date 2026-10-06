#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""筛选区改版后的视觉快照（桌面 + 移动，折叠态与展开态）"""
import base64
import json
import os
import sys
import time
import urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from verify_responsive import WS, start_chrome, new_tab  # noqa

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
OUT = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "_shots")
DESK = ("Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
        "(KHTML, like Gecko) Chrome/120.0 Safari/537.36")
MOB = ("Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) "
       "AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1")

# 只截筛选区附近，避免整页长图看不清
CLIP = r"""
(() => {
  const w = document.querySelector('.filter-wrap');
  if (!w) return null;
  const r = w.getBoundingClientRect();
  const pad = 16;
  return {x: Math.max(0, r.left - pad), y: Math.max(0, r.top - pad),
          width: r.width + pad * 2, height: r.height + pad * 2, scale: 2};
})()
"""

SHOTS = [
    ("index-desktop", "index.php?dynasty=tang&cat=war", 1440, 900, DESK, None),
    ("world-desktop", "world.php?region=europe&cat=medicine", 1440, 900, DESK, None),
    ("index-mobile-closed", "index.php?dynasty=tang&cat=war", 390, 844, MOB, None),
    ("index-mobile-open", "index.php?dynasty=tang&cat=war", 390, 844, MOB, "click"),
    ("world-mobile-closed", "world.php?region=europe&cat=medicine", 390, 844, MOB, None),
    ("world-mobile-open", "world.php?region=europe&cat=medicine", 390, 844, MOB, "click"),
]


def main():
    os.makedirs(OUT, exist_ok=True)
    chrome = start_chrome()
    try:
        ws = WS(new_tab())
        for name, page, w, h, ua, action in SHOTS:
            ws.call('Emulation.setDeviceMetricsOverride',
                    params={'width': w, 'height': h, 'deviceScaleFactor': 1, 'mobile': ua is MOB})
            ws.call('Emulation.setUserAgentOverride', params={'userAgent': ua})
            ws.call('Page.enable')
            ws.call('Page.navigate', params={'url': BASE + page})
            time.sleep(2.6)
            if action == 'click':
                ws.call("Runtime.evaluate", params={
                    "expression": "document.querySelector('.filter-toggle').click()"})
                time.sleep(0.6)
            clip = ws.call("Runtime.evaluate", params={"expression": CLIP, "returnByValue": True})
            c = clip.get('result', {}).get('value')
            if not c:
                print('  跳过 %s（找不到 .filter-wrap）' % name); continue
            c['format'] = 'png'
            # captureBeyondViewport：面板在窄屏展开后高 600px+，超出视口的部分
            # 默认不渲染，截出来会被截断（看着像布局 bug，其实是截图假象）
            r = ws.call("Page.captureScreenshot",
                        params={"clip": c, "captureBeyondViewport": True})
            path = os.path.join(OUT, 'filter_%s.png' % name)
            with open(path, 'wb') as f:
                f.write(base64.b64decode(r['data']))
            print('  %-42s %d B' % (os.path.basename(path), os.path.getsize(path)))
        ws.close()
    finally:
        chrome.terminate()
        try:
            chrome.wait(timeout=5)
        except Exception:
            chrome.kill()


if __name__ == '__main__':
    main()
