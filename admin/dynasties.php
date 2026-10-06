<?php
/**
 * 朝代分期 · 管理
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_admin();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check()) {
        flash('会话已过期，请重试。', 'err');
        header('Location: ' . alink('dynasties.php'));;
        exit;
    }
    $op = p('op');

    if ($op === 'delete') {
        $did = pint('id');
        $n   = (int) DB::fetchCol('SELECT COUNT(*) FROM cn_events WHERE dynasty_id = ?', [$did]);
        if ($n > 0) {
            flash("该朝代下还有 {$n} 个节点，请先移动或删除它们。", 'err');
        } else {
            DB::exec('DELETE FROM dynasties WHERE id = ?', [$did]);
            flash('朝代已删除。');
        }
        header('Location: ' . alink('dynasties.php'));;
        exit;
    }

    if ($op === 'save') {
        $did   = pint('id');
        $name  = p('name');
        $slug  = p('slug') ?: preg_replace('/[^a-z0-9_]/', '', strtolower($name));
        $start = pint('start_year');
        $end   = pint('end_year');
        $color = p('color') ?: '#4c6ef5';
        $sort  = pint('sort', 0);
        $summ  = p('summary') ?: null;

        if ($name === '') {
            flash('朝代名称为必填项。', 'err');
            header('Location: ' . alink('dynasties.php'));;
            exit;
        }
        if ($end < $start) { $end = $start; }

        try {
            if ($did > 0) {
                DB::exec(
                    'UPDATE dynasties SET name=?, slug=?, start_year=?, end_year=?, color=?, summary=?, sort=? WHERE id=?',
                    [$name, $slug, $start, $end, $color, $summ, $sort, $did]
                );
                flash('朝代已更新。');
            } else {
                DB::exec(
                    'INSERT INTO dynasties (name, slug, start_year, end_year, color, summary, sort, created_at) VALUES (?,?,?,?,?,?,?, ' . SQL_NOW . ')',
                    [$name, $slug, $start, $end, $color, $summ, $sort]
                );
                flash('朝代已创建。');
            }
        } catch (Throwable $e) {
            flash('保存失败：' . $e->getMessage(), 'err');
        }
        header('Location: ' . alink('dynasties.php'));;
        exit;
    }
}

$PAGE_TITLE = '朝代分期';
$ADMIN_NAV  = 'dyn';
require __DIR__ . '/../inc/admin_header.php';

$editId = qi('edit');
$edit   = null;
if ($editId > 0) {
    foreach (all_dynasties() as $d) {
        if ((int) $d['id'] === $editId) { $edit = $d; break; }
    }
}
$counts = dynasty_event_counts();
?>

<?php if ($edit): ?>
  <div class="page-head">
    <h1>编辑朝代</h1>
    <div class="spacer"></div>
    <a class="btn-s" href="<?= h(alink('dynasties.php')) ?>">← 取消</a>
  </div>
  <form class="acard acard-pad" method="post">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="op" value="save">
    <input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
    <div class="aform">
      <div class="grid-3">
        <div class="afield">
          <label>名称 <span style="color:#dc2626">*</span></label>
          <input type="text" name="name" value="<?= h($edit['name']) ?>" required>
        </div>
        <div class="afield">
          <label>标识 slug</label>
          <input type="text" name="slug" value="<?= h($edit['slug']) ?>" placeholder="英文小写，如 tang">
          <span class="hint">用于 URL，只能用字母数字下划线</span>
        </div>
        <div class="afield">
          <label>排序值</label>
          <input type="number" name="sort" value="<?= (int) $edit['sort'] ?>">
          <span class="hint">小的排前面</span>
        </div>
      </div>
      <div class="grid-3">
        <div class="afield">
          <label>起始年</label>
          <input type="number" name="start_year" value="<?= (int) $edit['start_year'] ?>">
          <span class="hint">公元前用负数</span>
        </div>
        <div class="afield">
          <label>结束年</label>
          <input type="number" name="end_year" value="<?= (int) $edit['end_year'] ?>">
        </div>
        <div class="afield">
          <label>标识色</label>
          <input type="text" name="color" value="<?= h($edit['color']) ?>" placeholder="#4c6ef5">
        </div>
      </div>
      <div class="afield">
        <label>简介</label>
        <textarea name="summary" rows="2"><?= h((string) ($edit['summary'] ?? '')) ?></textarea>
      </div>
      <div class="form-actions">
        <button class="btn-s primary" type="submit">保存修改</button>
        <a class="btn-s" href="<?= h(alink('dynasties.php')) ?>">取消</a>
      </div>
    </div>
  </form>
<?php else: ?>

  <div class="page-head">
    <h1>朝代分期</h1>
    <span class="muted small"><?= count(all_dynasties()) ?> 个</span>
    <div class="spacer"></div>
    <a class="btn-s primary" href="<?= h(alink('dynasties.php', ['edit' => 0, 'new' => 1])) ?>#new">+ 新增朝代</a>
  </div>

  <div class="acard">
    <?php if (!$counts && !all_dynasties()): ?><div class="aempty">还没有朝代。</div><?php endif; ?>
    <?php if (all_dynasties()): ?>
    <div style="overflow-x:auto">
    <table class="atable">
      <thead>
        <tr>
          <th style="width:130px">朝代</th>
          <th style="width:130px">年代</th>
          <th>简介</th>
          <th style="width:70px">节点数</th>
          <th style="width:60px">排序</th>
          <th style="width:110px">操作</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach (all_dynasties() as $d): ?>
        <tr>
          <td class="nowrap">
            <span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:<?= h($d['color']) ?>;margin-right:7px"></span>
            <strong><?= h($d['name']) ?></strong>
            <div class="small muted" style="margin-top:1px"><?= h($d['slug']) ?></div>
          </td>
          <td class="num nowrap small"><?= h(fmt_year_range((int) $d['start_year'], (int) $d['end_year'], false)) ?></td>
          <td><div class="t-sum" style="-webkit-line-clamp:2"><?= h((string) ($d['summary'] ?? '')) ?></div></td>
          <td class="num" style="text-align:center">
            <a href="<?= h(alink('cn_events.php', ['dynasty' => $d['id']])) ?>" style="color:var(--indigo)"><?= (int) ($counts[(int) $d['id']] ?? 0) ?></a>
          </td>
          <td class="num" style="text-align:center"><?= (int) $d['sort'] ?></td>
          <td>
            <div class="row-actions">
              <a class="btn-s" href="<?= h(alink('dynasties.php', ['edit' => $d['id']])) ?>">编辑</a>
              <form method="post" style="display:inline" onsubmit="return confirm('确定删除朝代「<?= h(addslashes($d['name'])) ?>」？')">
                <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="op" value="delete">
                <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
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
  </div>

  <div class="acard" id="new" style="margin-top:16px">
    <div class="acard-head"><h2>新增朝代</h2></div>
    <div class="acard-pad">
      <form class="aform" method="post">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="op" value="save">
        <input type="hidden" name="id" value="0">
        <div class="grid-3">
          <div class="afield">
            <label>名称 <span style="color:#dc2626">*</span></label>
            <input type="text" name="name" required placeholder="如：三国">
          </div>
          <div class="afield">
            <label>标识 slug</label>
            <input type="text" name="slug" placeholder="留空自动生成">
          </div>
          <div class="afield">
            <label>排序值</label>
            <input type="number" name="sort" value="<?= count(all_dynasties()) * 10 + 10 ?>">
          </div>
        </div>
        <div class="grid-3">
          <div class="afield"><label>起始年</label><input type="number" name="start_year" value="0" required></div>
          <div class="afield"><label>结束年</label><input type="number" name="end_year" value="0" required></div>
          <div class="afield"><label>标识色</label><input type="text" name="color" value="#4c6ef5"></div>
        </div>
        <div class="afield">
          <label>简介</label>
          <textarea name="summary" rows="2" placeholder="显示在时间轴朝代分隔处"></textarea>
        </div>
        <div class="form-actions">
          <button class="btn-s primary" type="submit">创建朝代</button>
        </div>
      </form>
    </div>
  </div>

<?php endif; ?>

<?php require __DIR__ . '/../inc/admin_footer.php'; ?>
