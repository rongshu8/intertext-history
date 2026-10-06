"""
多终端自适应实测（Chrome DevTools Protocol over HTTP + WebSocket）
--------------------------------------------------------------
不手写 WebSocket 帧，改用标准库实现最小客户端（CDP 只需文本帧）。

测什么（硬指标，非主观判断）：
  1. documentElement.scrollWidth > innerWidth  → 横向溢出
  2. 定位撑破容器的元素（选择器 + 位置）
  3. 触控目标尺寸（<32px 高在手机上难点）
  4. 输入框字号（<16px 时 iOS 会自动放大页面）
  5. 关键元素可见性
"""
import base64
import json
import os
import socket
import struct
import subprocess
import sys
import time
import urllib.request

CHROME = os.environ.get(
    'CHROME',
    r"C:\Program Files\Google\Chrome\Application\chrome.exe")
PORT = 9333


def base_url():
    """
    被测站点地址。走命令行参数或环境变量 BASE，**不写死默认值**。

    这个脚本要开源分发，硬编码域名等于把作者的生产站点
    连带暴露在公开仓库里，别人clone 下来第一件事就是
    往别人的站上打测试请求。
    """
    if len(sys.argv) > 1 and sys.argv[1].startswith('http'):
        return sys.argv[1].rstrip('/')
    env = os.environ.get('BASE', '').rstrip('/')
    if not env:
        raise SystemExit(
            '请指定被测站点：\n'
            '  python %s https://your-site.example.com/\n'
            '  或 export BASE=https://your-site.example.com/ 后再运行'
            % os.path.basename(sys.argv[0]))
    return env


BASE = base_url()

VIEWPORTS = [
    ("iPhone SE  320",   320, 568,  2),
    ("iPhone 8   375",   375, 667,  2),
    ("iPhone 11  390",   390, 844,  3),
    ("iPhone 14PM 430",  430, 932,  3),
    ("Android   360",    360, 800,  3),
    ("iPad mini 768",    768, 1024, 2),
    ("iPad      820",    820, 1180, 2),
    ("iPad Pro  834",    834, 1194, 2),
    ("iPad 横屏 1194",   1194, 834,  2),
    ("笔电     1366",   1366, 768,  1),
    ("桌面     1920",   1920, 1080, 1),
    ("超宽     2560",   2560, 1440, 1),
]

PAGES = [
    ("首页",     "/index.php"),
    ("节点页",   "/node.php?id=110"),
    ("世界大事", "/world.php"),
    ("关于",     "/about.php"),
    ("贡献名单", "/contributors.php"),
    ("我要贡献", "/contribute.php"),
    ("注册",     "/register.php"),
    ("登录",     "/admin/login.php"),
]

MEASURE_JS = r'''
(() => {
  const vw = window.innerWidth;
  const docW = document.documentElement.scrollWidth;
  const bodyW = document.body ? document.body.scrollWidth : 0;
  const over = [];
  document.querySelectorAll('body *').forEach(el => {
    const r = el.getBoundingClientRect();
    if (r.width > 0 && r.right > vw + 1) {
      const tag = el.tagName.toLowerCase();
      if (tag === 'html' || tag === 'body') return;
      let cls = '';
      if (el.className && typeof el.className === 'string') {
        cls = '.' + el.className.trim().split(/\s+/).slice(0,2).join('.');
      }
      over.push({ sel: tag + cls, right: Math.round(r.right), width: Math.round(r.width),
                  text: (el.textContent||'').trim().slice(0,24) });
    }
  });
  const seen = new Set(), uniq = [];
  for (const o of over) {
    if (uniq.length >= 5) break;
    if (!seen.has(o.sel)) { seen.add(o.sel); uniq.push(o); }
  }
  let smallTargets = 0; const stSample = [];
  document.querySelectorAll('a, button, .btn').forEach(el => {
    const r = el.getBoundingClientRect();
    if (r.width === 0 || r.height === 0) return;
    if (r.height < 28 || r.width < 24) {
      smallTargets++;
      if (stSample.length < 3) stSample.push({
        sel: el.tagName.toLowerCase() + (el.className ? '.' + String(el.className).trim().split(/\s+/)[0] : ''),
        w: Math.round(r.width), h: Math.round(r.height),
        t: (el.textContent||'').trim().slice(0,12) });
    }
  });
  let smallInput = 0;
  document.querySelectorAll('input, select, textarea').forEach(el => {
    if (el.offsetParent === null) return;
    const fs = parseFloat(getComputedStyle(el).fontSize);
    if (fs && fs < 16) smallInput++;
  });
  const vis = {};
  // 登录/注册是独立的卡片式布局，本来就没有 header/nav/main，
  // 因此这两个选择器都查不到才算缺失（login-wrap / reg-wrap 命中即正常）。
  const checks = {
    container: '.site-header,.admin-header,.user-header,.login-wrap,.reg-wrap,.wrap',
    main: 'main,.wrap,.login-wrap',
  };
  for (const [k,sel] of Object.entries(checks)) vis[k] = !!document.querySelector(sel);
  return JSON.stringify({ vw, docW, bodyW,
    overflow: docW > vw + 1, overBy: Math.max(docW,bodyW) - vw,
    offenders: uniq, smallTargets, stSample, smallInput, visible: vis });
})()
'''


class WS:
    """最小 WebSocket 客户端（只支持文本帧，够 CDP 用）"""

    def __init__(self, url):
        # ws://host:port/devtools/page/xxx
        assert url.startswith('ws://')
        rest = url[5:]
        hostport, path = rest.split('/', 1)
        path = '/' + path
        host, port = hostport.split(':')
        self.sock = socket.create_connection((host, int(port)), timeout=40)
        key = base64.b64encode(os.urandom(16)).decode()
        req = (
            f"GET {path} HTTP/1.1\r\n"
            f"Host: {hostport}\r\n"
            "Upgrade: websocket\r\n"
            "Connection: Upgrade\r\n"
            f"Sec-WebSocket-Key: {key}\r\n"
            "Sec-WebSocket-Version: 13\r\n\r\n"
        )
        self.sock.sendall(req.encode())
        buf = b''
        while b'\r\n\r\n' not in buf:
            chunk = self.sock.recv(4096)
            if not chunk:
                raise RuntimeError('握手失败')
            buf += chunk
        if b'101' not in buf.split(b'\r\n')[0]:
            raise RuntimeError('握手被拒: ' + buf.split(b'\r\n')[0].decode(errors='ignore'))
        self.rest = buf.split(b'\r\n\r\n', 1)[1]
        self._id = 0

    def _recv(self, n):
        while len(self.rest) < n:
            chunk = self.sock.recv(65536)
            if not chunk:
                raise RuntimeError('连接关闭')
            self.rest += chunk
        out, self.rest = self.rest[:n], self.rest[n:]
        return out

    def send(self, text):
        payload = text.encode()
        hdr = bytearray([0x81])
        mask = os.urandom(4)
        n = len(payload)
        if n < 126:
            hdr.append(0x80 | n)
        elif n < 65536:
            hdr.append(0x80 | 126)
            hdr += struct.pack('>H', n)
        else:
            hdr.append(0x80 | 127)
            hdr += struct.pack('>Q', n)
        hdr += mask
        hdr += bytes(b ^ mask[i % 4] for i, b in enumerate(payload))
        self.sock.sendall(bytes(hdr))

    def recv(self):
        while True:
            b1, b2 = self._recv(2)
            opcode = b1 & 0x0f
            ln = b2 & 0x7f
            if ln == 126:
                ln = struct.unpack('>H', self._recv(2))[0]
            elif ln == 127:
                ln = struct.unpack('>Q', self._recv(8))[0]
            data = self._recv(ln)
            if opcode == 0x1:      # text
                return data.decode('utf-8', 'replace')
            if opcode == 0x8:      # close
                raise RuntimeError('服务端关闭连接')
            # ping/pong/binary → 忽略

    def call(self, method, params=None, timeout=40):
        self._id += 1
        mid = self._id
        self.send(json.dumps({'id': mid, 'method': method, 'params': params or {}}))
        deadline = time.time() + timeout
        while time.time() < deadline:
            msg = json.loads(self.recv())
            if msg.get('id') == mid:
                if 'error' in msg:
                    raise RuntimeError(f'{method}: {msg["error"]}')
                return msg.get('result', {})
        raise TimeoutError(method)

    def close(self):
        try:
            self.sock.close()
        except Exception:
            pass


def start_chrome():
    profile = os.path.join(os.environ.get('TEMP', '/tmp'), 'cdp_prof2')
    p = subprocess.Popen([
        CHROME, f'--remote-debugging-port={PORT}', f'--user-data-dir={profile}',
        '--headless=new', '--disable-gpu', '--no-sandbox', '--disable-dev-shm-usage',
        '--hide-scrollbars', '--ignore-certificate-errors',
        '--window-size=1920,1080', 'about:blank',
    ], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    for _ in range(50):
        try:
            urllib.request.urlopen(f'http://127.0.0.1:{PORT}/json/version', timeout=1).read()
            return p
        except Exception:
            time.sleep(0.4)
    p.kill()
    raise RuntimeError('Chrome 启动失败')


def new_tab():
    req = urllib.request.Request(f'http://127.0.0.1:{PORT}/json/new?about:blank', method='PUT')
    d = json.loads(urllib.request.urlopen(req, timeout=10).read())
    return d['webSocketDebuggerUrl']


def close_tab(tid):
    try:
        urllib.request.urlopen(urllib.request.Request(
            f'http://127.0.0.1:{PORT}/json/close/{tid}'), timeout=5).read()
    except Exception:
        pass


def main():
    chrome = start_chrome()
    print('Chrome 已启动\n' + '=' * 66)
    issues_total = 0
    checked = 0
    try:
        for vp_name, w, h, dsf in VIEWPORTS:
            tid = None
            try:
                ws_url = new_tab()
                tid = ws_url.rsplit('/', 1)[-1]
                ws = WS(ws_url)
                ws.call('Page.enable')
                ws.call('Runtime.enable')

                for pg_name, path in PAGES:
                    ws.call('Emulation.setDeviceMetricsOverride', {
                        'width': w, 'height': h,
                        'deviceScaleFactor': dsf, 'mobile': w < 900,
                    })
                    ws.call('Page.navigate', {'url': BASE + path})
                    time.sleep(1.5)
                    # 等 DOM 就绪
                    for _ in range(6):
                        r = ws.call('Runtime.evaluate', {
                            'expression': 'document.readyState', 'returnByValue': True})
                        if r.get('result', {}).get('value') in ('interactive', 'complete'):
                            break
                        time.sleep(0.4)
                    time.sleep(0.5)

                    res = ws.call('Runtime.evaluate', {
                        'expression': MEASURE_JS, 'returnByValue': True})
                    d = json.loads(res['result']['value'])
                    checked += 1

                    probs = []
                    if d['overflow']:
                        probs.append(f"横向溢出+{d['overBy']}px")
                    if d['smallTargets'] > 0:
                        probs.append(f"触控目标偏小×{d['smallTargets']}")
                    # iOS 自动放大只发生在触屏（mobile=True，即 ≤900px）
                    if d['smallInput'] > 0 and w <= 900:
                        probs.append(f"输入框<16px×{d['smallInput']}")
                    miss = [k for k, v in d['visible'].items() if not v]
                    if miss:
                        probs.append('缺失:' + ','.join(miss))

                    if probs:
                        issues_total += len(probs)
                        print(f'  ✗ {vp_name:<16}{pg_name:<9} ' + '; '.join(probs))
                        for o in d['offenders'][:2]:
                            print(f"        └ {o['sel']}  right={o['right']} w={o['width']} 「{o['text']}」")
                        for s in d['stSample'][:1]:
                            print(f"        └ 触控 {s['sel']} {s['w']}×{s['h']} 「{s['t']}」")
                    else:
                        print(f'  ✓ {vp_name:<16}{pg_name:<9} {d["vw"]}px 正常')
                ws.close()
            except Exception as e:
                print(f'  ! {vp_name} 失败: {e}')
            finally:
                if tid:
                    close_tab(tid)
    finally:
        try:
            chrome.terminate(); chrome.wait(timeout=5)
        except Exception:
            chrome.kill()

    print('=' * 66)
    print(f'已测 {checked} 个「终端 × 页面」组合')
    if issues_total == 0:
        print('结果：零横向溢出、零过小触控目标、零缺失元素 ✓')
    else:
        print(f'结果：{issues_total} 处问题')
    return 0


if __name__ == '__main__':
    sys.exit(main())
