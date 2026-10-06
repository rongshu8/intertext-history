<?php
/**
 * 后台概览
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_admin();

$PAGE_TITLE = '概览';
$ADMIN_NAV  = 'dash';
require __DIR__ . '/../inc/admin_header.php';

$stats = site_stats();
$counts = dynasty_event_counts();

$recentCn = DB::fetchAll(
    'SELECT e.*, d.name AS dynasty_name, d.color AS dynasty_color
     FROM cn_events e LEFT JOIN dynasties d ON d.id = e.dynasty_id
     ORDER BY e.created_at DESC, e.id DESC LIMIT 8'
);
$recentWe = DB::fetchAll('SELECT * FROM world_events ORDER BY created_at DESC, id DESC LIMIT 8');

// 时间轴覆盖情况
$coverage = DB::fetchAll(
    'SELECT d.id, d.name, d.color, d.start_year, d.end_year, d.sort,
            (SELECT COUNT(*) FROM cn_events e WHERE e.dynasty_id = d.id) AS c
     FROM dynasties d ORDER BY d.sort'
);
$withData = array_filter($coverage, fn($d) => (int) $d['c'] > 0);
?>

<div class="page-head">
  <h1>概览</h1>
  <div class="spacer"></div>
  <a class="btn-s primary" href="<?= h(alink('cn_events.php', ['action' => 'new'])) ?>">+ 新增中国节点</a>
  <a class="btn-s primary" href="<?= h(alink('world_events.php', ['action' => 'new'])) ?>">+ 新增世界大事</a>
</div>

<div class="stat-grid">
  <div class="stat"><div class="n"><?= number_format($stats['cn']) ?></div><div class="l">中国节点</div></div>
  <div class="stat"><div class="n"><?= number_format($stats['world']) ?></div><div class="l">世界大事</div></div>
  <div class="stat"><div class="n"><?= number_format($stats['dynasties']) ?></div><div class="l">朝代分期</div></div>
  <div class="stat"><div class="n"><?= number_format($stats['relations']) ?></div><div class="l">中外联动</div></div>
  <div class="stat">
    <div class="n" style="font-size:17px"><?= h(fmt_year($stats['from_year'], false)) ?>–<?= h(fmt_year($stats['to_year'], false)) ?></div>
    <div class="l">覆盖年代</div>
  </div>
</div>

<div class="acard">
  <div class="acard-head">
    <h2>时间轴覆盖情况</h2>
    <div class="spacer"></div>
    <span class="small muted"><?= count($withData) ?> / <?= count($coverage) ?> 个朝代已有节点</span>
  </div>
  <div class="acard-pad">
    <?php
    $maxCount = max(1, max(array_map(fn($d) => (int) $d['c'], $coverage)));
    ?>
    <?php foreach ($coverage as $d): ?>
      <div style="display:grid;grid-template-columns:110px 1fr 56px;gap:12px;align-items:center;margin-bottom:7px">
        <div class="small" style="white-space:nowrap">
          <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?= h($d['color']) ?>;margin-right:6px"></span>
          <?= h($d['name']) ?>
        </div>
        <div style="height:9px;background:#f1f3f5;border-radius:999px;overflow:hidden">
          <div style="height:100%;width:<?= round((int) $d['c'] / $maxCount * 100) ?>%;background:<?= h($d['color']) ?>;border-radius:999px"></div>
        </div>
        <div class="small num" style="text-align:right;color:#6b7280"><?= (int) $d['c'] ?> 条</div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="grid-2" style="margin-top:16px;display:grid;grid-template-columns:1fr 1fr;gap:16px">
  <div class="acard">
    <div class="acard-head">
      <h2>最近新增的中国节点</h2>
      <div class="spacer"></div>
      <a class="btn-s" href="<?= h(alink('cn_events.php')) ?>">全部</a>
    </div>
    <div class="acard-pad">
      <?php if (!$recentCn): ?><div class="aempty">还没有数据</div><?php endif; ?>
      <?php foreach ($recentCn as $e): ?>
        <div style="padding:9px 0;border-bottom:1px solid #f0f2f4">
          <div style="display:flex;gap:9px;align-items:baseline">
            <span class="num small muted" style="min-width:78px"><?= h(fmt_year_range((int) $e['year'], !empty($e['year_end']) ? (int) $e['year_end'] : null, false)) ?></span>
            <a href="<?= h(alink('cn_events.php', ['action' => 'edit', 'id' => $e['id']])) ?>" style="font-weight:600;color:#1f2937"><?= h($e['title']) ?></a>
          </div>
          <div class="t-sum" style="margin-left:87px"><?= h($e['summary'] ?? '') ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="acard">
    <div class="acard-head">
      <h2>最近新增的世界大事</h2>
      <div class="spacer"></div>
      <a class="btn-s" href="<?= h(alink('world_events.php')) ?>">全部</a>
    </div>
    <div class="acard-pad">
      <?php if (!$recentWe): ?><div class="aempty">还没有数据</div><?php endif; ?>
      <?php foreach ($recentWe as $w): ?>
        <div style="padding:9px 0;border-bottom:1px solid #f0f2f4">
          <div style="display:flex;gap:9px;align-items:baseline">
            <span class="num small muted" style="min-width:78px"><?= h(fmt_year_range((int) $w['year'], !empty($w['year_end']) ? (int) $w['year_end'] : null, false)) ?></span>
            <a href="<?= h(alink('world_events.php', ['action' => 'edit', 'id' => $w['id']])) ?>" style="font-weight:600;color:#1f2937"><?= h($w['title']) ?></a>
          </div>
          <div class="t-sum" style="margin-left:87px"><?= h($w['summary'] ?? '') ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../inc/admin_footer.php'; ?>
