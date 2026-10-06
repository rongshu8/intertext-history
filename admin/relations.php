<?php
/**
 * 中外联动 · 管理
 * ---------------------------------------------------------------------------
 * 把「某条世界大事」挂到「某个中国节点」上，并标注关系类型与说明。
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_admin();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check()) {
        flash('会话已过期，请重试。', 'err');
        header('Location: ' . alink('relations.php'));;
        exit;
    }
    $op = p('op');

    if ($op === 'delete') {
        DB::exec('DELETE FROM relations WHERE id = ?', [pint('id')]);
        flash('联动已删除。');
        header('Location: ' . alink('relations.php', pint('cn_id') ? ['cn_id' => pint('cn_id')] : []));
        exit;
    }

    if ($op === 'save') {
        $rid      = pint('id');
        $cnId     = pint('cn_event_id');
        $weId     = pint('world_event_id');
        $type     = p('relation_type') ?: 'echo';
        $note     = p('note') ?: null;

        if ($cnId <= 0 || $weId <= 0) {
            flash('请同时选择中国节点与世界大事。', 'err');
            header('Location: ' . alink('relations.php'));;
            exit;
        }
        // 同一对关系不重复插入
        $dup = DB::fetchOne('SELECT id FROM relations WHERE cn_event_id = ? AND world_event_id = ?', [$cnId, $weId]);
        if ($dup && (int) $dup['id'] !== $rid) {
            flash('这两个事件之间已经有联动了。', 'warn');
            header('Location: ' . alink('relations.php'));;
            exit;
        }

        if ($rid > 0) {
            DB::exec('UPDATE relations SET cn_event_id=?, world_event_id=?, relation_type=?, note=? WHERE id=?',
                [$cnId, $weId, $type, $note, $rid]);
            flash('联动已更新。');
        } else {
            DB::exec('INSERT INTO relations (cn_event_id, world_event_id, relation_type, note, created_at) VALUES (?,?,?,?, ' . SQL_NOW . ')',
                [$cnId, $weId, $type, $note]);
            flash('联动已创建。');
        }
        header('Location: ' . alink('relations.php', ['cn_id' => $cnId]));
        exit;
    }
}

$PAGE_TITLE = '中外联动';
$ADMIN_NAV  = 'rel';
require __DIR__ . '/../inc/admin_header.php';

$rTypes   = dict_relation_types();
$filterCn = qi('cn_id');
$filterWe = qi('world_id');

$where = ['1=1'];
$args  = [];
if ($filterCn) { $where[] = 'r.cn_event_id = ?';    $args[] = $filterCn; }
if ($filterWe) { $where[] = 'r.world_event_id = ?'; $args[] = $filterWe; }
$ws = implode(' AND ', $where);

$rows = DB::fetchAll(
    "SELECT r.*,
            c.title AS c_title, c.year AS c_year, d.name AS c_dynasty, d.color AS c_color,
            w.title AS w_title, w.year AS w_year, w.region AS w_region
     FROM relations r
     LEFT JOIN cn_events c ON c.id = r.cn_event_id
     LEFT JOIN dynasties d ON d.id = c.dynasty_id
     LEFT JOIN world_events w ON w.id = r.world_event_id
     WHERE {$ws}
     ORDER BY c_year ASC, w_year ASC",
    $args
);

// 下拉选项
$cnOptions = DB::fetchAll(
    'SELECT e.id, e.title, e.year, d.name AS dynasty_name
     FROM cn_events e LEFT JOIN dynasties d ON d.id = e.dynasty_id
     ORDER BY e.year ASC'
);
$weOptions = DB::fetchAll('SELECT id, title, year, region FROM world_events ORDER BY year ASC');
?>

<div class="page-head">
  <h1>中外联动</h1>
  <span class="muted small"><?= count($rows) ?> 条</span>
  <div class="spacer"></div>
  <?php if ($filterCn): ?><a class="btn-s" href="<?= h(alink('relations.php')) ?>">清除筛选</a><?php endif; ?>
</div>

<div class="acard">
  <div class="acard-head">
    <h2>新建联动</h2>
    <div class="spacer"></div>
    <span class="small muted">标注「真正发生关联」的节点，不只是时间上的并列</span>
  </div>
  <div class="acard-pad">
    <form class="aform" method="post">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="op" value="save">
      <input type="hidden" name="id" value="0">

      <div class="grid-2">
        <div class="afield">
          <label>中国节点 <span style="color:#dc2626">*</span></label>
          <select name="cn_event_id" required>
            <option value="">— 选择中国节点 —</option>
            <?php foreach ($cnOptions as $c): ?>
              <option value="<?= (int) $c['id'] ?>" <?= $filterCn === (int) $c['id'] ? 'selected' : '' ?>>
                <?= h(fmt_year_range((int) $c['year'], null, false)) ?>　<?= h($c['title']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="afield">
          <label>世界大事 <span style="color:#dc2626">*</span></label>
          <select name="world_event_id" required>
            <option value="">— 选择世界大事 —</option>
            <?php foreach ($weOptions as $w): ?>
              <option value="<?= (int) $w['id'] ?>" <?= $filterWe === (int) $w['id'] ? 'selected' : '' ?>>
                <?= h(fmt_year_range((int) $w['year'], null, false)) ?>　<?= h($w['title']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="afield">
        <label>关系类型</label>
        <select name="relation_type">
          <?php foreach ($rTypes as $s => $t): ?>
            <option value="<?= h($s) ?>"><?= h($t['name']) ?> — <?= h($t['descr']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="afield">
        <label>说明</label>
        <textarea name="note" rows="3" placeholder="说清楚这两件事怎么联系在一起：为什么有因果？呼应在哪？"></textarea>
      </div>

      <div class="form-actions">
        <button class="btn-s primary" type="submit">创建联动</button>
      </div>
    </form>
  </div>
</div>

<div class="acard" style="margin-top:16px">
  <div class="acard-head"><h2>已有联动</h2></div>
  <?php if (!$rows): ?>
    <div class="aempty">还没有联动关系。</div>
  <?php else: ?>
    <div style="overflow-x:auto">
    <table class="atable">
      <thead>
        <tr>
          <th style="width:100px">中国节点</th>
          <th>关联</th>
          <th>关系</th>
          <th style="width:100px">世界大事</th>
          <th>说明</th>
          <th style="width:66px">操作</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="num nowrap"><?= h(fmt_year_range((int) $r['c_year'], null, false)) ?></td>
          <td>
            <a href="<?= h(link_to('../node.php', ['id' => $r['cn_event_id']])) ?>" target="_blank" style="font-weight:600;color:#1f2937"><?= h($r['c_title'] ?? '（已删除）') ?></a>
            <?php if (!empty($r['c_dynasty'])): ?>
              <div class="small muted" style="margin-top:2px"><?= h($r['c_dynasty']) ?></div>
            <?php endif; ?>
          </td>
          <td class="nowrap">
            <span class="pill" style="background:#ede9fe;color:#5f3dc4"><?= h($rTypes[$r['relation_type']]['name'] ?? $r['relation_type']) ?></span>
          </td>
          <td class="num nowrap"><?= h(fmt_year_range((int) $r['w_year'], null, false)) ?></td>
          <td>
            <a href="<?= h(link_to('../world.php', ['id' => $r['world_event_id']])) ?>" target="_blank" style="font-weight:600;color:#1f2937"><?= h($r['w_title'] ?? '（已删除）') ?></a>
            <?php if (!empty($r['note'])): ?><div class="t-sum"><?= h($r['note']) ?></div><?php endif; ?>
          </td>
          <td>
            <form method="post" onsubmit="return confirm('确定删除这条联动？')">
              <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="op" value="delete">
              <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <input type="hidden" name="cn_id" value="<?= (int) $r['cn_event_id'] ?>">
              <button class="btn-s danger" type="submit">删除</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../inc/admin_footer.php'; ?>
