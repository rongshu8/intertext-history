# -*- coding: utf-8 -*-
"""统计 cn_events 与 world_events 的年份分布，找出「有中国节点但同期世界大事稀缺」的缺口。"""
import re
import glob
import os
from collections import defaultdict

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

def load_all():
    seed = defaultdict(list)
    for f in sorted(glob.glob(os.path.join(ROOT, 'data', 'seed_part*.php'))):
        txt = open(f, encoding='utf-8').read()
        for key in ('dynasties', 'cn_events', 'world_events', 'relations'):
            # 抓取 'key' => [ ... ] 块
            m = re.search(r"^'%s'\s*=>\s*\[(.*?)^\]," % key, txt, re.S | re.M)
            if m:
                seed[key].append((os.path.basename(f), m.group(1)))
    return seed

def parse_entries(block):
    """把一个数组块拆成若干 [ ... ] 条目。"""
    out = []
    depth = 0
    buf = []
    in_str = False
    quote = ''
    i = 0
    while i < len(block):
        ch = block[i]
        if in_str:
            buf.append(ch)
            if ch == '\\':
                if i + 1 < len(block):
                    buf.append(block[i+1]); i += 2; continue
            elif ch == quote:
                in_str = False
            i += 1
            continue
        if ch in ("'", '"'):
            in_str = True; quote = ch; buf.append(ch); i += 1; continue
        if ch == '[':
            depth += 1
            if depth == 1:
                buf = []; i += 1; continue
        elif ch == ']':
            depth -= 1
            if depth == 0:
                out.append(''.join(buf)); buf = []; i += 1; continue
        if depth >= 1:
            buf.append(ch)
        i += 1
    return out

def field(entry, name):
    m = re.search(r"'%s'\s*=>\s*'((?:[^'\\]|\\.)*)'" % name, entry)
    if m:
        return m.group(1).replace("\\'", "'")
    m = re.search(r"'%s'\s*=>\s*(-?\d+)" % name, entry)
    if m:
        return int(m.group(1))
    return None

seed = load_all()

cn = []
for fn, block in seed['cn_events']:
    for e in parse_entries(block):
        y = field(e, 'year')
        if y is not None:
            cn.append((y, field(e, 'title'), field(e, 'dynasty')))

we = []
for fn, block in seed['world_events']:
    for e in parse_entries(block):
        y = field(e, 'year')
        if y is not None:
            ye = field(e, 'year_end')
            we.append((y, ye, field(e, 'title'), field(e, 'category'), field(e, 'region'), field(e, 'importance')))

print('cn_events  =', len(cn))
print('world_events =', len(we))
print()

cn.sort()
we.sort(key=lambda x: (x[0], -(x[5] or 3)))
print('--- cn_events 年份表 ---')
print(', '.join(str(y) for y, _, _ in cn))
print()
print('--- world_events 年份表 ---')
print(', '.join(str(y) for y, _, _, _, _, _ in we))
print()

# 缺口分析：每个中国节点 ±5 年内有多少条世界大事
WINDOW = 5
gaps = []
for y, t, d in cn:
    n = sum(1 for wy, wye, *_ in we
            if (wy <= y + WINDOW and (wye if wye else wy) >= y - WINDOW))
    gaps.append((n, y, t, d))
gaps.sort()
print('--- 中国节点 ±%d 年内世界大事最少的 40 个 ---' % WINDOW)
for n, y, t, d in gaps[:40]:
    print('%3d  %6d  %-10s %s' % (n, y, d, t))

print()
# 逐世纪统计世界大事密度
cen = defaultdict(int)
for wy, wye, *_ in we:
    cen[(wy // 100) * 100] += 1
print('--- 世界大事逐世纪分布 ---')
for k in sorted(cen):
    print('%5d-%4d : %d' % (k, k + 99, cen[k]))

print()
# 分类与区域分布
cat = defaultdict(int); reg = defaultdict(int)
for wy, wye, t, c, r, imp in we:
    cat[c] += 1; reg[r] += 1
print('--- 分类分布 ---'); print(cat)
print('--- 区域分布 ---'); print(reg)
print('--- 缺分类 ---')
have = set(cat)
for c in ('politics','war','science','literature','explore','culture','religion','economy','society','disaster'):
    if c not in have:
        print('  缺:', c)
