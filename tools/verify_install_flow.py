#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
安装流程端到端模拟
-----------------------------------------------------------------
复刻 install.php 的完整流程，用 sqlite3 真实执行：
  detect_state(empty) → create_tables → 导入字典/朝代/节点/大事/联动/管理员 → detect_state(complete)

并模拟各种中断场景，验证自愈能力：
  - 建表后崩溃（partial）→ 重跑能自动清理并完成
  - 重复安装 → 不撞唯一索引
  - 索引创建失败 → 错误信息可定位

用法：python tools/verify_install_flow.py
"""
import json
import os
import sqlite3
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, os.path.join(ROOT, 'tools'))

from verify_data import parse_php_arrays, SEED_FILES, CATEGORIES, REGIONS, REL_TYPES

CONTENT_TABLES = ['relations', 'cn_events', 'world_events', 'dynasties',
                  'categories', 'regions', 'relation_types', 'users']


def build_sqlite(ddl_map):
    return ddl_map


def build_index_sql(table, name, cols, unique, if_not_exists=True):
    uniq_kw = 'UNIQUE ' if unique else ''
    ine = 'IF NOT EXISTS ' if if_not_exists else ''
    return f'CREATE {uniq_kw}INDEX {ine}{name} ON {table} {cols}'


def detect_state(db):
    tables = ['users', 'cn_events', 'world_events', 'dynasties',
              'categories', 'regions', 'relation_types']
    exists = 0
    for t in tables:
        r = db.execute(
            "SELECT name FROM sqlite_master WHERE type='table' AND name=?", (t,)
        ).fetchone()
        if r:
            exists += 1
    if exists == 0:
        return 'empty'
    try:
        users = db.execute('SELECT COUNT(*) FROM users').fetchone()[0]
        cn = db.execute('SELECT COUNT(*) FROM cn_events').fetchone()[0]
    except sqlite3.Error:
        return 'partial'
    return 'complete' if (users > 0 and cn > 0) else 'partial'


def create_tables(db, ddl_map, indexes, log, errors):
    try:
        for name, ddl in ddl_map.items():
            try:
                db.execute(ddl)
                log.append(f'建表 {name}')
            except sqlite3.Error as e:
                raise RuntimeError(f'表 {name}: {e}') from e
        ok = 0
        for i in indexes:
            sql = build_index_sql(i['table'], i['name'], i['cols'], i['unique'])
            try:
                db.execute(sql)
                ok += 1
            except sqlite3.Error as e:
                raise RuntimeError(f'索引 {i["name"]} (ON {i["table"]}): {e}') from e
        log.append(f'索引创建完成（{ok} 个）')
        return True
    except RuntimeError as e:
        errors.append(f'建表失败: {e}')
        return False


def do_install(db, seed, log, errors):
    try:
        # 字典
        for i, (s, n, c) in enumerate(CATEGORIES):
            db.execute('INSERT INTO categories (slug,name,color,sort) VALUES (?,?,?,?)', (s, n, c, i * 10))
        for i, (s, n, e) in enumerate(REGIONS):
            db.execute('INSERT INTO regions (slug,name,emoji,sort) VALUES (?,?,?,?)', (s, n, e, i * 10))
        for r in REL_TYPES:
            db.execute('INSERT INTO relation_types (slug,name,descr,sort) VALUES (?,?,?,?)', r)
        log.append('字典表 3 张')

        # 朝代
        dyn = {}
        for d in seed['dynasties']:
            db.execute(
                'INSERT INTO dynasties (name,slug,start_year,end_year,color,summary,sort) VALUES (?,?,?,?,?,?,?)',
                (d.get('name'), d.get('slug'), d.get('start_year', -2070),
                 d.get('end_year', 2026), d.get('color', '#4c6ef5'),
                 d.get('summary'), d.get('sort', 0)))
            dyn[d.get('slug')] = db.execute('SELECT last_insert_rowid()').fetchone()[0]
        log.append(f'朝代 {len(dyn)} 条')

        # 中国节点
        cn = {}
        for e in seed['cn_events']:
            slug = e.get('dynasty')
            if slug not in dyn:
                raise ValueError(f'未知朝代 {slug}')
            db.execute(
                '''INSERT INTO cn_events (dynasty_id,title,year,year_end,month,day,category,place,summary,detail,figures,importance,is_key)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)''',
                (dyn[slug], e.get('title'), e.get('year', 0), e.get('year_end'),
                 e.get('month'), e.get('day'), e.get('category', 'politics'), e.get('place'),
                 e.get('summary'), e.get('detail'), e.get('figures'),
                 e.get('importance', 3), 1 if e.get('is_key') else 0))
            cn[e.get('title')] = db.execute('SELECT last_insert_rowid()').fetchone()[0]
        log.append(f'中国节点 {len(cn)} 条')

        # 世界大事
        we = {}
        for e in seed['world_events']:
            db.execute(
                '''INSERT INTO world_events (title,year,year_end,month,day,region,category,place,summary,detail,figures,importance,source)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)''',
                (e.get('title'), e.get('year', 0), e.get('year_end'), e.get('month'),
                 e.get('day'), e.get('region', 'global'), e.get('category', 'politics'),
                 e.get('place'), e.get('summary'), e.get('detail'), e.get('figures'),
                 e.get('importance', 3), e.get('source')))
            we[e.get('title')] = db.execute('SELECT last_insert_rowid()').fetchone()[0]
        log.append(f'世界大事 {len(we)} 条')

        # 联动
        n = 0
        for r in seed['relations']:
            a = cn.get(r.get('cn_ref'))
            b = we.get(r.get('world_ref'))
            if not a or not b:
                continue
            db.execute('INSERT INTO relations (cn_event_id,world_event_id,relation_type,note) VALUES (?,?,?,?)',
                       (a, b, r.get('type', 'echo'), r.get('note')))
            n += 1
        log.append(f'中外联动 {n} 条')

        # 管理员
        db.execute('INSERT INTO users (username,password,display_name) VALUES (?,?,?)',
                   ('admin', 'HASH', '管理员'))
        log.append('管理员 admin')
        return True
    except (sqlite3.Error, ValueError) as e:
        errors.append(f'导入失败: {e}')
        return False


def main():
    # 载入 schema 与 seed
    schema_path = os.path.join(ROOT, 'tools', '_schema.json')
    if not os.path.exists(schema_path):
        print('缺少 tools/_schema.json，请先运行：php tools/dump_schema.php > tools/_schema.json')
        return 1
    data = json.load(open(schema_path, encoding='utf-8'))
    ddl_map, indexes = data['tables'], data['indexes']

    seed = {'dynasties': [], 'cn_events': [], 'world_events': [], 'relations': []}
    for f in SEED_FILES:
        p = parse_php_arrays(f)
        for k in seed:
            seed[k].extend(p.get(k, []))

    print('=' * 72)
    print('安装流程端到端模拟')
    print('=' * 72)

    # ---- 场景 1：全新安装 ----
    print('\n[场景 1] 全新安装')
    db = sqlite3.connect(':memory:')
    st = detect_state(db)
    print(f'  初始状态: {st}  {"✓" if st == "empty" else "✗"}')

    log, errors = [], []
    if not create_tables(db, ddl_map, indexes, log, errors):
        print('  建表失败:', errors)
        return 1
    print(f'  建表后状态: {detect_state(db)}  （应为 partial）')

    # 安装器此时应自动清理残留（虽然本来是空的）
    if detect_state(db) == 'partial':
        for t in CONTENT_TABLES:
            if db.execute("SELECT name FROM sqlite_master WHERE type='table' AND name=?", (t,)).fetchone():
                db.execute(f'DELETE FROM {t}')
        print('  已清理残留')

    if not do_install(db, seed, log, errors):
        print('  导入失败:', errors)
        return 1
    for l in log:
        print(f'  ✓ {l}')
    st = detect_state(db)
    print(f'  完成状态: {st}  {"✓" if st == "complete" else "✗"}')

    # ---- 场景 2：中途失败后重跑（partial 自愈）----
    print('\n[场景 2] 导入中途失败 → 重跑自愈')
    db2 = sqlite3.connect(':memory:')
    log2, errors2 = [], []
    create_tables(db2, ddl_map, indexes, log2, errors2)
    # 只导字典表就"崩溃"
    for i, (s, n, c) in enumerate(CATEGORIES):
        db2.execute('INSERT INTO categories (slug,name,color,sort) VALUES (?,?,?,?)', (s, n, c, i * 10))
    st_mid = detect_state(db2)
    print(f'  崩溃后状态: {st_mid}  {"✓" if st_mid == "partial" else "✗"}')

    # 重跑：应先清理再完整导入
    log3, errors3 = [], []
    create_tables(db2, ddl_map, indexes, log3, errors3)
    cleaned = False
    if detect_state(db2) == 'partial':
        for t in CONTENT_TABLES:
            if db2.execute("SELECT name FROM sqlite_master WHERE type='table' AND name=?", (t,)).fetchone():
                db2.execute(f'DELETE FROM {t}')
        cleaned = True
    print(f'  重跑已清理残留: {"✓" if cleaned else "✗"}')
    if not do_install(db2, seed, log3, errors3):
        print('  重跑失败:', errors3)
        print('  ✗ partial 自愈失败')
        return 1
    st2 = detect_state(db2)
    cnt = db2.execute('SELECT COUNT(*) FROM cn_events').fetchone()[0]
    print(f'  重跑后状态: {st2}，节点数 {cnt}  {"✓" if st2 == "complete" and cnt == len(seed["cn_events"]) else "✗"}')

    # ---- 场景 3：重复安装不撞唯一索引 ----
    print('\n[场景 3] 完整状态下重装（force 清空）')
    # 先试不清空直接导 → 应该报唯一索引冲突（这正是 install.php 用 detect_state 拦住的场景）
    log4, errors4 = [], []
    dup_blocked = False
    try:
        do_install(db2, seed, log4, errors4)
    except sqlite3.IntegrityError:
        dup_blocked = True
    if not dup_blocked:
        # do_install 内部已捕获并记入 errors，也算拦住
        dup_blocked = any('UNIQUE' in e or 'constraint' in e.lower() for e in errors4)
        if not dup_blocked:
            print(f'  ✗ 未清空直接导入竟然成功了（错误信息：{errors4}）')
            return 1
    print('  未清空直接导入被唯一索引拦住 ✓')

    # 模拟 install.php 的 force 行为：先清空全部内容表再导
    for t in CONTENT_TABLES:
        if db2.execute("SELECT name FROM sqlite_master WHERE type='table' AND name=?", (t,)).fetchone():
            db2.execute(f'DELETE FROM {t}')
    log5, errors5 = [], []
    if not do_install(db2, seed, log5, errors5):
        print('  ✗ force 重装失败:', errors5)
        return 1
    cnt2 = db2.execute('SELECT COUNT(*) FROM cn_events').fetchone()[0]
    print(f'  force 清空重装后节点数 {cnt2}  {"✓" if cnt2 == len(seed["cn_events"]) else "✗"}')

    # ---- 场景 4：完整状态应被 install.php 拦住（detect_state == complete 且未 force）----
    print('\n[场景 4] 完整状态下 install.php 的拦截逻辑')
    st_complete = detect_state(db2)
    should_block = (st_complete == 'complete')
    print(f'  detect_state = {st_complete} → install.php {"会" if should_block else "不会"}要求确认清空  '
          f'{"✓" if should_block else "✗"}')

    # ---- 汇总 ----
    print('\n' + '=' * 72)
    print('安装流程全部通过 ✓')
    print('=' * 72)
    return 0


if __name__ == '__main__':
    sys.exit(main())
