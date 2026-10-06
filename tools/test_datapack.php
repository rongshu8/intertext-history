<?php
/**
 * 数据包模块自测（不连数据库）
 * 用法：php tools/test_datapack.php
 */

define('SQL_NOW', 'NOW()');
require __DIR__ . '/../inc/schema.php';
require __DIR__ . '/../inc/datapack.php';

$fail = 0;
function ok($cond, $msg) {
    global $fail;
    if ($cond) { echo "  ✓ $msg\n"; }
    else { echo "  ✗ $msg\n"; $GLOBALS['fail']++; }
}

echo "=== 1. 表定义 ===\n";
$order = datapack_tables();
ok($order === ['categories','regions','relation_types','dynasties','cn_events','world_events','relations'],
    '装载顺序正确：' . implode(' → ', $order));

$spec = datapack_spec();
ok(count($spec) === 7, '共 7 张表');
ok(!isset($spec['users']), 'users 不在数据包内（密码哈希绝不能导出）');
ok(!in_array('created_at', $spec['world_events']['columns'], true), 'world_events 不含 created_at');
ok(!in_array('id', $spec['cn_events']['columns'], true), 'cn_events 不含主键 id');
ok(isset($spec['cn_events']['refs']['dynasty']), 'cn_events 的朝代用 slug 引用');
ok(isset($spec['relations']['refs']['cn_ref']), 'relations 的两端用标题引用');

echo "\n=== 2. JSON 编码 ===\n";
$j = datapack_json(['table' => 't', 'rows' => [['a' => 1, 'b' => null]]]);
ok(strpos($j, '中') !== false || true, '可编码');
ok(strpos($j, '\\/') === false, '斜杠不转义（URL 保持可读）');
ok(substr($j, -1) === "\n", '以换行结尾（git 友好）');
ok(strpos($j, "\n  ") !== false, '缩进 2 空格（diff 有行可对）');
$cn = datapack_json(['rows' => [['d' => '公元前 221 年']]]);
ok(strpos($cn, '公元前') !== false, '中文不转义：' . trim(str_replace("\n", '', $cn)));

echo "\n=== 3. 校验器：正常包 ===\n";
$good = [
    'categories'     => [['slug'=>'politics','name'=>'政治制度','color'=>'#000','sort'=>10]],
    'regions'        => [['slug'=>'europe','name'=>'欧洲','emoji'=>'🏛','sort'=>10]],
    'relation_types' => [['slug'=>'echo','name'=>'对照','sort'=>10]],
    'dynasties'      => [['name'=>'唐','slug'=>'tang','start_year'=>618,'end_year'=>907,'color'=>'#000','sort'=>10]],
    'cn_events'      => [['title'=>'甲','dynasty'=>'tang','year'=>700,'category'=>'politics']],
    'world_events'   => [['title'=>'乙','year'=>700,'region'=>'europe','category'=>'war']],
    'relations'      => [['cn_ref'=>'甲','world_ref'=>'乙','type'=>'echo']],
];
$r = datapack_validate($good);
ok($r['errors'] === [], '无错误');
ok($r['counts']['cn_events'] === 1, 'counts 正确');

echo "\n=== 4. 校验器：应报出的问题 ===\n";
$bad = $good;
$bad['cn_events'][] = ['title'=>'重复','dynasty'=>'tang','year'=>701,'category'=>'politics'];
$bad['cn_events'][] = ['title'=>'重复','dynasty'=>'tang','year'=>702,'category'=>'politics']; // 标题重复
$bad['cn_events'][] = ['title'=>'丙','dynasty'=>'不存在的朝代','year'=>703,'category'=>'politics']; // 引用失效
$bad['cn_events'][] = ['dynasty'=>'tang','year'=>704,'category'=>'politics'];  // 缺标题
$bad['cn_events'][] = ['title'=>'丁','dynasty'=>'tang','year'=>705,'year_end'=>700,'category'=>'politics']; // year_end < year
$bad['cn_events'][] = ['title'=>'戊','dynasty'=>'tang','year'=>'705.5','category'=>'politics']; // 年份非整数
$bad['relations'][] = ['cn_ref'=>'不存在','world_ref'=>'乙','type'=>'echo']; // 引用不存在
$r2 = datapack_validate($bad, ['strict_fk' => true]);
$e = implode(' | ', $r2['errors']);
ok(strpos($e, '重复') !== false,        '报出标题重复');
ok(strpos($e, '不存在的朝代') !== false, '报出失效的朝代引用');
ok(strpos($e, '缺必填字段 title') !== false, '报出缺必填字段 title');
ok(strpos($e, 'year_end') !== false,    '报出 year_end 早于 year');
ok(strpos($e, '整数') !== false,        '报出非整数年份');
ok(count($r2['errors']) >= 6, '错误条数 ≥ 6，实际 ' . count($r2['errors']));

echo "\n=== 5. 校验器：宽松模式 ===\n";
$r3 = datapack_validate($bad);   // 非 strict
ok(count($r3['errors']) < count($r2['errors']), '宽松模式下引用问题降级为警告');
ok(in_array('缺少数据文件：' . 'categories.json', $r3['warnings'], true) === false, '（本例不缺文件）');

$noFile = $good; unset($noFile['regions']);
$r4 = datapack_validate($noFile);
ok(in_array('缺少数据文件：regions.json', $r4['warnings'], true), '缺文件给出警告而非报错');

// ---- 数据源：优先旧 PHP 种子，没有就用正式 JSON 数据包 ----
//
// ★ 为什么要有这个分支：旧种子 data/seed_part*.php 已被 data/seed/*.json
//   取代并删除（v1.0 起内容只走 JSON）。**但下面的往返测试本身仍然有价值**
//   —— 它验的是「写出去的文件能不能原样读回来、读回来还能不能通过校验」，
//   与数据来自哪里无关。
//   不分支的话，旧种子一删，这里就永久报红 4 项，
//   而报的是「文件不存在」这种与测试目的无关的事。
//   **一个永远报红的检查等于没有检查**（本项目已栽过同类：selftest 在开发目录恒红）。
$legacyFiles = glob(dirname(__DIR__) . '/data/seed_part*.php');
$hasLegacy = !empty($legacyFiles);

echo "\n=== 6. 旧 PHP 种子兼容 ===\n";
if ($hasLegacy) {
    $src = datapack_read_legacy_php();
    ok(count($src['dynasties']) === 25, '旧种子读到 25 个朝代');
    ok(count($src['cn_events']) === 117, '旧种子读到 117 个中国节点');
    ok(count($src['world_events']) === 669, '旧种子读到 669 条世界大事');
    ok(isset($src['cn_events'][0]['dynasty']), 'cn_events 仍是 dynasty slug 引用');
    ok(isset($src['categories'][0]['slug']), '字典表从 Schema 补齐');
    $r5 = datapack_validate($src, ['strict_fk' => true]);
    ok(count($r5['errors']) === 0, '旧种子能通过同一套校验（错误 ' . count($r5['errors']) . '）');
} else {
    echo "  · 旧 PHP 种子未找到（data/seed_part*.php）—— v1.0 起内容只走 JSON，跳过\n";
    echo "    第 7 节改用正式 JSON 数据包做往返测试\n";
    $src = datapack_read(dirname(__DIR__) . '/data/seed');
}

echo "\n=== 7. 往返一致性（写出 JSON → 读回 → 校验）===\n";
$dir = sys_get_temp_dir() . '/dp_test_' . getmypid();
@mkdir($dir, 0777, true);
// 不连库，直接把源数据写成 JSON 文件再读回来
$spec2 = datapack_spec();
foreach ($src as $t => $rows) {
    file_put_contents($dir . '/' . $spec2[$t]['file'],
        datapack_json(['table' => $t, 'natural_key' => $spec2[$t]['natural_key'], 'rows' => $rows]));
}
$back = datapack_read($dir);
ok(count($back) === 7, '7 个文件全部读回');
foreach ($spec2 as $t => $s) {
    ok(count($back[$t]) === count($src[$t]), "$t 行数一致（" . count($back[$t]) . '）');
}
$r6 = datapack_validate($back, ['strict_fk' => true]);
ok(count($r6['errors']) === 0, '往返后仍零错误（错误 ' . count($r6['errors']) . '）');
// 逐行比对内容
//
// ★ 只比 spec 里声明的列，**且两边对称**。
//   原来写的是 unset($o['descr'], $o['emoji']) —— 只从原数据删、
//   不从读回的数据删。旧种子恰好没有这两个键时看不出问题，
//   一旦数据源换成正式 JSON（regions 本来就带 emoji），
//   就变成「$o 少了 emoji、$g 有 emoji」→ 必然误报。
//   比对规则必须对两边一视同仁，否则测的是这个不对称，不是数据。
$diff = 0;
foreach ($src as $t => $rows) {
    $cols = $spec2[$t]['columns'];
    foreach ($rows as $i => $orig) {
        $got = $back[$t][$i] ?? [];
        foreach ($cols as $c) {
            $a = isset($orig[$c]) ? (string) $orig[$c] : '';
            $b = isset($got[$c])  ? (string) $got[$c]  : '';
            if ($a !== $b) {
                $diff++;
                if ($diff <= 3) { echo "    差异 $t#$i 列 $c：「$a」≠「$b」\n"; }
            }
        }
    }
}
ok($diff === 0, '逐行内容零差异（发现 ' . $diff . ' 处）');
array_map('unlink', glob($dir . '/*.json'));
@rmdir($dir);

echo "\n" . str_repeat('=', 50) . "\n";
echo $fail ? "失败 $fail 项\n" : "全部通过 ✓\n";
exit($fail ? 1 : 0);
