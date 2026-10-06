# -*- coding: utf-8 -*-
"""校验 seed 分片：PHP 结构、字段完整性、region/category 合法值、标题重复、字符污染。"""
import re
import sys
import os
import glob

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

VALID_REGION = {
    'east_asia', 'southeast_asia', 'south_asia', 'west_asia', 'central_asia',
    'europe', 'north_america', 'latam', 'africa', 'oceania', 'global',
}
VALID_CAT = {
    'politics', 'war', 'science', 'literature', 'culture', 'music',
    'philosophy', 'religion', 'explore', 'economy', 'society', 'medicine',
    'astronomy', 'education', 'architecture', 'disaster',
}
REQUIRED = ['title', 'year', 'region', 'category', 'summary', 'detail']

# 常见污染：中文夹无意义英文（允许专名缩写）
ALLOW_EN = {
    'WTO', 'GATT', 'EU', 'DNA', 'RNA', 'CPU', 'TCP', 'IP', 'UN', 'NASA',
    'Machiavelli', 'Marco', 'Polo', 'Ricci', 'Sulayman', 'Khwarizmi',
    'Galileo', 'Newton', 'Copernicus', 'Kepler', 'Shakespeare', 'Galilei',
    'Dante', 'Alighieri', 'Borges', 'Kafka', 'Tolstoy', 'Beethoven',
    'Mozart', 'Bach', 'Handel', 'Vivaldi', 'Monet', 'Picasso', 'Archimedes',
    'Euclid', 'Ptolemy', 'Archimedes', 'Hippocrates', 'Galen', 'Paracelsus',
    'Dante', 'Ovid', 'Virgil', 'Livy', 'Cicero', 'Seneca', 'Herodotus',
    'Thucydides', 'Plato', 'Aristotle', 'Socrates', 'Pythagoras',
    'Anaximander', 'Anaximenes', 'Thales', 'Empedocles', 'Zoroaster',
    'Confucius', 'Mozi', 'Socrates', 'Karaji', 'Baghdad', 'Bayt',
    'al-Hikma', 'al-Majun', 'al-Khwarizmi', 'Ibn', 'Sina', 'Razi',
    'Farabi', 'Avicenna', 'Aristotle', 'Ptolemy', 'Herodotus', 'Kautilya',
    'Panini', 'Panini', 'Alexander', 'Sargon', 'Naram-Sin', 'Ramses',
    'Tutankhamun', 'Hammurabi', 'Thutmose', 'Narmer', 'Menes', 'Cheops',
    'Gutenberg', 'Fust', 'Schoeffer', 'Vasco', 'Gama', 'Columbus', 'Magellan',
    'Elcano', 'Pigafetta', 'Copernicus', 'Galileo', 'Harvey', 'Boyle',
    'Newton', 'Leibniz', 'Lavoisier', 'Voltaire', 'Rousseau', 'Diderot',
    'Mozart', 'Beethoven', 'Bach', 'Handel', 'Vivaldi', 'Rossini', 'Verdi',
    'Wagner', 'Brahms', 'Strauss', 'Mahler', 'Debussy', 'Stravinsky',
    'Picasso', 'Matisse', 'Kandinsky', 'Duchamp', 'Monet', 'Cézanne',
    'Gauguin', 'Van', 'Gogh', 'Renoir', 'Rodin', 'Debussy', 'Woolf',
    'Joyce', 'Faulkner', 'Hemingway', 'Camus', 'Orwell', 'Nabokov',
    'Einstein', 'Bohr', 'Rutherford', 'Curie', 'Behring', 'Fleming',
    'Florey', 'Chain', 'Pasteur', 'Koch', 'Jenner', 'Semmelweis', 'Lister',
    'Pasteur', 'Darwin', 'Wallace', 'Mendel', 'Watson', 'Crick', 'Franklin',
    'Linus', 'Pauling', 'Shannon', 'Turing', 'Babbage', 'Lovelace',
    'Hopper', 'Kilby', 'Draper', 'Torres', 'Bell', 'Edison', 'Marconi',
    'Fleming', 'Fessenden', 'Franklin', 'Franklin', 'Roosevelt', 'Sagan',
    'Landau', 'Kramers', 'Anderson', 'Anderson', 'Planck', 'Heisenberg',
    'Schrodinger', 'Dirac', 'Bohr', 'Born', 'Rutherford', 'Curie',
    'Roentgen', 'Becquerel', 'Curie', 'Rutherford', 'Joliot', 'Curie',
    'Fermi', 'Oppenheimer', 'Hahn', 'Strassmann', 'Lise', 'Meitner',
    'Bohr', 'Fermi', 'Rutherford', 'Chadwick', 'Anderson', 'Dirac',
    'Gell-Mann', 'Zweig', 'Ivy', 'Glashow', 'Salam', 'Weinberg', 'Higgs',
    'Hinton', 'LeCun', 'Bengio', 'Silver', 'Sutton', 'Sutton', 'Russel',
    'Silver', 'Spence', 'Agrawal', 'Le', 'Hinton', 'Lecun', 'Bengio',
    'Polo', 'Odoric', 'Ricci', 'Marco', 'Ibn', 'Battuta', 'Ibn', 'Taimiyya',
    'Xuanzang', 'Jianzhen', 'Du', 'Huan',
}
TRAD = set('並醞釀這個們來時說學會發現實現點無為兒動務經濟產業關於權變邊書寫覺聽語辭農產營養護衛隨機觀點討論議題體現義務內圖書號們')

STRICT = False


def strip_php_comments(txt):
    out = []
    i = 0
    n = len(txt)
    in_str = False
    q = ''
    while i < n:
        ch = txt[i]
        if in_str:
            out.append(ch)
            if ch == '\\':
                if i + 1 < n:
                    out.append(txt[i+1]); i += 2; continue
            elif ch == q:
                in_str = False
            i += 1
            continue
        if ch in ("'", '"'):
            in_str = True; q = ch; out.append(ch); i += 1; continue
        if ch == '/' and i + 1 < n and txt[i+1] == '/':
            while i < n and txt[i] != '\n':
                i += 1
            continue
        if ch == '#':
            while i < n and txt[i] != '\n':
                i += 1
            continue
        out.append(ch)
        i += 1
    return ''.join(out)


def parse_entries(block):
    out = []
    depth = 0
    buf = []
    in_str = False
    q = ''
    i = 0
    while i < len(block):
        ch = block[i]
        if in_str:
            buf.append(ch)
            if ch == '\\':
                if i + 1 < len(block):
                    buf.append(block[i+1]); i += 2; continue
            elif ch == q:
                in_str = False
            i += 1
            continue
        if ch in ("'", '"'):
            in_str = True; q = ch; buf.append(ch); i += 1; continue
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


def f_str(entry, name):
    m = re.search(r"'%s'\s*=>\s*'((?:[^'\\]|\\.)*)'" % name, entry)
    return m.group(1).replace("\\'", "'") if m else None


def f_int(entry, name):
    m = re.search(r"'%s'\s*=>\s*(-?\d+)\b" % name, entry)
    return int(m.group(1)) if m else None


def check(path):
    raw = open(path, encoding='utf-8').read()
    issues = []
    # 畸形 key：形如 'year' > -2500（应为 =>）。只在「已知字段名 + 比较运算符」上判定，
    # 避免把 'region' => 'europe' 这类合法 value 误判。
    FIELDS = ('title|year|year_end|month|day|region|category|place|summary|'
              'detail|figures|importance|source|name|slug|descr|desc|color|sort|'
              'start_year|end_year|dynasty|dynasty_slug|is_key|emoji')
    for m in re.finditer(r"'(%s)'\s*[<>]=?" % FIELDS, raw):
        issues.append(('BAD_KEY', raw[m.start():m.end() + 12].replace('\n', ' ')))
    txt = strip_php_comments(raw)
    if txt.count('[') != txt.count(']'):
        issues.append(('BRACKET', "[%d ]%d" % (txt.count('['), txt.count(']'))))
    if txt.count("'") % 2 != 0:
        issues.append(('QUOTE', "单引号 %d 个（奇数）" % txt.count("'")))
    if txt.count('(') != txt.count(')'):
        issues.append(('PAREN', "(%d )%d" % (txt.count('('), txt.count(')'))))

    entries = []
    # 顶层数组块：行首 'key' => [ 一直到行首 ], 或文件末尾的 ]（最后一个块）
    for m in re.finditer(r"^'(\w+)'\s*=>\s*\[(.*?)(?:^\],|\]\s*;?\s*$)", txt, re.S | re.M):
        key, block = m.group(1), m.group(2)
        for e in parse_entries(block):
            entries.append((key, e))

    titles = []
    for key, e in entries:
        # 只对 world_events 块套用世界大事字段规范；cn_events / relations / dynasties 另有结构
        if key != 'world_events':
            continue
        t = f_str(e, 'title')
        if not t:
            issues.append(('NO_TITLE', e[:60]))
            continue
        titles.append(t)
        for r in REQUIRED:
            if r == 'year':
                if f_int(e, 'year') is None:
                    issues.append(('MISSING', '%s 缺 year' % t))
            elif f_str(e, r) is None:
                issues.append(('MISSING', '%s 缺 %s' % (t, r)))
        reg = f_str(e, 'region')
        if reg and reg not in VALID_REGION:
            issues.append(('REGION', '%s → %s' % (t, reg)))
        cat = f_str(e, 'category')
        if cat and cat not in VALID_CAT:
            issues.append(('CATEGORY', '%s → %s' % (t, cat)))
        imp = f_int(e, 'importance')
        if imp is not None and not (1 <= imp <= 5):
            issues.append(('IMPORTANCE', '%s → %s' % (t, imp)))
        y = f_int(e, 'year')
        if y is not None and not (-3000 <= y <= 2030):
            issues.append(('YEAR', '%s → %s' % (t, y)))
        # detail 长度（新分片要求 ≥110；老分片另有标准，用 --strict 时才查）
        d = f_str(e, 'detail') or ''
        if STRICT and d and len(d) < 110:
            issues.append(('DETAIL_SHORT', '%s → %d 字' % (t, len(d))))
        # 字符污染
        for m2 in re.finditer(r'[一-鿿]([A-Za-z]{2,})[一-鿿]', d + (f_str(e, 'summary') or '')):
            w = m2.group(1)
            if w not in ALLOW_EN:
                issues.append(('MIXED_EN', '%s → %s' % (t, w)))
        for ch in d:
            if ch in TRAD:
                issues.append(('TRAD', '%s → %s' % (t, ch)))
                break
        for m3 in re.finditer(r'[一-鿿][,.]', d + (f_str(e, 'summary') or '')):
            issues.append(('PUNCT', '%s → %s' % (t, m3.group(0))))
    # 标题重复
    seen = {}
    for t in titles:
        if t in seen:
            issues.append(('DUP_TITLE', t))
        seen[t] = 1
    return entries, issues, titles


if __name__ == '__main__':
    argv = [a for a in sys.argv[1:] if a != '--strict']
    STRICT = '--strict' in sys.argv
    files = argv or sorted(glob.glob(os.path.join(ROOT, 'data', 'seed_part*.php')))
    all_titles = {}
    total = 0
    bad = 0
    for p in files:
        entries, issues, titles = check(p)
        name = os.path.basename(p)
        n_we = len([1 for k, _ in entries if k == 'world_events'])
        n_cn = len([1 for k, _ in entries if k == 'cn_events'])
        total += n_we
        for t in titles:
            all_titles.setdefault(t, []).append(name)
        if issues:
            bad += len(issues)
            print('=== %s  (%d 条) ===' % (name, len(entries)))
            seenk = {}
            for kind, msg in issues:
                seenk[kind] = seenk.get(kind, 0) + 1
                if seenk[kind] <= 12:
                    print('  [%s] %s' % (kind, msg))
            if len(issues) > sum(min(v, 12) for v in seenk.values()):
                print('  ... 共 %d 个问题' % len(issues))
            print('  小计: %s' % seenk)
        else:
            print('OK  %-22s %3d 条 (world=%d cn=%d)' % (name, len(entries), n_we, n_cn))
    print()
    print('world_events 合计: %d' % total)
    cross = {t: v for t, v in all_titles.items() if len(v) > 1}
    if cross:
        print('!! 跨分片重复标题 %d 个:' % len(cross))
        for t, v in list(cross.items())[:20]:
            print('   %s  %s' % (t, v))
    else:
        print('无跨分片重复标题')
    if bad:
        print('!! 存在 %d 个字段级问题' % bad)
    else:
        print('字段级检查全通过')
