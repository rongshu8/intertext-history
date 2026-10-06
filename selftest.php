<?php
/**
 * 纯净包安装前自检 —— 在「包目录」里跑，而不是在开发目录里跑。
 * ---------------------------------------------------------------------------
 * 为什么必须单独验：纯净包**不含** data/seed_part*.php（旧的 PHP 种子）。
 * 一旦 data/seed/*.json 读不出来，安装器就会静默回退到旧种子 → 读到空 →
 * 装出一个空站。所以要在包目录里证明「JSON 路径真的能走通」。
 *
 * 用法：php selftest.php           （在包根目录，本文件会随包发布）
 *      php tools/selftest_package.php  （在开发目录）
 */

$root = null;
foreach ([__DIR__, dirname(__DIR__)] as $c) {
    if (is_file($c . '/inc/datapack.php') && is_file($c . '/install.php')) { $root = $c; break; }
}
if ($root === null) {
    exit("找不到 inc/datapack.php 与 install.php，请在包根目录或 tools/ 下运行\n");
}
chdir($root);

define('SQL_NOW', 'NOW()');   // inc/db.php 会用它拼时间戳，这里不需要连库

$fail = 0;
function ok($c, $m) {
    global $fail;
    echo ($c ? "  ✓ " : "  ✗ ") . $m . "\n";
    if (!$c) $GLOBALS['fail']++;
}

echo "=== 安装前自检 ===\n";
echo "检查目录: $root\n";

// ---- 这是开发目录还是纯净包？----
// 两种目录该跑的检查完全不同：
//   · 纯净包  → 必须没有 config.local.php / 锁 / tools/ / 旧种子
//   · git 仓库 → 这些**都应该有**（开发目录本来就有配置和工具）
// 用is_file 判据自动切换，别让人手工选错模式。
$isPackage = !is_file($root . '/config.local.php') && !is_dir($root . '/tools');
echo "模式: " . ($isPackage ? "纯净包（发布前检查）" : "开发目录 / git 仓库")
     . "\n\n";

// ---- 1) 关键文件在位 ----
echo "[1] 关键文件\n";
foreach (['install.php', 'inc/bootstrap.php', 'inc/datapack.php', 'inc/schema.php',
          'config.php', 'config.local.example.php', 'data/seed/manifest.json',
          // 安装向导：没这两个文件，新用户访问域名会直接白屏而不是进安装页
          'inc/install_gate.php', 'inc/install_precheck.php'] as $f) {
    ok(is_file($root . '/' . $f), $f);
}

// ★ 下面三项只对纯净包有意义。
//   开发目录里 config.local.php 本来就该在（否则本地连不上库），
//   判它"不该存在"会让自检在开发目录里全红 ——
//   而一个永远报红的检查等于没有检查。
if ($isPackage) {
    ok(!is_file($root . '/config.local.php'), 'config.local.php 不在包里（应只有 .example）');
    // ★ 锁的路径是 data/install.lock。
    //   这里原来写的是 install.php.lock —— 路径根本不对，这个检查从来没生效过。
    ok(!is_file($root . '/data/install.lock'), '没有遗留的安装锁（data/install.lock）');
} else {
    echo "  · 跳过「config.local.php 不应存在」等三项 —— 开发目录里它们本该在\n";
}

// 全新站的身份判据：包在未装状态下解出来，第一个页面必须能自动导流向导。
// 这三条是「别人拿到包就能直接装」的最低保证 ——
// 缺任何一条都会表现为「访问首页白屏」或「看到安装器但填不了表」。
echo "\n[1a] 全新站可安装性\n";
$mustHave = [
    'install.php'         => '安装向导本体',
    'config.local.example.php' => '配置模板（手工配置时用）',
    'inc/install_gate.php' => '安装闸门（未装时导流）',
    'inc/install_precheck.php' => '第一步：收数据库信息',
];
$miss1a = [];
foreach ($mustHave as $f => $why) {
    if (!is_file($root . '/' . $f)) { $miss1a[] = "$f（$why）"; }
}
ok(empty($miss1a), '安装链路的 4 个文件都在' . ($miss1a ? '：缺 ' . implode('、', $miss1a) : ''));

// 闸门必须能在「无配置」时跳走；这里只验判据函数本身，不真跳
$gate = (string) @file_get_contents($root . '/inc/install_gate.php');
$gateOK = strpos($gate, 'config.local.php') !== false
        && stripos($gate, 'is_file') !== false;
ok($gateOK, '闸门以 config.local.php 的存在与否作为判据');

// 包里绝不能出现任何真实域名 —— 开源分发的包带域名等于把别人锁到你的服务器上
//
// ★ 只查**会被执行/会生效**的文件类型（php/html/js/css/json），
//   不查 .md / .txt。
//   理由：README 的 badge 链接（shields.io / php.net）、贡献指南里的
//   示例域名（example.com）、数据格式说明里的举例 —— 这些是文档，
//   不是「预置的生产站点」，也不是任何人的服务器。查它们只会逼人
//   把badge 删掉换纯文字，收益为零。
//   ★ 而且**注释里的举例同样要排除**：install_gate.php 的注释里
//   举了个主机名来说明开放重定向，不剥注释它会把自己判成泄漏。
$leak = [];
$CODE_EXT = ['php', 'html', 'js', 'css', 'json'];
// ★ 扫描范围必须排除备份目录与工具产物。
//   _backup_*/ 里有 52 份历史快照，每份都含当时的配置与探针脚本 ——
//   它们不在暂存区、不进包，对发布没有任何意义，
//   但递归扫描会把它们全捞进来，然后报一堆「泄漏」，
//   而报的那些东西压根不会发布。**一个被噪声淹没的检查等于没有检查。**
$SKIP_DIRS = ['_backup_', '_shots', '_preview', '.git', '.workbuddy', '__pycache__'];

/** 路径里是否含要跳过的目录段（只看相对 root 的部分） */
function selftest_skip_path(string $path, string $root, array $skipDirs): bool
{
    $rel = trim(str_replace('\\', '/', substr($path, strlen($root))), '/');
    if ($rel === '') {
        return false;
    }
    foreach (explode('/', $rel) as $seg) {
        // ★ 必须是**前缀匹配**，不能全等。
        //   目录名是 `_backup_20261004_210029`，而跳过表里写的是 `_backup_` ——
        //   全等匹配永远不成立，剪枝静默失效，然后 52 份历史快照全被扫进来，
        //   报一堆「泄漏」而那些文件压根不会发布。
        //   （踩过：剪枝逻辑写对了，匹配方式错了，于是看起来像没生效。）
        foreach ($skipDirs as $s) {
            if ($s !== '' && strncmp($seg, $s, strlen($s)) === 0) {
                return true;
            }
        }
    }
    return false;
}

// 用 RecursiveCallbackFilterIterator 在**进入目录时**就剪掉整棵子树，
// 而不是遍历到文件后再判断 —— 后者拿不到完整相对路径，
// 早先的写法因此漏掉了备份目录（报出来的文件名还丢了路径前缀，
// 让人误以为是根目录下的文件）。
$dirIter = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
$filter = new RecursiveCallbackFilterIterator($dirIter, function ($cur) use ($root, $SKIP_DIRS) {
    if ($cur->isDir()) {
        return !selftest_skip_path($cur->getPathname(), $root, $SKIP_DIRS);
    }
    return true;
});
$rii = new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::LEAVES_ONLY);
foreach ($rii as $f) {
    if (!$f->isFile() || $f->getSize() > 2 * 1024 * 1024) continue;
    if (!in_array(strtolower($f->getExtension()), $CODE_EXT, true)) continue;
    // ★ config.local.php 必然含生产库信息与域名 —— 那是它的用途。
    //   它已被 .gitignore 排除、永不进包，所以这道检查要跳过它。
    //   （不跳的话：开发目录里恒报红，纯净包里本来就没这个文件，
    //     检查等于永远抓不到任何东西。）
    if ($f->getFilename() === 'config.local.php') continue;
    $txt = (string) @file_get_contents($f->getPathname());
    // 剥掉注释再匹配 —— 只在会被执行的代码里查，才是真风险。
    $txt = preg_replace('~/\*.*?\*/~s', ' ', $txt);
    $txt = preg_replace('~(^|\s)(//|#).*$~m', '$1 ', $txt);
    if (preg_match_all('~\b[a-z0-9][a-z0-9-]{2,}\.(?:com|cn|net|org|cc|io|dev|me|top|xyz)\b~i', $txt, $m)) {
        foreach (array_unique($m[0]) as $dom) {
            $leak[] = $f->getFilename() . ' → ' . $dom;
        }
    }
}
ok(empty($leak), '代码文件里无预制域名' . ($leak ? '：' . implode('；', array_slice($leak, 0, 3)) : ''));

echo "\n[1b] 前台页的安装闸门\n";
// 闸门必须在 bootstrap 之前 require，否则全新站打开首页是 HTTP 500 白屏。
$gateIssues = [];
foreach (['index.php', 'node.php', 'world.php', 'search.php',
          'about.php', 'register.php', 'contributors.php', 'contribute.php'] as $p) {
    $f = $root . '/' . $p;
    if (!is_file($f)) { continue; }
    $lines = preg_split('/\r\n|\r|\n/', (string) file_get_contents($f));
    $g = $b = -1;
    foreach ($lines as $i => $line) {
        $t = ltrim($line);
        if ($t === '' || $t[0] === '/' || $t[0] === '*' || $t[0] === '#') continue;
        if (stripos($t, 'require') === false) continue;
        if ($g < 0 && strpos($t, 'inc/install_gate.php') !== false) $g = $i;
        if ($b < 0 && strpos($t, 'inc/bootstrap.php') !== false) $b = $i;
    }
    if ($g < 0) {
        $gateIssues[] = "$p 未引入 install_gate.php";
    } elseif ($b >= 0 && $g > $b) {
        $gateIssues[] = "$p 闸门在 bootstrap 之后";
    }
}
ok(empty($gateIssues), '前台页均在 bootstrap 之前引入安装闸门'
    . ($gateIssues ? '：' . implode('；', $gateIssues) : ''));

echo "\n[2] 敏感文件不在包内\n";
if ($isPackage) {
    foreach (['cccc.txt', 'data/history.sqlite'] as $f) {
        ok(!is_file($root . '/' . $f), $f);
    }
    foreach (['tools', '.workbuddy'] as $d) {
        ok(!is_dir($root . '/' . $d), $d . '/');
    }
} else {
    echo "  · 跳过 —— 开发目录里 tools/（自检脚本）与配置文件本就该在\n";
}

echo "\n[3] 数据文件完整\n";
require_once $root . '/inc/schema.php';
require_once $root . '/inc/datapack.php';

ok(is_file($root . '/data/seed/manifest.json'), 'manifest.json 存在');
$m = json_decode(file_get_contents($root . '/data/seed/manifest.json'), true);
ok(is_array($m) && ($m['format'] ?? '') === DATAPACK_FORMAT,
    'manifest 格式标识正确：' . ($m['format'] ?? '?') . ' v' . ($m['version'] ?? '?'));

$data = datapack_read($root . '/data/seed');
ok(count($data) === 7, '读到 7 张表（实际 ' . count($data) . '）');

$expect = ['categories' => 16, 'regions' => 11, 'relation_types' => 3,
           'dynasties' => 25, 'cn_events' => 117, 'world_events' => 669, 'relations' => 19];
foreach ($expect as $t => $n) {
    $got = isset($data[$t]) ? count($data[$t]) : -1;
    ok($got === $n, sprintf('%-15s %d 行（期望 %d）', $t, $got, $n));
}

echo "\n[4] 旧 PHP 种子已剔除，且不影响装载\n";
// 开发目录里这些文件还留在磁盘上（只是不再跟踪、不进包），
// 所以这一项只对纯净包有意义 —— 而且它真正的价值是
// **证明 JSON 路径能走通**（下面那行才是关键）。
if ($isPackage) {
    $legacy = glob($root . '/data/seed_part*.php');
    ok(empty($legacy), '包内没有 data/seed_part*.php（' . count($legacy) . ' 个）');
} else {
    echo "  · 旧种子是否在包内：跳过（开发目录保留它们作为历史参考）\n";
}

// 期望行数从 manifest.json 读，**不要写死数字** ——
// 写死过一次（硬编码 669），结果内容一更新自检反而误报失败，
// 而「自检失败」会让人以为包坏了。期望值必须来自数据本身。
$wantWorld = (int) ($m['counts']['world_events'] ?? 0);
$wantCn    = (int) ($m['counts']['cn_events'] ?? 0);

// 关键：此时 datapack_load_default() 必须仍能拿到完整数据
$loaded = datapack_load_default();
ok($wantWorld > 0 && count($loaded['world_events'] ?? []) === $wantWorld,
    'datapack_load_default() 在无 PHP 种子时仍能读到 ' . number_format($wantWorld)
    . ' 条世界大事（实际 ' . number_format(count($loaded['world_events'] ?? [])) . '）');
ok(count($loaded['cn_events'] ?? []) === $wantCn,
    'datapack_load_default() 读到 ' . number_format($wantCn) . ' 个中国节点');

echo "\n[4b] 安装锁不得随包发布\n";
// ★ 带锁的包会「装完发现装不上」：锁在 → install.php 不渲染任何表单 →
//   表没建、没数据、页面只读，**而且它不报错**。这是最容易被忽略的坑。
$lock = $root . '/data/install.lock';
ok(!is_file($lock), '包内没有 data/install.lock'
    . (is_file($lock) ? ' ← ★ 删掉它，否则别人装完会发现安装器是只读的' : ''));

echo "\n[5] 数据校验\n";
$rep = datapack_validate($data, ['strict_fk' => true]);
ok(count($rep['errors']) === 0, '零错误' . (count($rep['errors']) ? '：' . implode('; ', array_slice($rep['errors'], 0, 5)) : ''));
ok(count($rep['warnings']) === 0, '零警告' . (count($rep['warnings']) ? '：' . implode('; ', array_slice($rep['warnings'], 0, 5)) : ''));

// 引用键必须真的在（曾经的静默丢数据点）
$refBad = 0;
foreach (datapack_tables() as $t) {
    $s = datapack_spec()[$t];
    if (empty($s['refs'])) continue;
    foreach ($data[$t] as $r) {
        foreach ($s['refs'] as $f => $rf) {
            if (!array_key_exists($f, $r) || (string) $r[$f] === '') $refBad++;
        }
    }
}
ok($refBad === 0, '跨表引用键全部存在且非空（问题 ' . $refBad . ' 处）');

echo "\n[6] 逐字节完整性（对照 manifest 里的 sha256）\n";
$bad = 0;
foreach ($m['files'] as $name => $info) {
    $p = $root . '/data/seed/' . $name;
    if (!is_file($p)) { echo "  ✗ $name 缺失\n"; $bad++; continue; }
    $h = hash_file('sha256', $p);
    if ($h !== $info['sha256']) { echo "  ✗ $name sha256 不符\n"; $bad++; }
}
ok($bad === 0, '全部 ' . count($m['files']) . ' 个文件 sha256 与 manifest 一致');

echo "\n[7] 管理页在位\n";
foreach (['admin/export.php', 'admin/restore.php', 'admin/import.php', 'admin/index.php'] as $f) {
    ok(is_file($root . '/' . $f), $f);
}

echo "\n" . str_repeat('=', 52) . "\n";
echo $fail ? "✗ 失败 $fail 项 —— 不要发布这个包\n" : "✓ 全部通过，包可发布\n";
exit($fail ? 1 : 0);
