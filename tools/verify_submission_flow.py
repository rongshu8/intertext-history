#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
投稿审核流程验证（用 Python sqlite3 复刻，验证 SQL 与业务规则）
-----------------------------------------------------------------
验证场景：
  1. 迁移：users 补字段 + submissions 建表
  2. 注册用户 → 提交三类投稿 → 状态为 pending
  3. **未审核内容不出现在正式表**（核心需求）
  4. 管理员审核通过 → 内容写入正式表，status 变 approved
  5. 驳回 → 不写入正式表
  6. 撤回 → 不可再审核
  7. 重复联动被拦截
  8. 标题重复自动加后缀

用法：python tools/verify_submission_flow.py
"""
import json
import os
import sqlite3
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, os.path.join(ROOT, 'tools'))

from verify_data import parse_php_arrays, SEED_FILES, CATEGORIES, REGIONS, REL_TYPES, DDL_FALLBACK

CONTENT_TABLES = ['submissions', 'relations', 'cn_events', 'world_events', 'dynasties',
                  'categories', 'regions', 'relation_types', 'users']

FAILS = []
OKS = 0


def check(label, cond, detail=''):
    global OKS
    if cond:
        OKS += 1
        print(f'    ✓ {label}')
    else:
        FAILS.append(f'{label}  {detail}')
        print(f'    ✗ {label}  {detail}')


def fresh_db(with_data=True):
    db = sqlite3.connect(':memory:')
    db.row_factory = sqlite3.Row
    for name, ddl in DDL_FALLBACK.items():
        db.execute(ddl)
    # users 补字段（模拟迁移后的结构）
    db.execute("ALTER TABLE users ADD COLUMN email VARCHAR(160) NULL")
    db.execute("ALTER TABLE users ADD COLUMN role VARCHAR(16) NOT NULL DEFAULT 'user'")
    db.execute("ALTER TABLE users ADD COLUMN status VARCHAR(16) NOT NULL DEFAULT 'active'")
    # submissions
    db.execute("""CREATE TABLE IF NOT EXISTS submissions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        kind VARCHAR(20) NOT NULL,
        payload TEXT NOT NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'pending',
        review_note VARCHAR(500) NULL,
        reviewer_id INTEGER NULL,
        reviewed_at DATETIME NULL,
        target_id INTEGER NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )""")
    db.execute("CREATE INDEX IF NOT EXISTS idx_sub_status ON submissions (status, kind)")
    db.execute("CREATE INDEX IF NOT EXISTS idx_sub_user ON submissions (user_id, created_at)")

    if with_data:
        for i, (s, n, c) in enumerate(CATEGORIES):
            db.execute('INSERT INTO categories (slug,name,color,sort) VALUES (?,?,?,?)', (s, n, c, i*10))
        for i, (s, n, e) in enumerate(REGIONS):
            db.execute('INSERT INTO regions (slug,name,emoji,sort) VALUES (?,?,?,?)', (s, n, e, i*10))
        for r in REL_TYPES:
            db.execute('INSERT INTO relation_types (slug,name,descr,sort) VALUES (?,?,?,?)', r)
        # 少量朝代与事件，便于测联动
        for d in [('秦', 'qin', -221, -206), ('东汉', 'dong_han', 25, 220)]:
            db.execute('INSERT INTO dynasties (name,slug,start_year,end_year,color,sort) VALUES (?,?,?,?,?,?)',
                       (d[0], d[1], d[2], d[3], '#000', 1))
        db.execute("""INSERT INTO cn_events (dynasty_id,title,year,summary)
                      VALUES (1,'秦始皇统一六国',-221,'建立中央集权帝国')""")
        db.execute("""INSERT INTO world_events (title,year,region,category,summary)
                      VALUES ('罗马共和国扩张',-264,'europe','war','确立地中海霸权')""")
        db.execute("INSERT INTO users (username,password,display_name,role,status) VALUES (?,?,?,?,?)",
                   ('admin', 'x', '管理员', 'admin', 'active'))
    return db


def main():
    print('=' * 70)
    print('投稿审核流程验证')
    print('=' * 70)

    # ---------- 场景 1：注册用户 + 三类投稿 ----------
    print('\n[1] 注册用户并提交投稿')
    db = fresh_db()
    db.execute("INSERT INTO users (username,password,display_name,email,role,status) VALUES (?,?,?,?,?,?)",
               ('zhangsan', 'h', '张三', 'zs@test.com', 'user', 'active'))
    uid = db.execute('SELECT last_insert_rowid()').fetchone()[0]
    check('注册用户创建', uid > 0)
    check('默认角色为 user', db.execute('SELECT role FROM users WHERE username=?', ('zhangsan',)).fetchone()[0] == 'user')

    # 中国节点投稿
    cn_payload = {
        'dynasty_id': 1, 'title': '王莽托古改制', 'year': 9, 'year_end': 23,
        'month': None, 'day': None, 'category': 'politics', 'place': '长安',
        'summary': '外戚王莽受禅称帝。', 'detail': '行王田制、五均六筦。',
        'figures': '王莽', 'importance': 3, 'is_key': 0,
    }
    db.execute('INSERT INTO submissions (user_id,kind,payload,status) VALUES (?,?,?,?)',
               (uid, 'cn_node', json.dumps(cn_payload, ensure_ascii=False), 'pending'))

    # 世界大事投稿
    we_payload = {
        'title': '东汉光武中兴', 'year': 25, 'year_end': None, 'month': None, 'day': None,
        'region': 'east_asia', 'category': 'politics', 'place': '洛阳',
        'summary': '刘秀重建汉朝。', 'detail': '柔道行之，六十年无大乱政。',
        'figures': '刘秀', 'importance': 3, 'source': '后汉书',
    }
    db.execute('INSERT INTO submissions (user_id,kind,payload,status) VALUES (?,?,?,?)',
               (uid, 'world_event', json.dumps(we_payload, ensure_ascii=False), 'pending'))

    # 联动投稿
    rel_payload = {
        'cn_event_id': 1, 'world_event_id': 1, 'relation_type': 'echo',
        'note': '秦统一与罗马扩张同处公元前 3 世纪，地中海与欧亚大陆的两端。',
    }
    db.execute('INSERT INTO submissions (user_id,kind,payload,status) VALUES (?,?,?,?)',
               (uid, 'relation', json.dumps(rel_payload, ensure_ascii=False), 'pending'))

    subs = db.execute('SELECT id,kind,status FROM submissions ORDER BY id').fetchall()
    check('三条投稿均创建', len(subs) == 3, f'实际 {len(subs)}')
    check('状态均为 pending', all(s['status'] == 'pending' for s in subs))

    # ---------- 场景 2：核心需求 —— 未审核内容不出现在正式表 ----------
    print('\n[2] 核心：未审核内容不进入正式表')
    n_cn = db.execute('SELECT COUNT(*) FROM cn_events').fetchone()[0]
    n_we = db.execute('SELECT COUNT(*) FROM world_events').fetchone()[0]
    n_rel = db.execute('SELECT COUNT(*) FROM relations').fetchone()[0]
    check('正式表无新节点', n_cn == 1, f'cn_events={n_cn}（应为 1，只有种子数据）')
    check('正式表无新大事', n_we == 1, f'world_events={n_we}（应为 1）')
    check('正式表无新联动', n_rel == 0, f'relations={n_rel}（应为 0）')
    check('投稿标题未泄露到正式表',
          db.execute("SELECT COUNT(*) FROM cn_events WHERE title='王莽托古改制'").fetchone()[0] == 0)

    # ---------- 场景 3：审核通过 ----------
    print('\n[3] 管理员审核通过')
    admin_id = db.execute("SELECT id FROM users WHERE role='admin'").fetchone()[0]
    s_id = subs[0]['id']

    p = json.loads(db.execute('SELECT payload FROM submissions WHERE id=?', (s_id,)).fetchone()[0])
    # 唯一标题处理
    title = p['title']
    if db.execute('SELECT id FROM cn_events WHERE title=?', (title,)).fetchone():
        title = title + '（补充）'
    db.execute("""INSERT INTO cn_events
                  (dynasty_id,title,year,year_end,month,day,category,place,summary,detail,figures,importance,is_key)
                  VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)""",
               (p['dynasty_id'], title, p['year'], p['year_end'], p['month'], p['day'],
                p['category'], p['place'], p['summary'], p['detail'], p['figures'],
                p['importance'], p['is_key']))
    tid = db.execute('SELECT last_insert_rowid()').fetchone()[0]
    db.execute("""UPDATE submissions SET status='approved', reviewer_id=?, reviewed_at=CURRENT_TIMESTAMP,
                  target_id=?, updated_at=CURRENT_TIMESTAMP WHERE id=?""", (admin_id, tid, s_id))

    row = db.execute('SELECT status,target_id FROM submissions WHERE id=?', (s_id,)).fetchone()
    check('投稿状态变 approved', row['status'] == 'approved')
    check('记录了正式表主键', row['target_id'] == tid)
    check('节点已进入正式表',
          db.execute('SELECT COUNT(*) FROM cn_events WHERE id=?', (tid,)).fetchone()[0] == 1)

    # ---------- 场景 4：驳回 ----------
    print('\n[4] 驳回不写入正式表')
    s_id2 = subs[1]['id']
    db.execute("""UPDATE submissions SET status='rejected', review_note=?, reviewer_id=?,
                  reviewed_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE id=?""", ('来源不明，请补充', admin_id, s_id2))
    n_we2 = db.execute('SELECT COUNT(*) FROM world_events').fetchone()[0]
    check('驳回后世界大事表未增加', n_we2 == 1, f'world_events={n_we2}')
    check('驳回意见已记录',
          db.execute('SELECT review_note FROM submissions WHERE id=?', (s_id2,)).fetchone()[0] == '来源不明，请补充')

    # ---------- 场景 5：撤回后不可审核 ----------
    print('\n[5] 撤回的投稿不可再审核')
    s_id3 = subs[2]['id']
    db.execute("UPDATE submissions SET status='withdrawn' WHERE id=?", (s_id3,))
    # 模拟审核逻辑：只有 pending 能被处理
    can_process = db.execute("SELECT COUNT(*) FROM submissions WHERE id=? AND status='pending'", (s_id3,)).fetchone()[0]
    check('撤回后不在待审集合', can_process == 0)
    n_rel2 = db.execute('SELECT COUNT(*) FROM relations').fetchone()[0]
    check('撤回的联动未进入正式表', n_rel2 == 0)

    # ---------- 场景 6：联动通过 ----------
    print('\n[6] 联动投稿审核通过')
    s_id4 = db.execute("SELECT id FROM submissions WHERE kind='relation' AND status='withdrawn'").fetchone()
    # 用一条新的联动投稿
    db.execute('INSERT INTO submissions (user_id,kind,payload,status) VALUES (?,?,?,?)',
               (uid, 'relation', json.dumps(rel_payload, ensure_ascii=False), 'pending'))
    s4 = db.execute('SELECT last_insert_rowid()').fetchone()[0]
    db.execute('INSERT INTO relations (cn_event_id, world_event_id, relation_type, note) VALUES (?,?,?,?)',
               (rel_payload['cn_event_id'], rel_payload['world_event_id'],
                rel_payload['relation_type'], rel_payload['note']))
    rtid = db.execute('SELECT last_insert_rowid()').fetchone()[0]
    db.execute("UPDATE submissions SET status='approved', reviewer_id=?, target_id=? WHERE id=?",
               (admin_id, rtid, s4))
    check('联动已进入正式表',
          db.execute('SELECT COUNT(*) FROM relations WHERE id=?', (rtid,)).fetchone()[0] == 1)
    check('联动投稿状态 approved',
          db.execute('SELECT status FROM submissions WHERE id=?', (s4,)).fetchone()[0] == 'approved')

    # ---------- 场景 7：重复联动拦截 ----------
    print('\n[7] 校验规则')
    dup = db.execute('SELECT id FROM relations WHERE cn_event_id=? AND world_event_id=?',
                     (rel_payload['cn_event_id'], rel_payload['world_event_id'])).fetchone()
    check('重复联动可被检出（用于拦截）', dup is not None)

    # 标题重复
    dup_title = db.execute("SELECT id FROM cn_events WHERE title=?", (title,)).fetchone()
    check('标题重复可被检出', dup_title is not None)

    # ---------- 场景 8：按用户/状态查询 ----------
    print('\n[8] 列表查询')
    mine = db.execute('SELECT COUNT(*) FROM submissions WHERE user_id=?', (uid,)).fetchone()[0]
    check('按用户查投稿', mine == 4, f'实际 {mine}')
    pend = db.execute("SELECT COUNT(*) FROM submissions WHERE status='pending'").fetchone()[0]
    check('按状态查待审', pend == 0, f'实际 {pend}')
    by_kind = db.execute("SELECT COUNT(*) FROM submissions WHERE kind='cn_node'").fetchone()[0]
    check('按类型查投稿', by_kind == 1, f'实际 {by_kind}')

    # ---------- 汇总 ----------
    print('\n' + '=' * 70)
    print(f'通过 {OKS} 项' + (f'，失败 {len(FAILS)} 项' if FAILS else '，全部通过 ✓'))
    for f in FAILS:
        print(f'  ✗ {f}')
    print('=' * 70)
    return 1 if FAILS else 0


if __name__ == '__main__':
    sys.exit(main())
