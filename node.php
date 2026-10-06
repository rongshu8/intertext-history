<?php
/**
 * 节点详情 · 同期世界大事
 * ---------------------------------------------------------------------------
 * 这是本项目的核心页面：
 *   左：某个中国历史节点的完整信息
 *   右：该节点时间窗口（默认 ±5 年，可调）内，全世界发生的大事
 *   下：中外联动关系、上一条 / 下一条
 *
 * URL 参数：
 *   id=123              节点 ID（必填）
 *   window=5            时间窗口年数，默认读配置 sync.window
 *   region=europe       按区域筛选
 *   cat=politics        按主题筛选
 */

// 未安装时导向安装向导（必须在 bootstrap 之前：那时数据库还连不上）
require_once __DIR__ . '/inc/install_gate.php';
require __DIR__ . '/inc/bootstrap.php';

$PAGE_TITLE = '节点详情';
$ACTIVE     = 'index';

$id     = qi('id');
$event  = $id > 0 ? get_cn_event($id) : null;

if (!$event) {
    http_response_code(404);
    $PAGE_TITLE = '未找到';
    require __DIR__ . '/inc/layout_header.php';
    echo '<div class="empty" style="padding:80px 20px">节点不存在或已被删除。<br><br>'
       . '<a class="btn" href="' . h(link_to('index.php')) . '">← 返回时间轴</a></div>';
    require __DIR__ . '/inc/layout_footer.php';
    exit;
}

$PAGE_TITLE = $event['title'];

$year    = (int) $event['year'];
$yearEnd = !empty($event['year_end']) ? (int) $event['year_end'] : null;
$window  = max(1, min(50, qi('window', c_sync_window())));
$region  = q('region');
$catF    = q('cat');

$opt = ['window' => $window];
if ($region) $opt['region'] = $region;
if ($catF)   $opt['category'] = $catF;

$world      = world_events_near($year, $yearEnd, $opt);
$totalWorld = world_events_near($year, $yearEnd, ['window' => $window, 'limit' => 9999]);
$relations  = relations_of_cn($id);
$neighbours = cn_event_neighbours($id);
$dyn        = dynasty_by_id((int) $event['dynasty_id']);
$catColor   = cat_color((string) $event['category']);

// 按区域分组
$byRegion = [];
foreach ($world as $w) {
    $byRegion[(string) $w['region']][] = $w;
}
// 区域排序：按字典顺序，global 排最后
$regionOrder = array_keys(dict_regions());
uksort($byRegion, function ($a, $b) use ($regionOrder) {
    $ia = array_search($a, $regionOrder, true);
    $ib = array_search($b, $regionOrder, true);
    $ia = $ia === false ? 99 : $ia;
    $ib = $ib === false ? 99 : $ib;
    return $ia <=> $ib;
});

$relTypes = dict_relation_types();
$cats     = dict_categories();
$regions  = dict_regions();

/** 保留当前筛选的链接构造 */
function nodeLink(array $override = []): string
{
    global $id, $window, $region, $catF;
    return link_to('node.php', array_merge([
        'id' => $id, 'window' => $window, 'region' => $region, 'cat' => $catF,
    ], $override));
}

// 时间窗口的可视化刻度
$lo = min($year, $yearEnd ?? $year) - $window;
$hi = max($year, $yearEnd ?? $year) + $window;
$span = max(1, $hi - $lo);
?>

<?php require __DIR__ . '/inc/layout_header.php'; ?>

<article>
  <div class="node-hero">
    <div class="node-hero-year">
      <div class="big"><?= h(fmt_year_range($year, $yearEnd, false)) ?></div>
    </div>
    <div class="node-hero-body">
      <h1><?= h($event['title']) ?></h1>
      <p class="sum"><?= h($event['summary']) ?></p>
      <div class="node-meta-row">
        <?php if ($dyn): ?>
          <a class="tag tag-cat" style="color:<?= h($dyn['color']) ?>;background:<?= h($dyn['color']) ?>1a"
             href="<?= h(link_to('index.php', ['dynasty' => $dyn['slug']])) ?>"><?= h($dyn['name']) ?></a>
        <?php endif; ?>
        <span class="tag tag-cat" style="color:<?= h($catColor) ?>"><?= h(cat_name((string) $event['category'])) ?></span>
        <?php if (!empty($event['place'])): ?>
          <span class="tag tag-cat">📍 <?= h($event['place']) ?></span>
        <?php endif; ?>
        <?php if (!empty($event['month'])): ?>
          <span class="tag tag-cat tag-year"><?= h(fmt_date($event)) ?></span>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <?php if (!empty($event['detail'])): ?>
    <div class="node-detail"><?= h($event['detail']) ?></div>
  <?php endif; ?>

  <?php if (!empty($event['figures'])): ?>
    <div class="node-figs"><b>关键人物</b>　<?= h($event['figures']) ?></div>
  <?php endif; ?>

  <!-- ============ 同期世界大事 ============ -->
  <div class="sync-head">
    <h2>🌍 同一时期，世界正在发生</h2>
    <span class="window-note">
      时间窗口 <?= h(fmt_year_range($lo, $hi, false)) ?>
    </span>
    <span class="count">共 <?= count($totalWorld) ?> 条<?= count($world) !== count($totalWorld) ? '，当前显示 ' . count($world) . ' 条' : '' ?></span>
  </div>

  <div class="window-bar">
    <div class="tick" style="left:0"></div>
    <div class="tick" style="left:50%"></div>
    <div class="tick" style="left:100%"></div>
    <span class="lbl left"><?= h(fmt_year($lo, false)) ?></span>
    <span class="lbl mid"><?= h(fmt_year_range($year, $yearEnd, false)) ?></span>
    <span class="lbl right"><?= h(fmt_year($hi, false)) ?></span>
  </div>

  <!-- 筛选 -->
  <div class="filter-bar" style="border-top:none;margin-top:0;padding-top:0">
    <span class="filter-label">区域</span>
    <a class="chip <?= $region === '' ? 'on' : '' ?>" href="<?= h(nodeLink(['region' => ''])) ?>">全部</a>
    <?php foreach ($regions as $slug => $r): ?>
      <a class="chip <?= $region === $slug ? 'on' : '' ?>" href="<?= h(nodeLink(['region' => $slug])) ?>">
        <?= h($r['emoji']) ?> <?= h($r['name']) ?>
      </a>
    <?php endforeach; ?>
  </div>
  <div class="filter-bar" style="border-top:none;padding-top:0;margin-top:-10px">
    <span class="filter-label">主题</span>
    <a class="chip <?= $catF === '' ? 'on' : '' ?>" href="<?= h(nodeLink(['cat' => ''])) ?>">全部</a>
    <?php foreach ($cats as $slug => $c): ?>
      <a class="chip <?= $catF === $slug ? 'on' : '' ?>" href="<?= h(nodeLink(['cat' => $slug])) ?>">
        <span class="dot" style="background:<?= h($c['color']) ?>"></span><?= h($c['name']) ?>
      </a>
    <?php endforeach; ?>
  </div>
  <div class="filter-bar" style="border-top:none;padding-top:0;margin-top:-10px">
    <span class="filter-label">窗口</span>
    <?php foreach ([1, 3, 5, 10, 20] as $w): ?>
      <a class="chip <?= $window === $w ? 'on' : '' ?>"
         href="<?= h(nodeLink(['window' => $w, 'region' => '', 'cat' => ''])) ?>">±<?= $w ?> 年</a>
    <?php endforeach; ?>
  </div>

  <?php if (!$world): ?>
    <div class="empty">
      这个时间窗口内还没有录入世界大事。<br>
      <span class="small">可以换个窗口试试，或 <a href="<?= h(link_to('admin/index.php')) ?>">到后台补充</a>。</span>
    </div>
  <?php endif; ?>

  <?php foreach ($byRegion as $rslug => $items): ?>
    <div class="region-group">
      <div class="region-head">
        <span class="emo"><?= h(region_emoji($rslug)) ?></span>
        <span><?= h(region_name($rslug)) ?></span>
        <span class="n"><?= count($items) ?> 条</span>
      </div>
      <?php foreach ($items as $w): ?>
        <div class="world-item" id="w<?= (int) $w['id'] ?>">
          <div class="wyear">
            <span><?= h(fmt_year_range((int) $w['year'], !empty($w['year_end']) ? (int) $w['year_end'] : null, false)) ?></span>
            <span class="tag tag-cat" style="color:<?= h(cat_color((string) $w['category'])) ?>"><?= h(cat_name((string) $w['category'])) ?></span>
          </div>
          <div>
            <div class="wtitle">
              <a href="<?= h(link_to('world.php', ['id' => $w['id']])) ?>"><?= h($w['title']) ?></a>
            </div>
            <?php if (!empty($w['place'])): ?>
              <div class="small muted" style="margin:-2px 0 4px">📍 <?= h($w['place']) ?></div>
            <?php endif; ?>
            <?php if (!empty($w['summary'])): ?>
              <div class="wsum"><?= h($w['summary']) ?></div>
            <?php endif; ?>
            <?php if (!empty($w['figures'])): ?>
              <div class="wfigs"><b>人物</b> <?= h($w['figures']) ?></div>
            <?php endif; ?>
            <?php if (!empty($w['detail'])): ?>
              <details style="margin-top:7px">
                <summary class="small muted" style="cursor:pointer;user-select:none">展开详情</summary>
                <div class="cdetail"><?= h($w['detail']) ?></div>
              </details>
            <?php endif; ?>
            <?php
            $links = cn_events_of_world((int) $w['id']);
            if ($links):
                $lk = array_filter($links, function ($r) use ($id) { return (int) $r['cn_event_id'] === (int) $id; });
            ?>
              <?php if ($lk): ?>
                <div class="link-item" style="border:none;padding:8px 0 0">
                  <span class="lt-type"><?= h($relTypes[$lk[0]['relation_type']]['name'] ?? '联动') ?></span>
                  <span class="small" style="color:var(--indigo)">与本节点存在直接关联</span>
                </div>
              <?php else: ?>
                <div class="link-item" style="border:none;padding:8px 0 0">
                  <span class="small muted">同期中国节点：
                    <?php foreach (array_slice($links, 0, 3) as $l): ?>
                      <a href="<?= h(link_to('node.php', ['id' => $l['cn_event_id']])) ?>"><?= h($l['c_title']) ?></a><?= $l !== end($links) && count($links) <= 3 ? '、' : '' ?>
                    <?php endforeach; ?>
                    <?php if (count($links) > 3): ?> 等 <?= count($links) ?> 个<?php endif; ?>
                  </span>
                </div>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>

  <!-- ============ 中外联动 ============ -->
  <?php if ($relations): ?>
    <div class="link-panel">
      <h3>⚡ 中外联动 · 真正的交汇点</h3>
      <?php foreach ($relations as $r): ?>
        <?php if (empty($r['w_title'])) continue; ?>
        <div class="link-item">
          <div class="lt-title">
            <span class="lt-type"><?= h($relTypes[$r['relation_type']]['name'] ?? '联动') ?></span>
            <a href="<?= h(link_to('world.php', ['id' => $r['world_event_id']])) ?>"><?= h($r['w_title']) ?></a>
            <span class="tag tag-year" style="margin-left:6px"><?= h(fmt_year_range((int) $r['w_year'], !empty($r['w_year_end']) ? (int) $r['w_year_end'] : null, false)) ?></span>
          </div>
          <?php if (!empty($r['note'])): ?>
            <div class="lt-note"><?= h($r['note']) ?></div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <!-- ============ 上一条 / 下一条 ============ -->
  <div class="pager">
    <?php if ($neighbours['prev']): $p = $neighbours['prev']; ?>
      <a class="prev" href="<?= h(link_to('node.php', ['id' => $p['id']])) ?>">
        <div class="dir">← 上一节点</div>
        <div class="t"><?= h($p['title']) ?></div>
        <div class="dir" style="margin-top:3px"><?= h(fmt_year_range((int) $p['year'], !empty($p['year_end']) ? (int) $p['year_end'] : null, false)) ?></div>
      </a>
    <?php else: ?><span class="void">已是第一个节点</span><?php endif; ?>

    <?php if ($neighbours['next']): $n = $neighbours['next']; ?>
      <a class="next" href="<?= h(link_to('node.php', ['id' => $n['id']])) ?>">
        <div class="dir">下一节点 →</div>
        <div class="t"><?= h($n['title']) ?></div>
        <div class="dir" style="margin-top:3px"><?= h(fmt_year_range((int) $n['year'], !empty($n['year_end']) ? (int) $n['year_end'] : null, false)) ?></div>
      </a>
    <?php else: ?><span class="void" style="text-align:right">已是最后一个节点</span><?php endif; ?>
  </div>
</article>

<?php require __DIR__ . '/inc/layout_footer.php'; ?>
