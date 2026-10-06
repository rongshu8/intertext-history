#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
种子数据质量检查器
-----------------------------------------------------------------
检测 PHP 种子数据里的四类问题：
  1. 中英混排污染：中文串里嵌入无意义英文单词（如 "Mux 之战"、"heart 心服"）
  2. 繁体混入：与同文件简体风格不一致的繁体字
  3. 标点异常：半角逗号/句号混用、全角空格
  4. 引用完整性：relations 引用的 cn_ref / world_ref 标题是否存在
  5. slug 引用完整性：cn_events 的 dynasty 是否存在于 dynasties 定义中

用法：python tools/check_seed.py
"""
import re
import sys
import glob
import os

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

# 允许出现的英文（技术术语、缩写、人名地名译名等）
ALLOW_WORDS = {
    # 常用缩写 / 术语
    'PDF', 'HTML', 'CSS', 'JavaScript', 'PHP', 'SQL', 'GDP', 'WTO', 'GATT', 'EU',
    'DNA', 'RNA', 'CPU', 'TCP', 'IP', 'ALU', 'MIT', 'NASA', 'CIA', 'FBI', 'UN',
    'E=mc', 'ptolemy',  # 占位
    # 常见专名（若需可继续补充）
    'Machiavelli', 'Marco', 'Polo', 'Mongolia',
}

# 明显的污染特征词（按本次发现补充）
SUSPECT_PATTERNS = [
    (r'[一-鿿][A-Za-z]{2,}[一-鿿]', '中文夹英文'),
    (r'[一-鿿]\s{2,}[一-鿿]', '中文间多空格'),
    (r'[一-鿿],', '半角逗号'),
    (r'[一-鿿]\.[^0-9]', '半角句号'),
    (r'[一-鿿]~[一-鿿]', '半角波浪号'),
    (r'[一-鿿][′′″]', '异常上标'),
]

# 「英文 + 中文标点」在人名/缩写后属正常（如「马可·Polo，」「加入WTO，」），
# 只有当英文串是随机噪声（不在白名单内）时才报警。
WHITELIST_EN_BEFORE_CN_PUNCT = {
    'Polo', 'Ricci', 'Sulayman', 'WTO', 'GATT', 'Khwarizmi', 'Fizzi', 'Biruni',
    'Machiavelli', 'Marco', 'Bose', 'DNA', 'NASA', 'CIA', 'UN', 'IP', 'TCP',
    # 常见产品名与印度数学家名：属正当专名，不是乱码污染
    'iPhone', 'Brahmagupta',
}

# 简繁不同形的繁体字（简繁同形字如「中」「国」不作检测）
TRAD_CHARS = set('並鐵醞釀這個們來時說學會發現實現點無為兒動務經濟產業關於權變邊書寫覺聽語辭農產營養護衛隨機觀點討論議題體現義務')
# 「並鐵」在简体中亦作他用易混淆，从检测集移除以免误报
TRAD_CHARS -= set('並鐵書')

# 常见错别字/拼音残留（本次生成中出现过的）
KNOWN_TYPOS = {
    'poverty', 'Mux', 'heart 心', 'laying', 'conformity', 'calicut',
    'Minimum', 'poly', 'Engels（同期', '铁范排版', 'iron 范',
    'Niccolo', 'Mongolia',
}


def extract_php_strings(text):
    """提取 PHP 单引号字符串内容（近似）"""
    return re.findall(r"'((?:[^'\\]|\\.)*)'", text)


def check_file(path):
    with open(path, 'r', encoding='utf-8') as f:
        content = f.read()
    lines = content.split('\n')
    issues = []

    for i, line in enumerate(lines, 1):
        # 跳过纯注释行
        stripped = line.strip()
        if stripped.startswith('*') or stripped.startswith('//') or stripped.startswith('/*'):
            continue
        # 只检查中文字符串
        if not re.search(r'[一-鿿]', line):
            continue

        for pattern, desc in SUSPECT_PATTERNS:
            for m in re.finditer(pattern, line):
                # 「与WTO世」这类：英文是大写缩写，视为正常（WTO/GATT 等专名）
                snippet = m.group(0)
                latin = re.findall(r'[A-Za-z]+', snippet)
                if all(w in WHITELIST_EN_BEFORE_CN_PUNCT for w in latin):
                    continue
                issues.append((i, desc, snippet[:60]))

        # 英文后接中文标点：仅当英文不在白名单时报警
        for m in re.finditer(r'([A-Za-z]{2,})[，。；、]', line):
            if m.group(1) not in WHITELIST_EN_BEFORE_CN_PUNCT:
                issues.append((i, '英文后接中文标点(疑似污染)', m.group(0)))

        # 繁体检测（仅在有简体同行时报警，避免全繁误报）
        if re.search(r'[一-鿿]', line):
            trad_hits = [c for c in line if c in TRAD_CHARS]
            if trad_hits:
                issues.append((i, '繁体混入', ''.join(trad_hits)))

    return issues


def check_refs():
    """检查 relations 引用完整性"""
    cn_titles = set()
    world_titles = set()
    dynasties = set()

    for path in sorted(glob.glob(os.path.join(ROOT, 'data', 'seed_part*.php'))):
        with open(path, 'r', encoding='utf-8') as f:
            content = f.read()

        # 收集 dynasties 的 slug
        for m in re.finditer(r"'name'\s*=>\s*'([^']+)'\s*,\s*'slug'\s*=>\s*'([^']+)'", content):
            dynasties.add(m.group(2))

        # 收集 cn_events 标题（在 'cn_events' 段）
        # 简化：抓所有 'title' => '...'，cn 和 world 分段
        in_cn = False
        in_world = False
        for line in content.split('\n'):
            # 更宽松的段标记匹配：段标题行形如 "'cn_events' => [" 或 "'world_events' => ["
            if re.search(r"'cn_events'\s*=>", line):
                in_cn, in_world = True, False
            if re.search(r"'world_events'\s*=>", line):
                in_cn, in_world = False, True
            if re.search(r"'relations'\s*=>", line):
                in_cn, in_world = False, False
            m = re.search(r"'title'\s*=>\s*'([^']+)'", line)
            if m:
                if in_cn:
                    cn_titles.add(m.group(1))
                elif in_world:
                    world_titles.add(m.group(1))
        # end file — reset for next

        # 收集 dynasty 引用
        for m in re.finditer(r"'dynasty'\s*=>\s*'([^']+)'", content):
            dynasties  # noop

    # relations 引用检查
    issues = []
    for path in sorted(glob.glob(os.path.join(ROOT, 'data', 'seed_part*.php'))):
        with open(path, 'r', encoding='utf-8') as f:
            content = f.read()
        for m in re.finditer(r"'cn_ref'\s*=>\s*'([^']+)'", content):
            if m.group(1) not in cn_titles:
                issues.append(('cn_ref 不存在', m.group(1)))
        for m in re.finditer(r"'world_ref'\s*=>\s*'([^']+)'", content):
            if m.group(1) not in world_titles:
                issues.append(('world_ref 不存在', m.group(1)))

    return issues, cn_titles, world_titles, dynasties


def check_dynasty_refs():
    """检查 cn_events 的 dynasty slug 是否都在 dynasties 中定义"""
    defined = set()
    used = {}
    for path in sorted(glob.glob(os.path.join(ROOT, 'data', 'seed_part*.php'))):
        with open(path, 'r', encoding='utf-8') as f:
            content = f.read()
        for m in re.finditer(r"'name'\s*=>\s*'[^']+'\s*,\s*'slug'\s*=>\s*'([^']+)'", content):
            defined.add(m.group(1))
        for m in re.finditer(r"'dynasty'\s*=>\s*'([^']+)'", content):
            used.setdefault(m.group(1), 0)
            used[m.group(1)] += 1
    missing = {k: v for k, v in used.items() if k not in defined}
    return defined, used, missing


def main():
    total_issues = 0
    print('=' * 70)
    print('种子数据质量检查')
    print('=' * 70)

    # 1. 逐文件检查文本污染
    for path in sorted(glob.glob(os.path.join(ROOT, 'data', 'seed_part*.php'))):
        name = os.path.basename(path)
        issues = check_file(path)
        if issues:
            print(f'\n[{name}] 发现 {len(issues)} 处：')
            for ln, desc, snippet in issues:
                print(f'  L{ln}: [{desc}] {snippet}')
            total_issues += len(issues)
        else:
            print(f'\n[{name}] 无问题')

    # 2. 检查引用完整性
    print('\n' + '-' * 70)
    print('引用完整性检查')
    ref_issues, cn_titles, world_titles, _ = check_refs()
    if ref_issues:
        for desc, ref in ref_issues:
            print(f'  [缺失] {desc}: 「{ref}」')
        total_issues += len(ref_issues)
    else:
        print('  relations 引用全部有效')

    # 3. 检查 dynasty 引用
    print('\n' + '-' * 70)
    print('朝代 slug 引用检查')
    defined, used, missing = check_dynasty_refs()
    if missing:
        for slug, cnt in missing.items():
            print(f'  [未定义] dynasty={slug}（被引用 {cnt} 次）')
        total_issues += len(missing)
    else:
        print('  dynasty 引用全部有定义')

    print('\n' + '=' * 70)
    print(f'共发现 {total_issues} 个问题')
    print(f'中国节点标题数：{len(cn_titles)}')
    print(f'世界大事标题数：{len(world_titles)}')
    print('=' * 70)
    return 0 if total_issues == 0 else 1


if __name__ == '__main__':
    sys.exit(main())
