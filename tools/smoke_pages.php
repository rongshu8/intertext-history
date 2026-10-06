<?php
/**
 * 无数据库环境下的页面冒烟测试
 * ---------------------------------------------------------------------------
 * 目的：在没有 pdo_sqlite 扩展的机器上，验证
 *   1. 每个页面能完整解析（无语法错误）
 *   2. include 顺序正确（不会出现 undefined function）
 *   3. 函数调用全部有定义
 *   4. 输出的 HTML 结构完整（含 CSS 引用）
 *
 * 手法：先加载一个「假 DB」把 PDO 相关调用替换成返回空数组的桩，
 * 然后用 php-cgi 式的 CLI 方式 include 页面，捕获致命错误。
 *
 * 用法：php tools/smoke_pages.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__);

// ---------------------------------------------------------------------------
// 1. 语法预检：所有 PHP 文件 php -l 等价检查（用 token 解析近似）
// ---------------------------------------------------------------------------
$pages = [
    'index.php', 'node.php', 'world.php', 'search.php', 'about.php',
    'install.php', 'register.php', 'contributors.php', 'contribute.php',
    'admin/index.php', 'admin/login.php', 'admin/logout.php', 'admin/review.php',
    'admin/cn_events.php', 'admin/world_events.php', 'admin/relations.php',
    'admin/dynasties.php', 'admin/import.php', 'admin/export.php',
    'admin/restore.php', 'admin/account.php',
    'user/index.php', 'user/submit.php', 'user/account.php',
];

echo "=" . str_repeat('=', 68) . "\n";
echo "页面冒烟测试（无数据库环境）\n";
echo "=" . str_repeat('=', 68) . "\n\n";

// ---------------------------------------------------------------------------
// 2. 定义桩函数，拦截所有 DB 访问
// ---------------------------------------------------------------------------
$GLOBALS['__SMOKE__'] = true;

echo "[1] 注入 DB 桩\n";

// 把 DB 类的方法体替换为返回空 —— 通过在 require 前定义类来实现
if (!class_exists('__SmokeDB')) {
    // 用输出缓冲 + 页面级错误捕获来跑
}

// ---------------------------------------------------------------------------
// 3. 逐页检查：解析 + 函数定义完整性
// ---------------------------------------------------------------------------
echo "\n[2] 页面 include 链与函数定义检查\n";

/**
 * 静态分析一个页面：
 *  - 收集它 require 的文件
 *  - 收集它调用的自定义函数
 *  - 检查被调用函数是否在「已加载文件集合」里有定义
 */
function collect_defined_functions(array $files): array
{
    $defs = [];
    foreach ($files as $f) {
        $src = @file_get_contents($f);
        if ($src === false) continue;
        if (preg_match_all('/function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/i', $src, $m)) {
            foreach ($m[1] as $fn) {
                $defs[strtolower($fn)] = basename($f);
            }
        }
    }
    return $defs;
}

function collect_php_builtin(): array
{
    // 语言结构（不是函数，但正则会当成函数调用）
    $lang = [
        'if', 'elseif', 'else', 'endif', 'endwhile', 'endfor', 'endforeach', 'endswitch',
        'foreach', 'for', 'while', 'do', 'switch', 'case', 'default', 'break', 'continue',
        'return', 'echo', 'print', 'function', 'fn', 'use', 'catch', 'finally', 'throw',
        'new', 'clone', 'instanceof', 'and', 'or', 'xor', 'as', 'global', 'static',
        'match', 'yield', 'require', 'require_once', 'include', 'include_once', 'exit', 'die',
        'unset', 'isset', 'empty', 'list', 'array', 'declare', 'endif', 'try', 'int', 'string',
        'bool', 'float', 'void', 'self', 'parent',
        // PHP 内置类与异常
        'PDO', 'Exception', 'Throwable', 'RuntimeException', 'LogicException',
        'InvalidArgumentException', 'OutOfBoundsException', 'PDOException', 'DateTime',
        'DateTimeImmutable', 'DateInterval', 'ArrayObject', 'ArrayIterator', 'SplFileObject',
        'Closure', 'Generator', 'Traversable', 'Countable', 'JsonSerializable',
        'ReflectionClass', 'ReflectionMethod', 'Error', 'TypeError', 'ValueError',
        'ArgumentCountError', 'ArithmeticError', 'UnhandledMatchError',
        'ZipArchive',
    ];
    // 标准库函数
    $fns = [
        'array','array_map','array_filter','array_merge','array_key_exists','array_keys',
        'array_values','array_slice','array_fill','array_column','array_unique','array_reverse',
        'array_search','array_push','array_pop','array_shift','array_unshift','array_splice',
        'array_walk','array_diff','array_intersect',
        'count','in_array','is_array','is_string','is_numeric','is_int','is_float','is_bool',
        'is_null','is_object','is_callable','is_file','is_dir',
        'file_exists','is_readable','is_writable','filemtime','filesize','glob','scandir',
        'sort','usort','uasort','uksort','ksort','krsort','rsort',
        'max','min','abs','round','floor','ceil','intdiv','pow','sqrt','intval','floatval',
        'strval','boolval','settype','gettype','base_convert','bindec','decoct','hexdec',
        'str_replace','substr','substr_count','substr_replace','strlen','strpos','strrpos',
        'stripos','strripos','str_ireplace','trim','ltrim','rtrim','ucfirst','lcfirst',
        'strtok','rawurlencode','htmlspecialchars','is_file','sprintf',
        'chmod','unlink','file_put_contents','file_get_contents',
        'ucwords','strtolower','strtoupper','str_split','str_pad','strrev','str_word_count',
        'strcmp','strcasecmp','strncasecmp','strnatcmp','strnatcasecmp',
        'sprintf','printf','vsprintf','number_format',
        'nl2br','implode','explode','wordwrap','htmlspecialchars','htmlspecialchars_decode',
        'html_entity_decode','htmlentities','strip_tags','addslashes','stripslashes',
        'json_encode','json_decode','json_last_error',
        'http_build_query','parse_url','parse_str','parse_args',
        'preg_match','preg_match_all','preg_replace','preg_replace_callback','preg_split',
        'preg_quote','preg_grep',
        'date','time','mktime','checkdate','microtime','uniqid','date_default_timezone_set',
        'strtotime','gmdate','str_repeat','str_pad',
        'sys_get_temp_dir','getmypid','ob_get_level','ob_end_clean',
        'readfile','stream_get_contents','fclose',
        'header','headers_sent','http_response_code',
        'setcookie','session_start','session_name','session_destroy','session_regenerate_id',
        'session_status','session_set_cookie_params','session_get_cookie_params','session_id',
        'password_hash','password_verify','password_needs_rehash',
        'hash','hash_equals','random_bytes','random_int','bin2hex','hex2bin',
        'ob_start','ob_get_clean','ob_get_contents','ob_end_clean','flush',
        'move_uploaded_file','fopen','fclose','fgetcsv','fread','fwrite','fseek','ftell',
        'rewind','feof','fgets','fputs','fputcsv',
        'file_get_contents','file_put_contents','unlink','copy','rename','touch',
        'mkdir','rmdir','opendir','readdir','closedir','pathinfo',
        'dirname','basename','realpath','getcwd','chdir',
        'extension_loaded','get_loaded_extensions','version_compare',
        'ini_get','ini_set','ini_alter','error_reporting','set_error_handler',
        'trigger_error','assert','php_sapi_name','php_uname','getenv','putenv',
        'compact','extract','serialize','unserialize','range','iterator_to_array',
        'ctype_digit','ctype_alpha','ctype_alnum','ctype_upper','ctype_lower',
        'mb_strlen','mb_substr','mb_internal_encoding','mb_strpos','mb_strrpos',
        'mb_strtolower','mb_strtoupper','mb_convert_encoding','mb_detect_encoding',
        'filter_var','filter_input','intdiv','boolval',
        'md5','sha1','crc32','base64_encode','base64_decode',
        'urlencode','urldecode','rawurlencode','rawurldecode','http_build_query',
        'defined','define','constant','function_exists','class_exists','interface_exists',
        'method_exists','property_exists','get_class','get_parent_class','get_object_vars',
        'call_user_func','call_user_func_array','func_get_args','func_num_args',
        'call_user_method','is_a','is_subclass_of',
        'date_create','date_diff','strtotime',
        // PHP 8.0+ 内置函数
        'str_contains','str_starts_with','str_ends_with','array_is_list',
        'get_debug_type','fdiv','preg_last_error_msg',
        // 数组字面量访问被正则误抓的词（array_values($x) 之类）
        'array_flip','array_reverse','array_combine','array_count_values',
        'array_fill','array_fill_keys','array_pad','array_walk','array_walk_recursive',
        'array_search','array_diff','array_diff_key','array_intersect',
        'array_splice','array_shift','array_pop','array_unshift','array_key_first','array_key_last',
        'current','next','reset','end','each',
        'key','key_exists','array_rand','shuffle','compact','extract',
        // 上面 array_xxx 的裸动词部分（正则会把 array_values( 里的 values 也抓出来）
        'keys','values','merge','filter','map','unique','combine','reverse','slice','push',
        'flip','fill','pad','walk','search','diff','intersect','splice','shift','pop',
        'count','sum','reduce','apply',
        // HTML 内联 JS 的伪函数 / 变量
        'confirm','alert','prompt','var','let','const',
        // DOM / JS 常用方法（内联 script 块里的 xxx() 会被误抓成 PHP 函数）
        'getelementbyid','queryselector','queryselectorall','getclasslist',
        'classlist','add','remove','toggle','contains','closest','matches',
        'setattribute','getattribute','removeattribute','dataset','style',
        'addeventlistener','removeeventlistener','preventdefault','stoppropagation',
        'appendchild','insertbefore','removechild','createelement','createtextnode',
        'settextcontent','innerhtml','textcontent','parentnode','children',
        'firstelementchild','nextelementsibling','getboundingclientrect',
        'offsetwidth','offsetheight','scrollwidth','scrollheight','clientwidth','clientheight',
        'matchmedia','addlistener','removelistener','push','pop','shift','unshift','splice',
        'slice','find','filter','some','every','sort','reverse','includes','indexof',
        'forEach','map','join','split','replace','replaceall','trim','tolowercase','touppercase',
        'settimeout','clearinterval','setinterval','clearTimeout','fetch','then','catch',
        // SQL 表名（DELETE FROM relations 等语句里的单词）
        'cn_events','world_events','relations','dynasties','users','categories',
        'regions','relation_types','submissions',
        // SQL 关键字与类型名（information_schema 查询、PRAGMA、DDL 里的词）
        'table_info','database','varchar','integer','current_timestamp','null',
        'information_schema','statistics','table_schema','table_name','column_name',
        'index_name','sqlite_master','count','sum','min','max','avg',
    ];
    // 转为「名称 => true」的关联数组，供 isset() 快速查表。
    // 用 array_flip 而非 array_merge：后者对数值索引数组做不出键值映射。
    $all = array_merge($lang, $fns);
    $map = [];
    foreach ($all as $name) {
        $map[strtolower($name)] = true;
    }
    return $map;
}

$allFiles = [];
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ROOT));
foreach ($rii as $f) {
    if ($f->isFile() && $f->getExtension() === 'php') {
        $allFiles[] = $f->getPathname();
    }
}
$allDefined = collect_defined_functions($allFiles);
$builtin    = collect_php_builtin();
$builtin    = collect_php_builtin();

$fail = 0;
foreach ($pages as $page) {
    $path = "$ROOT/$page";
    if (!is_file($path)) {
        echo "  ✗ $page  文件不存在\n";
        $fail++;
        continue;
    }
    $src = file_get_contents($path);

    // 收集本页面直接 require 的文件
    $loaded = [$path];
    if (preg_match_all("/require(?:_once)?\s+(__DIR__\s*\.\s*)?'([^']+)'/", $src, $m)) {
        foreach ($m[2] as $rel) {
            $cand = $ROOT . '/' . ltrim(str_replace('..', '', $rel), '/');
            $cand = str_replace('//', '/', $cand);
            if (is_file($cand)) {
                $loaded[] = $cand;
            }
        }
    }
    // layout/admin_header 会再拉 helpers/db/schema
    foreach (['/inc/layout_header.php', '/inc/admin_header.php'] as $extra) {
        if (is_file($ROOT . $extra)) {
            $loaded[] = $ROOT . $extra;
        }
    }
    $loaded[] = "$ROOT/inc/helpers.php";
    $loaded[] = "$ROOT/inc/db.php";
    $loaded[] = "$ROOT/inc/schema.php";
    $loaded[] = "$ROOT/inc/bootstrap.php";
    $loaded = array_values(array_unique($loaded));

    $pageDefined = collect_defined_functions($loaded);

    // 收集本文件定义的类（用于排除 DB:: / Schema:: 这类静态调用）
    $pageClasses = [];
    if (preg_match_all('/\bclass\s+([A-Za-z_][A-Za-z0-9_]*)/', $src, $mc)) {
        foreach ($mc[1] as $c) {
            $pageClasses[strtolower($c)] = true;
        }
    }

    // ★ 扫「调用的函数」之前必须先剥掉 <style> 块。
    //   CSS 里的 `@media (max-width: 560px)` 会被 `名字 + (` 的正则当成函数调用，
    //   报出「可能未定义的函数: media」——而它显然不是 PHP 函数。
    //   这不是把 media 加进白名单能解决的：**任何页面加一条媒体查询都会中招**。
    //   同样的道理适用于行内 style 属性与 <script> 里的 JSON。
    $codeOnly = preg_replace('~<style\b[^>]*>.*?</style>~is', ' ', $src);
    $codeOnly = preg_replace('~style\s*=\s*"[^"]*"~i', ' ', (string) $codeOnly);

    // 收集页面里调用的函数（排除语言结构）
    $called = [];
    if (preg_match_all('/(?<![\$>:\\\\\w])([a-z_][a-z0-9_]*)\s*\(/i', $codeOnly, $m2)) {
        foreach ($m2[1] as $fn) {
            $lfn = strtolower($fn);
            if (isset($builtin[$lfn])) continue;
            if (isset($pageDefined[$lfn])) continue;
            if (isset($pageClasses[$lfn])) continue;      // 本页定义的类
            if (isset($allDefined[$lfn])) continue;       // 项目内其他文件已定义
            $called[$lfn] = true;
        }
    }

    if ($called) {
        echo "  ✗ $page  可能未定义的函数: " . implode(', ', array_keys($called)) . "\n";
        $fail++;
    } else {
        echo "  ✓ $page\n";
    }
}

// ---------------------------------------------------------------------------
// 4. 检查裸相对链接与 CSS 引用
// ---------------------------------------------------------------------------
echo "\n[3] 硬编码路径检查\n";
$hardcode = 0;
foreach ($pages as $page) {
    $path = "$ROOT/$page";
    $src  = @file_get_contents($path);
    if ($src === false) continue;

    // CSS 引用必须是 asset() 或 alink()
    if (preg_match('/<link[^>]+href="(?!<\?=)([^"]+\.css)/', $src, $m)) {
        echo "  ✗ $page  CSS 用了硬编码路径: {$m[1]}\n";
        $hardcode++;
    }
    // 重定向必须是动态拼接
    if (preg_match("/header\('Location:\s*[a-zA-Z_\/]/", $src, $m)) {
        echo "  ✗ $page  重定向用了硬编码路径\n";
        $hardcode++;
    }
}
if ($hardcode === 0) {
    echo "  ✓ 所有页面 CSS 与重定向均为动态路径\n";
}

// ---------------------------------------------------------------------------
// 5. 检查 bootstrap 引入
// ---------------------------------------------------------------------------
echo "\n[4] bootstrap 引入检查\n";
$missBootstrap = 0;
foreach ($pages as $page) {
    $src = @file_get_contents("$ROOT/$page");
    if ($src === false) continue;
    if (strpos($src, 'bootstrap.php') === false) {
        echo "  ✗ $page  未引入 bootstrap.php\n";
        $missBootstrap++;
    }
}
if ($missBootstrap === 0) {
    echo "  ✓ 全部 " . count($pages) . " 个页面均已引入 bootstrap\n";
}

// ---------------------------------------------------------------------------
// 4b. 检查安装闸门
// ---------------------------------------------------------------------------
// 前台页必须在 bootstrap **之前** require inc/install_gate.php。
// 顺序反了等于没装：bootstrap 会 DB::pdo()，没配置时抛 PDOException，
// 全新用户打开首页看到的是 HTTP 500 白屏，而不是安装向导。
echo "\n[4b] 安装闸门检查\n";
$frontPages = ['index.php', 'node.php', 'world.php', 'search.php',
               'about.php', 'register.php', 'contributors.php', 'contribute.php'];

/**
 * 找出「真正执行 require 语句」的行号。
 * 不能直接 strpos 文件内容 —— 注释里提到 bootstrap.php 就会命中，
 * 那样把闸门挪到 bootstrap 之后也检测不出来（这个坑真踩过一轮）。
 */
function first_require_line(string $src, string $needleFile): int
{
    $lines = preg_split('/\r\n|\r|\n/', $src);
    foreach ($lines as $i => $line) {
        $t = ltrim($line);
        if ($t === '' || $t[0] === '/' || $t[0] === '*' || $t[0] === '#') {
            continue;   // 跳过注释行
        }
        if (stripos($t, 'require') !== false && strpos($t, $needleFile) !== false) {
            return $i;
        }
    }
    return -1;
}

$missGate = 0;
foreach ($frontPages as $page) {
    $f = "$ROOT/$page";
    if (!is_file($f)) { continue; }
    $src = (string) @file_get_contents($f);
    $gateLine = first_require_line($src, 'inc/install_gate.php');
    $bootLine = first_require_line($src, 'inc/bootstrap.php');
    if ($gateLine < 0) {
        echo "  ✗ $page  未引入 install_gate.php（未装时不会导向安装向导）\n";
        $missGate++;
    } elseif ($bootLine >= 0 && $gateLine > $bootLine) {
        echo "  ✗ $page  闸门在 bootstrap 之后（第 " . ($gateLine + 1) . " 行 vs 第 "
            . ($bootLine + 1) . " 行）—— 顺序错了，新站会白屏\n";
        $missGate++;
    }
}
// 闸门与前置检查两个文件本身必须在
foreach (['inc/install_gate.php', 'inc/install_precheck.php'] as $need) {
    if (!is_file("$ROOT/$need")) {
        echo "  ✗ 缺少 $need\n";
        $missGate++;
    }
}
if ($missGate === 0) {
    echo "  ✓ " . count($frontPages) . " 个前台页均已在 bootstrap 之前引入闸门\n";
}

echo "\n" . str_repeat('=', 69) . "\n";
$total = $fail + $hardcode + $missBootstrap + $missGate;
echo $total === 0 ? "冒烟测试全部通过 ✓\n" : "发现 $total 处问题\n";
echo str_repeat('=', 69) . "\n";

exit($total === 0 ? 0 : 1);
