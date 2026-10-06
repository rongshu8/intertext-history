"""
上传 GitHub 前的凭据自检。

为什么需要独立脚本而不是靠打包器：
打包器只扫「纯净包」这一个目录，而 git 提交的是**整个工程目录**。
两者范围不同 —— 打包器干净不代表 git 干净（本项目就真出现过：
根目录一个随手笔记里写着 FTP 密码，打包器查文件名没发现，
而 git 会把它整个提交上去）。

用法：
    python tools/check_repo_secrets.py
退出码 0 = 干净，1 = 有泄漏。
"""
import fnmatch
import io
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
BSL = chr(92)          # 反斜杠。用 chr 而不是字面量，避免 heredoc / 跨平台转义坑

SKIP_EXT = {'.png', '.jpg', '.jpeg', '.gif', '.ico', '.zip', '.gz',
            '.woff', '.woff2', '.pdf', '.mp4', '.webm'}
MAX_SIZE = 4 * 1024 * 1024

# 显式豁免：这些文件里出现「密码」是文档说明，不是凭据。
# 要豁免只能加到这里并写清理由 —— 不允许为了过检查去放宽上面的规则。
ALLOW = {
    'README.md': '安全章节在解释密码存储方式',
    'docs/开发指南.md': '开发文档在解释 CSRF / 密码策略',
    'data/seed/README.md': '数据格式说明',
    'config.local.example.php': '占位符模板',
    'config.php': '文件头注释里有 config.local.php 的格式示例（your_password）',
    # 这两个文件里出现的「ghp_」「密码」是**检测规则本身**，
    # 不是凭据。豁免它们，否则扫描器会把自己判成泄漏
    #（第 N次栽「验证工具自己没被验证」这一类）。
    'tools/check_repo_secrets.py': '本文件，含检测规则的正则字面量',
    'tools/build_clean_package.py': '打包器，含同样的检测规则',
}

PATTERNS = [
    (re.compile(r'(?i)\b(?:mysql|ftp|sftp|ssh|postgres(?:ql)?)://[^\s\'"]*:[^\s\'"@]+@'),
     '连接串里带密码'),
    # 私钥：匹配**完整的 PEM 头**（必须以五个连字符收尾）。
    # ★ 不能只写 `-----BEGIN [A-Z ]*PRIVATE KEY` ——
    #   检测脚本自己的源码里就含有这个模式（作为正则字面量），
    #   宽松的写法会把它判成私钥泄漏，于是**扫描器把自己判成泄漏**。
    #   本项目已栽 7 次「验证工具自己没被验证」，这是其中之一。
    #   要求尾部 `-----` 之后，规则字面量（结尾是 `\'`）就不再命中。
    (re.compile(r'-----BEGIN (?:RSA |EC |DSA |OPENSSH |PGP )?'
                r'PRIVATE KEY(?: BLOCK)?-----'),
     '私钥'),
    # API token：**要求 20 位以上的随机后缀**。
    # 只写前缀（`ghp_`）会让本文件与打包器的源码（它们含该前缀字面量）
    # 被判成泄漏 —— 扫描器把自己判成泄漏，本项目已栽 7 次这一类。
    # 真实的 GitHub token 是 ghp_ + 36 位随机字符，{20,} 足以区分。
    (re.compile(r'\b(?:ghp_|gho_|ghu_|ghs_)[A-Za-z0-9]{20,}'), 'GitHub token'),
    (re.compile(r'\bgithub_pat_[A-Za-z0-9_]{20,}'), 'GitHub token'),
    (re.compile(r'\bsk-[A-Za-z0-9]{20,}'), 'API key'),
    (re.compile(r'(?i)\baws_secret_access_key\s*=\s*\S+'), 'AWS密钥'),
    # 赋值 / 数组形式的硬编码密码。
    #   覆盖 `pass`、`password`、`db_pass`、`ftp_pass` 等，
    #   分隔符允许 `=>` `:` `=`。
    #
    #   ★ 这里【必须写成单行】，不能为了可读性换行 + 缩进：
    #     没开 (?x) 时，源码里的换行与空格是**字面量**，会要求文本里
    #     也出现同样多的空格 —— 结果什么都不匹配，而且不报错。
    #     （踩过：写成多行缩进版后4 个正例全 False，看起来"很合理"。）
    #     要可读性就开 (?x)，但开了 (?x) 就绝不能在字符类里写空格。
    #   ★ 关键：键名后面可能还跟着一个闭合引号（`'pass' => '...'`），
    #     所以分隔符前必须允许 `[\'"]?`。少了这一步，
    #     PHP 数组写法（最常见的一种）会全部漏掉 —— 而且不报错。
    (re.compile(
        r'(?i)\b(?:passwd|password|pass|db_pass|db_password|ftp_pass|smtp_pass|secret|token)'
        r'\b[\'"]?\s*(?:=>|:|=)\s*'
        r'(?:(?:\'\'|"")|null|none|0)?'
        r'[\'"][^\'"\r\n]{5,}[\'"]'
    ), '硬编码密码'),
]


def load_patterns(extra_literals):
    """生产凭据字面量：从本地配置文件反查，改了变量名也躲不掉。"""
    pats = list(PATTERNS)
    cfg = os.path.join(ROOT, 'config.local.php')
    vals = set()
    if os.path.isfile(cfg):
        txt = io.open(cfg, encoding='utf-8', errors='replace').read()
        for m in re.finditer(r"""['"]pass(?:word)?['"]\s*=>\s*['"]([^'"]+)['"]""", txt):
            if len(m.group(1)) >= 5:
                vals.add(m.group(1))
    for v in extra_literals:
        if v and len(str(v)) >= 5:
            vals.add(str(v))
    for v in vals:
        pats.append((re.compile(re.escape(v)), '含生产凭据字面量'))
    return pats


def gitignore_patterns():
    p = os.path.join(ROOT, '.gitignore')
    if not os.path.isfile(p):
        return []
    out = []
    for line in io.open(p, encoding='utf-8'):
        line = line.strip()
        if line and not line.startswith('#'):
            out.append(line)
    return out


def is_ignored(rel, pats):
    parts = rel.split('/')
    for p in pats:
        q = p.rstrip('/')
        if p.endswith('/'):
            # 目录规则：任一层目录名命中即忽略
            for i in range(len(parts)):
                if fnmatch.fnmatch(parts[i], q):
                    return True
            if fnmatch.fnmatch(rel, q) or fnmatch.fnmatch(rel, q + '/*'):
                return True
        else:
            if fnmatch.fnmatch(parts[-1], p) or fnmatch.fnmatch(rel, p):
                return True
    return False


def walk_repo(pats):
    """返回 (会提交的文件列表, 被忽略的条目数)"""
    keep, skipped = [], 0
    for dirpath, dirnames, filenames in os.walk(ROOT):
        rel_dp = os.path.relpath(dirpath, ROOT).replace(BSL, '/')
        if rel_dp != '.':
            sub = rel_dp + '/'
            dirnames[:] = [d for d in dirnames
                           if not is_ignored(rel_dp + '/' + d, pats)]
        for fn in filenames:
            rel = (rel_dp + '/' + fn) if rel_dp != '.' else fn
            if is_ignored(rel, pats):
                skipped += 1
                continue
            keep.append(rel)
    return keep, skipped


def main():
    extra = []
    if len(sys.argv) > 1:
        extra = sys.argv[1:]
    pats = gitignore_patterns()
    if not pats:
        print('！没有 .gitignore，无法判断哪些会提交')
        return 1
    files, skipped = walk_repo(pats)

    size = 0
    big = []
    for rel in files:
        full = os.path.join(ROOT, rel.replace('/', os.sep))
        try:
            sz = os.path.getsize(full)
        except OSError:
            continue
        size += sz
        if sz > 2 * 1024 * 1024:
            big.append((rel, sz))

    print('会提交 %d 个文件（%.1f MB），已忽略 %d 个条目'
          % (len(files), size / 1048576.0, skipped))

    leaks = []
    for rel in files:
        if rel in ALLOW:
            continue
        full = os.path.join(ROOT, rel.replace('/', os.sep))
        if os.path.splitext(rel)[1].lower() in SKIP_EXT:
            continue
        try:
            if os.path.getsize(full) > MAX_SIZE:
                continue
            txt = io.open(full, encoding='utf-8', errors='replace').read()
        except Exception:
            continue
        for pat, why in load_patterns(extra):
            m = pat.search(txt)
            if m:
                s = m.group(0)[:36].replace('\n', ' ')
                if len(s) > 8 and set(s) <= set('*') :
                    continue
                leaks.append((rel, why, s))
                break

    # 必须被忽略的关键文件（漏了就等于公开凭据）
    must_absent = ['config.local.php', 'data/install.lock']
    missing_guard = [p for p in must_absent if not is_ignored(p, pats)]

    if big:
        print('\n大文件（GitHub 单文件上限 100MB，注意仓库体积）：')
        for r, s in big:
            print('  %-44s %.1f MB' % (r, s / 1048576.0))

    if missing_guard:
        print('\n✗ 以下敏感文件没有被 .gitignore 排除：')
        for p in missing_guard:
            print('   ', p)

    if leaks:
        print('\n✗ 发现 %d 处疑似泄漏：' % len(leaks))
        for r, w, x in leaks:
            print('   %-38s %s  → %r' % (r, w, x))
        print('\n处理：改用环境变量 / 移到配置文件的 gitignore / 若是误报'
              '\n在 ALLOW 里显式豁免并写清理由。')
        return 1

    if missing_guard:
        return 1

    print('✓ 未发现凭据泄漏')
    return 0


if __name__ == '__main__':
    sys.exit(main())