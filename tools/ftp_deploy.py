#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
FTP 部署工具（只读模式 / 备份 / 上传）
-----------------------------------------------------------------
用法：
  python tools/ftp_deploy.py list                 列出线上文件
  python tools/ftp_deploy.py pull <本地目录>      下载线上文件到本地（备份）
  python tools/ftp_deploy.py push <本地目录>      上传/覆盖（不删除线上多余文件）
  python tools/ftp_deploy.php probe               探测站点根与关键文件

安全约束（遵守项目红线）：
  - **不删除**线上任何文件
  - push 前自动 pull 备份到 _backup_<时间戳>/
  - 只覆盖同名文件，新增文件直接传
"""
import ftplib
import os
import posixpath
import sys
import time
from datetime import datetime

# ---- 连接信息 ----
#
# ★ 全部走环境变量，**不写任何默认值**。
#   这个脚本要开源分发，写死主机名与密码等于把生产服务器的钥匙
#   印在公开的代码里 —— 任何人 clone 下来就能直接连上去删库。
#   （本项目真踩过：根目录一个随手笔记里写着 FTP 密码，
#     打包器只查文件名完全没发现，它就那样进了准备分发的 ZIP。）
#
# 用法：
#   export FTP_HOST=example.com FTP_USER=me FTP_PASS=***
#   python tools/ftp_deploy.py list
HOST = os.environ.get('FTP_HOST', '')
PORT = int(os.environ.get('FTP_PORT', '21'))
USER = os.environ.get('FTP_USER', '')
PASS = os.environ.get('FTP_PASS', '')

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

# 不上传的文件/目录
SKIP_NAMES = {'.git', '.idea', '.vscode', '__pycache__', '.DS_Store', 'Thumbs.db',
              '.workbuddy',
              # 服务器特有配置，绝不能覆盖
              '.user.ini',
              # ★ config.local.php 是**每台机器各自一份**的配置：
              #   线上那份的库名/库密码/持久连接开关可能和本地不同
              #   （本项目换过库名）。本地这份一旦过期，传上去会直接覆盖线上配置
              #   → 站点立刻连不上库，而报错指向 PDO 连接失败、
              #   不是"配置被覆盖"，排查方向容易被带偏。
              #   线上本来就有这份且是对的，不该由部署脚本管理它。
              'config.local.php'}
# 注意：不要在这里排 '.json' —— data/seed/*.json 是内容数据，必须上传。
# （此前把 .json 排掉导致数据包传不上去，install 只能回退读旧 PHP 种子。）
SKIP_EXT = {'.pyc', '.log', '.bak', '.old', '.sqlite', '.sqlite-wal', '.sqlite-shm'}
# 整个目录跳过
SKIP_DIRS = {'tools', 'cache'}
# 备份目录通配
SKIP_DIR_PREFIX = '_backup_'
# 临时目录前缀：_shots / _diag / _test 这类工作目录一律不上传。
# 曾因本地建了 _shots 存截图，结果被当成项目文件传到线上，
# 只能用 FTP 手动删文件+目录 —— FTP 不支持「删除非空目录」，很麻烦。
SKIP_DIR_UNDERSCORE = True
# 允许上传的隐藏文件（目录级 .htaccess 是安全配置，必须上传）
ALLOW_DOT_FILES = {'.htaccess'}


def connect():
    # 没给凭据就直接报错，不要去连空主机 —— 那会产生一条
    # 让人摸不着头脑的 socket 错误，掩盖真正的缺失。
    missing = [n for n, v in (('FTP_HOST', HOST), ('FTP_USER', USER), ('FTP_PASS', PASS))
               if not v]
    if missing:
        raise SystemExit(
            '缺少环境变量：%s\n'
            '用法示例：\n'
            '  export FTP_HOST=example.com FTP_USER=your_user FTP_PASS=your_pass\n'
            '  python %s list' % (', '.join(missing), os.path.basename(sys.argv[0])))
    f = ftplib.FTP()
    f.connect(HOST, PORT, timeout=30)
    f.login(USER, PASS)
    f.set_pasv(True)
    return f


def listdir(f, path='/'):
    """用 MLSD 拿精确的类型与大小；不支持时回退 nlst + size 试探"""
    items = []
    try:
        f.mlsd(path, facts=['type', 'size', 'modify'])
        raw = f.mlsd(path, facts=['type', 'size', 'modify'])
        for name, facts in raw:
            if name in ('.', '..'):
                continue
            items.append({
                'name': name,
                'path': posixpath.join(path, name) if path != '/' else '/' + name,
                'dir': facts.get('type') == 'dir',
                'size': int(facts.get('size', 0) or 0),
                'mtime': facts.get('modify'),
            })
        return items
    except (ftplib.error_perm, AttributeError):
        pass

    # 回退方案
    try:
        names = f.nlst(path)
    except ftplib.all_errors as e:
        print(f'  读取 {path} 失败: {e}')
        return []
    for n in names:
        base = posixpath.basename(n.rstrip('/'))
        if base in ('.', '..'):
            continue
        isdir = False
        try:
            cur = f.pwd()
            f.cwd(n)
            f.cwd(cur)
            isdir = True
        except Exception:
            isdir = False
        size = 0
        mtime = None
        try:
            size = f.size(n)
        except Exception:
            pass
        try:
            mtime = f.sendcmd(f'MDTM {n}')[4:].strip()
        except Exception:
            pass
        items.append({
            'name': base,
            'path': n if n.endswith('/') else n,
            'dir': isdir,
            'size': size,
            'mtime': mtime,
        })
    return items


def walk(f, path='/', depth=0, maxdepth=4, out=None):
    if out is None:
        out = []
    for it in listdir(f, path):
        it['depth'] = depth
        out.append(it)
        if it['dir'] and depth < maxdepth:
            walk(f, it['path'].rstrip('/') + '/', depth + 1, maxdepth, out)
    return out


def should_skip(name):
    # 目录级 .htaccess 是安全配置，必须上传
    if name in ALLOW_DOT_FILES:
        return False
    if name in SKIP_NAMES:
        return True
    if name.startswith('.') and name not in ALLOW_DOT_FILES:
        return True
    # 下划线开头 = 开发用参考文件（data/_WORLD_EVENT_SPEC.php、
    # data/_existing_world_titles.txt 等），不属于运行代码，不上传。
    # 项目里没有以下划线开头的合法运行文件，可放心按前缀排除。
    if name.startswith('_'):
        return True
    ext = os.path.splitext(name)[1].lower()
    return ext in SKIP_EXT


def collect_local(base):
    """收集要上传的本地文件"""
    files = []
    for dirpath, dirnames, filenames in os.walk(base):
        # 原地裁剪目录列表，跳过不传的目录
        dirnames[:] = [d for d in dirnames
                       if d not in SKIP_DIRS
                       and not d.startswith(SKIP_DIR_PREFIX)
                       and not (SKIP_DIR_UNDERSCORE and d.startswith('_'))
                       and d != '__pycache__']
        for fn in filenames:
            if should_skip(fn):
                continue
            full = os.path.join(dirpath, fn)
            rel = os.path.relpath(full, base).replace('\\', '/')
            if rel.startswith(SKIP_DIR_PREFIX) or rel.split('/')[0] in SKIP_DIRS:
                continue
            if any(seg.startswith('_') for seg in rel.split('/')[:-1]):
                continue
            files.append((full, rel, os.path.getsize(full)))
    return files


def ensure_dir(f, path):
    """递归创建远端目录"""
    parts = [p for p in path.strip('/').split('/') if p]
    cur = '/'
    for p in parts:
        nxt = posixpath.join(cur, p)
        try:
            f.cwd(nxt)
        except Exception:
            try:
                f.mkd(nxt)
            except Exception as e:
                print(f'  ! 创建目录 {nxt} 失败: {e}')
                return False
        cur = nxt
    f.cwd('/')
    return True


def cmd_list():
    f = connect()
    print('=' * 68)
    print(f'线上文件清单  {HOST}')
    print('=' * 68)
    items = walk(f, '/', maxdepth=3)
    for it in sorted(items, key=lambda x: x['path']):
        pre = '  ' * it['depth']
        if it['dir']:
            print(f'{pre}[DIR] {it["name"]}/')
        else:
            sz = it['size']
            szs = f'{sz:,}B' if sz else '?'
            print(f'{pre}{it["name"]}  ({szs})')
    print(f'\n共 {len([i for i in items if not i["dir"]])} 个文件, {len([i for i in items if i["dir"]])} 个目录')
    f.quit()


def cmd_probe():
    """探测站点结构：找真正的 web 根在哪"""
    f = connect()
    print('=' * 68)
    print('站点探测')
    print('=' * 68)
    print('当前目录:', f.pwd())
    try:
        print('根目录列表:')
        for it in listdir(f, '/'):
            t = 'DIR ' if it['dir'] else 'FILE'
            print(f'  [{t}] {it["name"]}')
    except Exception as e:
        print('  失败:', e)
    # 探测 www/wwwroot/public 等常见位置
    for cand in ['www', 'wwwroot', 'public', 'htdocs', 'web']:
        try:
            items = listdir(f, '/' + cand)
            if items:
                print(f'\n/{cand}/ 存在，内容:')
                for it in items[:20]:
                    t = 'DIR ' if it['dir'] else 'FILE'
                    print(f'  [{t}] {it["name"]}')
        except Exception:
            pass
    f.quit()


def cmd_pull(target=None):
    ts = datetime.now().strftime('%Y%m%d_%H%M%S')
    target = target or os.path.join(ROOT, '_backup_' + ts)
    os.makedirs(target, exist_ok=True)
    f = connect()
    print(f'备份线上文件到: {target}')
    items = walk(f, '/', maxdepth=4)
    n = 0
    for it in items:
        if it['dir']:
            local_dir = os.path.join(target, it['path'].strip('/'))
            os.makedirs(local_dir, exist_ok=True)
            continue
        rel = it['path'].strip('/')
        local_path = os.path.join(target, rel.replace('/', os.sep))
        os.makedirs(os.path.dirname(local_path), exist_ok=True)
        try:
            with open(local_path, 'wb') as fh:
                f.retrbinary(f'RETR {it["path"]}', fh.write)
            n += 1
            print(f'  ↓ {rel}')
        except Exception as e:
            print(f'  ! {rel} 下载失败: {e}')
    print(f'\n共备份 {n} 个文件')
    f.quit()
    return target


def cmd_push(local_base=None):
    local_base = local_base or ROOT

    # 1) 先备份
    print('[1/3] 备份线上现有文件…')
    backup = cmd_pull()

    # 2) 比对差异
    print('\n[2/3] 比对差异…')
    f = connect()
    remote = {it['path'].strip('/'): it for it in walk(f, '/', maxdepth=4) if not it['dir']}
    remote_dirs = {it['path'].strip('/') for it in walk(f, '/', maxdepth=4) if it['dir']}

    local = collect_local(local_base)
    to_upload = []
    to_create_dir = set()
    for full, rel, size in local:
        if rel not in remote:
            to_upload.append((full, rel, size, 'NEW'))
        else:
            to_upload.append((full, rel, size, 'OVERWRITE'))

    # 线上有但本地没有的（只报告，不删）
    local_rels = {rel for _, rel, _ in local}
    remote_only = sorted(set(remote) - local_rels)

    print(f'  本地待上传: {len(to_upload)} 个')
    print(f'    新增: {len([x for x in to_upload if x[3] == "NEW"])}')
    print(f'    覆盖: {len([x for x in to_upload if x[3] == "OVERWRITE"])}')
    if remote_only:
        print(f'  线上独有（保留不动）: {len(remote_only)} 个')
        for r in remote_only[:15]:
            print(f'    · {r}')
        if len(remote_only) > 15:
            print(f'    ... 另有 {len(remote_only) - 15} 个')

    # 3) 上传
    print('\n[3/3] 上传…')
    ok = 0
    fail = 0
    for full, rel, size, action in to_upload:
        rdir = posixpath.dirname(rel)
        if rdir and rdir not in remote_dirs:
            if ensure_dir(f, '/' + rdir):
                remote_dirs.add(rdir)
                print(f'  + mkdir /{rdir}/')
        try:
            with open(full, 'rb') as fh:
                f.storbinary(f'STOR {rel}', fh)
            ok += 1
            mark = '+' if action == 'NEW' else '↻'
            print(f'  {mark} {rel}  ({size:,}B)')
        except Exception as e:
            fail += 1
            print(f'  ! {rel} 上传失败: {e}')

    f.quit()
    print(f'\n上传完成: 成功 {ok}, 失败 {fail}')
    print(f'线上原有文件已备份到: {backup}')
    print('（按项目规则，未删除任何线上文件）')
    return 0 if fail == 0 else 1


if __name__ == '__main__':
    cmd = sys.argv[1] if len(sys.argv) > 1 else 'list'
    if cmd == 'list':
        cmd_list()
    elif cmd == 'probe':
        cmd_probe()
    elif cmd == 'pull':
        cmd_pull()
    elif cmd == 'push':
        sys.exit(cmd_push())
    else:
        print(__doc__)
        sys.exit(1)
