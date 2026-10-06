<?php
/**
 * 增量导入：字典同步 + 世界大事补录
 * ---------------------------------------------------------------------------
 * 用途
 *   安装器 install.php 只能整库安装（会清表重建），不能往**已在运行的站点**
 *   补数据。本脚本解决这个缺口：
 *     1) 把 Schema::categories() / Schema::regions() 的字典与数据库对齐
 *        （缺的插入，已有的更新名称/颜色/排序）—— 幂等，可重复执行
 *     2) 扫描 data/seed_part*.php，把 world_events 里**标题尚不存在**的条目插入
 *
 * 为什么必须同步字典
 *   新增的 category / region（music、philosophy、medicine、astronomy、
 *   education、architecture、southeast_asia、central_asia）只有在字典表里
 *   有记录，前台才显示中文名与颜色；否则会显示 slug 原文或灰色兜底。
 *
 * 去重口径
 *   按 world_events.title 精确匹配。标题已存在则跳过（不更新、不覆盖），
 *   所以本脚本**不会改动任何现有数据**，只做加法。
 *
 * 用法（在站点根目录）
 *   php _import_seed.php --token=执行令牌 [--dry-run]
 *   浏览器访问：_import_seed.php?token=执行令牌[&dry=1]
 *
 * 安全
 *   需 --token 且与下方 TOKEN 常量一致；跑完请立即删除本文件。
 */

define('IMPORT_TOKEN', 'REPLACE_WITH_RANDOM_TOKEN');

$isCli = (PHP_SAPI === 'cli');
$cliToken = null;
$dryRun = false;
foreach ($argv ?? [] as $a) {
    if (strpos($a, '--token=') === 0)  $cliToken = substr($a, 8);
    if ($a === '--dry-run')            $dryRun = true;
}
$token = $isCli ? $cliToken : ($_GET['token'] ?? null);
if (!$isCli && isset($_GET['dry'])) $dryRun = true;

if (!$token || !hash_equals(IMPORT_TOKEN, (string) $token)) {
    http_response_code(403);
    exit("forbidden\n");
}

require __DIR__ . '/inc/bootstrap.php';

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
}

$log = [];
function out($s) { global $log; $log[] = $s; if (PHP_SAPI !== 'cli') { echo $s, "\n"; } }

out('=== 增量导入开始 ' . date('Y-m-d H:i:s') . ($dryRun ? '（DRY RUN，不写库）' : '') . ' ===');

// ---------------------------------------------------------------------------
// 1) 字典同步：categories
// ---------------------------------------------------------------------------
$catAdded = 0; $catUpd = 0;
$i = 0;
foreach (Schema::categories() as $c) {
    $row = DB::fetchAll('SELECT id, name, color FROM categories WHERE slug = ?', [$c['slug']]);
    $sort = $i * 10; $i++;
    if (!$row) {
        if (!$dryRun) {
            DB::exec('INSERT INTO categories (slug, name, color, sort) VALUES (?,?,?,?)',
                [$c['slug'], $c['name'], $c['color'], $sort]);
        }
        $catAdded++;
        out('  + 新增分类 ' . $c['slug'] . '（' . $c['name'] . '）');
    } elseif ($row[0]['name'] !== $c['name'] || $row[0]['color'] !== $c['color']) {
        if (!$dryRun) {
            DB::exec('UPDATE categories SET name = ?, color = ?, sort = ? WHERE slug = ?',
                [$c['name'], $c['color'], $sort, $c['slug']]);
        }
        $catUpd++;
        out('  ~ 更新分类 ' . $c['slug'] . '：' . $row[0]['name'] . ' → ' . $c['name']);
    }
}
out('分类：新增 ' . $catAdded . '，更新 ' . $catUpd);

// ---------------------------------------------------------------------------
// 2) 字典同步：regions
// ---------------------------------------------------------------------------
$regAdded = 0; $regUpd = 0;
$i = 0;
foreach (Schema::regions() as $r) {
    $row = DB::fetchAll('SELECT id, name, emoji FROM regions WHERE slug = ?', [$r['slug']]);
    $sort = $i * 10; $i++;
    if (!$row) {
        if (!$dryRun) {
            DB::exec('INSERT INTO regions (slug, name, emoji, sort) VALUES (?,?,?,?)',
                [$r['slug'], $r['name'], $r['emoji'], $sort]);
        }
        $regAdded++;
        out('  + 新增区域 ' . $r['slug'] . '（' . $r['name'] . '）');
    } elseif ($row[0]['name'] !== $r['name'] || $row[0]['emoji'] !== $r['emoji']) {
        if (!$dryRun) {
            DB::exec('UPDATE regions SET name = ?, emoji = ?, sort = ? WHERE slug = ?',
                [$r['name'], $r['emoji'], $sort, $r['slug']]);
        }
        $regUpd++;
        out('  ~ 更新区域 ' . $r['slug'] . '：' . $row[0]['name'] . ' → ' . $r['name']);
    }
}
out('区域：新增 ' . $regAdded . '，更新 ' . $regUpd);

// ---------------------------------------------------------------------------
// 3) 世界大事补录
// ---------------------------------------------------------------------------
$existing = [];
foreach (DB::fetchAll('SELECT title FROM world_events') as $r) {
    $existing[$r['title']] = true;
}
out('库中现有世界大事 ' . count($existing) . ' 条');

$files = glob(__DIR__ . '/data/seed_part*.php');
sort($files);
$ins = 0; $skip = 0; $byFile = [];

foreach ($files as $f) {
    $part = require $f;
    if (!is_array($part) || empty($part['world_events'])) continue;
    $fn = basename($f);
    foreach ($part['world_events'] as $e) {
        $t = (string) ($e['title'] ?? '');
        if ($t === '') continue;
        if (isset($existing[$t])) { $skip++; continue; }
        if (!$dryRun) {
            DB::exec(
                'INSERT INTO world_events (title, year, year_end, month, day, region, category, place,
                                           summary, detail, figures, importance, source, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?, ' . SQL_NOW . ')',
                [
                    $t,
                    (int) $e['year'],
                    isset($e['year_end']) ? (int) $e['year_end'] : null,
                    isset($e['month']) ? (int) $e['month'] : null,
                    isset($e['day']) ? (int) $e['day'] : null,
                    $e['region'] ?? 'global',
                    $e['category'] ?? 'politics',
                    $e['place'] ?? null,
                    $e['summary'] ?? null,
                    $e['detail'] ?? null,
                    $e['figures'] ?? null,
                    (int) ($e['importance'] ?? 3),
                    $e['source'] ?? null,
                ]
            );
        }
        $existing[$t] = true;
        $ins++;
        $byFile[$fn] = ($byFile[$fn] ?? 0) + 1;
    }
}

out('新增 ' . $ins . ' 条，跳过重复 ' . $skip . ' 条');
foreach ($byFile as $fn => $n) out('    ' . $fn . '  +' . $n);

// ---------------------------------------------------------------------------
// 4) 结果核对
// ---------------------------------------------------------------------------
$total = (int) DB::fetchCol('SELECT COUNT(*) FROM world_events');
out('导入后世界大事总数：' . $total);

$badCat = DB::fetchAll(
    'SELECT DISTINCT w.category FROM world_events w
     LEFT JOIN categories c ON c.slug = w.category WHERE c.id IS NULL');
$badReg = DB::fetchAll(
    'SELECT DISTINCT w.region FROM world_events w
     LEFT JOIN regions r ON r.slug = w.region WHERE r.id IS NULL');
out($badCat ? '!! 未登记的分类：' . implode(', ', array_column($badCat, 'category')) : '分类引用全部有效 OK');
out($badReg ? '!! 未登记的区域：' . implode(', ', array_column($badReg, 'region')) : '区域引用全部有效 OK');

// 覆盖率抽查：每个中国节点 ±5 年内有多少条世界大事
$cn = DB::fetchAll('SELECT title, year FROM cn_events');
$we = DB::fetchAll('SELECT year, COALESCE(year_end, year) AS ye FROM world_events');
$low = 0;
foreach ($cn as $c) {
    $y = (int) $c['year']; $n = 0;
    foreach ($we as $w) {
        if ((int) $w['year'] <= $y + 5 && (int) $w['ye'] >= $y - 5) $n++;
    }
    if ($n < 4) { $low++; }
}
// 注意：. 与 - 同级左结合，拼接里做减法必须加括号，
// 否则 PHP 8 报 Deprecated 且算错（count($cn) - $low . ' / ' 会被解析成 count($cn) - ($low . ' / ')）
$covered = count($cn) - $low;
out('覆盖率：中国节点 ' . count($cn) . ' 个，其中 ±5 年窗口内世界大事 ≥4 条的占 '
    . $covered . ' / ' . count($cn) . '（' . round(100 * $covered / max(1, count($cn))) . '%）');

out('=== 完成 ===');

if ($isCli) {
    echo implode("\n", $log), "\n";
}
