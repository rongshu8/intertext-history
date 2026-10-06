<?php
/**
 * SQL 时间戳拼接检查（探针）
 * ---------------------------------------------------------------------------
 * 由 tools/check_sql_quotes.py 调用，不单独使用。
 *
 * 原理：用 PHP 官方 tokenizer 判断 SQL_NOW 是否被正确拼接。
 *   T_CONSTANT_ENCAPSED_STRING 是「完整字符串字面量」。
 *   若某个字面量的内容里含有 ' . SQL_NOW . ' 这个片段，
 *   说明拼接运算符落在了字符串内部 —— SQL_NOW 不会被求值，
 *   会被原样送进数据库，导致 1292 Invalid datetime format。
 *
 * 正确写法：'... VALUES (?, ' . SQL_NOW . ')'
 *   → SQL_NOW 处于代码区（token 为 T_STRING），字面量内容里没有它
 *
 * 用法：php tools/_probe_sqlnow.php <json文件路径>
 * 输出：JSON 数组，每项 {file, line, text}
 */

$listFile = $argv[1] ?? '';
if ($listFile === '' || !is_file($listFile)) {
    echo '[]';
    exit;
}

$files = json_decode(file_get_contents($listFile), true);
if (!is_array($files)) {
    echo '[]';
    exit;
}

// 定义判断不是拼接 bug：defined('SQL_NOW')、'SQL_NOW' 这类
$NEEDLE = ' . SQL_NOW . ';

$bad = [];
foreach ($files as $path) {
    $src = @file_get_contents($path);
    if ($src === false) {
        continue;
    }
    $tokens = @token_get_all($src);
    if (!$tokens) {
        continue;
    }
    foreach ($tokens as $t) {
        if (!is_array($t) || $t[0] !== T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }
        $inner = substr($t[1], 1, -1);
        // 字面量内容里出现了拼接片段 → 拼接符在串内，是 bug
        if (strpos($inner, $NEEDLE) === false) {
            continue;
        }
        $bad[] = [
            'file' => $path,
            'line' => $t[2],
            'text' => $t[1],
        ];
    }
}

echo json_encode($bad, JSON_UNESCAPED_UNICODE);
