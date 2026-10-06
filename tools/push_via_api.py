"""
git push 走不通时，用 GitHub Git Data API 直接提交。

什么时候需要：git push 依赖 github.com 的 git 协议（HTTPS smart transport），
而 GitHub REST API 走的是另一个域名（api.github.com），两者的网络可达性
**可能完全不同**。本项目 2026-10-06 遇到过：push 报
`CONNECT tunnel failed, 502`（代理拦 github.com 的 CONNECT），
而 curl --noproxy 到 api.github.com 是 200 —— API 明明通。

所以「push 连不上」不等于「GitHub 连不上」。这时换API 通道即可。

做法（Git Data API 三步）：
    1. GET  /repos/{o}/{r}/git/ref/heads/{branch}          取当前 commit sha
    2. POST /repos/{o}/{r}/git/trees                        用 base_tree 建新 tree
    3. POST /repos/{o}/{r}/git/commits                      以新 tree 建 commit
    4. PATCH /repos/{o}/{r}/git/refs/heads/{branch}         把分支指到新 commit

只传改动的文件（不是全量重推），所以很快，也不碰其他文件。

用法：
    export GH_TOKEN=xxx
    python tools/push_via_api.py            # 推送当前 HEAD 相对 origin/main 的改动
    python tools/push_via_api.py --dry-run  # 只看要传什么
"""
import base64
import io
import json
import os
import subprocess
import sys
import urllib.error
import urllib.request

API = 'https://api.github.com'


def sh(*args):
    return subprocess.run(args, capture_output=True, text=True,
                          encoding='utf-8', errors='replace').stdout.strip()


def api(method, path, token, payload=None, host='api'):
    url = '%s/%s' % (API, path)
    data = json.dumps(payload).encode('utf-8') if payload is not None else None
    req = urllib.request.Request(url, data=data, method=method)
    req.add_header('Authorization', 'token ' + token)
    req.add_header('Accept', 'application/vnd.github+json')
    req.add_header('User-Agent', 'push-via-api')
    if data:
        req.add_header('Content-Type', 'application/json')
    # 显式不走系统代理 —— 实测 api.github.com 直连是通的，
    # 而走代理反而可能 502（见文件头说明）。
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
    with opener.open(req, timeout=60) as r:
        return json.loads(r.read().decode('utf-8'))


def parse_repo(remote_url):
    """从 remote 地址取出 owner/repo。"""
    u = remote_url.rstrip('/')
    if u.endswith('.git'):
        u = u[:-4]
    parts = u.replace(':', '/').split('/')
    return parts[-2], parts[-1]


def main():
    token = os.environ.get('GH_TOKEN', '')
    if not token:
        print('缺少环境变量 GH_TOKEN')
        return 1
    dry = '--dry-run' in sys.argv

    remote = sh('git', 'remote', 'get-url', 'origin')
    owner, repo = parse_repo(remote)
    branch = sh('git', 'rev-parse', '--abbrev-ref', 'HEAD')
    base_sha = sh('git', 'rev-parse', 'HEAD')
    parent = sh('git', 'rev-parse', 'HEAD~1') if sh('git', 'rev-parse', '--verify', 'HEAD~1') else None

    print('仓库   %s/%s' % (owner, repo))
    print('分支   %s' % branch)
    print('本地   %s' % base_sha[:12])

    if not parent:
        print('\n只有一个 commit，无法用 API 增量推送（API 方式需要父提交作为 base）')
        print('这种情况请直接 git push。')
        return 1

    # 远端当前 commit —— base_tree 要用它
    ref = api('GET', 'repos/%s/%s/git/ref/heads/%s' % (owner, repo, branch), token)
    remote_sha = ref['object']['sha']
    print('远端   %s' % remote_sha[:12])

    if remote_sha == base_sha:
        print('\n✓ 远端已是最新，无需推送')
        return 0

    # 待传的改动文件
    changed = sh('git', 'diff', '--name-only', parent, base_sha).splitlines()
    changed = [c for c in changed if c.strip()]
    if not changed:
        print('\n没有文件改动')
        return 0

    print('\n待推送 %d 个文件：' % len(changed))
    for c in changed:
        print('   ', c)

    if dry:
        print('\n[dry-run] 未实际推送')
        return 0

    # 取远端 base commit 的 tree
    base_commit = api('GET', 'repos/%s/%s/git/commits/%s' % (owner, repo, remote_sha), token)
    base_tree = base_commit['tree']['sha']
    print('\nbase tree %s' % base_tree[:12])

    # 建新 tree：content 为 null 表示删除，这里只改不删
    entries = []
    for path in changed:
        full = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), path)
        with open(full, 'rb') as fh:
            raw = fh.read()
        entries.append({
            'path': path,
            'mode': '100644',
            'type': 'blob',
            'sha': api('POST', 'repos/%s/%s/git/blobs' % (owner, repo), token,
                       {'content': base64.b64encode(raw).decode('ascii'),
                        'encoding': 'base64'})['sha'],
        })
        print('   blob %s' % path)

    new_tree = api('POST', 'repos/%s/%s/git/trees' % (owner, repo), token,
                   {'base_tree': base_tree, 'tree': entries})['sha']
    print('\nnew tree %s' % new_tree[:12])

    msg = sh('git', 'log', '-1', '--pretty=%s', base_sha) or 'update'
    new_commit = api('POST', 'repos/%s/%s/git/commits' % (owner, repo), token,
                     {'message': msg, 'tree': new_tree, 'parents': [remote_sha]})['sha']
    print('new commit %s' % new_commit[:12])

    api('PATCH', 'repos/%s/%s/git/refs/heads/%s' % (owner, repo, branch), token,
        {'sha': new_commit, 'force': False})
    print('\n✓ 已推送到 %s/%s@%s' % (owner, repo, branch))
    print('  提示：本地 git 历史与远端已分叉，下次git push 需先 git pull --rebase')
    return 0


if __name__ == '__main__':
    try:
        sys.exit(main())
    except urllib.error.HTTPError as e:
        print('HTTP %s' % e.code)
        print(e.read().decode('utf-8', 'replace')[:400])
        sys.exit(1)
