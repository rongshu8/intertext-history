<?php
/**
 * 应用引导（所有入口页的第一行）
 * ---------------------------------------------------------------------------
 * 用法：每个可访问的 PHP 入口页开头写
 *
 *     <?php
 *     require __DIR__ . '/inc/bootstrap.php';
 *     $PAGE_TITLE = '...';
 *     require __DIR__ . '/inc/layout_header.php';
 *
 * 为什么必须有这个文件：
 *   helpers.php 里定义了 qi() / q() / h() / fmt_year() 等大量函数，
 *   但它过去是被 layout_header.php 间接 require 的。
 *   于是任何「在 require layout 之前调用这些函数」的页面都会
 *   Fatal error: Call to undefined function qi()。
 *   （node.php 就是这么挂的：第 20 行用 qi('id')，第 26 行才 require layout。）
 *
 *   把依赖收敛到这里，入口页只需一行 require，调用顺序不再重要。
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/datapack.php';

// 会话要在任何鉴权判断前启动（is_logged_in() / csrf_token() 都依赖它）
start_session();

// ---------------------------------------------------------------------------
// 全局 URL 助手
// ---------------------------------------------------------------------------

if (!function_exists('site_base')) {
    /**
     * 站点根路径前缀。
     * 实际实现复用 helpers.php 的 base_path()，此处只做转发，
     * 避免两处各写一份、行为不一致。
     */
    function site_base(): string
    {
        return base_path();
    }
}

if (!function_exists('link_to')) {
    /** 站内页面链接 */
    function link_to(string $path = '', array $query = []): string
    {
        $u = site_base() . '/' . ltrim($path, '/');
        if ($query) {
            $u .= (strpos($u, '?') === false ? '?' : '&') . http_build_query($query);
        }
        return $u;
    }
}

if (!function_exists('asset')) {
    /** 静态资源链接（带 mtime 版本号，避免浏览器缓存旧文件） */
    function asset(string $path): string
    {
        $rel = '/assets/' . ltrim($path, '/');
        $ver = @filemtime(__DIR__ . '/../assets/' . ltrim($path, '/'));
        return site_base() . $rel . ($ver ? '?v=' . $ver : '');
    }
}

if (!function_exists('alink')) {
    /**
     * 后台内部链接。
     * 用 site_base() 拼接，兼容子目录部署
     * （旧写法硬编码 'admin/' 前缀，在子目录下会 404）。
     */
    function alink(string $path = '', array $query = []): string
    {
        $u = site_base() . '/admin/' . ltrim($path, '/');
        if ($query) {
            $u .= (strpos($u, '?') === false ? '?' : '&') . http_build_query($query);
        }
        return $u;
    }
}

// ---------------------------------------------------------------------------
// 后台消息提示
// ---------------------------------------------------------------------------

if (!function_exists('flash')) {
    /** 记一条待显示的消息 */
    function flash(string $msg, string $type = 'ok'): void
    {
        if (!isset($_SESSION['_flash'])) {
            $_SESSION['_flash'] = [];
        }
        $_SESSION['_flash'][] = ['msg' => $msg, 'type' => $type];
    }
}

if (!function_exists('take_flash')) {
    /** 取出并清空待显示消息 */
    function take_flash(): array
    {
        $f = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $f;
    }
}
