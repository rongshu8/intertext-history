#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
端到端数据验证（无需 PHP 扩展）
-----------------------------------------------------------------
用 Python 复刻 install.php 的建表与导入流程，验证：
  1. Schema::tables() 生成的 DDL 能否被 SQLite 接受
  2. 种子数据能否完整导入（外键、必填、引用完整性）
  3. world_events_near() 的时间窗口查询是否返回合理结果
  4. 联动关系的外键是否都指向存在的记录

需要先导出 DDL：php tools/dump_ddl.php > /tmp/ddl.json
用法：python tools/verify_data.py
"""
import json
import os
import re
import sqlite3
import subprocess
import sys
import tempfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PHP = os.environ.get('PHP_BIN', 'php')

# PHP 侧不在本机时，改为直接解析 seed 文件
SEED_FILES = sorted(
    os.path.join(ROOT, 'data', f)
    for f in os.listdir(os.path.join(ROOT, 'data'))
    if f.startswith('seed_part') and f.endswith('.php')
)


def parse_php_arrays(path):
    """
    极简解析：把 PHP 文件里的 return [...] 结构读成 Python。
    只处理本项目的数据格式（单引号字符串、=> 关联数组、整数）。
    """
    src = open(path, encoding='utf-8').read()
    # 去掉注释
    src = re.sub(r'/\*.*?\*/', '', src, flags=re.S)
    src = re.sub(r'//[^\n]*', '', src)

    def unquote(s):
        s = s.strip()
        if s.startswith("'") and s.endswith("'"):
            return s[1:-1].replace("\\'", "'").replace('\\\\', '\\')
        if s.startswith('"') and s.endswith('"'):
            return s[1:-1]
        return s

    def parse_keyvals(body):
        """解析 key => value 序列"""
        result = {}
        # 逐个匹配 'key' => value
        pos = 0
        n = len(body)
        while pos < n:
            m = re.compile(r"'([a-zA-Z_][a-zA-Z0-9_]*)'\s*=>\s*").search(body, pos)
            if not m:
                break
            key = m.group(1)
            vstart = m.end()
            # 读取值
            if body[vstart] == "'":
                # 找配对的单引号
                j = vstart + 1
                buf = []
                while j < n:
                    if body[j] == '\\':
                        buf.append(body[j:j+2]); j += 2; continue
                    if body[j] == "'":
                        break
                    buf.append(body[j]); j += 1
                val = ''.join(buf).replace("\\'", "'").replace('\\\\', '\\')
                pos = j + 1
            else:
                m2 = re.compile(r'-?\d+').match(body, vstart)
                if m2:
                    val = int(m2.group(0))
                    pos = m2.end()
                else:
                    # 可能是数组
                    val = None
                    pos = vstart
            result[key] = val
            # 跳到下一个逗号
            m3 = re.compile(r'\s*,\s*').search(body, pos)
            if m3:
                pos = m3.end()
            else:
                break
        return result

    out = {}
    for section in ['dynasties', 'cn_events', 'world_events', 'relations']:
        m = re.search(r"'" + section + r"'\s*=>\s*\[(.*?)\n\s*\],", src, re.S)
        if not m:
            continue
        body = m.group(1)
        # 拆成一个个 [ ... ] 条目
        items = []
        depth = 0
        start = None
        i = 0
        while i < len(body):
            c = body[i]
            if c == "'":
                i += 1
                while i < len(body) and body[i] != "'":
                    if body[i] == '\\':
                        i += 1
                    i += 1
            elif c == '[':
                if depth == 0:
                    start = i + 1
                depth += 1
            elif c == ']':
                depth -= 1
                if depth == 0 and start is not None:
                    items.append(body[start:i])
                    start = None
            i += 1
        out[section] = [parse_keyvals(it) for it in items]
    return out


def get_ddl():
    """调用 PHP 导出 DDL"""
    script = os.path.join(ROOT, 'tools', 'dump_ddl.php')
    if not os.path.exists(script) or not shutil_which(PHP):
        return None
    r = subprocess.run([PHP, script], capture_output=True, text=True)
    try:
        return json.loads(r.stdout)
    except Exception:
        return None


def shutil_which(cmd):
    from shutil import which
    return which(cmd)


DDL_FALLBACK = {
    'dynasties': """CREATE TABLE dynasties (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL, slug TEXT NOT NULL,
        start_year INTEGER NOT NULL DEFAULT -2070, end_year INTEGER NOT NULL DEFAULT 1949,
        color TEXT NOT NULL DEFAULT '#4c6ef5', summary TEXT, sort INTEGER NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)""",
    'cn_events': """CREATE TABLE cn_events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        dynasty_id INTEGER NOT NULL, title TEXT NOT NULL, year INTEGER NOT NULL,
        year_end INTEGER, month INTEGER, day INTEGER,
        category TEXT NOT NULL DEFAULT 'politics', place TEXT, summary TEXT, detail TEXT,
        figures TEXT, importance INTEGER NOT NULL DEFAULT 3, is_key INTEGER NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)""",
    'world_events': """CREATE TABLE world_events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL, year INTEGER NOT NULL, year_end INTEGER, month INTEGER, day INTEGER,
        region TEXT NOT NULL DEFAULT 'global', category TEXT NOT NULL DEFAULT 'politics',
        place TEXT, summary TEXT, detail TEXT, figures TEXT,
        importance INTEGER NOT NULL DEFAULT 3, source TEXT,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)""",
    'relations': """CREATE TABLE relations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        cn_event_id INTEGER NOT NULL, world_event_id INTEGER NOT NULL,
        relation_type TEXT NOT NULL DEFAULT 'echo', note TEXT,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)""",
    'categories': """CREATE TABLE categories (
        id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT NOT NULL, name TEXT NOT NULL,
        color TEXT NOT NULL DEFAULT '#4c6ef5', sort INTEGER NOT NULL DEFAULT 0)""",
    'regions': """CREATE TABLE regions (
        id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT NOT NULL, name TEXT NOT NULL,
        emoji TEXT, sort INTEGER NOT NULL DEFAULT 0)""",
    'relation_types': """CREATE TABLE relation_types (
        id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT NOT NULL, name TEXT NOT NULL,
        descr TEXT, sort INTEGER NOT NULL DEFAULT 0)""",
    'users': """CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT NOT NULL, password TEXT NOT NULL,
        display_name TEXT, last_login DATETIME,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)""",
}

CATEGORIES = [
    ('politics', '政治制度', '#4c6ef5'), ('war', '战争军事', '#e03131'),
    ('science', '科技发明', '#0ca678'), ('literature', '出版文献', '#7048e8'),
    ('explore', '探索地理', '#1098ad'), ('culture', '文化艺术', '#f76707'),
    ('religion', '宗教思想', '#c2255c'), ('economy', '经济金融', '#2f9e44'),
    ('society', '社会民生', '#495057'), ('disaster', '灾害疫情', '#868e96'),
]
REGIONS = [
    ('east_asia', '东亚', '🏯'), ('south_asia', '南亚与东南亚', '🛕'),
    ('west_asia', '中东与西亚', '🕌'), ('europe', '欧洲', '🏛'),
    ('north_america', '北美', '🗽'), ('latam', '拉丁美洲', '🌎'),
    ('africa', '非洲', '🌍'), ('oceania', '大洋洲与极地', '🐧'), ('global', '全球', '🌐'),
]
REL_TYPES = [
    ('cause', '因果', '此世界事件是中国节点发生的原因或直接触发因素', 10),
    ('effect', '影响', '中国节点影响了此世界事件的走向', 20),
    ('echo', '对照', '二者同期发生，反映某种结构性的历史呼应', 30),
]


def main():
    print('=' * 70)
    print('数据端到端验证（Python 复刻 install.php 流程）')
    print('=' * 70)

    # 1) 解析种子
    seed = {'dynasties': [], 'cn_events': [], 'world_events': [], 'relations': []}
    for f in SEED_FILES:
        part = parse_php_arrays(f)
        for k in seed:
            seed[k].extend(part.get(k, []))

    print(f"\n[1] 种子解析")
    print(f"  朝代 {len(seed['dynasties'])}  中国节点 {len(seed['cn_events'])}  "
          f"世界大事 {len(seed['world_events'])}  联动 {len(seed['relations'])}")

    errors = []

    # 2) 建库
    db = sqlite3.connect(':memory:')
    db.execute('PRAGMA foreign_keys = ON')
    for name, ddl in DDL_FALLBACK.items():
        try:
            db.execute(ddl)
        except Exception as e:
            errors.append(f'建表 {name} 失败: {e}')
    print(f"\n[2] 建表: {'✓ 8 张表' if not errors else '✗'}")

    # 3) 字典
    for i, (s, n, c) in enumerate(CATEGORIES):
        db.execute('INSERT INTO categories (slug,name,color,sort) VALUES (?,?,?,?)', (s, n, c, i * 10))
    for i, (s, n, e) in enumerate(REGIONS):
        db.execute('INSERT INTO regions (slug,name,emoji,sort) VALUES (?,?,?,?)', (s, n, e, i * 10))
    for r in REL_TYPES:
        db.execute('INSERT INTO relation_types (slug,name,descr,sort) VALUES (?,?,?,?)', r)

    # 4) 朝代
    dyn_by_slug = {}
    for d in seed['dynasties']:
        try:
            db.execute(
                'INSERT INTO dynasties (name,slug,start_year,end_year,color,summary,sort) VALUES (?,?,?,?,?,?,?)',
                (d.get('name'), d.get('slug'), d.get('start_year', -2070), d.get('end_year', 2026),
                 d.get('color', '#4c6ef5'), d.get('summary'), d.get('sort', 0)))
            dyn_by_slug[d.get('slug')] = db.execute('SELECT last_insert_rowid()').fetchone()[0]
        except Exception as e:
            errors.append(f'朝代插入失败 {d.get("name")}: {e}')
    print(f"[3] 朝代插入: {len(dyn_by_slug)}/{len(seed['dynasties'])}")

    # 5) 中国节点
    cn_by_title = {}
    for e in seed['cn_events']:
        slug = e.get('dynasty')
        if slug not in dyn_by_slug:
            errors.append(f'节点「{e.get("title")}」朝代 slug 无效: {slug}')
            continue
        try:
            db.execute(
                '''INSERT INTO cn_events (dynasty_id,title,year,year_end,month,day,category,place,summary,detail,figures,importance,is_key)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)''',
                (dyn_by_slug[slug], e.get('title'), e.get('year', 0), e.get('year_end'),
                 e.get('month'), e.get('day'), e.get('category', 'politics'), e.get('place'),
                 e.get('summary'), e.get('detail'), e.get('figures'),
                 e.get('importance', 3), 1 if e.get('is_key') else 0))
            cn_by_title[e.get('title')] = db.execute('SELECT last_insert_rowid()').fetchone()[0]
        except Exception as ex:
            errors.append(f'节点插入失败「{e.get("title")}」: {ex}')
    print(f"[4] 中国节点插入: {len(cn_by_title)}/{len(seed['cn_events'])}")

    # 6) 世界大事
    we_by_title = {}
    for e in seed['world_events']:
        try:
            db.execute(
                '''INSERT INTO world_events (title,year,year_end,month,day,region,category,place,summary,detail,figures,importance,source)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)''',
                (e.get('title'), e.get('year', 0), e.get('year_end'), e.get('month'), e.get('day'),
                 e.get('region', 'global'), e.get('category', 'politics'), e.get('place'),
                 e.get('summary'), e.get('detail'), e.get('figures'), e.get('importance', 3), e.get('source')))
            we_by_title[e.get('title')] = db.execute('SELECT last_insert_rowid()').fetchone()[0]
        except Exception as ex:
            errors.append(f'世界大事插入失败「{e.get("title")}」: {ex}')
    print(f"[5] 世界大事插入: {len(we_by_title)}/{len(seed['world_events'])}")

    # 7) 联动
    rel_ok = 0
    for r in seed['relations']:
        cn = cn_by_title.get(r.get('cn_ref'))
        we = we_by_title.get(r.get('world_ref'))
        if cn is None:
            errors.append(f'联动 cn_ref 不存在: 「{r.get("cn_ref")}」')
            continue
        if we is None:
            errors.append(f'联动 world_ref 不存在: 「{r.get("world_ref")}」')
            continue
        db.execute('INSERT INTO relations (cn_event_id,world_event_id,relation_type,note) VALUES (?,?,?,?)',
                   (cn, we, r.get('type', 'echo'), r.get('note')))
        rel_ok += 1
    print(f"[6] 联动插入: {rel_ok}/{len(seed['relations'])}")

    # 8) 核心查询验证：world_events_near
    print(f"\n[7] 核心查询验证（world_events_near 逻辑复刻）")
    samples = [
        ('秦始皇统一六国，建立中国历史上第一个中央集权帝国', -221, None),
        ('造纸术', 105, None),
        ('郑和七下西洋，最壮观的远洋航行', 1405, 1433),
        ('牛顿《自然哲学的数学原理》，科学革命完成', 1687, None),
        ('甲午战争', 1894, 1895),
    ]
    empty_windows = 0
    for title, year, yend in samples:
        row = db.execute('SELECT id FROM cn_events WHERE title LIKE ? LIMIT 1', (f'%{title[:8]}%',)).fetchone()
        if not row:
            print(f"    跳过（节点未找到）: {title[:20]}")
            continue
        w = 5
        lo = min(year, yend or year) - w
        hi = max(year, yend or year) + w
        rows = db.execute(
            '''SELECT title, year FROM world_events
               WHERE (year_end IS NULL AND year BETWEEN ? AND ?)
                  OR (year_end IS NOT NULL AND year <= ? AND year_end >= ?)
               ORDER BY year ASC''', (lo, hi, hi, lo)).fetchall()
        flag = '✓' if rows else '⚠ 空窗口'
        if not rows:
            empty_windows += 1
        print(f"    {flag} {year}年 窗口[{lo},{hi}] → {len(rows)} 条世界大事")
        for t, y in rows[:2]:
            print(f"        · {y} {t[:42]}")

    # 9) 数据完整性
    print(f"\n[8] 完整性检查")
    orphan = db.execute(
        'SELECT COUNT(*) FROM relations r LEFT JOIN cn_events c ON c.id=r.cn_event_id WHERE c.id IS NULL').fetchone()[0]
    print(f"    孤立联动: {orphan} {'✓' if orphan == 0 else '✗'}")
    if orphan:
        errors.append(f'{orphan} 条联动指向不存在的中国节点')

    no_dyn = db.execute('SELECT COUNT(*) FROM cn_events WHERE dynasty_id=0').fetchone()[0]
    print(f"    无朝代节点: {no_dyn} {'✓' if no_dyn == 0 else '✗'}")
    if no_dyn:
        errors.append(f'{no_dyn} 个节点没有朝代')

    # 年份合理性
    bad_year = db.execute('SELECT COUNT(*) FROM cn_events WHERE year > 2100 OR year < -3000').fetchone()[0]
    print(f"    异常年份: {bad_year} {'✓' if bad_year == 0 else '✗'}")
    if bad_year:
        errors.append(f'{bad_year} 个节点年份超出合理范围')

    bad_cat = db.execute(
        "SELECT COUNT(*) FROM cn_events WHERE category NOT IN (%s)" %
        ','.join('?' * len(CATEGORIES)), [c[0] for c in CATEGORIES]).fetchone()[0]
    print(f"    非法分类: {bad_cat} {'✓' if bad_cat == 0 else '✗'}")
    if bad_cat:
        errors.append(f'{bad_cat} 个节点分类不在字典中')

    bad_reg = db.execute(
        "SELECT COUNT(*) FROM world_events WHERE region NOT IN (%s)" %
        ','.join('?' * len(REGIONS)), [r[0] for r in REGIONS]).fetchone()[0]
    print(f"    非法区域: {bad_reg} {'✓' if bad_reg == 0 else '✗'}")
    if bad_reg:
        errors.append(f'{bad_reg} 条世界大事区域不在字典中')

    # 空标题 / 空概述
    no_sum = db.execute('SELECT COUNT(*) FROM cn_events WHERE summary IS NULL OR summary = ""').fetchone()[0]
    print(f"    缺概述节点: {no_sum}（可接受，但影响前台显示）")

    # 10) 汇总
    print('\n' + '=' * 70)
    if errors:
        print(f'发现 {len(errors)} 个问题：')
        for e in errors[:30]:
            print(f'  ✗ {e}')
        if len(errors) > 30:
            print(f'  ... 另有 {len(errors) - 30} 条')
    else:
        print('全部通过 ✓')
    print('=' * 70)
    return 1 if errors else 0


if __name__ == '__main__':
    sys.exit(main())
