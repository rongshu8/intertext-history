<?php
/**
 * 世界大事 · 列表 / 详情
 * ---------------------------------------------------------------------------
 * 无 id  → 列表（支持区域、主题、朝代区间、年份区间筛选 + 分页）
 * 有 id  → 详情（显示该世界大事关联的中国节点）
 */

// 未安装时导向安装向导（必须在 bootstrap 之前：那时数据库还连不上）
require_once __DIR__ . '/inc/install_gate.php';
require __DIR__ . '/inc/bootstrap.php';

$id = qi('id');

$PAGE_TITLE = '世界大事';
$ACTIVE     = 'world';
require __DIR__ . '/inc/layout_header.php';

if ($id > 0):
    // ==================== 详情 ====================
    $w = get_world_event($id);
    if (!$w) {
        echo '<div class="empty" style="padding:80px 20px">该世界大事不存在。<br><br>'
           . '<a class="btn" href="' . h(link_to('world.php')) . '">← 返回列表</a></div>';
        require __DIR__ . '/inc/layout_footer.php';
        exit;
    }
    $PAGE_TITLE = $w['title'];
    $links = cn_events_of_world($id);
    $rType = dict_relation_types();
    $cats  = dict_categories();
    $regs  = dict_regions();
    ?>
    <article>
      <div class="list-head">
        <div class="node-meta-row" style="margin-bottom:10px">
          <span class="tag tag-year big" style="font-size:14px;padding:3px 12px"><?= h(fmt_year_range((int) $w['year'], !empty($w['year_end']) ? (int) $w['year_end'] : null, false)) ?></span>
          <span class="tag tag-cat" style="color:<?= h(cat_color((string) $w['category'])) ?>"><?= h(cat_name((string) $w['category'])) ?></span>
          <span class="tag tag-cat"><?= h(region_emoji((string) $w['region'])) ?> <?= h(region_name((string) $w['region'])) ?></span>
          <?php if (!empty($w['place'])): ?><span class="tag tag-cat">📍 <?= h($w['place']) ?></span><?php endif; ?>
        </div>
        <h1 style="font-size:clamp(22px,3.4vw,30px);line-height:1.4"><?= h($w['title']) ?></h1>
        <?php if (!empty($w['summary'])): ?><p><?= h($w['summary']) ?></p><?php endif; ?>
      </div>

      <?php if (!empty($w['detail'])): ?>
        <div class="node-detail"><?= h($w['detail']) ?></div>
      <?php endif; ?>

      <?php if (!empty($w['figures'])): ?>
        <div class="node-figs"><b>关键人物</b>　<?= h($w['figures']) ?></div>
      <?php endif; ?>

      <?php if ($links): ?>
        <div class="link-panel">
          <h3>🔗 同期中国节点</h3>
          <?php foreach ($links as $l): ?>
            <div class="link-item">
              <div class="lt-title">
                <span class="lt-type"><?= h($rType[$l['relation_type']]['name'] ?? '对照') ?></span>
                <a href="<?= h(link_to('node.php', ['id' => $l['cn_event_id']])) ?>"><?= h($l['c_title']) ?></a>
                <span class="tag tag-year" style="margin-left:6px"><?= h(fmt_year_range((int) $l['c_year'], null, false)) ?></span>
              </div>
              <?php if (!empty($l['note'])): ?><div class="lt-note"><?= h($l['note']) ?></div><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="pager">
        <a href="<?= h(link_to('world.php')) ?>"><div class="dir">← 返回</div><div class="t">世界大事列表</div></a>
        <span></span>
      </div>
    </article>
    <?php

else:
    // ==================== 列表 ====================
    $region   = q('region');
    $catF     = q('cat');
    $kw       = q('q');
    $page     = max(1, qi('page', 1));
    $perPage  = 40;

    $where = ['1=1'];
    $args  = [];
    if ($region) { $where[] = 'region = ?';  $args[] = $region; }
    if ($catF)   { $where[] = 'category = ?'; $args[] = $catF; }
    if ($kw) {
        $where[] = '(title LIKE ? OR summary LIKE ? OR detail LIKE ?)';
        $like = '%' . $kw . '%';
        $args[] = $like; $args[] = $like; $args[] = $like;
    }
    $whereSql = implode(' AND ', $where);

    $total = (int) DB::fetchCol("SELECT COUNT(*) FROM world_events WHERE {$whereSql}", $args);
    $pages = max(1, (int) ceil($total / $perPage));
    $page  = min($page, $pages);
    $off   = ($page - 1) * $perPage;

    // limit/offset 直接拼接（已强制转 int，无注入风险）
    $rows = DB::fetchAll(
        "SELECT * FROM world_events WHERE {$whereSql}
         ORDER BY year ASC, month ASC, importance DESC, id ASC
         LIMIT {$perPage} OFFSET {$off}",
        $args
    );

    $regions = dict_regions();
    $cats    = dict_categories();

    function wLink(array $ov = []): string {
        global $region, $catF, $kw, $page;
        return link_to('world.php', array_merge([
            'region' => $region, 'cat' => $catF, 'q' => $kw, 'page' => $page,
        ], $ov));
    }
    ?>
    <div class="list-head">
      <h1>世界大事</h1>
      <p>共 <?= number_format($total) ?> 条。按时间正序排列，每一条都可回溯到同期发生的中国节点。</p>
      <form class="search-box" action="<?= h(link_to('world.php')) ?>" method="get" style="max-width:520px">
        <input type="search" name="q" value="<?= h($kw) ?>" placeholder="搜索世界大事…" autocomplete="off">
        <?php if ($region): ?><input type="hidden" name="region" value="<?= h($region) ?>"><?php endif; ?>
        <?php if ($catF): ?><input type="hidden" name="cat" value="<?= h($catF) ?>"><?php endif; ?>
        <button class="btn btn-primary" type="submit">搜索</button>
      </form>
    </div>

    <div class="filter-wrap">
      <button type="button" class="filter-toggle" aria-expanded="false" aria-controls="filterPanel">
        <span class="ft-icon" aria-hidden="true"></span>
        <span class="ft-text">筛选</span>
        <span class="ft-summary"></span>
        <span class="ft-count" hidden></span>
      </button>

      <div class="filter-panel" id="filterPanel" data-empty-hint="全部区域 · 全部主题">
        <div class="filter-bar">
          <span class="filter-label">区域</span>
          <a class="chip <?= $region === '' ? 'on' : '' ?>" href="<?= h(wLink(['region' => '', 'page' => 1])) ?>">全部</a>
          <?php foreach ($regions as $s => $r): ?>
            <a class="chip <?= $region === $s ? 'on' : '' ?>" href="<?= h(wLink(['region' => $s, 'page' => 1])) ?>">
              <?= h($r['emoji']) ?> <?= h($r['name']) ?>
            </a>
          <?php endforeach; ?>
        </div>
        <div class="filter-bar filter-bar-cont">
          <span class="filter-label">主题</span>
          <a class="chip <?= $catF === '' ? 'on' : '' ?>" href="<?= h(wLink(['cat' => '', 'page' => 1])) ?>">全部</a>
          <?php foreach ($cats as $s => $c): ?>
            <a class="chip <?= $catF === $s ? 'on' : '' ?>" href="<?= h(wLink(['cat' => $s, 'page' => 1])) ?>">
              <span class="dot" style="background:<?= h($c['color']) ?>"></span><?= h($c['name']) ?>
            </a>
          <?php endforeach; ?>
        </div>
      </div>

      <noscript>
        <style>.filter-panel { display: block !important; }</style>
      </noscript>
    </div>

    <script src="<?= h(asset('filter-toggle.js')) ?>" defer></script>

    <?php if (!$rows): ?>
      <div class="empty">没有匹配的世界大事。</div>
    <?php endif; ?>

    <div style="margin-top:10px">
      <?php foreach ($rows as $w): ?>
        <div class="wl-row">
          <div class="wl-year"><?= h(fmt_year_range((int) $w['year'], !empty($w['year_end']) ? (int) $w['year_end'] : null, false)) ?></div>
          <div>
            <div class="wl-title"><a href="<?= h(link_to('world.php', ['id' => $w['id']])) ?>"><?= h($w['title']) ?></a></div>
            <?php if (!empty($w['summary'])): ?><div class="wl-sum"><?= h($w['summary']) ?></div><?php endif; ?>
            <div class="wl-meta">
              <span class="tag tag-cat" style="color:<?= h(cat_color((string) $w['category'])) ?>"><?= h(cat_name((string) $w['category'])) ?></span>
              <span class="tag tag-cat"><?= h(region_emoji((string) $w['region'])) ?> <?= h(region_name((string) $w['region'])) ?></span>
              <?php if (!empty($w['place'])): ?><span class="tag tag-cat">📍 <?= h($w['place']) ?></span><?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if ($pages > 1): ?>
      <div class="pager-bar">
        <?php if ($page > 1): ?>
          <a href="<?= h(wLink(['page' => $page - 1])) ?>">← 上一页</a>
        <?php endif; ?>
        <?php
        $start = max(1, $page - 3);
        $end   = min($pages, $start + 6);
        $start = max(1, $end - 6);
        if ($start > 1): ?><span class="gap">…</span><?php endif; ?>
        <?php for ($i = $start; $i <= $end; $i++): ?>
          <?php if ($i === $page): ?><span class="cur"><?= $i ?></span><?php else: ?>
            <a href="<?= h(wLink(['page' => $i])) ?>"><?= $i ?></a><?php endif; ?>
        <?php endfor; ?>
        <?php if ($end < $pages): ?><span class="gap">…</span><?php endif; ?>
        <?php if ($page < $pages): ?>
          <a href="<?= h(wLink(['page' => $page + 1])) ?>">下一页 →</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <?php
endif;

require __DIR__ . '/inc/layout_footer.php';
