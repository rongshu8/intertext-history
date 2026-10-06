<?php
/**
 * 投稿内容校验与审核落地
 * ---------------------------------------------------------------------------
 * 抽出独立文件，让 user/submit.php（提交时校验）与
 * admin/review.php（审核通过时落地）共用同一套规则，
 * 避免「提交时过了、审核时字段对不上」这类不一致。
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/helpers.php';

/**
 * 校验并规范化投稿内容
 *
 * @param string $kind  cn_node | world_event | relation
 * @param array  $raw   $_POST 原始数据
 * @return array{ok:bool, errors:string[], data:array}
 */
function submission_validate(string $kind, array $raw): array
{
    $errors = [];
    $data   = [];

    $validCats    = array_keys(dict_categories());
    $validRegions = array_keys(dict_regions());
    $validRTypes  = array_keys(dict_relation_types());

    if ($kind === 'cn_node') {
        $dynastyId = (int) ($raw['dynasty_id'] ?? 0);
        $title     = trim((string) ($raw['title'] ?? ''));
        $year      = trim((string) ($raw['year'] ?? ''));

        if ($dynastyId <= 0)   $errors[] = '请选择朝代。';
        if ($title === '')     $errors[] = '请填写标题。';
        if (mb_strlen($title) > 160) $errors[] = '标题不能超过 160 字。';
        if ($year === '' || !is_numeric($year)) $errors[] = '请填写年份，公元前用负数（如 -221）。';

        $yearEnd = trim((string) ($raw['year_end'] ?? ''));
        $month   = trim((string) ($raw['month'] ?? ''));
        $day     = trim((string) ($raw['day'] ?? ''));

        if ($yearEnd !== '' && is_numeric($year) && is_numeric($yearEnd) && (int) $yearEnd < (int) $year) {
            $errors[] = '结束年份不能早于起始年份。';
        }

        $cat = (string) ($raw['category'] ?? 'politics');
        if (!in_array($cat, $validCats, true)) $cat = 'politics';

        $data = [
            'dynasty_id' => $dynastyId,
            'title'      => $title,
            'year'       => is_numeric($year) ? (int) $year : 0,
            'year_end'   => ($yearEnd !== '' && is_numeric($yearEnd)) ? (int) $yearEnd : null,
            'month'      => ($month !== '' && is_numeric($month)) ? (int) $month : null,
            'day'        => ($day !== '' && is_numeric($day)) ? (int) $day : null,
            'category'   => $cat,
            'place'      => trim((string) ($raw['place'] ?? '')) ?: null,
            'summary'    => trim((string) ($raw['summary'] ?? '')) ?: null,
            'detail'     => trim((string) ($raw['detail'] ?? '')) ?: null,
            'figures'    => trim((string) ($raw['figures'] ?? '')) ?: null,
            'importance' => max(1, min(5, (int) ($raw['importance'] ?? 3))),
            'is_key'     => isset($raw['is_key']) ? 1 : 0,
        ];
        if (mb_strlen((string) $data['summary']) > 500) $errors[] = '一句话概述不能超过 500 字。';

    } elseif ($kind === 'world_event') {
        $title = trim((string) ($raw['title'] ?? ''));
        $year  = trim((string) ($raw['year'] ?? ''));

        if ($title === '') $errors[] = '请填写标题。';
        if (mb_strlen($title) > 200) $errors[] = '标题不能超过 200 字。';
        if ($year === '' || !is_numeric($year)) $errors[] = '请填写年份，公元前用负数。';

        $yearEnd = trim((string) ($raw['year_end'] ?? ''));
        if ($yearEnd !== '' && is_numeric($year) && is_numeric($yearEnd) && (int) $yearEnd < (int) $year) {
            $errors[] = '结束年份不能早于起始年份。';
        }

        $region = (string) ($raw['region'] ?? 'global');
        if (!in_array($region, $validRegions, true)) $region = 'global';
        $cat = (string) ($raw['category'] ?? 'politics');
        if (!in_array($cat, $validCats, true)) $cat = 'politics';

        $month = trim((string) ($raw['month'] ?? ''));
        $day   = trim((string) ($raw['day'] ?? ''));

        $data = [
            'title'      => $title,
            'year'       => is_numeric($year) ? (int) $year : 0,
            'year_end'   => ($yearEnd !== '' && is_numeric($yearEnd)) ? (int) $yearEnd : null,
            'month'      => ($month !== '' && is_numeric($month)) ? (int) $month : null,
            'day'        => ($day !== '' && is_numeric($day)) ? (int) $day : null,
            'region'     => $region,
            'category'   => $cat,
            'place'      => trim((string) ($raw['place'] ?? '')) ?: null,
            'summary'    => trim((string) ($raw['summary'] ?? '')) ?: null,
            'detail'     => trim((string) ($raw['detail'] ?? '')) ?: null,
            'figures'    => trim((string) ($raw['figures'] ?? '')) ?: null,
            'importance' => max(1, min(5, (int) ($raw['importance'] ?? 3))),
            'source'     => trim((string) ($raw['source'] ?? '')) ?: null,
        ];
        if (mb_strlen((string) $data['summary']) > 500) $errors[] = '一句话概述不能超过 500 字。';

    } elseif ($kind === 'relation') {
        $cnId = (int) ($raw['cn_event_id'] ?? 0);
        $weId = (int) ($raw['world_event_id'] ?? 0);
        $type = (string) ($raw['relation_type'] ?? 'echo');
        $note = trim((string) ($raw['note'] ?? ''));

        $cn = $cnId > 0 ? get_cn_event($cnId) : null;
        $we = $weId > 0 ? get_world_event($weId) : null;

        if (!$cn) $errors[] = '请选择一个已收录的中国节点。';
        if (!$we) $errors[] = '请选择一条已收录的世界大事。';
        if (!in_array($type, $validRTypes, true)) $type = 'echo';
        if ($note === '') $errors[] = '请说明这两件事的关联理由。';
        if (mb_strlen($note) > 500) $errors[] = '说明不能超过 500 字。';

        // 同一对关系不重复
        if ($cn && $we) {
            $dup = DB::fetchOne(
                'SELECT id FROM relations WHERE cn_event_id = ? AND world_event_id = ?',
                [$cnId, $weId]
            );
            if ($dup) {
                $errors[] = '这两个事件之间已经有联动了。';
            }
        }

        $data = [
            'cn_event_id'    => $cnId,
            'world_event_id' => $weId,
            'relation_type'  => $type,
            'note'           => $note,
        ];

    } else {
        $errors[] = '未知的投稿类型。';
    }

    return ['ok' => empty($errors), 'errors' => $errors, 'data' => $data];
}

/**
 * 审核通过：把投稿写入正式表
 *
 * @return array{ok:bool, error?:string, target_id?:int, warning?:string}
 */
function submission_publish(int $submissionId, int $reviewerId, string $note = ''): array
{
    $sub = get_submission($submissionId);
    if (!$sub) {
        return ['ok' => false, 'error' => '投稿不存在。'];
    }
    if ($sub['status'] !== 'pending') {
        return ['ok' => false, 'error' => '该投稿已被处理过。'];
    }
    $p = submission_payload($sub);
    if (!$p) {
        return ['ok' => false, 'error' => '投稿内容无法解析。'];
    }

    $warning = '';
    $targetId = null;

    try {
        $targetId = DB::transaction(function () use ($sub, $p, $reviewerId, $note, &$warning, $submissionId) {
            $kind = (string) $sub['kind'];

            if ($kind === 'cn_node') {
                // 标题重复时加后缀，避免与既有内容冲突导致覆盖
                $title = (string) ($p['title'] ?? '');
                $title = submission_unique_title('cn_events', $title);
                if ($title !== (string) ($p['title'] ?? '')) {
                    $warning = '标题与既有内容重复，发布时自动改为「' . $title . '」。';
                }
                DB::exec(
                    'INSERT INTO cn_events (dynasty_id,title,year,year_end,month,day,category,place,summary,detail,figures,importance,is_key, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?, ' . SQL_NOW . ')',
                    [
                        (int) $p['dynasty_id'], $title, (int) $p['year'], $p['year_end'] ?? null,
                        $p['month'] ?? null, $p['day'] ?? null, (string) $p['category'],
                        $p['place'] ?? null, $p['summary'] ?? null, $p['detail'] ?? null,
                        $p['figures'] ?? null, (int) ($p['importance'] ?? 3), (int) ($p['is_key'] ?? 0),
                    ]
                );
                return DB::lastInsertId();
            }

            if ($kind === 'world_event') {
                $title = (string) ($p['title'] ?? '');
                $title = submission_unique_title('world_events', $title);
                if ($title !== (string) ($p['title'] ?? '')) {
                    $warning = '标题与既有内容重复，发布时自动改为「' . $title . '」。';
                }
                DB::exec(
                    'INSERT INTO world_events (title,year,year_end,month,day,region,category,place,summary,detail,figures,importance,source, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?, ' . SQL_NOW . ')',
                    [
                        $title, (int) $p['year'], $p['year_end'] ?? null,
                        $p['month'] ?? null, $p['day'] ?? null, (string) $p['region'],
                        (string) $p['category'], $p['place'] ?? null, $p['summary'] ?? null,
                        $p['detail'] ?? null, $p['figures'] ?? null,
                        (int) ($p['importance'] ?? 3), $p['source'] ?? null,
                    ]
                );
                return DB::lastInsertId();
            }

            if ($kind === 'relation') {
                $cnId = (int) ($p['cn_event_id'] ?? 0);
                $weId = (int) ($p['world_event_id'] ?? 0);
                // 审核时再查一次：投稿期间原事件可能被删
                if (!get_cn_event($cnId) || !get_world_event($weId)) {
                    throw new RuntimeException('关联的中国节点或世界大事已不存在，无法发布。');
                }
                DB::exec(
                    'INSERT INTO relations (cn_event_id, world_event_id, relation_type, note, created_at) VALUES (?,?,?,?, ' . SQL_NOW . ')',
                    [$cnId, $weId, (string) ($p['relation_type'] ?? 'echo'), $p['note'] ?? null]
                );
                return DB::lastInsertId();
            }

            throw new RuntimeException('未知的投稿类型：' . $kind);
        });
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => '发布失败：' . $e->getMessage()];
    }

    // 更新投稿状态
    // SQL_NOW 定义在 config.php / inc/db.php，固定为 NOW()（MySQL 专用）
    DB::exec(
        'UPDATE submissions
         SET status = ?, review_note = ?, reviewer_id = ?, reviewed_at = ' . SQL_NOW . ',
             target_id = ?, updated_at = ' . SQL_NOW . '
         WHERE id = ?',
        ['approved', $note ?: null, $reviewerId, $targetId, $submissionId]
    );

    return ['ok' => true, 'target_id' => $targetId, 'warning' => $warning];
}

/** 审核驳回 */
function submission_reject(int $submissionId, int $reviewerId, string $note = ''): array
{
    $sub = get_submission($submissionId);
    if (!$sub) {
        return ['ok' => false, 'error' => '投稿不存在。'];
    }
    if ($sub['status'] !== 'pending') {
        return ['ok' => false, 'error' => '该投稿已被处理过。'];
    }
    DB::exec(
        'UPDATE submissions
         SET status = ?, review_note = ?, reviewer_id = ?, reviewed_at = ' . SQL_NOW . ',
             updated_at = ' . SQL_NOW . '
         WHERE id = ?',
        ['rejected', $note ?: null, $reviewerId, $submissionId]
    );
    return ['ok' => true];
}

/** 用户撤回自己的待审投稿 */
function submission_withdraw(int $submissionId, int $userId): array
{
    $sub = get_submission($submissionId);
    if (!$sub || (int) $sub['user_id'] !== $userId) {
        return ['ok' => false, 'error' => '投稿不存在或无权操作。'];
    }
    if ($sub['status'] !== 'pending') {
        return ['ok' => false, 'error' => '只有待审核的投稿可以撤回。'];
    }
    DB::exec("UPDATE submissions SET status = 'withdrawn', updated_at = " . SQL_NOW . " WHERE id = ?", [$submissionId]);
    return ['ok' => true];
}

/**
 * 生成不重复的标题：重复则加 (2) (3) … 后缀
 *
 * $table 会直接拼进 SQL（表名无法用预处理占位），因此必须过白名单。
 * 即使当前只有两个硬编码调用点，也保留这层校验 —— 函数是 public 的，
 * 将来若有人从请求参数传入就会直接变成注入漏洞。
 */
function submission_unique_title(string $table, string $title): string
{
    static $allowed = ['cn_events' => true, 'world_events' => true];
    if (!isset($allowed[$table])) {
        throw new InvalidArgumentException('非法的表名: ' . $table);
    }
    $suffix = $title . '（补充）';
    $n = 2;
    while (DB::fetchOne("SELECT id FROM {$table} WHERE title = ?", [$suffix])) {
        $suffix = $title . '（补充' . $n . '）';
        $n++;
        if ($n > 50) {
            $suffix = $title . '（补充' . date('His') . '）';
            break;
        }
    }
    return $suffix;
}
