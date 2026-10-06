<?php
/**
 * 后台 · 导出数据包
 * ---------------------------------------------------------------------------
 * 把当前库的全部内容导出一份 ZIP，结构与 data/seed/ 完全一致：
 *
 *   manifest.json  categories.json  regions.json  relation_types.json
 *   dynasties.json cn_events.json   world_events.json relations.json
 *
 * 这份 ZIP 可以：
 *   - 直接覆盖 data/seed/ 后随安装包分发（开源用）
 *   - 在别的站的后台「导入」里原样灌回去（换站 / 回滚 / 合并内容）
 *
 * 不导出的东西（有意为之，别改）：
 *   users            账号与密码哈希，绝不能进任何可分发的包
 *   submissions      草稿与审核记录，是站点的内部流程不是内容
 *   created_at 等    「何时写进这个站」，不是内容本身
 *
 * 用法：直接打开本页即下载；?preview=1 只看统计不下载。
 */

require __DIR__ . '/../inc/bootstrap.php';
require_admin();

$PAGE_TITLE = '导出数据';
$ADMIN_NAV  = 'export';
require __DIR__ . '/../inc/admin_header.php';

$spec = datapack_spec();

if (q('preview') !== '') {
    $counts = [];
    foreach (datapack_tables() as $t) {
        try {
            $counts[$t] = (int) DB::fetchCol('SELECT COUNT(*) FROM `' . $t . '`');
        } catch (Throwable $e) {
            $counts[$t] = -1;
        }
    }
    ?>
    <h1>导出数据</h1>
    <p class="muted">下面是当前库的内容规模。确认无误后去掉
       <code>?preview=1</code> 访问本页即可下载 ZIP。</p>

    <div class="acard">
      <div class="acard-head"><h2>将要导出的内容</h2></div>
      <table class="atable">
        <tr><th>文件</th><th>内容</th><th class="num">行数</th><th>主键口径</th></tr>
        <?php foreach (datapack_tables() as $t): $s = $spec[$t]; ?>
        <tr>
          <td><code><?= h($s['file']) ?></code></td>
          <td><?= h($s['label']) ?></td>
          <td class="num"><?= $counts[$t] < 0 ? '—' : number_format($counts[$t]) ?></td>
          <td><?= $s['natural_key'] ? h($s['natural_key']) : '<span class="muted">（引用两端）</span>' ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>

    <div class="acard">
      <div class="acard-head"><h2>不会出现在导出里</h2></div>
      <div class="acard-body">
        <p><b>用户账号</b>（<code>users</code>）—— 包含密码哈希，任何情况下都不进可分发的包。
           换站请在新站重新注册管理员。</p>
        <p><b>投稿记录</b>（<code>submissions</code>）—— 草稿与审核流转属于站点内部状态。
           已通过审核的内容会作为正式条目出现在 <code>cn_events</code> / <code>world_events</code> 里。</p>
        <p><b>时间戳</b>（<code>created_at</code> 等）—— 导入时由数据库重新生成。</p>
      </div>
    </div>

    <p style="margin-top:16px">
      <a class="btn btn-primary" href="<?= h(alink('export.php')) ?>">下载 ZIP</a>
      <a class="btn" href="<?= h(alink('restore.php')) ?>">前往导入</a>
    </p>
    <?php
    require __DIR__ . '/../inc/admin_footer.php';
    exit;
}

// ---------------------------------------------------------------------------
// 真正导出
// ---------------------------------------------------------------------------
$tmp = sys_get_temp_dir() . '/dp_export_' . getmypid() . '_' . time();
@mkdir($tmp, 0777, true);

try {
    $res = datapack_write($tmp, ['source' => 'admin-export']);
} catch (Throwable $e) {
    @array_map('unlink', glob($tmp . '/*'));
    @rmdir($tmp);
    flash('导出失败：' . $e->getMessage(), 'err');
    header('Location: ' . alink('export.php'));
    exit;
}

// 顺手写一份人读的说明，放进 ZIP 根部
@file_put_contents($tmp . '/README.txt', datapack_readme($res['manifest']));

$zipName = 'datapack-' . date('Ymd-His') . '.zip';
$zipPath = $tmp . '.zip';

// PHP 的 ZipArchive 在部分主机上不可用；不可用时退化为逐文件下载清单
if (class_exists('ZipArchive')) {
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
        foreach (glob($tmp . '/*') as $f) {
            $zip->addFile($f, basename($f));
        }
        $zip->close();

        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $zipName . '"');
        header('Content-Length: ' . filesize($zipPath));
        header('Cache-Control: no-store');
        readfile($zipPath);
        @unlink($zipPath);
    } else {
        flash('无法创建 ZIP，请改用文件列表逐个下载。', 'err');
        header('Location: ' . alink('export.php'));
        exit;
    }
} else {
    // 没有 ZipArchive：把每个文件作为独立下载
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: text/plain; charset=utf-8');
    echo "本机 PHP 没有 ZipArchive 扩展，无法打包。\n";
    echo "请到服务器上直接取用 data/seed/ 目录，或安装 ZipArchive 后重试。\n\n";
    foreach ($res['files'] as $name => $info) {
        echo '  ' . $name . '  (' . number_format($info['bytes']) . " B, {$info['rows']} 行)\n";
    }
}

@array_map('unlink', glob($tmp . '/*'));
@rmdir($tmp);
exit;

/** 生成 ZIP 里的 README.txt */
function datapack_readme(array $manifest): string
{
    $lines = [];
    $lines[] = '互文 · 世界同期大事录 —— 内容数据包';
    $lines[] = str_repeat('=', 46);
    $lines[] = '';
    $lines[] = '格式版本 : ' . $manifest['format'] . ' v' . $manifest['version'];
    $lines[] = '导出时间 : ' . $manifest['generated_at'];
    $lines[] = '站点     : ' . $manifest['site'];
    $lines[] = '【这是什么】';
    $lines[] = '本站的全部内容（朝代、中国节点、世界大事、中外联动、字典表），';
    $lines[] = '以语言无关的 JSON 文件形式存放。不含用户账号与投稿记录。';
    $lines[] = '';
    $lines[] = '【怎么用】';
    $lines[] = '· 全新安装：把这些文件放进 <站点>/data/seed/，打开安装器即可。';
    $lines[] = '· 已有站点：后台「导入」页面上传本包，可选覆盖或合并。';
    $lines[] = '· 想改内容：直接编辑 JSON，用任意文本编辑器都行，不限于 PHP。';
    $lines[] = '';
    $lines[] = '【约定】';
    $lines[] = '· 不含主键 id —— 两次安装的自增 id 完全不同，跨表一律用';
    $lines[] = '  slug（字典、朝代）或标题（节点、大事）互引，装载时解析成 id。';
    $lines[] = '· 值为 null 的字段不写出来，缺字段即表示空。';
    $lines[] = '· 年份是整数，公元前为负数（公元前 221 年写作 -221）。';
    $lines[] = '· 中文以 UTF-8 原样存放，未做 \\u 转义。';
    $lines[] = '【文件清单】';
    foreach ($manifest['files'] as $name => $info) {
        $lines[] = sprintf('  %-22s %6d 行  %8s  sha256:%s',
            $name, $info['rows'], number_format($info['bytes']) . 'B', substr($info['sha256'], 0, 12));
    }
    $lines[] = '';
    $lines[] = '校验：sha256 见上，可用 sha256sum 逐一核对。';
    return implode("\n", $lines) . "\n";
}
