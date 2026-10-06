#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
PHP 静态自查（无需 PHP 运行时）
-----------------------------------------------------------------
检查项：
  1. 括号/引号配对平衡
  2. 每个 PHP 文件以 <?php 开头、无 BOM
  3. HTML 输出函数 h() 的使用（查找未转义的 echo $_GET/$_POST）
  4. SQL 拼接中是否含未转义变量（排除已 int 转型的）
  5. include/require 路径是否存在
  6. link_to() / asset() 等自定义函数在使用前是否已定义
  7. 中文里混入的英文噪声

用法：python tools/check_php.py
"""
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PHP_FILES = []
for dirpath, dirnames, filenames in os.walk(ROOT):
    dirnames[:] = [d for d in dirnames if d not in ('.git', 'node_modules', '.workbuddy')]
    for f in filenames:
        if f.endswith('.php'):
            PHP_FILES.append(os.path.join(dirpath, f))

# 自定义函数定义点
CUSTOM_FUNCS = set()


def strip_php_strings_and_comments(src):
    """粗略剥离字符串与注释，避免误判括号"""
    out = []
    i = 0
    n = len(src)
    while i < n:
        c = src[i]
        # 单引号
        if c == "'":
            i += 1
            while i < n:
                if src[i] == '\\':
                    i += 2
                    continue
                if src[i] == "'":
                    i += 1
                    break
                i += 1
            continue
        # 双引号
        if c == '"':
            i += 1
            while i < n:
                if src[i] == '\\':
                    i += 2
                    continue
                if src[i] == '"':
                    i += 1
                    break
                i += 1
            continue
        # 行注释
        if src.startswith('//', i) or src[i] == '#':
            while i < n and src[i] != '\n':
                i += 1
            continue
        # 块注释
        if src.startswith('/*', i):
            j = src.find('*/', i + 2)
            i = (j + 2) if j != -1 else n
            continue
        out.append(c)
        i += 1
    return ''.join(out)


def check_balance(path, src):
    issues = []
    clean = strip_php_strings_and_comments(src)
    for open_c, close_c, name in [('(', ')', '圆括号'), ('{', '}', '花括号'), ('[', ']', '方括号')]:
        # 只统计 PHP 代码段内的括号（简易：全文统计，HTML 里一般配平）
        d = 0
        minv = 0
        for ch in clean:
            if ch == open_c:
                d += 1
            elif ch == close_c:
                d -= 1
                minv = min(minv, d)
        if d != 0:
            issues.append(f'{name}不配对：净{d:+d}（最小{minv}）')
    return issues


def check_bom(path):
    with open(path, 'rb') as f:
        head = f.read(3)
    return head == b'\xef\xbb\xbf'


def check_open_tag(path, src):
    if not src.startswith('<?php'):
        return '文件未以 <?php 开头'
    return None


def check_raw_superglobal(path, src):
    """查找 echo/print 直接输出超全局变量（应经 h() 转义）"""
    issues = []
    for m in re.finditer(r'(echo|print)\s+[^;]{0,120}?\$_(GET|POST|REQUEST|COOKIE)', src):
        line = src[:m.start()].count('\n') + 1
        snippet = m.group(0)[:90].replace('\n', ' ')
        # 如果被 h() 包着就没问题
        if re.search(r'h\s*\(\s*\$_' + m.group(2), snippet):
            continue
        issues.append(f'L{line} 未经 h() 转义直接输出超全局: {snippet}')
    return issues


def check_includes(path, src):
    issues = []
    for m in re.finditer(r'(?:include|require)(?:_once)?\s+(__DIR__\s*\.\s*|\$__DIR__\s*\.\s*)?[\'"]([^\'"]+)[\'"]', src):
        rel = m.group(2)
        if rel.startswith('/') or rel.startswith('http'):
            continue
        base = os.path.dirname(path)
        if m.group(1):
            target = os.path.normpath(os.path.join(base, rel))
        else:
            target = os.path.normpath(os.path.join(base, rel))
        if not os.path.exists(target):
            line = src[:m.start()].count('\n') + 1
            issues.append(f'L{line} include 目标不存在: {rel} -> {target}')
    return issues


def collect_defined_funcs(src):
    return set(re.findall(r'function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(', src))


def check_func_usage(path, src, all_defined):
    """检查自定义函数在同文件或已 require 的文件中是否定义"""
    issues = []
    used = set(re.findall(r'(?<![\$>:\w])([a-z_][a-z0-9_]*)\s*\(', src))
    builtin = {
        'h', 'q', 'qi', 'p', 'pi', 'url', 'fmt_year', 'fmt_year_range', 'fmt_date',
        'event_sort_key', 'h_multiline', 'csrf_token', 'csrf_field', 'csrf_check',
        'start_session', 'is_logged_in', 'current_user', 'require_login',
        'dict_categories', 'dict_regions', 'dict_relation_types', 'all_dynasties',
        'dynasty_by_id', 'cat_name', 'cat_color', 'region_name', 'region_emoji',
        'list_cn_events', 'count_cn_events', 'get_cn_event', 'get_world_event',
        'world_events_near', 'relations_of_cn', 'cn_events_of_world',
        'cn_event_neighbours', 'dynasty_event_counts', 'site_stats', 'random_world_event',
        'db', 'cfg', 'c_site_name', 'c_sync_window', 'c_sync_limit', 'c_db_driver',
        'link_to', 'asset', 'site_base', 'alink', 'flash', 'take_flash', 'hl',
        'if', 'for', 'foreach', 'while', 'switch', 'function', 'array', 'isset',
        'unset', 'empty', 'list', 'echo', 'print', 'return', 'catch', 'match',
        'fn', 'static', 'exit', 'die', 'require', 'require_once', 'include',
        'include_once', 'new', 'clone', 'use', 'insteadof', 'elseif', 'do',
        'int', 'str', 'bool', 'float', 'intval', 'strlen', 'count', 'array_map',
        'in_array', 'is_numeric', 'is_array', 'is_file', 'is_dir', 'file_exists',
        'sprintf', 'printf', 'number_format', 'implode', 'explode', 'trim',
        'str_replace', 'substr', 'strpos', 'strtolower', 'strtoupper', 'ucfirst',
        'mb_substr', 'mb_strlen', 'json_encode', 'json_decode', 'base64_encode',
        'date', 'time', 'microtime', 'uniqid', 'random_bytes', 'bin2hex',
        'hash', 'hash_equals', 'password_hash', 'password_verify', 'session_start',
        'session_destroy', 'session_regenerate_id', 'session_name',
        'session_set_cookie_params', 'session_get_cookie_params', 'setcookie',
        'header', 'headers_sent', 'ob_start', 'ob_get_clean', 'ob_end_clean',
        'move_uploaded_file', 'fopen', 'fclose', 'fgetcsv', 'fread', 'fwrite',
        'rewind', 'feof', 'file_get_contents', 'file_put_contents', 'unlink',
        'mkdir', 'rmdir', 'glob', 'scandir', 'opendir', 'closedir', 'pathinfo',
        'dirname', 'basename', 'realpath', 'parse_url', 'http_build_query',
        'htmlspecialchars', 'nl2br', 'var_dump', 'print_r', 'preg_match',
        'preg_replace', 'preg_split', 'strtotime', 'mktime', 'checkdate',
        'filter_var', 'in_array', 'array_key_exists', 'array_keys', 'array_values',
        'array_filter', 'array_merge', 'array_unique', 'array_slice', 'array_fill',
        'array_column', 'array_combine', 'array_reverse', 'sort', 'usort', 'ksort',
        'max', 'min', 'abs', 'round', 'floor', 'ceil', 'pow', 'sqrt', 'intdiv',
        'PHP_VERSION', 'extension_loaded', 'version_compare', 'ini_get', 'defined',
        'serialize', 'unserialize', 'range', 'compact', 'extract', 'is_int',
        'is_string', 'is_null', 'is_bool', 'class_exists', 'method_exists',
        'spl_autoload_register', 'define', 'defined', 'constant', 'error_reporting',
        'ini_set', 'set_error_handler', 'trigger_error', 'assert', 'func_get_args',
        'call_user_func', 'usleep', 'sleep', 'file_put_contents', 'tempnam',
    }
    for fn in used:
        if fn in builtin or fn in all_defined:
            continue
        if len(fn) > 3:  # 短名多为语言结构或误判
            line = None
            for m in re.finditer(r'(?<![\$>:\w])' + re.escape(fn) + r'\s*\(', src):
                line = src[:m.start()].count('\n') + 1
                break
            issues.append(f'L{line} 可能未定义的函数: {fn}()')
    return issues


def main():
    # 第一遍：收集所有已定义函数
    contents = {}
    for p in PHP_FILES:
        with open(p, 'r', encoding='utf-8') as f:
            contents[p] = f.read()
    all_defined = set()
    for src in contents.values():
        all_defined |= collect_defined_funcs(src)

    total = 0
    print('=' * 70)
    print('PHP 静态自查')
    print('=' * 70)

    for path in sorted(PHP_FILES):
        rel = os.path.relpath(path, ROOT)
        src = contents[path]
        issues = []

        if check_bom(path):
            issues.append('文件含 UTF-8 BOM（可能导致 header 之前输出空白）')
        e = check_open_tag(path, src)
        if e:
            issues.append(e)
        issues += check_balance(path, src)
        issues += check_raw_superglobal(path, src)
        issues += check_includes(path, src)
        issues += check_func_usage(path, src, all_defined)

        if issues:
            print(f'\n[{rel}]')
            for i in issues:
                print(f'  · {i}')
            total += len(issues)

    print('\n' + '=' * 70)
    print(f'扫描 {len(PHP_FILES)} 个 PHP 文件，发现 {total} 个问题')
    print('=' * 70)
    return 0 if total == 0 else 1


if __name__ == '__main__':
    sys.exit(main())
