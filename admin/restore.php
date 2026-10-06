<?php
/**
 * 后台 · 导入数据包
 * ---------------------------------------------------------------------------
 * 把一份 ZIP / JSON 数据包灌进当前库。两种模式：
 *
 *   replace 覆盖：先清空七张内容表再写入。适合全新安装、换站、还原到某个快照。
 *                **会删掉当前站的内容**，所以必须先出预检报告并要求二次确认。
 *   merge   合并：只补缺失的（按自然键去重），已有行一律不动。适合给现有站加内容。
 *
 * 安全设计（按重要性排序）：
 *   1. 全程单事务 —— 669 条插到第 400 条失败，整批回滚，不会留下半个库
 *   2. 先预检后写入 —— 校验不通过直接拒绝，不碰数据库
 *   3. 导入 users / submissions 是不可能的：这两个表根本不在数据包格式里
 *   4. 覆盖模式要求勾选确认框
 *
 * 用法：
 *   默认页面上传文件 → 预检（?act=check）→ 确认导入（?act=do）
 */

require __DIR__ . '/../inc/bootstrap.php';
require_admin();

$PAGE_TITLE = '导入数据';
$ADMIN_NAV  = 'import';
require __DIR__ . '/../inc/admin_header.php';

$spec    = datapack_spec();
$act     = q('act');
$stage   = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? 'post' : 'get';
$pending = [];   // 预检通过后暂存于 session 的包（避免大文件来回传）

// ---------------------------------------------------------------------------
// 第一步：上传并预检
// ---------------------------------------------------------------------------
if ($act === 'check') {
    $err = [];
    $dir = null;

    if (empty($_FILES['pack']) || ($_FILES['pack']['error'] ?? 9) !== UPLOAD_ERR_OK) {
        $err[] = '没有收到文件，或上传出错。';
    } else {
        $tmp = $_FILES['pack']['tmp_name'];
        $ext = strtolower(pathinfo($_FILES['pack']['name'], PATHINFO_EXTENSION));

        if ($ext === 'zip') {
            if (!class_exists('ZipArchive')) {
                $err[] = '本机 PHP 没有 ZipArchive 扩展，无法解压。请改为逐个上传 .json 文件。';
            } else {
                $dir = sys_get_temp_dir() . '/dp_in_' . getmypid() . '_' . time();
                @mkdir($dir, 0777, true);
                $zip = new ZipArchive();
                if ($zip->open($tmp) !== true) {
                    $err[] = 'ZIP 打开失败，可能已损坏。';
                } else {
                    // 只取我们认识的固定文件名，不按包内路径落盘（防目录穿越）
                    $want = [];
                    foreach ($spec as $s) $want[strtolower($s['file'])] = $s['file'];
                    $want['manifest.json'] = 'manifest.json';
                    $want['readme.txt']    = 'README.txt';
                    for ($i = 0; $i < $zip->numFiles; $i++) {
                        $name = $zip->getNameIndex($i);
                        if ($name === false || $name === '') continue;
                        $base = strtolower(basename($name));
                        if (!isset($want[$base])) continue;
                        $out = $dir . '/' . $want[$base];
                        $s = $zip->getStream($name);
                        if ($s) { file_put_contents($out, stream_get_contents($s)); fclose($s); }
                    }
                    $zip->close();
                }
            }
        } elseif ($ext === 'json') {
            $dir = sys_get_temp_dir() . '/dp_in_' . getmypid() . '_' . time();
            @mkdir($dir, 0777, true);
            $base = basename($_FILES['pack']['name']);
            // 单文件上传：按 manifest 里的 table 字段或文件名判断归属
            $doc = json_decode(file_get_contents($tmp), true);
            $table = null;
            if (is_array($doc)) {
                if (isset($doc['table']) && isset($spec[$doc['table']])) {
                    $table = $doc['table'];
                } else {
                    foreach ($spec as $tn => $ts) {
                        if (strtolower($ts['file']) === strtolower($base)) { $table = $tn; break; }
                    }
                }
            }
            if ($table === null) {
                $err[] = '认不出这个 JSON 属于哪张表：文件应命名为 ' . implode(' / ', array_column($spec, 'file'));
            } else {
                copy($tmp, $dir . '/' . $spec[$table]['file']);
            }
        } else {
            $err[] = '只支持 .zip 或 .json。';
        }
    }

    if (!$err && $dir !== null) {
        $data = datapack_read($dir);
        $rep  = datapack_validate($data, ['strict_fk' => true]);
        $pending = ['dir' => $dir, 'report' => $rep];
        if ($rep['errors']) {
            $err = array_slice($rep['errors'], 0, 15);
        }
    }

    if ($err) {
        if (!empty($pending['dir'])) @array_map('unlink', glob($pending['dir'] . '/*'));
        if (!empty($pending['dir'])) @rmdir($pending['dir']);
        ?>
        <h1>导入数据</h1>
        <div class="alert alert-err">
          <b>预检未通过，未写入任何数据。</b>
          <ul style="margin:8px 0 0 18px">
            <?php foreach ($err as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
          </ul>
        </div>
        <p><a class="btn" href="<?= h(alink('restore.php')) ?>">返回</a></p>
        <?php
        require __DIR__ . '/../inc/admin_footer.php';
        exit;
    }

    // 预检通过：把目录记进 session，实际导入时再读
    $_SESSION['dp_import_dir'] = $dir;
    flash('预检通过，可以选择导入方式。');
    header('Location: ' . alink('restore.php', ['act' => 'confirm']));
    exit;
}

// ---------------------------------------------------------------------------
// 第二步：确认并写入
// ---------------------------------------------------------------------------
if ($act === 'do') {
    $dir = $_SESSION['dp_import_dir'] ?? '';
    if (!$dir || !is_dir($dir)) {
        flash('预检结果已过期，请重新上传。', 'err');
        header('Location: ' . alink('restore.php'));
        exit;
    }
    $mode = p('mode') === 'merge' ? 'merge' : 'replace';
    if (!csrf_check()) {
        flash('会话已过期，请重试。', 'err');
    } else {
        try {
            $data = datapack_read($dir);
            $rep  = datapack_import($data, $mode);
            $msg  = '导入完成（' . ($mode === 'replace' ? '覆盖' : '合并') . '）：';
            foreach ($rep['inserted'] as $t => $n) {
                $msg .= ' ' . $spec[$t]['label'] . ' +' . $n;
                if (!empty($rep['skipped'][$t])) $msg .= '（跳过 ' . $rep['skipped'][$t] . '）';
            }
            flash($msg);
        } catch (Throwable $e) {
            flash('导入失败，已回滚：' . $e->getMessage(), 'err');
        }
    }
    @array_map('unlink', glob($dir . '/*'));
    @rmdir($dir);
    unset($_SESSION['dp_import_dir']);
    header('Location: ' . alink('restore.php'));
    exit;
}

// ---------------------------------------------------------------------------
// 确认页
// ---------------------------------------------------------------------------
$dir = $_SESSION['dp_import_dir'] ?? '';
if ($act === 'confirm' && $dir && is_dir($dir)) {
    $data = datapack_validate(datapack_read($dir), ['strict_fk' => true]);
    $cur  = [];
    foreach (datapack_tables() as $t) {
        try { $cur[$t] = (int) DB::fetchCol('SELECT COUNT(*) FROM `' . $t . '`'); }
        catch (Throwable $e) { $cur[$t] = -1; }
    }
    ?>
    <h1>导入数据</h1>
    <p class="muted">预检通过。请选择导入方式。</p>

    <div class="acard">
      <div class="acard-head"><h2>对比</h2></div>
      <table class="atable">
        <tr><th>内容</th><th class="num">当前站</th><th class="num">数据包</th><th class="num">差值</th></tr>
        <?php foreach (datapack_tables() as $t):
          $a = $cur[$t]; $b = (int) ($data['counts'][$t] ?? 0);
          $cls = $b > $a ? 'muted' : '';
        ?>
        <tr>
          <td><?= h($spec[$t]['label']) ?></td>
          <td class="num"><?= $a < 0 ? '—' : number_format($a) ?></td>
          <td class="num"><?= number_format($b) ?></td>
          <td class="num <?= $cls ?>"><?= $b > $a ? '+' : '' ?><?= number_format($b - $a) ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
      <?php if ($data['warnings']): ?>
        <div class="alert" style="margin:12px 0 0">
          <b>提示 <?= count($data['warnings']) ?> 条：</b>
          <ul style="margin:6px 0 0 18px">
            <?php foreach (array_slice($data['warnings'], 0, 8) as $w): ?><li><?= h($w) ?></li><?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
    </div>

    <form method="post" action="<?= h(alink('restore.php', ['act' => 'do'])) ?>"
          onsubmit="return confirm('导入会在单个事务里进行，失败会整体回滚。确定继续？');">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">

      <div class="acard">
        <div class="acard-head"><h2>合并（推荐用于已有内容的站）</h2></div>
        <div class="acard-body">
          <p>只补充数据包里有、当前站没有的条目（按标题 / slug 去重）。
             <b>当前站已有的内容一律不动</b>，不会删除任何东西。</p>
          <label style="display:flex;gap:8px;align-items:flex-start;cursor:pointer">
            <input type="radio" name="mode" value="merge" checked style="margin-top:4px">
            <span>合并导入</span>
          </label>
        </div>
      </div>

      <div class="acard" style="border-color:#d9534f">
        <div class="acard-head"><h2>覆盖（用于全新安装 / 还原快照）</h2></div>
        <div class="acard-body">
          <div class="alert alert-err" style="margin:0 0 12px">
            <b>会先清空以下 7 张表再写入：</b>
            <?= h(implode('、', array_column($spec, 'label'))) ?>。<br>
            当前站已有的内容将被<b>全部删除</b>。用户账号与投稿记录不受影响。
          </div>
          <div class="alert" style="margin:0 0 12px">
            <b>另外会失效的：</b>所有指向具体条目的旧链接。
            条目主键是自增的，覆盖导入后 id 会重新分配，
            此前分享出去的 <code>node.php?id=…</code>、<code>world.php?id=…</code>
            可能指向别的条目或直接 404。<br>
            如果只是想<b>补内容</b>，请用上面的「合并」—— 合并不动已有条目，链接也不会变。
          </div>
          <label style="display:flex;gap:8px;align-items:flex-start;cursor:pointer">
            <input type="radio" name="mode" value="replace" style="margin-top:4px">
            <span>覆盖导入（我已确认清空上述内容表）</span>
          </label>
        </div>
      </div>

      <p style="margin-top:16px">
        <button class="btn btn-primary" type="submit">开始导入</button>
        <a class="btn" href="<?= h(alink('restore.php', ['act' => 'cancel'])) ?>">取消</a>
      </p>
    </form>
    <?php
    if (q('act') === 'cancel') {
        @array_map('unlink', glob($dir . '/*'));
        @rmdir($dir);
        unset($_SESSION['dp_import_dir']);
    }
    require __DIR__ . '/../inc/admin_footer.php';
    exit;
}

// ---------------------------------------------------------------------------
// 上传页
// $_SESSION 里可能残留上一次的目录（用户没点取消就走了）
if ($dir && is_dir($dir) && $act !== 'confirm') {
    @array_map('unlink', glob($dir . '/*'));
    @rmdir($dir);
    unset($_SESSION['dp_import_dir']);
}
?>

<h1>导入数据</h1>
<p class="muted">上传一份内容数据包（ZIP 或单个 JSON）。导入前会先预检，
   校验不通过不会写入任何数据。</p>

<div class="acard">
  <div class="acard-head"><h2>上传数据包</h2></div>
  <div class="acard-body">
    <form method="post" action="<?= h(alink('restore.php', ['act' => 'check'])) ?>"
          enctype="multipart/form-data">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <p>
        <input type="file" name="pack" accept=".zip,.json" required
               style="font-size:14px">
      </p>
      <p class="small muted">
        ZIP 请包含 <?= h(implode('、', array_column($spec, 'file'))) ?>。
        也可以一次只上传一个 JSON 文件（会与其他表合并判断）。
      </p>
      <button class="btn btn-primary" type="submit">上传并预检</button>
    </form>
  </div>
</div>

<div class="acard">
  <div class="acard-head"><h2>当前站的内容规模</h2></div>
  <table class="atable">
    <tr><th>内容</th><th>文件</th><th class="num">行数</th></tr>
    <?php foreach (datapack_tables() as $t): $s = $spec[$t]; ?>
    <tr>
      <td><?= h($s['label']) ?></td>
      <td><code><?= h($s['file']) ?></code></td>
      <td class="num"><?php
        try { echo number_format((int) DB::fetchCol('SELECT COUNT(*) FROM `' . $t . '`')); }
        catch (Throwable $e) { echo '—'; }
      ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>

<div class="acard">
  <div class="acard-head"><h2>不会被动到的表</h2></div>
  <div class="acard-body">
    <p><code>users</code>（账号与密码）、<code>submissions</code>（投稿记录）不在数据包格式里，
       任何导入模式都不会碰它们。</p>
    <p>需要一份当前内容做备份？去 <a href="<?= h(alink('export.php')) ?>">导出页</a>下载。</p>
  </div>
</div>

<?php require __DIR__ . '/../inc/admin_footer.php'; ?>
