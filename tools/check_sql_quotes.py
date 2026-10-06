"""
校验 SQL 时间戳拼接（SQL_NOW）的正确性。

为什么需要它
------------
线上 MySQL 是 5.5.62，不支持 DATETIME DEFAULT CURRENT_TIMESTAMP
（5.6+ 才有该语法，5.5 只允许一个 TIMESTAMP 列自动初始化），
所以 created_at / updated_at 等时间戳必须由应用层拼接。

踩过的坑（php -l 完全查不出）：

    错误： 'INSERT INTO t (a, created_at) VALUES (?, " . SQL_NOW . ")'
           ↑ 整段是单引号字符串，" . SQL_NOW . " 只是串内字面量
           → SQL_NOW 原样进数据库 → 1292 Invalid datetime format

    正确： 'INSERT INTO t (a, created_at) VALUES (?, ' . SQL_NOW . ')'
           ↑ 引号闭合后再拼接

两种写法 php -l 都报「无语法错误」，因为引号是配对的，
只是拼出来的 SQL 字符串内容不对。

纯正则判断极易漏报（作者改了三版都误判）。
本脚本改用 PHP 官方 tokenizer 做权威判定 —— 见 tools/_probe_sqlnow.php。

用法：
    python tools/check_sql_quotes.py
"""
import io
import json
import os
import re
import shutil
import subprocess
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PROBE = os.path.join(ROOT, 'tools', '_probe_sqlnow.php')

PHP_CANDIDATES = [
    os.environ.get('PHP_BIN', ''),
    shutil.which('php') or '',
    r'C:\Users\abu\AppData\Local\Temp\phpportable\php.exe',
    '/tmp/phpportable/php.exe',
]

SKIP_DIRS = {'_backup', 'tools', 'node_modules', '.git', '.workbuddy', 'cache'}


def find_php():
    for c in PHP_CANDIDATES:
        if c and os.path.isfile(c):
            return c
    return None


def collect_targets():
    out = []
    for dirpath, dirnames, filenames in os.walk(ROOT):
        dirnames[:] = [d for d in dirnames
                       if d not in SKIP_DIRS and not d.startswith('_backup')]
        for fn in sorted(filenames):
            if not fn.endswith('.php'):
                continue
            full = os.path.join(dirpath, fn)
            try:
                src = io.open(full, encoding='utf-8').read()
            except Exception:
                continue
            # 只看含拼接片段的，defined('SQL_NOW') 之类不需要查
            if ' . SQL_NOW . ' in src:
                out.append(full)
    return out


def check_paren_balance(targets):
    """
    检查含 SQL_NOW 拼接的 INSERT 语句，括号是否配平。

    ★ 为什么 tokenizer 查不出来（2026-10-06 真实事故）：
      `php -l` 只看 PHP 语法，不看字符串里的 SQL。
      PHP 的 token_get_all 也不看 —— 字符串在它眼里只是一个 T_CONSTANT_ENCAPSED_STRING。
      所以下面这条**能通过 php -l、也能通过 tokenizer**：
          'INSERT INTO t (a, created_at) VALUES (?, ' . SQL_NOW . ''
      展开后少一个右括号 → MySQL 报 1064 ... near '' at line 1
      而 "near ''" 看起来像 SQL 语法问题，实际是**拼接时少写了一个字符**。

    做法：把拼接在虚拟环境下求值，再数括号。
    这里不做完整 SQL 解析，只做「左右括号是否配平」这一件最要紧的事。
    """
    bad = []
    for path in targets:
        try:
            src = io.open(path, encoding='utf-8').read()
        except Exception:
            continue
        for m in re.finditer(r"'([^']*?VALUES[^']*?)'\s*\.\s*SQL_NOW\s*\.\s*'([^']*)'",
                             src, re.S):
            head, tail = m.group(1), m.group(2)
            # 跳过明确的错误形态：拼接符落在双引号串内
            if '"' in head or '"' in tail:
                continue
            full = head + 'NOW()' + tail
            if full.count('(') != full.count(')'):
                line = src[:m.start()].count('\n') + 1
                bad.append({'file': path, 'line': line,
                            'text': ('...' + m.group(0)[:120]),
                            'l': full.count('('), 'r': full.count(')')})
    return bad


def main():
    targets = collect_targets()
    if not targets:
        print('SQL 时间戳拼接全部正确 ✓（项目中无拼接点）')
        return 0

    php = find_php()
    if not php:
        print('未找到 php CLI，无法运行 tokenizer 检查')
        print('待检查文件 %d 个；有 php 环境时请复跑：' % len(targets))
        for t in targets[:10]:
            print('  ' + os.path.relpath(t, ROOT))
        return 0

    flist = os.path.join(ROOT, 'tools', '_probe_files.json')
    io.open(flist, 'w', encoding='utf-8').write(json.dumps(targets, ensure_ascii=False))

    try:
        r = subprocess.run([php, PROBE, flist], capture_output=True, text=True, timeout=120)
        raw = (r.stdout or '').strip()
        js = None
        for line in reversed(raw.split('\n')):
            line = line.strip()
            if line.startswith('['):
                js = line
                break
        if js is None:
            print('探针无输出。stdout=%r stderr=%r' % (raw[:200], (r.stderr or '')[:300]))
            return 2
        bad = json.loads(js)
    finally:
        try:
            os.remove(flist)
        except OSError:
            pass

    paren = check_paren_balance(targets)
    if paren:
        print('发现 %d 处 **括号不配平**（拼接时少了右括号）：\n' % len(paren))
        for b in paren:
            rel = os.path.relpath(b['file'], ROOT)
            print('  %s:%d   左括号 %d / 右括号 %d' % (rel, b['line'], b['l'], b['r']))
            print('      %s' % b['text'])
            print('      → 展开后是残缺 SQL，MySQL 报 1064 ... near \'\' at line 1\n')

    if not bad and not paren:
        print('SQL 时间戳拼接全部正确 ✓（已检查 %d 个文件）' % len(targets))
        return 0

    if bad:
        print('发现 %d 处「拼接符落在字符串内」的破损：\n' % len(bad))
        for b in bad:
            rel = os.path.relpath(b['file'], ROOT)
            print('  %s:%d' % (rel, b['line']))
            print('      %s' % b['text'][:130])
            print('      → SQL_NOW 被当字面量，写库时报 1292 Invalid datetime\n')
    return 1


if __name__ == '__main__':
    sys.exit(main())
