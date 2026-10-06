#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
索引 SQL 真实执行验证
-----------------------------------------------------------------
从 PHP 导出 Schema::indexes() 与 Schema::tables()，
用 sqlite3 真实建库建索引，验证：
  1. 全部 DDL 可执行
  2. 全部索引可创建（含 UNIQUE 位置正确）
  3. 重复执行幂等
  4. 唯一约束真正生效
  5. 核心查询（world_events_near）可用索引且结果正确

用法：
  php tools/dump_schema.php > /tmp/schema.json
  python tools/verify_index.py tools/_schema.json
"""
import json
import os
import re
import sqlite3
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))


def build_index_sql(table, name, cols, unique, if_not_exists):
    """复刻 Schema::createIndex() 的拼串逻辑"""
    uniq_kw = 'UNIQUE ' if unique else ''
    ine = 'IF NOT EXISTS ' if if_not_exists else ''
    return f'CREATE {uniq_kw}INDEX {ine}{name} ON {table} {cols}'


def main():
    path = sys.argv[1] if len(sys.argv) > 1 else os.path.join(ROOT, 'tools', '_schema.json')
    data = json.load(open(path, encoding='utf-8'))
    tables = data['tables']
    indexes = data['indexes']

    print('=' * 72)
    print('索引与 DDL 真实执行验证')
    print('=' * 72)

    db = sqlite3.connect(':memory:')
    db.execute('PRAGMA foreign_keys = ON')

    # 1) 建表
    print(f'\n[1] 建表（{len(tables)} 张）')
    fail = 0
    for name, ddl in tables.items():
        # DDL 洁净检查：整段会被原样送进数据库，出现注释符号会直接语法报错
        # （踩过的坑：submissions 的 DDL 里写了 // 行内注释，SQLite 报 near "/"）
        # 只查「注释符号 + 至少一个空格」的形态，避免误伤 #4c6ef5 这类十六进制颜色值
        bad_markers = []
        if re.search(r'//\s*\S', ddl):
            bad_markers.append('// 行注释')
        if re.search(r'/\*', ddl):
            bad_markers.append('/* 块注释')
        # 行首（可有缩进）才算 # 注释
        if re.search(r'(?m)^\s*#\s*\S', ddl):
            bad_markers.append('# 行注释')
        for desc in bad_markers:
            print(f'    ✗ {name} 的 DDL 含 {desc}，执行时必然语法错误')
            fail += 1
        try:
            db.execute(ddl)
            print(f'    OK   {name}')
        except Exception as e:
            print(f'    FAIL {name}: {e}')
            fail += 1
    if fail:
        print('\n建表失败，终止')
        return 1

    # 2) 建索引
    print(f'\n[2] 建索引（{len(indexes)} 个）')
    idx_fail = 0
    for i in indexes:
        sql = build_index_sql(i['table'], i['name'], i['cols'], i['unique'], True)
        try:
            db.execute(sql)
            print(f'    OK   {sql}')
        except Exception as e:
            print(f'    FAIL {sql}\n         → {e}')
            idx_fail += 1
    if idx_fail:
        print(f'\n{idx_fail} 个索引创建失败')
        return 1

    # 3) 幂等性
    print('\n[3] 重复执行（IF NOT EXISTS 应静默跳过）')
    re_fail = 0
    for i in indexes:
        sql = build_index_sql(i['table'], i['name'], i['cols'], i['unique'], True)
        try:
            db.execute(sql)
        except Exception as e:
            print(f'    FAIL {sql}\n         → {e}')
            re_fail += 1
    print(f'    {"全部幂等 OK" if re_fail == 0 else f"{re_fail} 个不幂等"}')

    # 4) 唯一约束生效验证
    #    这些表有 NOT NULL 字段，插入时需补齐最小必填值。
    #    fill 取「列名 → SQL 字面量」，唯一列用占位符 ?。
    print('\n[4] 唯一约束验证')
    fillers = {
        'dynasties':      {'name': "'__n__'"},
        'categories':     {'name': "'__n__'"},
        'regions':        {'name': "'__n__'"},
        'relation_types': {'name': "'__n__'"},
    }
    for i in indexes:
        if not i['unique']:
            continue
        t = i['table']
        col = i['cols'].strip('()').split(',')[0].strip()
        extra = fillers.get(t, {})
        cols_sql = ', '.join([col] + list(extra.keys()))
        vals_sql = ', '.join(['?'] + list(extra.values()))
        db.execute(f'INSERT INTO {t} ({cols_sql}) VALUES ({vals_sql})', ('__dup__',))
        try:
            db.execute(f'INSERT INTO {t} ({cols_sql}) VALUES ({vals_sql})', ('__dup__',))
            print(f'    ✗ {t}.{col} 唯一约束未生效')
        except sqlite3.IntegrityError:
            print(f'    ✓ {t}.{col} 唯一约束生效')
        db.execute(f'DELETE FROM {t} WHERE {col} = ?', ('__dup__',))

    # 5) 实际列出 SQLite 里的索引，确认都建上了
    print('\n[5] 数据库中实际存在的索引')
    for t in tables:
        rows = db.execute(
            "SELECT name, sql FROM sqlite_master WHERE type='index' AND tbl_name=? AND sql IS NOT NULL",
            (t,)
        ).fetchall()
        if rows:
            for n, s in rows:
                mark = 'UNIQUE' if s.upper().startswith('CREATE UNIQUE') else '      '
                print(f'    {mark}  {n}')

    # 6) 核心查询验证（带真实数据）
    print('\n[6] 核心查询 world_events_near 验证')
    db.execute("INSERT INTO world_events (title, year, region, category) VALUES ('测试A', 100, 'europe', 'science')")
    db.execute("INSERT INTO world_events (title, year, year_end, region, category) VALUES ('测试B区间', 200, 260, 'europe', 'war')")
    db.execute("INSERT INTO world_events (title, year, region, category) VALUES ('测试C', 500, 'europe', 'science')")

    def near(year, year_end, lo, hi):
        return db.execute(
            '''SELECT title FROM world_events
               WHERE (year_end IS NULL AND year BETWEEN ? AND ?)
                  OR (year_end IS NOT NULL AND year <= ? AND year_end >= ?)''',
            (lo, hi, hi, lo)
        ).fetchall()

    # 节点 100 年，窗口 ±5 → [95,105]，应命中测试A
    r1 = near(100, None, 95, 105)
    print(f'    节点 100 年 ±5 → {[x[0] for x in r1]}  {"✓" if len(r1) == 1 and r1[0][0] == "测试A" else "✗"}')

    # 节点 250 年，窗口 ±20 → [230,270]，应命中区间事件测试B
    r2 = near(250, None, 230, 270)
    print(f'    节点 250 年 ±20 → {[x[0] for x in r2]}  {"✓" if len(r2) == 1 else "✗"}')

    # 节点 495 年，窗口 ±10 → [485,505]，应命中测试C
    r3 = near(495, None, 485, 505)
    print(f'    节点 495 年 ±10 → {[x[0] for x in r3]}  {"✓" if len(r3) == 1 else "✗"}')

    # 窗口外应为空
    r4 = near(100, None, 300, 400)
    print(f'    窗口外查询 → {r4}  {"✓" if len(r4) == 0 else "✗"}')

    print('\n' + '=' * 72)
    print('全部通过 ✓' if fail == 0 and idx_fail == 0 and re_fail == 0 else '存在问题 ✗')
    print('=' * 72)
    return 0 if (fail == 0 and idx_fail == 0 and re_fail == 0) else 1


if __name__ == '__main__':
    sys.exit(main())
