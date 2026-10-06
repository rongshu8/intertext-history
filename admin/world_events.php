<?php
/**
 * 世界大事 · 列表 / 新增 / 编辑 / 删除
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_admin();

$action = q('action');
$id     = qi('id');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check()) {
        flash('会话已过期，请重试。', 'err');
        header('Location: ' . alink('world_events.php'));;
        exit;
    }
    $op = p('op');

    if ($op === 'delete') {
        $did = pint('id');
        DB::transaction(function () use ($did) {
            DB::exec('DELETE FROM relations WHERE world_event_id = ?', [$did]);
            DB::exec('DELETE FROM world_events WHERE id = ?', [$did]);
        });
        flash('世界大事已删除。');
        header('Location: ' . alink('world_events.php'));;
        exit;
    }

    if ($op === 'save') {
        $wid = pint('id');
        $data = [
            'title'      => p('title'),
            'year'       => pint('year'),
            'year_end'   => empty($_POST['year_end']) ? null : pint('year_end'),
            'month'      => empty($_POST['month']) ? null : pint('month'),
            'day'        => empty($_POST['day']) ? null : pint('day'),
            'region'     => p('region') ?: 'global',
            'category'   => p('category') ?: 'politics',
            'place'      => p('place') ?: null,
            'summary'    => p('summary') ?: null,
            'detail'     => p('detail') ?: null,
            'figures'    => p('figures') ?: null,
            'importance' => max(1, min(5, pint('importance', 3))),
            'source'     => p('source') ?: null,
        ];

        if ($data['title'] === '' || $data['year'] === 0 && $data['year'] === null) {
            flash('标题为必填项。', 'err');
            header('Location: ' . ($wid ? alink('world_events.php', ['action' => 'edit', 'id' => $wid]) : alink('world_events.php', ['action' => 'new'])));
            exit;
        }
        if ($data['year_end'] !== null && $data['year_end'] < $data['year']) {
            $data['year_end'] = $data['year'];
        }

        if ($wid > 0) {
            $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($data)));
            DB::exec("UPDATE world_events SET {$sets} WHERE id = ?", array_merge(array_values($data), [$wid]));
            flash('世界大事已更新。');
        } else {
            $cols = implode(', ', array_keys($data));
            $qs   = implode(', ', array_fill(0, count($data), '?'));
            DB::exec("INSERT INTO world_events ({$cols}, created_at) VALUES ({$qs}, ' . SQL_NOW . ')", array_values($data));
            $wid = DB::lastInsertId();
            flash('世界大事已创建。');
        }
        header('Location: ' . alink('world_events.php', ['action' => 'edit', 'id' => $wid]));
        exit;
    }
}

$PAGE_TITLE = '世界大事';
$ADMIN_NAV  = 'we';
require __DIR__ . '/../inc/admin_header.php';

$cats    = dict_categories();
$regions = dict_regions();

if ($action === 'new' || $action === 'edit') {
    $w = [
        'id' => 0, 'title' => '', 'year' => 0, 'year_end' => null, 'month' => null, 'day' => null,
        'region' => 'global', 'category' => 'politics', 'place' => '', 'summary' => '',
        'detail' => '', 'figures' => '', 'importance' => 3, 'source' => '',
    ];
    if ($action === 'edit') {
        $row = get_world_event($id);
        if (!$row) { flash('记录不存在。', 'err'); header('Location: ' . alink('world_events.php'));; exit; }
        $w = $row;
    } elseif (!empty($_GET['year'])) {
        $w['year'] = qi('year');
    }
    $isEdit = $action === 'edit';

    // 该事件已关联的中国节点
    $linked = $isEdit ? cn_events_of_world((int) $w['id']) : [];
    ?>
    <div class="page-head">
      <h1><?= $isEdit ? '编辑世界大事' : '新增世界大事' ?></h1>
      <div class="spacer"></div>
      <a class="btn-s" href="<?= h(alink('world_events.php')) ?>">← 返回列表</a>
      <?php if ($isEdit): ?>
        <a class="btn-s" href="<?= h(link_to('../world.php', ['id' => $w['id']])) ?>" target="_blank">前台预览 ↗</a>
      <?php endif; ?>
    </div>

    <form class="acard acard-pad" method="post">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="op" value="save">
      <input type="hidden" name="id" value="<?= (int) $w['id'] ?>">

      <div class="aform">
        <div class="afield">
          <label>标题 <span style="color:#dc2626">*</span></label>
          <input type="text" name="title" value="<?= h((string) $w['title']) ?>" required
                 placeholder="如：牛顿《自然哲学的数学原理》出版，经典物理体系建立">
        </div>

        <div class="grid-4">
          <div class="afield">
            <label>年份 <span style="color:#dc2626">*</span></label>
            <input type="number" name="year" value="<?= (int) $w['year'] ?>" required placeholder="1687">
            <span class="hint">公元前用负数</span>
          </div>
          <div class="afield">
            <label>结束年份</label>
            <input type="number" name="year_end" value="<?= $w['year_end'] ? (int) $w['year_end'] : '' ?>" placeholder="留空=单点">
          </div>
          <div class="afield">
            <label>区域</label>
            <select name="region">
              <?php foreach ($regions as $s => $r): ?>
                <option value="<?= h($s) ?>" <?= (string) $w['region'] === $s ? 'selected' : '' ?>>
                  <?= h($r['emoji']) ?> <?= h($r['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="afield">
            <label>主题</label>
            <select name="category">
              <?php foreach ($cats as $s => $c): ?>
                <option value="<?= h($s) ?>" <?= (string) $w['category'] === $s ? 'selected' : '' ?>><?= h($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="grid-4">
          <div class="afield"><label>月</label><input type="number" name="month" min="1" max="12" value="<?= $w['month'] ? (int) $w['month'] : '' ?>"></div>
          <div class="afield"><label>日</label><input type="number" name="day" min="1" max="31" value="<?= $w['day'] ? (int) $w['day'] : '' ?>"></div>
          <div class="afield">
            <label>地点</label>
            <input type="text" name="place" value="<?= h((string) ($w['place'] ?? '')) ?>" placeholder="如：伦敦">
          </div>
          <div class="afield">
            <label>重要度</label>
            <select name="importance">
              <?php for ($i = 5; $i >= 1; $i--): ?>
                <option value="<?= $i ?>" <?= (int) $w['importance'] === $i ? 'selected' : '' ?>>
                  <?= $i ?> — <?= ['参考','一般','重要','王朝级大事','世界级转折'][$i - 1] ?>
                </option>
              <?php endfor; ?>
            </select>
          </div>
        </div>

        <div class="afield">
          <label>一句话概述</label>
          <input type="text" name="summary" value="<?= h((string) ($w['summary'] ?? '')) ?>" placeholder="会显示在列表与节点页，建议 40 字以内">
        </div>

        <div class="afield">
          <label>详细描述</label>
          <textarea name="detail" rows="7" placeholder="展开讲清楚这件事为什么重要。"><?= h((string) ($w['detail'] ?? '')) ?></textarea>
        </div>

        <div class="grid-2">
          <div class="afield">
            <label>关键人物</label>
            <input type="text" name="figures" value="<?= h((string) ($w['figures'] ?? '')) ?>" placeholder="用顿号分隔">
          </div>
          <div class="afield">
            <label>来源 / 参考</label>
            <input type="text" name="source" value="<?= h((string) ($w['source'] ?? '')) ?>" placeholder="如：维基百科 / 剑桥史 / 某专著">
          </div>
        </div>

        <div class="form-actions">
          <button class="btn-s primary" type="submit"><?= $isEdit ? '保存修改' : '创建' ?></button>
          <a class="btn-s" href="<?= h(alink('world_events.php')) ?>">取消</a>
          <div class="spacer"></div>
          <?php if ($isEdit): ?><span class="small muted">ID <?= (int) $w['id'] ?></span><?php endif; ?>
        </div>
      </div>
    </form>

    <?php if ($isEdit): ?>
      <div class="acard" style="margin-top:16px">
        <div class="acard-head"><h2>已关联的中国节点</h2><div class="spacer"></div>
          <a class="btn-s" href="<?= h(alink('relations.php', ['world_id' => $w['id']])) ?>">管理联动 →</a>
        </div>
        <div class="acard-pad">
          <?php if (!$linked): ?>
            <div class="aempty">还没有关联任何中国节点。到「联动」页可以把这条世界大事挂到某个中国节点上。</div>
          <?php else: ?>
            <?php foreach ($linked as $l): ?>
              <div style="padding:7px 0;border-bottom:1px solid #f0f2f4;display:flex;gap:10px;align-items:baseline">
                <span class="num small muted" style="min-width:78px"><?= h(fmt_year_range((int) $l['c_year'], null, false)) ?></span>
                <a href="<?= h(link_to('../node.php', ['id' => $l['cn_event_id']])) ?>" target="_blank"><?= h($l['c_title']) ?></a>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    <?php endif;
    ?>

<?php
} else {
    $kw      = q('q');
    $fRegion = q('region');
    $fCat    = q('cat');
    $fFrom   = q('from');
    $fTo     = q('to');
    $page    = max(1, qi('page', 1));
    $perPage = 30;

    $where = ['1=1'];
    $args  = [];
    if ($fRegion) { $where[] = 'region = ?';  $args[] = $fRegion; }
    if ($fCat)    { $where[] = 'category = ?'; $args[] = $fCat; }
    if ($fFrom !== '' && is_numeric($fFrom)) { $where[] = 'year >= ?'; $args[] = (int) $fFrom; }
    if ($fTo   !== '' && is_numeric($fTo))   { $where[] = 'year <= ?'; $args[] = (int) $fTo; }
    if ($kw) {
        $where[] = '(title LIKE ? OR summary LIKE ? OR detail LIKE ? OR figures LIKE ? OR place LIKE ?)';
        $like = '%' . $kw . '%';
        array_push($args, $like, $like, $like, $like, $like);
    }
    $ws = implode(' AND ', $where);

    $total = (int) DB::fetchCol("SELECT COUNT(*) FROM world_events WHERE {$ws}", $args);
    $pages = max(1, (int) ceil($total / $perPage));
    $page  = min($page, $pages);
    $off   = ($page - 1) * $perPage;

    $rows = DB::fetchAll(
        "SELECT * FROM world_events WHERE {$ws}
         ORDER BY year ASC, month ASC, importance DESC, id ASC
         LIMIT {$perPage} OFFSET {$off}",
        $args
    );

    function wLink2(array $ov = []): string {
        global $kw, $fRegion, $fCat, $fFrom, $fTo, $page;
        return link_to('admin/world_events.php', array_merge([
            'q' => $kw, 'region' => $fRegion, 'cat' => $fCat, 'from' => $fFrom, 'to' => $fTo, 'page' => $page,
        ], array_filter($ov, fn($v) => $v !== '' && $v !== null)));
    }
    ?>
    <div class="page-head">
      <h1>世界大事</h1>
      <span class="muted small">共 <?= number_format($total) ?> 条</span>
      <div class="spacer"></div>
      <a class="btn-s primary" href="<?= h(alink('world_events.php', ['action' => 'new'])) ?>">+ 新增世界大事</a>
    </div>

    <div class="acard">
      <form class="afilter" method="get">
        <input type="text" name="q" value="<?= h($kw) ?>" placeholder="搜索标题、概述、人物…">
        <select name="region">
          <option value="">全部区域</option>
          <?php foreach ($regions as $s => $r): ?>
            <option value="<?= h($s) ?>" <?= $fRegion === $s ? 'selected' : '' ?>><?= h($r['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="cat">
          <option value="">全部主题</option>
          <?php foreach ($cats as $s => $c): ?>
            <option value="<?= h($s) ?>" <?= $fCat === $s ? 'selected' : '' ?>><?= h($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <input type="number" name="from" value="<?= h($fFrom) ?>" placeholder="起始年" style="width:96px">
        <input type="number" name="to" value="<?= h($fTo) ?>" placeholder="结束年" style="width:96px">
        <button class="btn-s dark" type="submit">筛选</button>
        <?php if ($kw || $fRegion || $fCat || $fFrom !== '' || $fTo !== ''): ?>
          <a class="btn-s" href="<?= h(alink('world_events.php')) ?>">清除</a>
        <?php endif; ?>
      </form>

      <?php if (!$rows): ?>
        <div class="aempty">没有匹配的世界大事。</div>
      <?php else: ?>
        <div style="overflow-x:auto">
        <table class="atable">
          <thead>
            <tr>
              <th style="width:96px">年份</th>
              <th>标题</th>
              <th style="width:96px">区域</th>
              <th style="width:80px">主题</th>
              <th style="width:52px">重要</th>
              <th style="width:130px">操作</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $w): ?>
            <tr>
              <td class="num nowrap"><?= h(fmt_year_range((int) $w['year'], !empty($w['year_end']) ? (int) $w['year_end'] : null, false)) ?></td>
              <td>
                <div class="t-title"><?= h($w['title']) ?></div>
                <?php if (!empty($w['summary'])): ?><div class="t-sum"><?= h($w['summary']) ?></div><?php endif; ?>
              </td>
              <td class="nowrap small"><?= h(region_emoji((string) $w['region'])) ?> <?= h(region_name((string) $w['region'])) ?></td>
              <td class="nowrap">
                <span class="pill" style="background:<?= h(cat_color((string) $w['category'])) ?>1a;color:<?= h(cat_color((string) $w['category'])) ?>"><?= h(cat_name((string) $w['category'])) ?></span>
              </td>
              <td class="num" style="text-align:center"><?= (int) $w['importance'] ?></td>
              <td>
                <div class="row-actions">
                  <a class="btn-s" href="<?= h(alink('world_events.php', ['action' => 'edit', 'id' => $w['id']])) ?>">编辑</a>
                  <a class="btn-s" href="<?= h(link_to('../world.php', ['id' => $w['id']])) ?>" target="_blank">预览</a>
                  <form method="post" style="display:inline"
                        onsubmit="return confirm('确定删除「<?= h(addslashes($w['title'])) ?>」？关联的联动也会删除。')">
                    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="op" value="delete">
                    <input type="hidden" name="id" value="<?= (int) $w['id'] ?>">
                    <button class="btn-s danger" type="submit">删除</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      <?php endif; ?>

      <?php if ($pages > 1): ?>
        <div class="pager-bar">
          <?php if ($page > 1): ?><a href="<?= h(wLink2(['page' => $page - 1])) ?>">← 上一页</a><?php endif; ?>
          <span class="cur"><?= $page ?> / <?= $pages ?></span>
          <?php if ($page < $pages): ?><a href="<?= h(wLink2(['page' => $page + 1])) ?>">下一页 →</a><?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
    <?php
}

require __DIR__ . '/../inc/admin_footer.php';
