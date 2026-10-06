#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
base_path() 部署场景回归测试
-----------------------------------------------------------------
base_path() 决定所有 CSS/链接的根前缀，算错就会全站 404 或路径重复
（曾两次踩坑：dirname() 把 /admin 当成部署前缀；漏掉 user 导致 /user/user/）。

用法：python tools/verify_base_path.py
需要 PHP 可执行文件。
"""
import os
import subprocess
import sys
import tempfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

# (SCRIPT_NAME, 期望的 base_path, 期望的 asset() 前缀)
CASES = [
    ('/index.php',                          '',            '/assets/style.css'),
    ('/node.php',                           '',            '/assets/style.css'),
    ('/world.php',                          '',            '/assets/style.css'),
    ('/register.php',                       '',            '/assets/style.css'),
    ('/admin/login.php',                    '',            '/assets/style.css'),
    ('/admin/index.php',                    '',            '/assets/style.css'),
    ('/admin/review.php',                   '',            '/assets/style.css'),
    ('/user/index.php',                     '',            '/assets/style.css'),
    ('/user/submit.php',                    '',            '/assets/style.css'),
    ('/user/account.php',                   '',            '/assets/style.css'),
    # 子目录部署
    ('/history/index.php',                  '/history',    '/history/assets/style.css'),
    ('/history/node.php',                   '/history',    '/history/assets/style.css'),
    ('/history/admin/login.php',            '/history',    '/history/assets/style.css'),
    ('/history/user/submit.php',            '/history',    '/history/assets/style.css'),
    # 多级子目录
    ('/a/b/index.php',                      '/a/b',        '/a/b/assets/style.css'),
    ('/a/b/admin/index.php',                '/a/b',        '/a/b/assets/style.css'),
    ('/a/b/user/submit.php',                '/a/b',        '/a/b/assets/style.css'),
]


def find_php():
    for c in [os.environ.get('PHP_BIN'),
              r'C:\Users\abu\AppData\Local\Temp\phpportable\php.exe',
              '/tmp/phpportable/php.exe']:
        if c and os.path.isabs(c) and os.path.isfile(c):
            return c
    from shutil import which
    return which('php') or 'php'


PHP = find_php()


def run_case(script_name):
    """每个场景单独一个进程 —— base_path() 内部有 static 缓存，同进程会串"""
    # 场景写进独立 PHP 文件，避开 Git Bash 的路径转换
    lit = script_name.replace('\\', '\\\\').replace("'", "\\'")
    code = f"""<?php
$_SERVER['SCRIPT_NAME'] = '{lit}';
require '{ROOT.replace(chr(92), '/')}/inc/bootstrap.php';
echo json_encode([
    'base'  => base_path(),
    'asset' => asset('style.css'),
    'alink' => alink('index.php'),
    'link'  => link_to('index.php'),
], JSON_UNESCAPED_SLASHES);
"""
    with tempfile.NamedTemporaryFile('w', suffix='.php', delete=False, encoding='utf-8') as f:
        f.write(code)
        tmp = f.name
    try:
        r = subprocess.run([PHP, tmp], capture_output=True, text=True, timeout=30)
        out = (r.stdout or '').strip()
        if not out.startswith('{'):
            return None, (r.stderr or '').strip()[:120]
        import json
        return json.loads(out), ''
    finally:
        try:
            os.unlink(tmp)
        except OSError:
            pass


def main():
    print('=' * 72)
    print('base_path() 部署场景回归')
    print('=' * 72)
    print(f'PHP: {PHP}\n')
    print("SCRIPT_NAME".ljust(32), "base".ljust(9), "asset".ljust(34), "结果")
    print('-' * 92)

    ok = 0
    fails = []
    for sn, exp_base, exp_asset in CASES:
        res, err = run_case(sn)
        if res is None:
            print(f'{sn:<32} {"—":<9} {"执行失败: " + err}')
            fails.append(f'{sn} 执行失败')
            continue
        base_ok = res['base'] == exp_base
        asset_ok = res['asset'].startswith(exp_asset)
        # 不应出现路径重复（/admin/admin/ 或 /user/user/）
        no_dup = '/user/user/' not in res['asset'] and '/admin/admin/' not in res['alink']
        good = base_ok and asset_ok and no_dup
        mark = 'OK' if good else 'FAIL'
        if good:
            ok += 1
        else:
            fails.append(f'{sn}: base={res["base"]!r} asset={res["asset"]!r} alink={res["alink"]!r}')
        bs = res['base'] if res['base'] else '(空)'
        print(f'{sn:<32} {bs:<9} {res["asset"][:33]:<34} {mark}')

    print('\n' + '=' * 72)
    print(f'通过 {ok}/{len(CASES)}' + ('，全部通过 ✓' if not fails else ''))
    for f in fails:
        print(f'  ✗ {f}')
    print('=' * 72)
    return 0 if not fails else 1


if __name__ == '__main__':
    sys.exit(main())
