#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
后台「导出 / 导入」UI 实测 —— 真实登录 + 真实点击 + 真实下载 ZIP。

为什么必须跑浏览器：
    这两页此前只验证了 datapack_write/import/read 这些**业务函数**，
    而用户实际要碰的是**页面** —— 表单能不能提交、按钮点不点得动、
    预览报不报错、ZIP 能不能下载。这些只有真点才知道。

安全约束（重要）：
    ★ 全程绝不点「确认导入」。预检（act=check）走完后只打印报告就停手。
      真正的写入必须由用户在自己浏览器里、看着内容决定。
    ★ 只用 merge 语义做认知验证，不触发 replace。

用法：python tools/verify_admin_ui.py <密码>
"""
import base64
import json
import os
import re
import ssl
import sys
import time
import urllib.parse

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from verify_responsive import WS, start_chrome, new_tab   # noqa: E402

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
DESK = ('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        '(KHTML, like Gecko) Chrome/120.0 Safari/537.36')
OUT = '_uistest'


def ev(ws, js):
    """
    在页面里跑 JS，返回 Python 对象。

    ⚠️ 约定：表达式**自己**负责 JSON.stringify（异步的必须如此，否则
       returnByValue 拿到的是未决的 Promise，报不出 KeyError 之外的错）。
       这里只做解析，不重复 stringify —— 包两层会让 JSON 变成字符串再套字符串。
    """
    r = ws.call('Runtime.evaluate',
                params={'expression': js, 'returnByValue': True, 'awaitPromise': True})
    if r.get('exceptionDetails'):
        raise RuntimeError('页面内 JS 异常: ' +
                           json.dumps(r['exceptionDetails'], ensure_ascii=False)[:400])
    v = r.get('result', {}).get('value')
    return json.loads(v) if isinstance(v, str) else v


def goto(ws, url, wait=2.2):
    ws.call('Page.navigate', params={'url': url})
    time.sleep(wait)


def ok(c, msg, extra=''):
    print(('  [OK] ' if c else '  [!!] ') + msg + (('  → ' + extra) if extra else ''))
    return bool(c)


def main():
    if len(sys.argv) < 2:
        print('用法: python tools/verify_admin_ui.py <管理员密码>')
        return 2
    pw = sys.argv[1]
    os.makedirs(OUT, exist_ok=True)
    allok = True

    ch = start_chrome()
    try:
        ws = WS(new_tab())
        ws.call('Emulation.setDeviceMetricsOverride',
                params={'width': 1440, 'height': 900, 'deviceScaleFactor': 1, 'mobile': False})
        ws.call('Emulation.setUserAgentOverride', params={'userAgent': DESK})
        ws.call('Page.enable')
        ws.call('Network.enable')

        # ---------- 1. 登录 ----------
        print('\n=== 1. 登录 admin/login.php ===')
        goto(ws, BASE + '/admin/login.php')
        d = ev(ws, """JSON.stringify((() => {
            const f = document.querySelector('form');
            return {hasForm: !!f, action: f && f.getAttribute('action'),
                    fields: f ? [...f.querySelectorAll('input')].map(i=>i.name) : []};
        })())""")
        allok &= ok(d['hasForm'], '登录页有表单', '字段: ' + ','.join(d['fields']))

        tok = ev(ws, "JSON.stringify((()=>{const i=document.querySelector('input[name=\"_csrf\"]');return i?i.value:null})())")
        allok &= ok(bool(tok), '拿到 CSRF 令牌', (tok[:12] + '...') if tok else '')

        # ★ 用**真实表单提交**（fill + submit），而不是 fetch POST。
        #
        # 为什么：login.php 成功后调了 session_regenerate_id(true) —— session ID 会换新。
        # fetch 能拿到响应，但 CDP 这边若不显式处理 Set-Cookie，
        # 后续导航仍带着旧 cookie，服务端认不出 → 被重定向回登录页。
        # 我第一版就是这么写的，结果把「登录页的 404」误判成「导出按钮坏了」。
        # 表单提交让浏览器自己管 cookie，和真人操作完全一致。
        r = ev(ws, """JSON.stringify((() => {
            document.querySelector('input[name=username]').value = %s;
            document.querySelector('input[name=password]').value = %s;
            document.querySelector('form').submit();   // 真实提交，浏览器接管 cookie
            return 'submitted';
        })())""" % (json.dumps('admin'), json.dumps(pw)))
        print('  表单提交 →', r)
        time.sleep(3.0)   # 提交后是整页跳转，等它落地

        # 确认真的进了后台（访问受保护页面应返回 200 而非跳回登录页）
        d = ev(ws, "JSON.stringify((()=>({title:document.title, url:location.pathname,\n            hasLogout: !!document.querySelector('a[href*=\"logout\"]')}))())")
        logged = d['url'].endswith('/admin/index.php') and d['hasLogout']
        allok &= ok(logged, '已进入后台', 'title=' + d['title'])

        # ---------- 2. 导出页 UI ----------
        print('\n=== 2. 导出页 admin/export.php ===')
        # ★ 必须走 ?preview=1。
        #   裸 /admin/export.php 返回的是 attachment 下载 —— 浏览器会直接
        #   触发下载、把当前页留在原地，看起来就像"被弹回概览页"。
        #   我第一版没加 preview 就断言"在导出页"，结果把下载行为误判成鉴权失败，
        #   连带把后面 8 项全判成红色。**测试自己必须先被验证。**
        goto(ws, BASE + '/admin/export.php?preview=1')
        d = ev(ws, """JSON.stringify((() => {
            // 按 href 找，不按文案 —— 导航栏里也有「导入」，靠文字匹配容易抓错
            const btn = document.querySelector('a.btn[href*="export.php"]');
            return {url: location.pathname + location.search,
                    title: document.title,
                    buttons: [...document.querySelectorAll('a,button')]
                        .map(b => (b.textContent||'').trim()).filter(Boolean).slice(0, 14),
                    exportBtn: btn ? btn.getAttribute('href') : null,
                    exportText: btn ? btn.textContent.trim() : null,
                    counts: (document.body.innerText.match(/\\d+\\s*(条|行|个)/g) || []).slice(0, 8),
                    err: /Fatal error|SQLSTATE|Warning:|Deprecated:/.test(document.body.innerText)};
        })())""")
        allok &= ok(d['url'].startswith('/admin/export.php'), '确实在导出页', d['url'])
        allok &= ok('导出数据' in d['title'], '标题正确', d['title'])
        allok &= ok(not d['err'], '导出页无 PHP 错误')
        print('  页面要素:', d['buttons'][:8])
        print('  数量摘要:', d['counts'][:6])
        allok &= ok(bool(d['exportBtn']), '找到导出按钮', d['exportText'] or '')

        # ---------- 3. 真的把 ZIP 下载下来 ----------
        print('\n=== 3. 点击导出并下载 ZIP ===')
        # 用 fetch 拿二进制 + 响应头（点按钮会触发下载，抓不到头）
        # ★ 用 ?preview 去掉后的完整绝对路径：href 是 '/admin/export.php'，
        #   相对 fetch 会落到当前页 query 上，必须自己拼绝对地址。
        dl_url = BASE + (d['exportBtn'] or '/admin/export.php')
        d = ev(ws, """(async () => {
            const resp = await fetch(%s, {credentials: 'include'});
            const buf = await resp.arrayBuffer();
            const bytes = new Uint8Array(buf);
            let bin = '';
            const CH = 8192;
            for (let i = 0; i < bytes.length; i += CH) {
                bin += String.fromCharCode.apply(null, bytes.subarray(i, i + CH));
            }
            return {status: resp.status, type: resp.headers.get('content-type'),
                    disp: resp.headers.get('content-disposition'),
                    size: bytes.length, b64: btoa(bin)};
        })()""" % json.dumps(dl_url))
        allok &= ok(d['status'] == 200, '下载请求 HTTP 200', 'HTTP %s' % d['status'])
        allok &= ok((d['type'] or '').startswith('application/zip'),
                    'Content-Type 是 application/zip', d['type'] or '')
        allok &= ok('attachment' in (d['disp'] or ''),
                    'Content-Disposition 是 attachment（浏览器会存盘而非渲染）',
                    d['disp'] or '')
        allok &= ok(d['size'] > 50000, 'ZIP 非空', '%.1f KB' % (d['size'] / 1024.0))

        if d.get('b64'):
            raw = base64.b64decode(d['b64'])
            zp = os.path.join(OUT, 'export-from-admin.zip')
            open(zp, 'wb').write(raw)
            print('  已保存:', zp, '(%d B)' % len(raw))

            # ZIP 结构核对（真正的验收点：包里该有什么）
            import zipfile
            try:
                z = zipfile.ZipFile(zp)
                names = z.namelist()
                bad = z.testzip()
                allok &= ok(bad is None, 'ZIP 完整（无损坏条目）', bad or '')
                need = ['manifest.json', 'dynasties.json', 'cn_events.json',
                        'world_events.json', 'relations.json', 'categories.json',
                        'regions.json', 'relation_types.json']
                missing = [n for n in need if n not in names]
                allok &= ok(not missing, 'ZIP 含全部 8 个数据文件',
                            '缺: ' + ','.join(missing) if missing else '%d 个' % len(names))
                print('  包内清单:')
                for n in sorted(names):
                    print('     %-24s %8d B' % (n, z.getinfo(n).file_size))
                # manifest 里的行数应与页面显示的一致
                man = json.loads(z.read('manifest.json').decode('utf-8'))
                print('  manifest:', json.dumps(man.get('counts', {}), ensure_ascii=False))
            except Exception as e:
                allok &= ok(False, 'ZIP 解析失败', str(e))

        # ---------- 4. 导入页 UI（只到预检，绝不确认写入）----------
        print('\n=== 4. 导入页 admin/restore.php（仅预检）===')
        goto(ws, BASE + '/admin/restore.php')
        d = ev(ws, """JSON.stringify((() => {
            const f = document.querySelector('form[enctype]');
            return {url: location.pathname + location.search,
                    hasFile: !!document.querySelector('input[type=file]'),
                    enctype: f && f.getAttribute('enctype'),
                    formAction: f && f.getAttribute('action'),
                    // 注意：「合并 / 覆盖」两个单选框**不在上传页**，
                    // 它们在预检通过后的第二张表单里（act=do）。
                    // 这不是缺陷 —— 不该让人在没看到预检报告前就选「覆盖」。
                    // 所以这里只该有 0 个，出现 2 个反而说明流程串了。
                    modeRadiosHere: document.querySelectorAll('input[type=radio][name=mode]').length,
                    submit: (document.querySelector('button[type=submit],input[type=submit]')||{}).textContent,
                    err: /Fatal error|SQLSTATE|Warning:|Deprecated:/.test(document.body.innerText)};
        })())""")
        allok &= ok(not d['err'], '导入页无 PHP 错误')
        allok &= ok(d['hasFile'], '有文件上传控件')
        allok &= ok('multipart/form-data' in (d['enctype'] or ''), 'enctype 正确', d['enctype'] or '')
        allok &= ok('act=check' in (d['formAction'] or ''), '上传表单指向预检步骤', d['formAction'] or '')
        allok &= ok(d['modeRadiosHere'] == 0, '上传阶段不提供「覆盖」选项（应在预检后才选）',
                    '找到 %d 个' % d['modeRadiosHere'])
        print('  提交按钮:', (d['submit'] or '').strip())

        # 真做一次上传预检：把刚下载的 ZIP 喂给表单
        zp = os.path.join(OUT, 'export-from-admin.zip')
        if os.path.isfile(zp):
            print('\n=== 5. 上传该 ZIP 做预检（act=check，不写库）===')
            # base64 走 JSON 注入，不做字符串拼接，避免转义问题
            b64 = base64.b64encode(open(zp, 'rb').read()).decode()
            js = """(async () => {
                const bin = atob(%s);
                const arr = new Uint8Array(bin.length);
                for (let i=0;i<bin.length;i++) arr[i]=bin.charCodeAt(i);
                const file = new File([arr], 'export.zip', {type:'application/zip'});
                const fd = new FormData();
                const inp = document.querySelector('input[type=file]');
                const dt = new DataTransfer();
                dt.items.add(file);
                inp.files = dt.files;
                fd.append('pack', file);
                const tokEl = document.querySelector('input[name="_csrf"]');
                if (tokEl) fd.append('_csrf', tokEl.value);
                fd.append('act', 'check');
                // ⚠️ redirect:'manual' 的 fetch 响应是 opaque（status 0、头全空）——
                //   读不到 Location 也就无法判断预检结果。必须用 follow，
                //   靠最终 resp.url 里有没有 act=confirm 来判断。
                const resp = await fetch('/admin/restore.php?act=check',
                    {method:'POST', body: fd, credentials:'include', redirect:'follow'});
                const t = await resp.text();
                return {status: resp.status, loc: resp.url, len: t.length, html: t,
                        err: /Fatal error|SQLSTATE|Warning:|Deprecated:/.test(t),
                        text: t.replace(/<[^>]+>/g,' ').replace(/\\s+/g,' ').slice(0, 700)};
            })()""" % json.dumps(b64)
            r = ev(ws, js)
            print('  HTTP %s  最终 URL: %s  响应 %d B' % (r['status'], r['loc'] or '(无)', r['len']))
            allok &= ok(not r['err'], '预检响应无 PHP 错误')
            allok &= ok('act=confirm' in r['loc'],
                        '预检通过后重定向到确认页', r['loc'] or '(无 Location → 预检未通过)')
            print('\n  预检响应文字摘要:\n   ', r['text'][:600])

            if 'act=confirm' not in r['loc']:
                # 预检没过：正文里一定有具体原因，把它打出来，别只报一个布尔
                m = re.search(r'预检未通过[^。]*。([^<]*)', r['text'])
                print('\n  ★ 预检未通过，服务端说明:', (m.group(1).strip() if m else r['text'][:300]))
                allok = False

            # ★ 关键：确认页应出现「合并 / 覆盖」选择，且**默认选中合并**。
            #   预检本身不写库，所以这一步可以放心验证。
            #   follow 模式下 resp 已经是确认页正文，直接断言即可；
            #   不要 goto(restore.php) —— 那会把 act=confirm 冲掉又回到上传页。
            d = ev(ws, """JSON.stringify((() => {
                const doc = new DOMParser().parseFromString(%s, 'text/html');
                const rs = [...doc.querySelectorAll('input[type=radio][name=mode]')];
                const f = doc.querySelector('form[action*=do]');
                return {n: rs.length,
                        modes: rs.map(x => x.value + (x.checked ? '(默认)' : '')),
                        act: f ? f.getAttribute('action') : null,
                        text: doc.body.innerText.replace(/\\s+/g,' ').slice(0, 500)};
            })())""" % json.dumps(r.get('html', '')))
            allok &= ok(d['n'] == 2, '预检后出现 合并/覆盖 两个选项', ','.join(d['modes']))
            allok &= ok('merge(默认)' in ','.join(d['modes']),
                        '★默认选中「合并」而非「覆盖」', ','.join(d['modes']))
            allok &= ok('act=do' in (d['act'] or ''), '确认表单指向 do（真正写入）步骤', d['act'] or '')
            print('\n  确认页文字摘要:\n   ', d['text'][:450])
            print('\n  ★ 到此为止 —— 绝不点「确认导入」。写入必须由你在自己浏览器里、')
            print('    看着预检报告自己决定。')

        ws.close()
    finally:
        ch.terminate()

    print('\n' + ('全部通过 ✓' if allok else '存在问题，见上面 [!!]'))
    return 0 if allok else 1


if __name__ == '__main__':
    sys.exit(main())