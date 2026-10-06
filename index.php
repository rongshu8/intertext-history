<?php
/**
 * 首页 · 主时间轴
 * ---------------------------------------------------------------------------
 * 以中国历史节点为主轴，每个节点右侧列出同期（默认 ±5 年）的世界大事。
 * 支持按朝代、分类筛选；?key=1 只看关键节点；?dynasty=slug 定位到某朝代。
 */

// 未安装时导向安装向导（必须在 bootstrap 之前：那时数据库还连不上）
require_once __DIR__ . '/inc/install_gate.php';
require __DIR__ . '/inc/bootstrap.php';

$PAGE_TITLE = '时间轴';
$ACTIVE     = 'index';
require __DIR__ . '/inc/layout_header.php';

$dynastySlug = q('dynasty');
$category    = q('cat');
$onlyKey     = qi('key') === 1;
$kw          = q('q');

// ---- 窄屏减量 ----
// 手机上每个节点渲染 4 条同期大事会让单行高达 500px+，时间轴没法看。
// 这里按 UA 判断出移动端就少传，桌面端仍给全量：
//   - 移动端 2 条
//   - 其余 4 条（平板/桌面）
// 依据 UA 而非屏宽：服务端拿不到真实屏宽（viewport meta 的 width 由前端告知），
// 平板 UA 与桌面 UA 有重叠，但平板本来也不需要那么多 —— CSS 侧还有一层兜底减重。
$isMobileUA = (bool) preg_match(
    '/Android|webOS|iPhone|iPod|BlackBerry|IEMobile|Opera Mini|Mobile|mobile/i',
    (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
);
$worldLimit = $isMobileUA ? 2 : 4;

$dynasties    = all_dynasties();
$dynastyBySlug = [];
foreach ($dynasties as $d) {
    $dynastyBySlug[$d['slug']] = $d;
}
$dynastyId = $dynastySlug && isset($dynastyBySlug[$dynastySlug]) ? (int) $dynastyBySlug[$dynastySlug]['id'] : 0;

$opt = [];
if ($dynastyId) $opt['dynasty'] = $dynastyId;
if ($category)  $opt['category'] = $category;
if ($onlyKey)   $opt['key'] = true;
if ($kw)        $opt['q'] = $kw;

$events = list_cn_events($opt);
$stats  = site_stats();
$cats   = dict_categories();

// 按朝代分组
$grouped = [];
foreach ($events as $e) {
    $grouped[(int) $e['dynasty_id']][] = $e;
}
// 排序：朝代按 sort，节点按 year
$sortedGroups = [];
foreach ($grouped as $did => $list) {
    $d = dynasty_by_id($did);
    if (!$d) continue;
    $sortedGroups[] = ['dynasty' => $d, 'events' => $list];
}
usort($sortedGroups, function ($a, $b) {
    return (int) $a['dynasty']['sort'] <=> (int) $b['dynasty']['sort'];
});

/**
 * 渲染单条事件卡片
 */
function render_event(array $e, bool $isWorld = false): void
{
    $catSlug = (string) $e['category'];
    $color   = cat_color($catSlug);
    ?>
    <a class="ev-card <?= $isWorld ? 'world' : '' ?> <?= !empty($e['is_key']) ? 'key' : '' ?>"
       href="<?= h(link_to('node.php', ['id' => $e['id']])) ?>">
      <div class="ev-title">
        <span class="cat-strip" style="background:<?= h($color) ?>" aria-hidden="true"></span>
        <span><?= h($e['title']) ?></span>
        <?php if (!empty($e['is_key'])): ?><span class="ev-key-badge">◆ 关键</span><?php endif; ?>
      </div>
      <div class="ev-meta">
        <span class="tag tag-cat" style="color:<?= h($color) ?>"><?= h(cat_name($catSlug)) ?></span>
        <?php if (!empty($e['place'])): ?><span class="ev-place"><?= h($e['place']) ?></span><?php endif; ?>
      </div>
      <?php if (!empty($e['summary'])): ?>
        <div class="ev-sum"><?= h($e['summary']) ?></div>
      <?php endif; ?>
    </a>
    <?php
}
?>

<section class="hero">
  <h1>以中国节点为轴，<span class="hl">看见同一刻的世界</span></h1>
  <p class="lede">
    每一个中国历史节点，都同时对应着世界另一端正在发生的事：战争、发明、出版、政权更迭、思想爆发。
    沿时间轴往下走，看两个文明如何在同一个年份里各自发生着彼此不知道的事。
  </p>

  <form class="search-box" action="<?= h(link_to('search.php')) ?>" method="get">
    <input type="search" name="q" placeholder="搜索事件、人物、地点…（如「造纸」「郑和」「哥伦比亚」）"
           value="<?= h($kw) ?>" autocomplete="off">
    <button class="btn btn-primary" type="submit">搜索</button>
  </form>

  <div class="hero-stats">
    <div><b><?= number_format($stats['cn']) ?></b>中国节点</div>
    <div><b><?= number_format($stats['world']) ?></b>同期世界大事</div>
    <div><b><?= number_format($stats['dynasties']) ?></b>朝代分期</div>
    <div><b><?= number_format($stats['relations']) ?></b>中外联动</div>
    <div><b><?= h(fmt_year($stats['from_year'], false)) ?><span style="font-size:14px;color:var(--muted)"> — </span><?= h(fmt_year($stats['to_year'], false)) ?></b>覆盖年代</div>
  </div>
</section>

<div class="filter-wrap" id="filters">
  <button type="button" class="filter-toggle" id="filterToggle"
          aria-expanded="false" aria-controls="filterPanel">
    <span class="ft-icon" aria-hidden="true"></span>
    <span class="ft-text">筛选</span>
    <span class="ft-summary" id="filterSummary"></span>
    <span class="ft-count" id="filterCount" hidden></span>
  </button>

  <div class="filter-panel" id="filterPanel" data-empty-hint="全部朝代 · 全部主题">
    <div class="filter-bar">
      <span class="filter-label">朝代</span>
      <a class="chip <?= $dynastySlug === '' ? 'on' : '' ?>" href="<?= h(link_to('index.php', array_filter(['cat' => $category, 'key' => $onlyKey ? 1 : null, 'q' => $kw]))) ?>">全部</a>
      <?php foreach ($dynasties as $d): ?>
        <a class="chip <?= $dynastySlug === $d['slug'] ? 'on' : '' ?>"
           href="<?= h(link_to('index.php', array_filter(['dynasty' => $d['slug'], 'cat' => $category, 'key' => $onlyKey ? 1 : null, 'q' => $kw]))) ?>">
          <span class="dot" style="background:<?= h($d['color']) ?>"></span><?= h($d['name']) ?>
        </a>
      <?php endforeach; ?>
    </div>

    <div class="filter-bar filter-bar-cont">
      <span class="filter-label">主题</span>
      <a class="chip <?= $category === '' ? 'on' : '' ?>" href="<?= h(link_to('index.php', array_filter(['dynasty' => $dynastySlug, 'key' => $onlyKey ? 1 : null, 'q' => $kw]))) ?>">全部</a>
      <?php foreach ($cats as $slug => $c): ?>
        <a class="chip <?= $category === $slug ? 'on' : '' ?>"
           href="<?= h(link_to('index.php', array_filter(['dynasty' => $dynastySlug, 'cat' => $slug, 'key' => $onlyKey ? 1 : null, 'q' => $kw]))) ?>">
          <span class="dot" style="background:<?= h($c['color']) ?>"></span><?= h($c['name']) ?>
        </a>
      <?php endforeach; ?>
      <a class="chip chip-alt <?= $onlyKey ? 'on' : '' ?>" href="<?= h(link_to('index.php', array_filter(['dynasty' => $dynastySlug, 'cat' => $category, 'q' => $kw]))) ?>">仅关键节点</a>
    </div>
  </div>

  <noscript>
    <style>.filter-panel { display: block !important; }</style>
  </noscript>
</div>

<script src="<?= h(asset('filter-toggle.js')) ?>" defer></script>


<?php if (!$sortedGroups): ?>
  <div class="empty">没有符合条件的节点。试试清除筛选，或 <a href="<?= h(link_to('index.php')) ?>">回到全部</a>。</div>
<?php endif; ?>

<div class="timeline">
<?php foreach ($sortedGroups as $g):
    $d = $g['dynasty'];
    ?>
    <div class="dyn-head">
      <div class="dyn-badge">
        <span class="dot" style="background:<?= h($d['color']) ?>"></span>
        <b><?= h($d['name']) ?></b>
        <span class="span"><?= h(fmt_year_range((int) $d['start_year'], (int) $d['end_year'], false)) ?></span>
      </div>
    </div>
    <?php if (!empty($d['summary'])): ?>
      <div class="dyn-note"><?= h($d['summary']) ?></div>
    <?php endif; ?>

    <?php foreach ($g['events'] as $e):
        $yearEnd = !empty($e['year_end']) ? (int) $e['year_end'] : null;
        $world   = world_events_near((int) $e['year'], $yearEnd, ['limit' => $worldLimit]);
        ?>
        <div class="tl-row <?= !empty($e['is_key']) ? 'key' : '' ?>">
          <div class="tl-node">
            <div class="tl-year"><?= h(fmt_year_range((int) $e['year'], $yearEnd, false)) ?></div>
          </div>

          <div class="tl-cards left">
            <a class="ev-card <?= !empty($e['is_key']) ? 'key' : '' ?>" href="<?= h(link_to('node.php', ['id' => $e['id']])) ?>">
              <?php /* 年份在卡片内再输出一次：窄屏时 .tl-node 隐藏（见 responsive.css §13），
                       由这里承载；桌面端 .tl-year-in 隐藏，走主轴那一份。
                       两份用 CSS 互斥显示，避免同一信息出现两次。 */ ?>
              <div class="tl-year-in"><?= h(fmt_year_range((int) $e['year'], $yearEnd, false)) ?></div>
              <div class="ev-title">
                <span class="cat-strip" style="background:<?= h(cat_color((string) $e['category'])) ?>" aria-hidden="true"></span>
                <span><?= h($e['title']) ?></span>
              </div>
              <div class="ev-meta">
                <span class="tag tag-cat" style="color:<?= h(cat_color((string) $e['category'])) ?>"><?= h(cat_name((string) $e['category'])) ?></span>
                <?php if (!empty($e['place'])): ?><span class="ev-place"><?= h($e['place']) ?></span><?php endif; ?>
              </div>
              <?php if (!empty($e['summary'])): ?>
                <div class="ev-sum"><?= h($e['summary']) ?></div>
              <?php endif; ?>
            </a>
          </div>

          <div class="tl-cards right">
            <?php if (!$world): ?>
              <span class="ev-card ev-empty">
                同期暂无录入世界大事
              </span>
            <?php else: ?>
              <?php foreach ($world as $w): ?>
                <a class="ev-card world" href="<?= h(link_to('world.php', ['id' => $w['id']])) ?>">
                  <div class="ev-title">
                    <span class="cat-strip" style="background:<?= h(cat_color((string) $w['category'])) ?>" aria-hidden="true"></span>
                    <span><?= h($w['title']) ?></span>
                  </div>
                  <div class="ev-meta">
                    <span class="tag tag-year"><?= h(fmt_year_range((int) $w['year'], !empty($w['year_end']) ? (int) $w['year_end'] : null, false)) ?></span>
                    <?php /* 地区在窄屏隐藏 —— emoji + 名称占一行，实际信息密度低 */ ?>
                    <span class="ev-region"><?= h(region_emoji((string) $w['region'])) ?> <?= h(region_name((string) $w['region'])) ?></span>
                  </div>
                </a>
              <?php endforeach; ?>
              <a class="btn btn-ghost btn-sm tl-more"
                 href="<?= h(link_to('node.php', ['id' => $e['id']])) ?>">
                查看全部同期大事 →
              </a>
            <?php endif; ?>
          </div>
        </div>
    <?php endforeach; ?>
<?php endforeach; ?>
</div>

<?php require __DIR__ . '/inc/layout_footer.php'; ?>
