<?php
/**
 * 安装闸门 —— 未安装时把访问者导向安装向导
 * ---------------------------------------------------------------------------
 * 用法：每个**前台**入口页，在 require bootstrap.php **之前**写一行
 *
 *     require __DIR__ . '/inc/install_gate.php';
 *
 * 为什么必须在 bootstrap 之前：
 *   bootstrap → db.php → DB::pdo() → new PDO(…)，连不上就抛异常，
 *   未配置数据库时整页直接 Fatal error，用户看到的是白屏 + 500，
 *   完全不知道「哦原来要先去装」。
 *
 *   而「有没有装」这件事不需要连库就能判断 ——
 *   看两个文件在不在：config.local.php（配了库信息）与
 *   data/install.lock（装完了并上了锁）。**不碰数据库，一次 I/O 都省了。**
 *
 * 已安装的站，这个文件的开销是两次 is_file()，可忽略。
 *
 * 为什么只给前台页加：
 *   后台/用户中心的入口本来就少人直接访问，而 install_gate 会 header 跳转，
 *   放在那里纯属多余。且 admin/logout.php 这类「要求有会话」的页面
 *   未安装时跳转过去也没意义。
 */

if (!function_exists('huwen_needs_install')) {

    /**
     * 是否需要引导到安装向导。
     *
     * 判据（任一成立即需要安装）：
     *   1. config.local.php 不存在 —— 还没填数据库信息
     *   2. 根目录没有 index.php 对应的可访问安装器 —— 装着但安装器被删了
     *      （这种情况不该跳，因为站点其实能跑；交给它正常报错更有用）
     *
     * 刻意**不**做的事：
     *   - 不连数据库（连不上会抛异常，闸门自己先挂了）
     *   - 不检查表是否存在（同样要连库）
     *   - 不写任何文件
     */
    function huwen_needs_install(): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $root = dirname(__DIR__);
        // config.local.php 缺失 = 没有任何数据库信息 = 必然没装成
        $cached = !is_file($root . '/config.local.php');
        return $cached;
    }

    /**
     * 已安装但安装器还在 → 提示一次。装完之后这个提示才出现。
     *
     * 单独抽出来是因为它依赖 data/install.lock，
     * 与「有没有配置」是两个独立维度。
     */
    function huwen_is_locked(): bool
    {
        return is_file(dirname(__DIR__) . '/data/install.lock');
    }

    /**
     * 站点基路径（如 '' 或 '/history'）。
     *
     * 这里内联了 helpers.php 里 base_path() 的算法，而不是调它 ——
     * 闸门跑在 bootstrap **之前**，helpers.php 还没加载。
     *
     * 规则与 base_path() 必须一致：
     *   /index.php          → ''
     *   /admin/login.php    → ''   （admin 是应用内部目录，不是部署前缀）
     *   /history/node.php   → '/history'
     *   /history/admin/x.php→ '/history'
     *
     * $internal 里多了一个 'inc'：本文件自己就位于 inc/ 下，
     * 入口页 require 它时 SCRIPT_NAME 是入口页的（如 /index.php），
     * 不会命中；但若哪天从 inc/ 内直接访问本文件，不加就会把 /inc 当部署前缀。
     */
    function huwen_base_path(): string
    {
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
        $parts  = array_values(array_filter(explode('/', $script), 'strlen'));
        if (!empty($parts)) {
            array_pop($parts);   // 剥掉文件名
        }
        $internal = ['admin', 'user', 'inc'];
        for ($i = count($parts) - 1; $i >= 0; $i--) {
            if (in_array(strtolower($parts[$i]), $internal, true)) {
                array_splice($parts, $i, 1);
            } else {
                break;
            }
        }
        return $parts ? '/' . implode('/', $parts) : '';
    }

    /**
     * 把当前请求导向安装向导并结束。
     */
    function huwen_redirect_to_install(string $here = ''): void
    {
        // 已经在安装器上了就别再跳，否则死循环
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        if (preg_match('~/install\.php$~i', $script)) {
            return;
        }

        $target = huwen_base_path() . '/install.php';

        // 带上来源，装完可以跳回去。
        // 只接受以单个 / 开头的同源路径。防开放重定向的关键：
        //   形如「双斜杠 + 主机名」的协议相对 URL 也以 / 开头，不挡就是漏洞。
        if ($here !== '' && $here[0] === '/' && strpos($here, '//') === false) {
            $target .= '?from=' . rawurlencode($here);
        }

        if (!headers_sent()) {
            header('Location: ' . $target, true, 302);
        }
        exit;
    }
}

if (function_exists('huwen_needs_install') && huwen_needs_install()) {
    huwen_redirect_to_install($_SERVER['REQUEST_URI'] ?? '');
}
