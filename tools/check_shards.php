<?php
/**
 * 世界大事数据校验（真实 PHP 解析版）
 * ---------------------------------------------------------------------------
 * 用 require 真正加载分片，再按 world_events 规范逐条断言。
 * 比正则可靠得多 —— 正则做过三次，每次都误判。
 *
 * 注意：本项目便携版 PHP 无 mbstring（已知限制），已内置兜底实现。
 *       字符串拼接一律用 . 而非插值 —— 因为「$var）全角字符」会被 PHP
 *       当成变量名的一部分（全角字节在标识符合法范围内），插值必然翻车。
 *
 * 用法：php tools/check_shards.php
 */

$root = dirname(__DIR__);

if (!function_exists('mb_strlen')) {
    function mb_strlen($s, $enc = null) { return preg_match_all('/./us', $s); }
}

$VALID_REGION = ['east_asia','southeast_asia','south_asia','west_asia','central_asia',
                 'europe','north_america','latam','africa','oceania','global'];
$VALID_CAT = ['politics','war','science','literature','culture','music','philosophy',
              'religion','explore','economy','society','medicine','astronomy',
              'education','architecture','disaster'];
$REQUIRED = ['title','year','region','category','summary','detail'];

$files = glob($root . '/data/seed_part*.php');
sort($files);

$all = []; $errs = 0; $warns = 0; $totWe = 0;
$byCat = []; $byRegion = []; $byCentury = []; $we = []; $cn = [];

foreach ($files as $f) {
    $name = basename($f);
    $part = require $f;
    if (!is_array($part)) { echo "!! $name 未返回数组\n"; $errs++; continue; }

    $n = 0;
    $list = isset($part['world_events']) && is_array($part['world_events']) ? $part['world_events'] : [];
    foreach ($list as $i => $e) {
        $n++; $totWe++;
        $tag = $name . '#' . $i;
        $t = isset($e['title']) ? $e['title'] : '?';
        $pd = '(' . $t . ')';

        foreach ($REQUIRED as $k) {
            if (!isset($e[$k]) || $e[$k] === '') {
                echo '!! [' . $tag . '] 缺字段 ' . $k . $pd . "\n"; $errs++;
            }
        }
        if (!isset($all[$t])) $all[$t] = [];
        $all[$t][] = $tag;

        $r = isset($e['region']) ? $e['region'] : '';
        if ($r !== '' && !in_array($r, $VALID_REGION, true)) {
            echo '!! [' . $tag . '] 非法 region: ' . $r . $pd . "\n"; $errs++;
        }
        $c = isset($e['category']) ? $e['category'] : '';
        if ($c !== '' && !in_array($c, $VALID_CAT, true)) {
            echo '!! [' . $tag . '] 非法 category: ' . $c . $pd . "\n"; $errs++;
        }

        $y = isset($e['year']) ? $e['year'] : null;
        if (!is_int($y) || $y < -3000 || $y > 2030) {
            echo '!! [' . $tag . '] 年份越界: ' . var_export($y, true) . $pd . "\n"; $errs++;
        }
        if (isset($e['year_end']) && $e['year_end'] !== null) {
            $ye = $e['year_end'];
            if (!is_int($ye) || $ye < $y) {
                echo '!! [' . $tag . '] year_end 异常: ' . var_export($ye, true) . $pd . "\n"; $errs++;
            }
        }
        $imp = isset($e['importance']) ? $e['importance'] : 3;
        if (!is_int($imp) || $imp < 1 || $imp > 5) {
            echo '!! [' . $tag . '] importance 越界: ' . var_export($imp, true) . $pd . "\n"; $errs++;
        }

        $d = isset($e['detail']) ? (string) $e['detail'] : '';
        $s = isset($e['summary']) ? (string) $e['summary'] : '';
        $both = $s . $d;

        if (preg_match('/[\x{4e00}-\x{9fff}][,.]/u', $d, $m)) {
            echo '!! [' . $tag . '] detail 半角标点「' . $m[0] . '」' . $pd . "\n"; $errs++;
        }
        if (preg_match('/[們來時說學會發現實現點無為兒動務經濟產業關於權變邊書寫覺聽語辭農產營養護衛隨機觀點討論議題體現義務內圖號]/u', $both, $m)) {
            echo '!! [' . $tag . '] 含繁体字「' . $m[0] . '」' . $pd . "\n"; $errs++;
        }
        if (preg_match('/[\x{4e00}-\x{9fff}]([A-Za-z]{3,})[\x{4e00}-\x{9fff}]/u', $both, $m)) {
            echo '?? [' . $tag . '] 中文夹英文「' . $m[1] . '」' . $pd . "\n"; $warns++;
        }
        $dl = mb_strlen($d, 'UTF-8');
        if ($dl < 110) {
            echo '?? [' . $tag . '] detail 偏短 ' . $dl . ' 字' . $pd . "\n"; $warns++;
        }

        $byCat[$c] = (isset($byCat[$c]) ? $byCat[$c] : 0) + 1;
        $byRegion[$r] = (isset($byRegion[$r]) ? $byRegion[$r] : 0) + 1;
        $k2 = (int) floor($y / 100) * 100;
        $byCentury[$k2] = (isset($byCentury[$k2]) ? $byCentury[$k2] : 0) + 1;
        $we[] = [$y, isset($e['year_end']) && $e['year_end'] !== null ? (int) $e['year_end'] : $y];
    }
    printf("OK  %-20s world=%d\n", $name, $n);

    foreach ((isset($part['cn_events']) && is_array($part['cn_events']) ? $part['cn_events'] : []) as $e) {
        $cn[] = $e;
    }
}

echo "\n----- world_events 合计: " . $totWe . " -----\n";

$dup = [];
foreach ($all as $t => $v) { if (count($v) > 1) $dup[$t] = $v; }
if ($dup) {
    echo '!! 重复标题 ' . count($dup) . " 个:\n";
    foreach ($dup as $t => $v) { echo '   ' . $t . '  <- ' . implode(', ', $v) . "\n"; }
    $errs += count($dup);
} else {
    echo "无重复标题 OK\n";
}

echo 'cn_events 合计: ' . count($cn) . "\n";

$W = 5;
$cov = [];
foreach ($cn as $e) {
    $y = (int) $e['year']; $n = 0;
    foreach ($we as $w) { if ($w[0] <= $y + $W && $w[1] >= $y - $W) $n++; }
    $cov[] = [$n, $y, $e['title']];
}
sort($cov);
$lo = []; $ok4 = 0;
foreach ($cov as $c) { if ($c[0] < 4) $lo[] = $c; else $ok4++; }
printf("\n覆盖率（+-%d 年）：>=4 条的中国节点 %d / %d（%.0f%%）\n",
    $W, $ok4, count($cov), 100 * $ok4 / max(1, count($cov)));
echo '不足 4 条的 ' . count($lo) . " 个（最多列 40）:\n";
foreach (array_slice($lo, 0, 40) as $c) {
    printf("  %2d 条  %6d  %s\n", $c[0], $c[1], $c[2]);
}

echo "\n分类分布:\n"; ksort($byCat);
foreach ($byCat as $k => $v) printf("  %-14s %3d\n", $k, $v);
echo "区域分布:\n"; ksort($byRegion);
foreach ($byRegion as $k => $v) printf("  %-16s %3d\n", $k, $v);

echo "\n硬性问题: " . $errs . " 个；提示: " . $warns . " 个\n";
exit($errs ? 1 : 0);
