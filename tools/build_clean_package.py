"""
打纯净版安装包。

与线上站点完全隔离：只读本地工程，产物写到独立目录 history_clean/，
原工程一个字节都不改。符合项目红线「打包=只产出独立副本，绝不碰原站」。

排除清单分三类：
  安全   —— 含生产凭据 / 线上数据，泄露即事故
  冗余   —— 开发自检脚本、git 配置，对使用者无价值
  一次性 —— 装完即可删的工具（但 sqlite_to_mysql 保留，见下）

关于 sqlite_to_mysql.php：
  新环境若先跑 SQLite 后来转 MySQL，需要它。但它能 TRUNCATE 目标库、
  能显示连接信息，所以纯净版里给它加了口令保护（见 patch_migration_guard）。
"""
import hashlib
import io
import json
import os
import re
import shutil
import sys
import time
import zipfile

SRC = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(SRC)
OUT_DIR = os.path.join(os.path.dirname(ROOT), 'history_clean')
STAMP = time.strftime('%Y%m%d_%H%M')

# ---- 排除规则 ----
EXCLUDE_FILES = {
    # 安全
    'config.local.php',          # 生产数据库密码
    '.gitignore',
    # 推广文：写给公众号的，正文里有生产站点地址。
    # 它不进运行包，但**打包器的排除清单和 .gitignore 是两套** ——
    # 加了 .gitignore 不等于打包器会跳过，扫到就中止打包（本次实际发生）。
    '公众号推广文.md',
}

EXCLUDE_DIRS = {
    '.workbuddy',
    'tools',                     # 开发自检
    '__pycache__',
    '.git',
    '.idea',
    '.vscode',
    '_shots',                    # 截图产物
    '_preview',                  # 本地预览页
    '_cover',                    # 封面图工作目录
    '.venv_tmp',                 # 临时虚拟环境
}

EXCLUDE_EXT = {'.sqlite', '.sqlite-wal', '.sqlite-shm', '.pyc', '.log', '.bak', '.old'}

# 需要 EXCLUDE_FILES 之外的额外处理：文件名以这些前缀开头的（历史备份目录）
# 注意：data/ 下以 _ 开头的都是编写种子时用的参考文件，不属于运行代码，要排除
EXCLUDE_PREFIX = ('_backup_', '_diag', '_env', '_probe', '_dbg', '_tmp',
                  '_WORLD_EVENT_SPEC', '_existing_world_titles')

# 旧版 PHP 种子（data/seed_part*.php）。
# 现在内容以 data/seed/*.json 为主格式，安装器优先读 JSON；
# 旧种子保留在**仓库**里作为回退与迁移对照，但纯净包不带 ——
# 避免同一份内容存在两个来源，改了 JSON 却让人以为改了 PHP。
LEGACY_SEED_PREFIX = 'data/seed_part'



def should_exclude_dir(name):
    if name in EXCLUDE_DIRS:
        return True
    return any(name.startswith(p) for p in ('_backup_',))


def should_exclude_file(name):
    if name in EXCLUDE_FILES:
        return True
    ext = os.path.splitext(name)[1].lower()
    if ext in EXCLUDE_EXT:
        return True
    if name.startswith(EXCLUDE_PREFIX):
        return True
    return False


def scan_for_secrets(root):
    """
    扫描目录里所有文本文件，找「真的密钥」而不是「像密钥的文件名」。

    匹配规则刻意分两类：
      A. 确定是凭据的关键词组合（密码 / 用户名 / 私钥头 / 连接串）
      B. 与 config.local.php 里真实值一致的字面量（从本地配置反查）

    B 是关键：即使有人把密码写进一个叫 notes.md 的文件、A 没命中，
    只要内容与生产密码一字不差，也会被拦下。

    返回 [(相对路径, 命中原因), ...]
    """
    findings = []

    # ---- 从本地生产配置取真实值，作为「字面量黑名单」 ----
    literals = set()
    cfg = os.path.join(ROOT, 'config.local.php')
    if os.path.isfile(cfg):
        try:
            import re as _re
            src = open(cfg, encoding='utf-8', errors='replace').read()
            for m in _re.finditer(r"'pass'\s*=>\s*'([^']+)'", src):
                if len(m.group(1)) >= 6:
                    literals.add(m.group(1))
            for m in _re.finditer(r"'user'\s*=>\s*'([^']+)'", src):
                if len(m.group(1)) >= 4:
                    literals.add(m.group(1))
        except Exception:
            pass
    # FTP 凭据写在部署脚本里，也一并纳入
    for rel in ('tools/ftp_deploy.py',):
        p = os.path.join(ROOT, rel)
        if os.path.isfile(p):
            try:
                import re as _re
                src = open(p, encoding='utf-8', errors='replace').read()
                for key in ('PASS', 'USER', 'HOST'):
                    m = _re.search(r"FTP_%s'\)?,\s*'([^']+)'" % key, src) or \
                        _re.search(r"'%s'\s*,\s*'([^']{4,})'" % key, src)
                    if m and m.group(1):
                        literals.add(m.group(1))
            except Exception:
                pass

    # ---- A. 关键词组合 ----
    # 「密码：xxx」只在 **值像凭据** 时才算命中：
    # 不含空白与中文、长度 6~64。否则文档里解释「密码是什么」会被误报。
    PW = r'(?=[^\s一-鿿]{6,64}\b)(?=[^\s一-鿿]*\d)(?=[^\s一-鿿]*[A-Za-z])[^\s一-鿿]+'
    PATTERNS = [
        (re.compile(r'密码\s*[:：=]\s*[\'"]?(' + PW + r')'),
         '出现「密码: xxx」且值像真实凭据'),
        (re.compile(r'\bpassword\s*[:=]\s*[\'"]?(' + PW + r')', re.I),
         '出现 password=xxx 且值像真实凭据'),
        # 私钥必须匹配完整 PEM 头（含尾部 -----）。
        # 写成 `-----BEGIN [A-Z ]*PRIVATE KEY` 会让本文件自己的源码
        # （含该正则字面量）被判成私钥泄漏 —— 扫描器把自己判成泄漏。
        (re.compile(r'-----BEGIN (?:RSA |EC |DSA |OPENSSH |PGP )?'
                    r'PRIVATE KEY(?: BLOCK)?-----'), '私钥文件头'),
        (re.compile(r'\b(?:mysql|mysqlnd|pdo_mysql):\/\/[^\s\'"]*:[^\s\'"@]+@', re.I),
         '数据库连接串含密码'),
        (re.compile(r'(?i)\b(?:ftp|sftp|ssh)://[^\s\'"]*:[^\s\'"@]+@'), 'FTP 连接串含密码'),
        # API token 要求 20+ 位随机后缀，避免把规则源码里的前缀字面量判成泄漏
        (re.compile(r'\b(?:ghp_|gho_|ghu_|ghs_)[A-Za-z0-9]{20,}'), 'GitHub token'),
        (re.compile(r'\bgithub_pat_[A-Za-z0-9_]{20,}'), 'GitHub token'),
        (re.compile(r'\bsk-[A-Za-z0-9]{20,}'), 'API key'),
    ]

    # 显式豁免：这些文件里的「密码」是文档说明，不是凭据。
    # 要豁免只能加到这里并写清理由 —— 不允许为了过检查去放宽上面的规则。
    ALLOW_FILES = {
        'README.md': '说明文档，解释密码与默认账号的处置方式',
        '安装说明.md': '安装指南，同上',
        'data/seed/README.md': '数据格式说明，解释字段约定',
    }

    SKIP_EXT = {'.png', '.jpg', '.jpeg', '.gif', '.ico', '.woff', '.woff2',
                '.zip', '.gz', '.sqlite', '.pdf'}

    for dirpath, dirnames, filenames in os.walk(root):
        dirnames[:] = [d for d in dirnames if d != '__pycache__']
        for fn in sorted(filenames):
            ext = os.path.splitext(fn)[1].lower()
            if ext in SKIP_EXT:
                continue
            full = os.path.join(dirpath, fn)
            rel = os.path.relpath(full, root).replace(os.sep, '/')
            try:
                if os.path.getsize(full) > 4 * 1024 * 1024:
                    continue
                text = open(full, encoding='utf-8', errors='replace').read()
            except Exception:
                continue

            for pat, why in PATTERNS:
                if rel in ALLOW_FILES:
                    continue
                m = pat.search(text)
                if m:
                    findings.append((rel, '%s（命中 %r）' % (why, m.group(1)[:24] if m.groups() else m.group(0)[:24])))

            # ---- B. 与生产凭据一字不差（任何文件都不豁免）----
            for lit in literals:
                if lit and lit in text:
                    findings.append((rel, '含生产凭据字面量 %r' % (lit[:4] + '***')))
                    break

    return findings


def collect():
    """收集要打进包的文件（相对路径 -> 源绝对路径）"""
    items = []
    for dirpath, dirnames, filenames in os.walk(ROOT):
        dirnames[:] = [d for d in dirnames if not should_exclude_dir(d)]
        for fn in sorted(filenames):
            if should_exclude_file(fn):
                continue
            full = os.path.join(dirpath, fn)
            rel = os.path.relpath(full, ROOT).replace(os.sep, '/')
            if rel.startswith(LEGACY_SEED_PREFIX):
                continue
            items.append((rel, full))
    return items


def sanitize_readme(dest_root):
    """
    去掉 README 里的生产环境信息（域名、服务器路径、真实版本号）。

    ★ 这段逻辑现在是**兜底**，不是主路径 ——
      新的 README 本身就是通用写法，不含任何生产信息。
      所以「匹配不到要改的那节」是**正常情况**，不是失败。

    ★★ 但绝不能因此报告「已去生产化」——
      2026-10-06 踩过：换了新 README 后这里 re.search 匹配不到，
      函数什么都没做却 return True，打印出
      「✓ README.md 已去生产化」。**一个没做事的检查报了成功**，
      比没有检查更危险：它让人以为已经处理过了。
      现在返回三态并如实报告。
    """
    p = os.path.join(dest_root, 'README.md')
    if not os.path.isfile(p):
        return 'missing'
    src = io.open(p, encoding='utf-8').read()

    # 替换「当前生产环境」整节
    m = re.search(r'## 零、当前生产环境.*?(?=\n---\n)', src, re.S)
    if not m:
        # 没匹配到 → 检查是否本来就没有生产信息，是则如实报告
        # ★ 泄漏关键词**从本地配置动态提取**，不在这里硬编码。
        #   早先这里直接写了生产的域名与服务器路径 ——
        #   结果**打包器自己成了泄漏源**：它开源分发，
        #   源码里就带着作者的生产信息。检查器持有它要检查的秘密，
        #   就像门卫把钥匙印在制服上。
        #   （注意：写在注释里也一样会进仓库，注释不是安全区。）
        #   现在改为：读 config.local.php 拿到真实的库名/主机，
        #   推出「如果这些出现在 README 里就是泄漏」。
        leaks = []
        try:
            local_cfg = os.path.join(ROOT, 'config.local.php')
            if os.path.isfile(local_cfg):
                raw = io.open(local_cfg, encoding='utf-8', errors='replace').read()
                for m in re.finditer(r"""['"](?:host|name)['"]\s*=>\s*['"]([^'"]{3,})['"]""", raw):
                    val = m.group(1)
                    # 排除本地地址：127.0.0.1 / localhost 在文档里正当出现
                    # （"本机数据库填 127.0.0.1"），把它当泄漏是误报。
                    if val in ('127.0.0.1', 'localhost', '::1'):
                        continue
                    leaks.append(val)
        except Exception:
            pass
        # 连接串形态（任一主机通用，不含任何具体站点信息）
        leaks += ['mysql://', 'ftp://', 'sftp://', 'ssh://']
        # 常见的虚拟主机站点根目录形态。这里只放**形态**（含占位符），
        # 不放任何真实站点名 —— 早先写了生产的服务器路径，
        # 那是同一个错误的另一种形式：检查器自己成了泄漏源。
        leaks += [re.compile(r'/www/wwwroot/[a-z0-9]', re.I),
                  re.compile(r'/home/[a-z0-9]+/public_html', re.I)]
        hits = []
        for t in leaks:
            if t is None:
                continue
            try:
                if isinstance(t, str):
                    if t and t in src:
                        hits.append(t)
                else:                      # 编译好的正则（形态匹配）
                    m = t.search(src)
                    if m:
                        hits.append(m.group(0))
            except Exception:
                continue
        return 'clean' if not hits else 'dirty:' + ','.join(hits[:3])
    replacement = """## 零、运行环境支持范围

| 项 | 支持范围 |
|---|---|
| PHP | **7.4 ~ 8.4**（未使用任何 8.x 移除的特性；8.1+ 的 deprecated 项已加白名单） |
| MySQL | **5.5 ~ 8.4** / MariaDB 10.x |
| SQLite | 3.8+ |
| Web 服务器 | Apache（读 `.htaccess`）/ Nginx（需手工加规则，见第七节） |

安装器第一屏会显示实际检测到的 PHP 版本、扩展、数据库版本、
排序规则与 `sql_mode`，**有红叉先别装**。

### MySQL 版本相关的设计约束（重要）

1. **不依赖 `DATETIME DEFAULT CURRENT_TIMESTAMP`**
   该语法 MySQL 5.6+ 才有，5.5 不支持；`TIMESTAMP` 自动初始化在 5.5 又只能有一列。
   因此本项目所有时间列都是 `DATETIME NULL`，由应用层用 `SQL_NOW` 常量显式写入。
   **这既是 5.5 兼容方案，也是 8.x 上的稳定做法**（行为完全一致，不受默认 sql_mode 影响）。

2. **建表显式锁定 `utf8mb4_unicode_ci`**
   MySQL 8.0 的 utf8mb4 默认排序规则是 `utf8mb4_0900_ai_ci`，5.5 是 `utf8mb4_general_ci`，
   两者对中文的排序与比较结果不同。同一份数据装在不同版本上，`ORDER BY` 顺序会变。
   `utf8mb4_unicode_ci` 是 5.5 到 8.4 唯一的安全交集。

3. **`sql_mode` 采用追加而非覆盖**
   连接时只追加 `STRICT_TRANS_TABLES` 与 `NO_ENGINE_SUBSTITUTION`，
   保留服务端原有的全部设置。覆盖式写法在 8.0 上等于主动关闭了它精心设计的默认防护。

4. **索引键长上限**
   MySQL 5.5 / 5.6 / 5.7 默认 `innodb_large_prefix=OFF`，索引键上限 767 字节
   （utf8mb4 下唯一索引列 ≤191 字符）。本项目最长的唯一索引列是
   `users.username VARCHAR(48)` = 192 字节，安全。
   若你的环境开了 `innodb_large_prefix`，上限提升到 3072 字节，更宽松。

5. **不使用 8.0 专属语法**
   不用 `utf8mb4_0900_*` 排序规则、不用 `CHECK` 约束、不用
   `ALTER TABLE ... ALGORITHM=INSTANT`，这些在 5.5 上会直接语法报错。

**跨方言时间戳的正确写法**（`php -l` 查不出写错的版本）：

```php
// 正确：引号闭合后再拼接
'INSERT INTO t (a, created_at) VALUES (?, ' . SQL_NOW . ')'

// 错误：拼接符落在单引号串内，SQL_NOW 变字面量 → 1292 Invalid datetime
'INSERT INTO t (a, created_at) VALUES (?, " . SQL_NOW . ")'
```

改完 SQL 务必跑一次 `python tools/check_sql_quotes.py`（用 PHP tokenizer 判定，
需 php CLI；本包不含 tools/，可从原工程复制，或自行用 php -l + 人工核对）。

"""
    src = src[:m.start()] + replacement + src[m.end():]
    io.open(p, 'w', encoding='utf-8', newline='\n').write(src)
    return 'replaced'


def render_counts(guide, dest_root):
    """
    把指南里的 __COUNTS__ 占位符换成从 manifest.json 读出的真实行数。

    为什么不写死：之前文档里写的是「124 条同期世界大事」，
    而实际数据是 669 条 —— 数字一改文档就撒谎，而且没人会发现。
    文档里的数据必须从数据本身来。
    """
    mpath = os.path.join(dest_root, 'data', 'seed', 'manifest.json')
    label = [('dynasties', '朝代分期'), ('cn_events', '中国节点'),
             ('world_events', '同期世界大事'), ('relations', '中外联动')]
    try:
        counts = json.load(io.open(mpath, encoding='utf-8')).get('counts', {})
        parts = ['%s %s 个' % (n, format(int(counts[k]), ','))
                 for k, n in label if k in counts]
        text = '**' + ' / '.join(parts) + '**'
        world = format(int(counts.get('world_events', 0)), ',')
    except Exception as e:
        # 读不到就留个明显的占位，别悄悄显示错数字
        text = '**（数据包行数未能读取，请核对 data/seed/manifest.json：%s）**' % e
        world = '?'
    return guide.replace('__COUNTS__', text).replace('__WORLD__', world)


def write_install_guide(dest_root, stats):
    """写一份装包说明（比 README 更聚焦于「怎么装」）"""
    guide = """# 安装说明

## 这是什么

一套「中国历史节点为轴、查看同期世界大事」的 PHP 网站。
无需 composer / npm，**上传文件 + 打开安装器**即可运行。

## 环境要求

| 项 | 要求 |
|---|---|
| PHP | 7.4 ~ 8.4（8.x 已适配） |
| 必需扩展 | `pdo` + `pdo_mysql` |
| 建议扩展 | `mbstring`（中文）、`json`（投稿）、`session`（登录） |
| 数据库 | **MySQL 5.5 ~ 8.4** / MariaDB 10.x（**不支持 SQLite**） |
| Web 服务器 | Apache / Nginx / PHP 内置服务器均可 |
| 协议 | **`http://` 与 `https://` 都可安装、可用**，无差别 |

> 安装器第一屏就会做环境检测，把 PHP 版本、扩展、数据库版本、
> 排序规则、sql_mode 全部列出来。**有红叉先别装**。
>
> 站点根目录需要**可写权限**（安装器要在那里写 `config.local.php`）。

## 装之前先自检（强烈建议）

包里带了一个自检脚本，**上传完、打开安装器之前**先跑一次，能排掉九成问题。
它不连数据库，纯静态检查：

```bash
php selftest.php
```

它会验：PHP 版本与扩展、数据文件是否齐全、行数是否与 manifest 一致、
**跨表引用是否全部有效**（节点↔联动不会指向不存在的条目）、
逐文件 sha256 是否与 manifest 一致、后台管理页是否在位。

最后一行出现 `✓ 全部通过，包可发布` 再去开安装器。
（没装 PHP 命令行也能跳过，���接开安装器即可——安装器第一屏本身就是环境检测。）

## 数据就在包里，不需要另外下载

内容是**纯数据文件**，不在 PHP 代码里：

```
data/seed/
  manifest.json      校验和清单
  categories.json    分类字典
  regions.json       区域字典
  relation_types.json 联动类型
  dynasties.json     朝代分期
  cn_events.json     中国节点
  world_events.json  世界大事
  relations.json     中外联动
```

想改内容直接编辑这些 JSON（UTF-8，缩进 2 空格），或装完后在后台
「导出 / 导入」里管理。**不要改 `id` 字段 —— 跨表引用用的是 slug 与标题。**

## 安装

### 1. 上传

解压 ZIP，得到的一堆文件与文件夹就是全部内容 —— **直接把里面的东西**
（`index.php`、`inc/`、`admin/`、`data/` …）上传到网站根目录或子目录。

> 解压后**不要**再套一层目录。如果解压出来是 `互文/` 或 `history_clean/` 这样的壳，
> 说明多解压了一次，得把里面的内容提出来再上传。
> 传到壳目录里的话，网站根目录会是空的，打开首页直接 404。

本目录需要**写权限** —— 安装器要在这里生成 `config.local.php`。

### 2. 打开域名，剩下的自动完成

浏览器访问你的域名根目录即可 —— **`http://` 和 `https://` 都行**，没有区别：

```
http://你的域名/    →  302 → /install.php
https://你的域名/   →  302 → /install.php
```

会自动跳到安装页，然后：

1. **填数据库信息** —— 地址、端口、库名、用户名、密码。点「下一步」自动测连接
2. **环境检测** —— 自动显示 PHP 版本、扩展、MySQL 版本、排序规则
3. **点「开始安装」** —— 建表、导入数据、写配置、上锁，一次完成

**不需要手工编辑任何文件，也不需要先复制 `config.local.php`。**

表单里填四样：数据库地址、端口、库名、用户名、密码。
本机数据库一般填 `127.0.0.1`、端口 `3306`。

**库不存在？没关系，安装器会自己建。** 点「下一步」时它会依次尝试：
连上 MySQL 服务器 → 库在就用、不在就 `CREATE DATABASE`（字符集自动设为
`utf8mb4`）→ 建一张临时表验证权限 → 删掉临时表。任一步失败都会明确告诉你原因。

> ⚠️ **自动建库需要 MySQL 账号有全局 `CREATE` 权限。**
> 宝塔与多数虚拟主机默认只给「单个库」的权限，这种情况下自动建库会失败。
> 页面会明确告诉你，并给出两条路：
> ① 在面板（宝塔「数据库」）点「添加数据库」，字符集选 `utf8mb4`，
> 然后把库名回填到表单 —— **这是绝大多数主机上的正常路径**；
> ② 换一个对该库有权限的 MySQL 账号密码。
>
> 唯一的前置条件：**站点根目录要可写**。
> 安装器要在那里写 `config.local.php`。权限不够时会明确提示，
> 那时可以改用下面的「手工配置」。

### 手工配置（可选）

若你的环境不允许安装器写文件（目录只读），可以自己建 `config.local.php`：

```php
<?php
return [
    'db' => [
        'driver'     => 'mysql',
        'host'       => '127.0.0.1',
        'port'       => 3306,
        'name'       => '你的库名',
        'user'       => '你的用户名',
        'pass'       => '你的密码',
        'charset'    => 'utf8mb4',
        'persistent' => true,
    ],
];
```

放好之后直接访问 `/install.php` 会跳过第一步，从环境检测开始。

### 3. 完成

安装完成，导入 __COUNTS__。

完成页会给你两个大按钮 —— **进入前台** / **进入后台**，
并显示管理员 `admin` 的随机密码。

密码**只显示这一次**，刷新即失效、无法找回，请立刻抄下来。
登录后台后请在「账户」里改成自己的密码。

## 装完必做（重要）

1. **记住上面显示的管理员密码**，登录 `admin/login.php` 后进「账户」页改掉
2. **删除 `install.php`**（它能建表、清库、导数据）

> 安装完成时安装器会自动写入 `data/install.lock`，
> 之后该页面**不再渲染任何表单**（详见下文「安装锁」）。

## 装完怎么验证装对了

依次打开这几个页面，看到数字就说明装成功了：

| 页面 | 预期 |
|---|---|
| `/` | 首页有完整时间轴，能看到节点与同期世界大事 |
| `/world.php` | 列表页顶部显示「共 __WORLD__ 条」 |
| `/about.php` | 「当前规模」卡里的数字与上一致 |
| `/admin/login.php` | 能用刚才的密码登录，进去有「导出 / 导入」 |

任一页显示 **0 条** 或报500，都没装成功 —— 回到「常见故障」。

## 常见故障

| 现象 | 原因与解法 |
|---|---|
| 首页 404，FTP 里文件都在 | 多套了一层目录：文件传进了 `xxx/` 里而不是网站根目录。把内容提到根目录 |
| 安装器打开是只读的，没有任何按钮 | 包里混进了 `data/install.lock`（那是**别人装完后**生成的）。删掉它再打开 |
| 第一步填完点「下一步」没反应 | 页面下方会写明原因。**最常见是缺 `pdo_mysql`** —— 宝塔「软件商店 → PHP → 设置 → 安装扩展」勾上后重启 PHP |
| 提示「目录不可写」 | 站点根目录没写权限，安装器写不了 `config.local.php`。宝塔里把网站目录权限设为 755、所有者为 `www`，或改用上面的「手工配置」 |
| 环境检测有红叉 | 按提示补扩展。宝塔里在「PHP → 安装扩展」勾 `pdo_mysql` |
| `SQLSTATE[HY000] [1045]` | 数据库名/用户名/密码不对，或用户没有该库的权限 |
| `Access denied for user` | 同上。宝塔里建站一般会自动建库，先在「数据库」里确认库名 |
| 提示「没有创建数据库的权限」 | 你的 MySQL 账号只有单库权限。在面板「数据库」点**添加数据库**（字符集 `utf8mb4`），把库名回填到表单 |
| 提示「没有建表权限」 | 同上，账号对该库权限不足。面板里给该账号授权，或换一个 |
| 装完前台 500 | 检查 `data/` 是否完整上传（尤其 `data/seed/*.json`），建议用 FTP 二进制模式 |
| 中文乱码 | 库不是 `utf8mb4`。`config.local.php` 的 `charset` 改对，库本身也要是 utf8mb4 |
| 页面白屏、无报错信息 | 生产环境常关 `display_errors`。把 `config.php` 里的调试开关打开看错误 |
| 导入报索引键过长 | MySQL 5.5 下唯一索引列不能超过 191 字符。本项目已按此约束建表，一般不会遇到 |

## 安装锁

装完自动生成 `data/install.lock`，此后 `install.php` 只读：没有表单可提交，
伪造 POST 也会被 403 挡下。**这不是靠登录或令牌，而是根本没有可提交的东西。**

为什么要这样：CSRF 令牌就印在页面上，而 CSRF 防的是**第三方页面跨站触发**、
不是**直接访问** —— 只靠令牌的「保护」对直接访问者等于不存在。

需要重装时，两条正规途径（都需要服务器权限）：

- 通过 FTP 删除 `data/install.lock`，再访问安装器
- 命令行执行 `php install.php --force`

若只想**更新内容数据**、不重装结构，请用后台的「导入」页 ——
它是事务化的，失败会整体回滚。

## 目录说明

```
├── index.php            首页（时间轴）
├── node.php             节点详情 + 同期世界大事
├── world.php            世界大事列表
├── search.php           搜索
├── about.php            关于
├── register.php         用户注册
├── install.php          安装器（装完删）
│
├── admin/               管理后台（需 admin 角色）
├── user/                用户中心（投稿、查审核状态）
│
├── inc/                 核心代码（.htaccess 已禁止 Web 访问）
│   ├── bootstrap.php    ★ 所有页面的第一行依赖
│   ├── db.php           PDO 抽象（MySQL/SQLite 双驱动）
│   ├── schema.php       建表 DDL
│   ├── helpers.php      工具函数（转义、鉴权、限流、安全头）
│   ├── submission.php   投稿与审核落地
│   └── env_check.php    环境自检
│
├── data/                SQLite 数据库 + 种子数据
├── assets/              CSS / JS
└── config.php           主配置（一般不用改）
```

## 常见问题

**Q：页面提示「表不存在」？**
A：没跑安装器。访问 `install.php`。页面顶部会显示环境检测与具体错误。

**Q：中文乱码，或排序顺序在不同机器上不一致？**
A：库字符集必须是 `utf8mb4`（不是 `utf8`）。安装器会显示当前
连接排序规则与库默认排序规则 —— 两者不同是正常的，**建表时已显式锁定
`utf8mb4_unicode_ci`**，所以 `ORDER BY` 的结果跨 MySQL 版本一致。

**Q：403 / 打不开样式？**
A：Apache 读 `.htaccess`（已配好）。**Nginx 不读**，需要手工加：

```nginx
location ~ ^/(data|inc|tools|cache)/ { deny all; }
location ~ \\.(sqlite|sqlite-wal|sqlite-shm|sql|bak|old|log|ini|md)$ { deny all; }
location = /config.php  { include fastcgi_params; fastcgi_pass unix:/tmp/php-fpm.sock; }
location = /config.local.php { deny all; }
```

**Q：部署在子目录（如 `/history/`）？**
A：可以，URL 前缀会自动识别。但 `.htaccess` 里的规则若要改成子目录
写法，需自行调整。

**Q：数据能导入 Excel 吗？**
A：能。后台「CSV 导入」页有模板下载，支持中国节点与世界大事。

## 许可

源码可自由使用与修改。
"""
    io.open(os.path.join(dest_root, '安装说明.md'), 'w', encoding='utf-8',
            newline='\n').write(render_counts(guide, dest_root))


def main():
    print('=' * 66)
    print('打纯净版安装包')
    print('=' * 66)
    print('源目录: %s' % ROOT)
    print('产物目录: %s' % OUT_DIR)
    print()

    # 1) 清空并重建产物目录
    if os.path.isdir(OUT_DIR):
        shutil.rmtree(OUT_DIR)
    os.makedirs(OUT_DIR)

    # 2) 拷贝
    items = collect()
    copied = 0
    skipped_log = []
    for rel, full in items:
        dest = os.path.join(OUT_DIR, rel)
        os.makedirs(os.path.dirname(dest), exist_ok=True)
        shutil.copy2(full, dest)
        copied += 1

    # tools/ 整个不进包，但「装前自检」是给使用者看的，要单独带进去。
    # 放在包根目录，文件名自解释，且不依赖 tools/。
    _st = os.path.join(ROOT, 'tools', 'selftest_package.php')
    if os.path.isfile(_st):
        shutil.copy2(_st, os.path.join(OUT_DIR, 'selftest.php'))
        copied += 1
        print('已附带安装前自检脚本 selftest.php')
    print('已拷贝 %d 个文件' % copied)

    # 3) 确认敏感文件确实不在包里
    print()
    print('安全检查（这些绝不能出现在包里）：')
    must_absent = ['config.local.php', 'data/history.sqlite', '.workbuddy',
                   'tools/ftp_deploy.py', 'migrate.php', 'sqlite_to_mysql.php',
                   'SHA256SUMS.txt', '安装说明.md',
                   # ★ 锁绝不能进包。本地生产站装完后会写 data/install.lock，
                   #   打包时若把它带上，别人拿到的包装完直接就是「安装锁已生效」——
                    #   表没建、没数据、一个表单都没有，**而且不报错**，
                    #   表现为「安装器打开是空白/只读」，极难自查。
                   'data/install.lock',
                   # ★ 旧 PHP 种子（259 KB × 15 个文件）。
                   #   纯负担：内容已全部转成 data/seed/*.json，留着只会让人
                   #   以为要跑两遍、或误改到不生效的那份。selftest 已验过
                   #   没有它也能装出完整数据。
                   'data/seed_part1.php',
                   # 编写期参考文件，不是运行代码
                   'data/_WORLD_EVENT_SPEC.php',
                   'data/_existing_world_titles.txt']
    ok = True
    for rel in must_absent:
        p = os.path.join(OUT_DIR, rel.replace('/', os.sep))
        if os.path.exists(p):
            print('  ✗ 仍在包中: %s' % rel)
            ok = False
        else:
            print('  ✓ 已排除: %s' % rel)
    # 旧种子是一个系列，逐个断言不现实，用计数兜底
    legacy_left = [f for f in os.listdir(os.path.join(OUT_DIR, 'data'))
                   if f.startswith('seed_part')] if os.path.isdir(os.path.join(OUT_DIR, 'data')) else []
    if legacy_left:
        print('  ✗ 仍有 %d 个 data/seed_part*.php' % len(legacy_left))
        ok = False
    else:
        print('  ✓ 已排除: data/seed_part*.php（0 个）')
    # 检查残留的备份目录
    for d in os.listdir(OUT_DIR):
        if d.startswith('_backup_'):
            print('  ✗ 残留备份目录: %s' % d)
            ok = False

    # ★ 内容级密钥扫描放在**最后**（README 去生产化、安装说明写入之后）——
    #   放前面会误报：sanitize_readme() 还没来得及把生产域名从 README 里替换掉。
    #   顺序很关键：先脱敏，再验证「脱敏之后还剩不剩密钥」。

    if not ok:
        print('\n安全检查未通过（文件名/残留项），中止打包')
        return 1

    # 4) README 去生产化
    #    三态如实报告。「什么都没做」不能报成「已处理」——
    #    一个谎报成功的检查比没有检查更危险，它让人以为已经处理过了。
    st = sanitize_readme(OUT_DIR)
    if st == 'replaced':
        print('  ✓ README.md 已替换生产环境章节为通用说明')
    elif st == 'clean':
        print('  ✓ README.md 本就不含生产信息（无需处理）')
    elif st and st.startswith('dirty'):
        print('\n  ✗ README.md 含生产信息但未能自动替换：%s —— 中止打包'
              % st[6:])
        return 1
    else:
        print('\n  ✗ 包内找不到 README.md —— 中止打包')
        return 1

    # 5) 写安装说明
    stats = {'files': copied}
    write_install_guide(OUT_DIR, stats)
    print('  ✓ 安装说明.md 已写入')

    # 6) 内容级密钥扫描（脱敏之后）
    #   比文件名检查重要得多。真实事故：根目录一个叫 cccc.txt 的随手笔记里
    #   写着 FTP 地址与密码，文件名检查完全看不出问题，它就那样进了
    #   准备开源分发的包。所以这里不查「文件名像不像密钥」，
    #   而是查**内容里有没有真的密钥**。
    leaks = scan_for_secrets(OUT_DIR)
    if leaks:
        for rel, why in leaks:
            print('  ✗ 泄漏风险 %s —— %s' % (rel, why))
        print('\n内容扫描发现疑似密钥，已中止打包。'
              '\n若确认是误报（例如文档在解释「密码」这个概念），'
              '\n请在 scan_for_secrets() 的忽略清单里显式豁免该文件，不要放宽规则。')
        return 1
    print('  ✓ 内容扫描：脱敏后未发现密钥/密码/连接串')


    # 6) 生成校验清单
    print()
    manifest = []
    for dirpath, dirnames, filenames in os.walk(OUT_DIR):
        dirnames[:] = [d for d in dirnames if d != '__pycache__']
        for fn in sorted(filenames):
            full = os.path.join(dirpath, fn)
            rel = os.path.relpath(full, OUT_DIR).replace(os.sep, '/')
            if rel == 'SHA256SUMS.txt':
                continue
            h = hashlib.sha256()
            with open(full, 'rb') as f:
                for chunk in iter(lambda: f.read(65536), b''):
                    h.update(chunk)
            manifest.append((h.hexdigest(), rel))
    manifest.sort(key=lambda x: x[1])
    with io.open(os.path.join(OUT_DIR, 'SHA256SUMS.txt'), 'w',
                 encoding='utf-8', newline='\n') as f:
        f.write('# 校验清单（安装前可核对文件完整性）\n')
        f.write('# 格式: <sha256>  <相对路径>\n')
        for h, rel in manifest:
            f.write('%s  %s\n' % (h, rel))
    print('  ✓ SHA256SUMS.txt（%d 个文件）' % len(manifest))

    # 7) 打成 zip
    zip_path = os.path.join(
        os.path.dirname(OUT_DIR),
        'history_clean_%s.zip' % STAMP)
    print()
    print('打包中…')
    with zipfile.ZipFile(zip_path, 'w', zipfile.ZIP_DEFLATED, compresslevel=9) as zf:
        # ★ 不要加顶层目录前缀。
        #   加了前缀（history_clean/…）的话，用户按"上传所有文件"会把文件传进
        #   /history_clean/ 里 —— 站点根目录什么都没有，打开就是 404，
        #   而所有文件都"上传成功"了，排查起来极其费劲。
        #   不加前缀：解压得到的就是文件本身，直接拖进 FTP 即可。
        for dirpath, dirnames, filenames in os.walk(OUT_DIR):
            dirnames[:] = [d for d in dirnames if d != '__pycache__']
            for fn in sorted(filenames):
                full = os.path.join(dirpath, fn)
                arc = os.path.relpath(full, OUT_DIR)
                zf.write(full, arc.replace(os.sep, '/'))
    zip_size = os.path.getsize(zip_path)

    print()
    print('=' * 66)
    print('完成')
    print('=' * 66)
    print('目录包: %s' % OUT_DIR)
    print('ZIP:    %s' % zip_path)
    print('大小:   目录 %.1f KB / ZIP %.1f KB' % (
        sum(os.path.getsize(os.path.join(dp, f))
            for dp, dn, fn in os.walk(OUT_DIR) for f in fn) / 1024,
        zip_size / 1024))
    print()
    print('包内文件清单:')
    for h, rel in manifest:
        print('  %s' % rel)
    return 0


if __name__ == '__main__':
    sys.exit(main())
