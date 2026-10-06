<?php
/**
 * CSV 批量导入
 * ---------------------------------------------------------------------------
 * 支持两类文件：
 *   cn.csv     中国节点：title, dynasty_slug, year, year_end, month, day, category, place, summary, detail, figures, importance, is_key
 *   world.csv  世界大事：title, year, year_end, month, day, region, category, place, summary, detail, figures, importance, source
 *
 * 行为：
 *   - 标题已存在 → 跳过（除非勾选「覆盖」）
 *   - 提供「下载模板」按钮
 *   - 逐行插入并报告成功/跳过/失败
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_admin();

$PAGE_TITLE = '批量导入';
$ADMIN_NAV  = 'imp';
$result     = null;

// ---------------------------------------------------------------------------
// 下载模板
// ---------------------------------------------------------------------------
if (q('tpl') === 'cn') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="cn_template.csv"');
    echo "\xEF\xBB\xBF"; // BOM，Excel 友好
    echo "title,dynasty_slug,year,year_end,month,day,category,place,summary,detail,figures,importance,is_key\n";
    echo "秦始皇统一六国，建立中央集权帝国,qin,-221,,,,politics,咸阳,十年之间次第灭六国，废分封设郡县。,详细描述可留空,嬴政、李斯,5,1\n";
    echo "蔡伦改进造纸术,dong_han,105,,,,science,长安,树皮麻头破布为原料，纸术成本大降。,,蔡伦,5,1\n";
    exit;
}
if (q('tpl') === 'world') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="world_template.csv"');
    echo "\xEF\xBB\xBF";
    echo "title,year,year_end,month,day,region,category,place,summary,detail,figures,importance,source\n";
    echo "牛顿《自然哲学的数学原理》出版,1687,,,,europe,science,伦敦,万有引力定律统一天上与地上的运动。,,牛顿,5,\n";
    echo "古腾堡活字印刷术,1450,1455,,,,europe,science,美因茨,金属活字使书籍成本骤降。,,古腾堡,5,\n";
    exit;
}

// ---------------------------------------------------------------------------
// 处理上传
// ---------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_FILES['csv'])) {
    $type  = p('type') === 'world' ? 'world' : 'cn';
    $overwrite = isset($_POST['overwrite']);

    if (!csrf_check()) {
        flash('会话已过期，请重试。', 'err');
        header('Location: ' . alink('import.php'));;
        exit;
    }
    $f = $_FILES['csv'];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        flash('上传失败，错误码 ' . $f['error'] . '。', 'err');
        header('Location: ' . alink('import.php'));;
        exit;
    }
    if ($f['size'] > 8 * 1024 * 1024) {
        flash('文件超过 8MB，请拆分后再传。', 'err');
        header('Location: ' . alink('import.php'));;
        exit;
    }
    if (!in_array(strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)), ['csv', 'txt'], true)) {
        flash('只支持 .csv 或 .txt 文件。', 'err');
        header('Location: ' . alink('import.php'));;
        exit;
    }

    // 读入
    $fh = fopen($f['tmp_name'], 'r');
    if (!$fh) {
        flash('无法读取上传的文件。', 'err');
        header('Location: ' . alink('import.php'));;
        exit;
    }
    // 去 BOM
    $bom = fread($fh, 3);
    if ($bom !== "\xEF\xBB\xBF") {
        rewind($fh);
    }

    $header = fgetcsv($fh);
    if (!$header) {
        fclose($fh);
        flash('文件是空的。', 'err');
        header('Location: ' . alink('import.php'));;
        exit;
    }
    $header = array_map(function ($s) {
        return strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $s)));
    }, $header);

    $idx = array_flip($header);
    $dynBySlug = [];
    foreach (all_dynasties() as $d) {
        $dynBySlug[$d['slug']] = (int) $d['id'];
    }
    $validCats = array_keys(dict_categories());
    $validRegs = array_keys(dict_regions());

    $stats = ['ok' => 0, 'skip' => 0, 'err' => 0];
    $rows  = [];

    $lineNo = 1;
    while (($cols = fgetcsv($fh)) !== false) {
        $lineNo++;
        if (count($cols) < 2 || (trim($cols[0] ?? '') === '' && trim($cols[1] ?? '') === '')) {
            continue;
        }
        $get = function (string $key) use ($cols, $idx) {
            $i = $idx[$key] ?? null;
            return ($i === null) ? '' : trim((string) ($cols[$i] ?? ''));
        };
        $title = $get('title');
        if ($title === '') {
            $stats['err']++;
            $rows[] = ['line' => $lineNo, 'status' => 'err', 'msg' => '缺少 title'];
            continue;
        }

        $year = $get('year');
        if ($year === '' || !is_numeric($year)) {
            $stats['err']++;
            $rows[] = ['line' => $lineNo, 'status' => 'err', 'msg' => "「{$title}」year 无效：{$year}"];
            continue;
        }
        $year = (int) $year;
        $yearEnd = $get('year_end');
        $yearEnd = ($yearEnd !== '' && is_numeric($yearEnd)) ? (int) $yearEnd : null;
        if ($yearEnd !== null && $yearEnd < $year) { $yearEnd = $year; }

        $intOrNull = function (string $s) {
            return ($s !== '' && is_numeric($s)) ? (int) $s : null;
        };
        $imp = $get('importance');
        $imp = ($imp !== '' && is_numeric($imp)) ? max(1, min(5, (int) $imp)) : 3;

        try {
            if ($type === 'cn') {
                $slug = $get('dynasty_slug');
                $did  = $dynBySlug[$slug] ?? null;
                if (!$did) {
                    $stats['err']++;
                    $rows[] = ['line' => $lineNo, 'status' => 'err', 'msg' => "「{$title}」朝代 slug 无效：{$slug}"];
                    continue;
                }
                $cat = $get('category');
                if (!in_array($cat, $validCats, true)) { $cat = 'politics'; }

                $exists = DB::fetchOne('SELECT id FROM cn_events WHERE title = ?', [$title]);
                if ($exists && !$overwrite) {
                    $stats['skip']++;
                    $rows[] = ['line' => $lineNo, 'status' => 'skip', 'msg' => "「{$title}」已存在，跳过"];
                    continue;
                }
                $data = [$did, $title, $year, $yearEnd, $intOrNull($get('month')), $intOrNull($get('day')),
                         $cat, $get('place') ?: null, $get('summary') ?: null, $get('detail') ?: null,
                         $get('figures') ?: null, $imp, $get('is_key') === '1' ? 1 : 0];
                if ($exists) {
                    DB::exec(
                        'UPDATE cn_events SET dynasty_id=?,title=?,year=?,year_end=?,month=?,day=?,category=?,
                         place=?,summary=?,detail=?,figures=?,importance=?,is_key=? WHERE id=?',
                        array_merge($data, [(int) $exists['id']])
                    );
                    $rows[] = ['line' => $lineNo, 'status' => 'ok', 'msg' => "更新「{$title}」"];
                } else {
                    DB::exec(
                        'INSERT INTO cn_events (dynasty_id,title,year,year_end,month,day,category,place,summary,detail,figures,importance,is_key, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?, ' . SQL_NOW . ')',
                        $data
                    );
                    $rows[] = ['line' => $lineNo, 'status' => 'ok', 'msg' => "新增「{$title}」"];
                }
            } else {
                $reg = $get('region');
                if (!in_array($reg, $validRegs, true)) { $reg = 'global'; }
                $cat = $get('category');
                if (!in_array($cat, $validCats, true)) { $cat = 'politics'; }

                $exists = DB::fetchOne('SELECT id FROM world_events WHERE title = ?', [$title]);
                if ($exists && !$overwrite) {
                    $stats['skip']++;
                    $rows[] = ['line' => $lineNo, 'status' => 'skip', 'msg' => "「{$title}」已存在，跳过"];
                    continue;
                }
                $data = [$title, $year, $yearEnd, $intOrNull($get('month')), $intOrNull($get('day')),
                         $reg, $cat, $get('place') ?: null, $get('summary') ?: null, $get('detail') ?: null,
                         $get('figures') ?: null, $imp, $get('source') ?: null];
                if ($exists) {
                    DB::exec(
                        'UPDATE world_events SET title=?,year=?,year_end=?,month=?,day=?,region=?,category=?,
                         place=?,summary=?,detail=?,figures=?,importance=?,source=? WHERE id=?',
                        array_merge($data, [(int) $exists['id']])
                    );
                    $rows[] = ['line' => $lineNo, 'status' => 'ok', 'msg' => "更新「{$title}」"];
                } else {
                    DB::exec(
                        'INSERT INTO world_events (title,year,year_end,month,day,region,category,place,summary,detail,figures,importance,source, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?, ' . SQL_NOW . ')',
                        $data
                    );
                    $rows[] = ['line' => $lineNo, 'status' => 'ok', 'msg' => "新增「{$title}」"];
                }
            }
            $stats['ok']++;
        } catch (Throwable $ex) {
            $stats['err']++;
            $rows[] = ['line' => $lineNo, 'status' => 'err', 'msg' => "「{$title}」写入失败：" . $ex->getMessage()];
        }
    }
    fclose($fh);
    $result = ['type' => $type, 'stats' => $stats, 'rows' => $rows];
}

require __DIR__ . '/../inc/admin_header.php';
$cats   = dict_categories();
$validC = array_keys($cats);
$validR = array_keys(dict_regions());
?>

<div class="page-head">
  <h1>批量导入</h1>
  <div class="spacer"></div>
  <a class="btn-s" href="<?= h(alink('cn_events.php', ['action' => 'new'])) ?>">+ 手动新增</a>
</div>

<div class="alert alert-info">
  用 CSV 批量补充内容，比一条条点快得多。<b>title 完全相同</b>的记录会被视为重复（默认跳过，勾选覆盖则更新）。
</div>

<div class="acard">
  <div class="acard-head"><h2>1. 下载模板</h2></div>
  <div class="acard-pad">
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
      <a class="btn-s primary" href="<?= h(alink('import.php', ['tpl' => 'cn'])) ?>">下载中国节点模板</a>
      <a class="btn-s primary" href="<?= h(alink('import.php', ['tpl' => 'world'])) ?>">下载世界大事模板</a>
      <span class="small muted">用 Excel / WPS 打开填写，保存为 CSV（UTF-8）后上传</span>
    </div>
  </div>
</div>

<div class="acard" style="margin-top:16px">
  <div class="acard-head"><h2>2. 上传文件</h2></div>
  <div class="acard-pad">
    <form method="post" enctype="multipart/form-data" class="aform">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <div class="grid-2">
        <div class="afield">
          <label>数据类型</label>
          <select name="type">
            <option value="cn">中国节点</option>
            <option value="world">世界大事</option>
          </select>
        </div>
        <div class="afield">
          <label>CSV 文件</label>
          <input type="file" name="csv" accept=".csv,.txt" required
                 style="width:100%;padding:7px;border:1px dashed #d1d5db;border-radius:7px;background:#fff">
        </div>
      </div>
      <div class="afield">
        <label class="check">
          <input type="checkbox" name="overwrite" value="1">
          标题重复时<b>覆盖更新</b>已有记录（不勾则跳过）
        </label>
      </div>
      <div class="form-actions">
        <button class="btn-s primary" type="submit">开始导入</button>
      </div>
    </form>
  </div>
</div>

<div class="acard" style="margin-top:16px">
  <div class="acard-head"><h2>3. 字段说明</h2></div>
  <div class="acard-pad">
    <h3 style="font-size:14px;margin:0 0 8px">中国节点 CSV</h3>
    <div style="overflow-x:auto">
    <table class="atable">
      <thead><tr><th style="width:150px">字段</th><th style="width:110px">必填</th><th>说明</th></tr></thead>
      <tbody>
        <tr><td class="nowrap"><code>title</code></td><td>是</td><td>事件标题，同时作为去重依据</td></tr>
        <tr><td class="nowrap"><code>dynasty_slug</code></td><td>是</td><td>朝代标识，如 <code>qin</code>、<code>tang</code>、<code>bei_song</code>。在「朝代」页可查</td></tr>
        <tr><td class="nowrap"><code>year</code></td><td>是</td><td>年份，公元前用负数，如 <code>-221</code></td></tr>
        <tr><td class="nowrap"><code>year_end</code></td><td>否</td><td>区间事件的结束年，单点事件留空</td></tr>
        <tr><td class="nowrap"><code>month / day</code></td><td>否</td><td>精确日期，可留空</td></tr>
        <tr><td class="nowrap"><code>category</code></td><td>否</td><td><?= h(implode(' / ', $validC)) ?>。填错则归为 politics</td></tr>
        <tr><td class="nowrap"><code>place</code></td><td>否</td><td>地点</td></tr>
        <tr><td class="nowrap"><code>summary</code></td><td>否</td><td>一句话概述，显示在时间轴卡片上</td></tr>
        <tr><td class="nowrap"><code>detail</code></td><td>否</td><td>详细描述，支持换行（用双引号包裹字段）</td></tr>
        <tr><td class="nowrap"><code>figures</code></td><td>否</td><td>关键人物，顿号分隔</td></tr>
        <tr><td class="nowrap"><code>importance</code></td><td>否</td><td>1–5，默认 3</td></tr>
        <tr><td class="nowrap"><code>is_key</code></td><td>否</td><td>1 = 关键节点（时间轴标红）</td></tr>
      </tbody>
    </table>
    </div>

    <h3 style="font-size:14px;margin:22px 0 8px">世界大事 CSV</h3>
    <div style="overflow-x:auto">
    <table class="atable">
      <thead><tr><th style="width:150px">字段</th><th style="width:110px">必填</th><th>说明</th></tr></thead>
      <tbody>
        <tr><td class="nowrap"><code>title</code></td><td>是</td><td>事件标题</td></tr>
        <tr><td class="nowrap"><code>year</code></td><td>是</td><td>年份，公元前用负数</td></tr>
        <tr><td class="nowrap"><code>year_end</code></td><td>否</td><td>结束年</td></tr>
        <tr><td class="nowrap"><code>region</code></td><td>否</td><td><?= h(implode(' / ', $validR)) ?>。填错则归为 global</td></tr>
        <tr><td class="nowrap"><code>category</code></td><td>否</td><td>同中国节点</td></tr>
        <tr><td class="nowrap"><code>place / summary / detail / figures / importance</code></td><td>否</td><td>同上</td></tr>
        <tr><td class="nowrap"><code>source</code></td><td>否</td><td>来源参考，便于日后核查</td></tr>
      </tbody>
    </table>
    </div>
  </div>
</div>

<?php if ($result): ?>
  <?php
  $s = $result['stats'];
  $total = $s['ok'] + $s['skip'] + $s['err'];
  ?>
  <div class="acard" style="margin-top:16px">
    <div class="acard-head">
      <h2>导入结果 · <?= $result['type'] === 'cn' ? '中国节点' : '世界大事' ?></h2>
      <div class="spacer"></div>
      <span class="small">共 <?= $total ?> 行：</span>
      <span class="pill" style="background:#dcfce7;color:#15803d">成功 <?= $s['ok'] ?></span>
      <span class="pill" style="background:#fef3c7;color:#a16207">跳过 <?= $s['skip'] ?></span>
      <span class="pill" style="background:#fee2e2;color:#b91c1c">失败 <?= $s['err'] ?></span>
    </div>
    <div class="acard-pad" style="max-height:460px;overflow-y:auto">
      <table class="atable">
        <thead><tr><th style="width:64px">行号</th><th style="width:64px">结果</th><th>说明</th></tr></thead>
        <tbody>
        <?php foreach ($result['rows'] as $r): ?>
          <tr>
            <td class="num"><?= (int) $r['line'] ?></td>
            <td>
              <?php if ($r['status'] === 'ok'): ?>
                <span class="pill" style="background:#dcfce7;color:#15803d">成功</span>
              <?php elseif ($r['status'] === 'skip'): ?>
                <span class="pill" style="background:#fef3c7;color:#a16207">跳过</span>
              <?php else: ?>
                <span class="pill" style="background:#fee2e2;color:#b91c1c">失败</span>
              <?php endif; ?>
            </td>
            <td class="small"><?= h($r['msg']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/../inc/admin_footer.php'; ?>
