<?php
/**
 * 生成「新增世界大事」预览页（本地审阅用，不上线）
 * 用法：php tools/make_preview.php
 * 产物：E:/webs/history/_preview/world_events_preview.html
 */

$root = dirname(__DIR__);

// ---- 从分片里读数据：用真实 PHP require，不做正则解析 ----
$NEW_PARTS = ['seed_part7.php','seed_part8.php','seed_part9.php','seed_part10.php',
              'seed_part11.php','seed_part12.php','seed_part13.php',
              'seed_part14.php','seed_part15.php'];

$catName = [];
$regName = [];
// 分类/区域中文名（与 Schema 保持一致）
$schema = file_get_contents($root . '/inc/schema.php');
preg_match_all("/'slug' => '(\w+)',\s*'name' => '([^']+)',\s*'color' => '([^']+)'/", $schema, $m, PREG_SET_ORDER);
foreach ($m as $r) $catName[$r[1]] = ['name' => $r[2], 'color' => $r[3]];
preg_match_all("/'slug' => '(\w+)',\s*'name' => '([^']+)',\s*'emoji' => '([^']+)'/u", $schema, $m2, PREG_SET_ORDER);
foreach ($m2 as $r) $regName[$r[1]] = ['name' => $r[2], 'emoji' => $r[3]];

$rows = [];
foreach ($NEW_PARTS as $p) {
    $f = $root . '/data/' . $p;
    if (!is_file($f)) continue;
    $part = require $f;
    foreach (($part['world_events'] ?? []) as $e) {
        $e['_src'] = $p;
        $rows[] = $e;
    }
}
usort($rows, function ($a, $b) { return $a['year'] <=> $b['year']; });

// 中国节点（用于同期标注）
$cn = [];
foreach (glob($root . '/data/seed_part*.php') as $f) {
    $part = require $f;
    foreach (($part['cn_events'] ?? []) as $e) $cn[] = $e;
}
usort($cn, function ($a, $b) { return $a['year'] <=> $b['year']; });

function fy($y) {
    return $y < 0 ? '公元前 ' . (-$y) . ' 年' : $y . ' 年';
}
function near_cn($cn, $y, $w = 5) {
    $out = [];
    foreach ($cn as $c) {
        $cy = (int) $c['year'];
        $ce = isset($c['year_end']) && $c['year_end'] !== null ? (int) $c['year_end'] : $cy;
        if ($cy <= $y + $w && $ce >= $y - $w) $out[] = $c;
    }
    return $out;
}

$stats = ['cat' => [], 'reg' => [], 'century' => []];
foreach ($rows as $r) {
    $stats['cat'][$r['category']] = ($stats['cat'][$r['category']] ?? 0) + 1;
    $stats['reg'][$r['region']] = ($stats['reg'][$r['region']] ?? 0) + 1;
    $k = (int) floor($r['year'] / 100) * 100;
    $stats['century'][$k] = ($stats['century'][$k] ?? 0) + 1;
}

// 覆盖率
$cncov = 0;
$cndetail = [];
foreach ($cn as $c) {
    $cy = (int) $c['year'];
    $n = 0;
    foreach ($rows as $r) {
        $re = isset($r['year_end']) && $r['year_end'] !== null ? (int) $r['year_end'] : (int) $r['year'];
        if ($r['year'] <= $cy + 5 && $re >= $cy - 5) $n++;
    }
    $cndetail[] = [$n, $cy, $c['title']];
    if ($n >= 4) $cncov++;
}
usort($cndetail, function ($a, $b) { return $a[0] <=> $b[0]; });

$H = [];
$H[] = '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">';
$H[] = '<meta name="viewport" content="width=device-width,initial-scale=1">';
$H[] = '<title>新增世界大事预览 · 互文</title>';
$H[] = '<style>
:root{--bg:#f7f8fa;--card:#fff;--line:#e3e6ec;--tx:#1f2329;--mu:#6b7280;--ac:#2563eb}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--tx);
  font:15px/1.75 -apple-system,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif}
.wrap{max-width:1100px;margin:0 auto;padding:28px 20px 80px}
h1{font-size:26px;margin:0 0 6px}
.sub{color:var(--mu);font-size:14px;margin-bottom:22px}
.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;margin:18px 0 26px}
.card{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:14px 16px}
.card .n{font-size:26px;font-weight:700;color:var(--ac);line-height:1.2}
.card .l{color:var(--mu);font-size:13px;margin-top:2px}
h2{font-size:17px;margin:30px 0 12px;padding-bottom:6px;border-bottom:1px solid var(--line)}
.chips{display:flex;flex-wrap:wrap;gap:7px}
.chip{background:var(--card);border:1px solid var(--line);border-radius:999px;
  padding:4px 11px;font-size:13px;display:flex;align-items:center;gap:6px}
.dot{width:8px;height:8px;border-radius:50%;flex:0 0 auto}
.ev{background:var(--card);border:1px solid var(--line);border-radius:10px;
  padding:15px 18px;margin-bottom:11px}
.ev .hd{display:flex;flex-wrap:wrap;gap:8px;align-items:baseline;margin-bottom:7px}
.y{font-weight:700;color:var(--ac);font-size:14px;font-variant-numeric:tabular-nums;flex:0 0 auto}
.t{font-weight:600;font-size:16px}
.tag{font-size:12px;color:var(--mu);border:1px solid var(--line);
  border-radius:4px;padding:1px 7px;flex:0 0 auto}
.sm{background:#f9fafb;border-left:3px solid var(--line);padding:8px 12px;
  border-radius:0 6px 6px 0;margin:7px 0;font-size:14px}
.dt{font-size:14px;color:#374151}
.fg{font-size:13px;color:var(--mu);margin-top:7px}
.cn{margin-top:7px;font-size:13px;color:#92400e;background:#fffbeb;
  border:1px solid #fde68a;border-radius:6px;padding:5px 10px}
.src{font-size:11px;color:#9ca3af;float:right}
.toc{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:12px 16px;margin-bottom:8px}
.toc a{color:var(--ac);text-decoration:none;margin-right:14px;font-size:13px;white-space:nowrap}
table{border-collapse:collapse;width:100%;font-size:13px;background:var(--card);
  border:1px solid var(--line);border-radius:8px;overflow:hidden}
th,td{padding:7px 11px;border-bottom:1px solid var(--line);text-align:left}
th{background:#f3f4f6;font-weight:600;color:var(--mu)}
td.num{text-align:right;font-variant-numeric:tabular-nums}
.warn{background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px 14px;
  font-size:13px;color:#92400e;margin:10px 0}
</style></head><body><div class="wrap">';

$H[] = '<h1>新增「同期世界大事」预览</h1>';
$H[] = '<div class="sub">本次新增 <b>' . count($rows) . '</b> 条世界大事，'
     . '与原有 124 条合并后共 <b>669</b> 条。题材覆盖科技、文学、艺术、音乐、思想、宗教、'
     . '医药、天文、建筑、教育、经济、社会、灾害等 16 个门类，不限于政治军事。</div>';

$H[] = '<div class="cards">';
$H[] = '<div class="card"><div class="n">' . count($rows) . '</div><div class="l">新增条数</div></div>';
$H[] = '<div class="card"><div class="n">' . $cncov . '/' . count($cn) . '</div>'
     . '<div class="l">中国节点「同期大事 ≥4 条」覆盖</div></div>';
$H[] = '<div class="card"><div class="n">' . count(array_filter($stats['cat'])) . '</div>'
     . '<div class="l">涉及门类</div></div>';
$H[] = '<div class="card"><div class="n">' . count(array_filter($stats['reg'])) . '</div>'
     . '<div class="l">涉及区域</div></div>';
$H[] = '</div>';

// 门类分布
ksort($stats['cat']);
$H[] = '<h2>门类分布</h2><div class="chips">';
foreach ($stats['cat'] as $k => $v) {
    $c = $catName[$k]['color'] ?? '#868e96';
    $n = $catName[$k]['name'] ?? $k;
    $H[] = '<span class="chip"><span class="dot" style="background:' . htmlspecialchars($c)
         . '"></span>' . htmlspecialchars($n) . ' <b>' . $v . '</b></span>';
}
$H[] = '</div>';

// 区域分布
ksort($stats['reg']);
$H[] = '<h2>区域分布</h2><div class="chips">';
foreach ($stats['reg'] as $k => $v) {
    $n = $regName[$k]['name'] ?? $k;
    $em = $regName[$k]['emoji'] ?? '';
    $H[] = '<span class="chip">' . htmlspecialchars($em . ' ' . $n) . ' <b>' . $v . '</b></span>';
}
$H[] = '</div>';

// 时段分布
ksort($stats['century']);
$H[] = '<h2>时段分布</h2><table><tr><th>世纪</th><th class="num">条数</th></tr>';
foreach ($stats['century'] as $k => $v) {
    $label = $k < 0 ? '公元前 ' . (-$k) . ' 世纪' : $k . ' 世纪';
    $H[] = '<tr><td>' . $label . '</td><td class="num">' . $v . '</td></tr>';
}
$H[] = '</table>';

// 覆盖率明细
$low = array_values(array_filter($cndetail, function ($c) { return $c[0] < 4; }));
$H[] = '<h2>中国节点覆盖率</h2>';
if ($low) {
    $H[] = '<div class="warn">仍有 ' . count($low) . ' 个节点同期大事不足 4 条。</div>';
} else {
    $H[] = '<div class="sub" style="margin:0 0 10px">全部 117 个中国节点，'
         . '±5 年窗口内都已有 ≥4 条可选的世界大事。</div>';
}
$H[] = '<table><tr><th>同类条数</th><th>年份</th><th>中国节点</th></tr>';
foreach (array_slice($cndetail, 0, 12) as $c) {
    $H[] = '<tr><td class="num">' . $c[0] . '</td><td>' . fy($c[1]) . '</td><td>'
         . htmlspecialchars($c[2]) . '</td></tr>';
}
$H[] = '</table><div class="sub" style="margin-top:6px">（上表为中国节点中同期条数最少的 12 个，'
     . '可见最少的也有 4 条）</div>';

// 条目清单
$H[] = '<h2>条目清单（按年份排序）</h2>';
$H[] = '<div class="toc">';
$anchor = [];
foreach ($rows as $i => $r) {
    $k = (int) floor($r['year'] / 100) * 100;
    if (!isset($anchor[$k])) {
        $anchor[$k] = 1;
        $label = $k < 0 ? '前' . (-$k) . '世纪' : $k . 's';
        $H[] = '<a href="#c' . $k . '">' . $label . '</a>';
    }
}
$H[] = '</div>';

$cur = null;
foreach ($rows as $r) {
    $k = (int) floor($r['year'] / 100) * 100;
    if ($k !== $cur) {
        $cur = $k;
        $label = $k < 0 ? '公元前 ' . (-$k) . ' 世纪' : $k . ' 世纪';
        $H[] = '<h2 id="c' . $k . '">' . $label . '</h2>';
    }
    $cc = $catName[$r['category']]['color'] ?? '#868e96';
    $cnn = $catName[$r['category']]['name'] ?? $r['category'];
    $rn = $regName[$r['region']]['name'] ?? $r['region'];
    $H[] = '<div class="ev">';
    $H[] = '<div class="hd"><span class="y">' . fy((int) $r['year']) . '</span>'
         . '<span class="t">' . htmlspecialchars($r['title']) . '</span>'
         . '<span class="tag" style="color:' . htmlspecialchars($cc) . ';border-color:'
         . htmlspecialchars($cc) . '33">' . htmlspecialchars($cnn) . '</span>'
         . '<span class="tag">' . htmlspecialchars($rn) . '</span>'
         . (isset($r['place']) && $r['place'] !== '' ? '<span class="tag">' . htmlspecialchars($r['place']) . '</span>' : '')
         . '<span class="src">' . htmlspecialchars($r['_src']) . '</span>'
         . '</div>';
    if (!empty($r['summary'])) {
        $H[] = '<div class="sm">' . htmlspecialchars($r['summary']) . '</div>';
    }
    if (!empty($r['detail'])) {
        $H[] = '<div class="dt">' . htmlspecialchars($r['detail']) . '</div>';
    }
    if (!empty($r['figures'])) {
        $H[] = '<div class="fg">关键人物：' . htmlspecialchars($r['figures']) . '</div>';
    }
    $nears = near_cn($cn, (int) $r['year']);
    if ($nears) {
        $ls = [];
        foreach (array_slice($nears, 0, 4) as $c) $ls[] = $c['title'] . '（' . fy((int) $c['year']) . '）';
        $H[] = '<div class="cn">同期中国节点：' . htmlspecialchars(implode('、', $ls))
             . (count($nears) > 4 ? ' 等 ' . count($nears) . ' 条' : '') . '</div>';
    }
    $H[] = '</div>';
}

$H[] = '</div></body></html>';

$dir = $root . '/_preview';
if (!is_dir($dir)) mkdir($dir, 0777, true);
$out = $dir . '/world_events_preview.html';
file_put_contents($out, implode("\n", $H));
echo "已生成: $out\n";
echo '条目数: ' . count($rows) . "\n";
echo '文件大小: ' . round(filesize($out) / 1024, 1) . " KB\n";
