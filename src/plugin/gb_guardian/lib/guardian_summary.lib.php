<?php
/**
 * 그누보드 운영지킴이 - 요약 발송 시스템
 *
 * 책임:
 *   1. 일일 / 주간 요약 처리 (cron 또는 visitor 트리거에서 호출)
 *   2. 큐 → 통계 집계 → 메일 발송 → 큐 처리완료 표시
 *   3. 발송 시점 판정 (HH:00 윈도우 + 중복 발송 방지)
 *   4. 데이터 정리 (큐 / 매칭 로그 / 해결된 오류 로그)
 *
 * 모든 외부 호출(메일 발송) 은 guardian_send_email() 사용.
 * 발송 보호 시스템(비상정지 / 일일한도) 은 그대로 적용된다.
 *
 * (c) 2026 K3SOFT / WizardOfCode
 *
 * @package gb_guardian
 * @version 1.0.0
 */
if (!defined('_GNUBOARD_')) exit;

// =====================================================================
// 1. 일일 / 주간 요약 처리
// =====================================================================
/**
 * mode='daily' 활성 규칙 모두에 대해 발송 시점을 체크하고, 시점이 맞으면
 * 큐에서 미처리 항목을 가져와 요약 메일을 발송한다.
 *
 * @return void
 */
function guardian_process_daily_summaries()
{
    guardian_process_summaries('daily');
}

/**
 * mode='weekly' 활성 규칙 처리.
 *
 * @return void
 */
function guardian_process_weekly_summaries()
{
    guardian_process_summaries('weekly');
}

/**
 * 일일/주간 공통 처리 본체.
 *
 * @param  string $mode 'daily' | 'weekly'
 * @return void
 */
function guardian_process_summaries($mode)
{
    global $g5, $config;

    if ($mode !== 'daily' && $mode !== 'weekly') return;
    if (empty($g5['guardian_rule_table'])) return;

    // 활성 + 해당 모드 규칙 조회
    $sql = " SELECT * FROM `{$g5['guardian_rule_table']}`
             WHERE rule_active = 1
               AND mode = '" . sql_escape_string($mode) . "'
             ORDER BY rule_id ASC ";
    $result = @sql_query($sql, false);
    if (!$result) return;

    while ($rule = sql_fetch_array($result)) {
        try {
            // 발송 시점 체크 (지금이 그 시각인가)
            if (!guardian_should_send_summary_now($rule)) continue;

            // 오늘/이번주 이미 보냈는가 (중복 발송 방지)
            if (guardian_already_sent_summary($rule['rule_id'], $mode)) continue;

            // 큐에서 미처리 항목 조회
            $items = guardian_get_pending_summary_items($rule['rule_id'], $mode);
            if (empty($items)) continue; // 발송할 오류 없으면 메일 안 보냄 (조용한 정책)

            // 요약 발송
            guardian_send_summary_for_rule($rule, $mode, $items);

            // 큐 처리완료 표시
            $queue_ids = array();
            foreach ($items as $it) {
                if (!empty($it['queue_id'])) $queue_ids[] = (int)$it['queue_id'];
            }
            guardian_mark_summary_items_processed($queue_ids);

        } catch (Exception $e) {
            // 한 규칙 실패가 다른 규칙을 막지 않도록 흡수
        }
    }

    // 전체 처리 완료 시각 기록
    // v1.1+ : guardian_config 테이블에 저장 (g5_config 컬럼은 제거됨)
    $ts  = date('Y-m-d H:i:s');
    $key = ($mode === 'daily') ? 'summary_last_daily' : 'summary_last_weekly';
    if (function_exists('guardian_config_set')) {
        guardian_config_set($key, $ts);
    }
    // 글로벌 $config 캐시도 즉시 갱신해 이후 로직이 최신값을 볼 수 있도록
    $cf_col = ($mode === 'daily') ? 'cf_guardian_summary_last_daily' : 'cf_guardian_summary_last_weekly';
    if (isset($config) && is_array($config)) {
        $config[$cf_col] = $ts;
    }
}

// =====================================================================
// 2. 발송 시점 판단
// =====================================================================
/**
 * 지금이 이 규칙의 요약 발송 시각 윈도우(±1시간) 내인가.
 *
 * cron 이 5분마다 돌든, 방문자 트리거가 5분 가드를 두고 동작하든 1시간
 * 윈도우면 충분히 한 번은 통과한다.
 *
 * @param  array $rule
 * @return bool
 */
function guardian_should_send_summary_now(array $rule)
{
    $mode = isset($rule['mode']) ? (string)$rule['mode'] : '';
    $sched = isset($rule['schedule_time']) ? (string)$rule['schedule_time'] : '';

    if ($mode === 'daily') {
        // 형식: "HH:MM"
        $parts = explode(':', $sched);
        if (count($parts) !== 2) return false;

        $target_h = (int)$parts[0];
        if ($target_h < 0 || $target_h > 23) return false;

        $now_h = (int)date('G');
        return ($now_h === $target_h);
    }

    if ($mode === 'weekly') {
        // 형식: "D|HH:MM" (1=월~7=일, ISO)
        $parts = explode('|', $sched);
        if (count($parts) !== 2) return false;

        $target_dow = (int)$parts[0];
        $time_parts = explode(':', $parts[1]);
        if (count($time_parts) !== 2) return false;

        $target_h = (int)$time_parts[0];
        $now_dow  = (int)date('N'); // 1=월~7=일
        $now_h    = (int)date('G');

        if ($target_dow < 1 || $target_dow > 7) return false;
        if ($target_h   < 0 || $target_h   > 23) return false;

        return ($now_dow === $target_dow && $now_h === $target_h);
    }

    return false;
}

/**
 * 해당 규칙으로 오늘(또는 이번 주) 이미 성공 발송한 이력이 있는지.
 *
 * @param  int    $rule_id
 * @param  string $mode 'daily' | 'weekly'
 * @return bool
 */
function guardian_already_sent_summary($rule_id, $mode)
{
    global $g5;
    if (empty($g5['guardian_notify_log_table'])) return false;

    $rid = (int)$rule_id;
    if ($rid <= 0) return false;

    if ($mode === 'daily') {
        $row = sql_fetch(
            " SELECT notify_id
              FROM `{$g5['guardian_notify_log_table']}`
              WHERE rule_id = " . $rid . "
                AND DATE(sent_dt) = CURDATE()
                AND status = 'success'
              LIMIT 1 ",
            false
        );
    } elseif ($mode === 'weekly') {
        $row = sql_fetch(
            " SELECT notify_id
              FROM `{$g5['guardian_notify_log_table']}`
              WHERE rule_id = " . $rid . "
                AND sent_dt >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                AND status = 'success'
              LIMIT 1 ",
            false
        );
    } else {
        return false;
    }

    return !empty($row['notify_id']);
}

// =====================================================================
// 3. 큐 관리
// =====================================================================
/**
 * 미처리 큐 항목 조회.
 *
 * @param  int    $rule_id
 * @param  string $mode
 * @return array
 */
function guardian_get_pending_summary_items($rule_id, $mode)
{
    global $g5;
    if (empty($g5['guardian_summary_queue_table'])) return array();

    $rid = (int)$rule_id;
    $m   = sql_escape_string((string)$mode);
    if ($rid <= 0) return array();

    // daily: 최근 1일, weekly: 최근 7일
    $interval = ($mode === 'weekly') ? '7 DAY' : '1 DAY';

    $sql = " SELECT *
             FROM `{$g5['guardian_summary_queue_table']}`
             WHERE rule_id = " . $rid . "
               AND mode = '" . $m . "'
               AND processed = 0
               AND created_dt >= DATE_SUB(NOW(), INTERVAL " . $interval . ")
             ORDER BY created_dt ASC ";

    $result = @sql_query($sql, false);
    $items = array();
    if ($result) {
        while ($row = sql_fetch_array($result)) {
            $items[] = $row;
        }
    }
    return $items;
}

/**
 * 큐 항목 처리완료 표시.
 *
 * @param  array $queue_ids
 * @return void
 */
function guardian_mark_summary_items_processed(array $queue_ids)
{
    global $g5;
    if (empty($g5['guardian_summary_queue_table'])) return;

    $ids = array();
    foreach ($queue_ids as $qid) {
        $qid = (int)$qid;
        if ($qid > 0) $ids[] = $qid;
    }
    if (empty($ids)) return;

    $ids_str = implode(',', $ids);
    @sql_query(
        " UPDATE `{$g5['guardian_summary_queue_table']}`
          SET processed = 1
          WHERE queue_id IN (" . $ids_str . ") ",
        false
    );
}

// =====================================================================
// 4. 통계 집계
// =====================================================================
/**
 * 큐 항목들의 log_id 를 모아 g5_guardian_log 에서 통계 집계.
 *
 * @param  array $items
 * @return array array('total'=>int, 'unique_count'=>int, 'fatal_count'=>int,
 *                     'by_level'=>array, 'top_errors'=>array)
 */
function guardian_aggregate_summary_stats(array $items)
{
    global $g5;
    $blank = array(
        'total'        => 0,
        'unique_count' => 0,
        'fatal_count'  => 0,
        'by_level'     => array(),
        'top_errors'   => array(),
    );
    if (empty($items) || empty($g5['guardian_log_table'])) return $blank;

    // 큐 항목에서 log_id 와 error_hash 를 모두 모은다. 신규 데이터는 log_id
    // 가 정상 채워지지만, 과거 버그로 log_id=0 인 항목이 큐에 남아있으면
    // error_hash 폴백으로 해당 hash 의 최신 log_id 를 g5_guardian_log 에서
    // 보조 조회해 같은 통계 SQL 에 함께 넣는다.
    $log_ids = array();
    $hashes_for_fallback = array();
    foreach ($items as $it) {
        $lid = isset($it['log_id']) ? (int)$it['log_id'] : 0;
        if ($lid > 0) {
            $log_ids[] = $lid;
        } elseif (!empty($it['error_hash'])) {
            $hashes_for_fallback[] = (string)$it['error_hash'];
        }
    }
    $hashes_for_fallback = array_values(array_unique($hashes_for_fallback));

    // log_id 폴백 보조 조회: error_hash 별 최신 log_id 를 1개씩만 가져옴
    // (집계는 occurrence_count 기반이라 row 한 개의 누적 카운트만 봐도 충분)
    if (!empty($hashes_for_fallback)) {
        $hash_in = "'" . implode("','", array_map('sql_escape_string', $hashes_for_fallback)) . "'";
        $fb_sql = " SELECT MAX(log_id) AS log_id
                    FROM `{$g5['guardian_log_table']}`
                    WHERE error_hash IN ({$hash_in})
                    GROUP BY error_hash ";
        $fb_res = @sql_query($fb_sql, false);
        if ($fb_res) {
            while ($fb_row = sql_fetch_array($fb_res)) {
                $fb_lid = isset($fb_row['log_id']) ? (int)$fb_row['log_id'] : 0;
                if ($fb_lid > 0) $log_ids[] = $fb_lid;
            }
        }
    }

    $log_ids = array_values(array_unique(array_map('intval', $log_ids)));
    if (empty($log_ids)) return $blank;
    $ids_str = implode(',', $log_ids);

    // 등급별 집계
    $level_sql = " SELECT error_level, SUM(occurrence_count) AS cnt
                   FROM `{$g5['guardian_log_table']}`
                   WHERE log_id IN (" . $ids_str . ")
                   GROUP BY error_level
                   ORDER BY cnt DESC ";
    $res = @sql_query($level_sql, false);
    $by_level = array();
    $total = 0;
    $fatal_count = 0;
    if ($res) {
        while ($row = sql_fetch_array($res)) {
            $cnt = (int)$row['cnt'];
            $level = isset($row['error_level']) ? (string)$row['error_level'] : '';
            $by_level[] = array('level' => $level, 'count' => $cnt);
            $total += $cnt;
            if ($level === 'FATAL' || $level === 'ERROR' || $level === 'EXCEPTION') {
                $fatal_count += $cnt;
            }
        }
    }

    // 최다 오류 TOP 10 (해시 기준 그룹)
    $top_sql = " SELECT error_hash, error_level, error_message, error_file, error_line,
                        SUM(occurrence_count) AS cnt
                 FROM `{$g5['guardian_log_table']}`
                 WHERE log_id IN (" . $ids_str . ")
                 GROUP BY error_hash
                 ORDER BY cnt DESC
                 LIMIT 10 ";
    $res = @sql_query($top_sql, false);
    $top_errors = array();
    $unique_count = 0;
    if ($res) {
        while ($row = sql_fetch_array($res)) {
            $top_errors[] = $row;
        }
    }

    // 고유 오류 수 (전체)
    $uniq_sql = " SELECT COUNT(DISTINCT error_hash) AS cnt
                  FROM `{$g5['guardian_log_table']}`
                  WHERE log_id IN (" . $ids_str . ") ";
    $row = sql_fetch($uniq_sql, false);
    if (!empty($row['cnt'])) $unique_count = (int)$row['cnt'];

    return array(
        'total'        => $total,
        'unique_count' => $unique_count,
        'fatal_count'  => $fatal_count,
        'by_level'     => $by_level,
        'top_errors'   => $top_errors,
    );
}

// =====================================================================
// 5. 요약 메일 발송
// =====================================================================
/**
 * 한 규칙의 모든 활성 수신자에게 요약 메일을 발송한다.
 *
 * @param  array  $rule
 * @param  string $mode
 * @param  array  $items 큐 항목들
 * @return void
 */
function guardian_send_summary_for_rule(array $rule, $mode, array $items)
{
    global $config;

    $stats = guardian_aggregate_summary_stats($items);

    // 수신자 ID 파싱
    $recipient_csv = isset($rule['recipient_ids']) ? (string)$rule['recipient_ids'] : '';
    $recipient_ids = array();
    foreach (explode(',', $recipient_csv) as $rid) {
        $rid = (int)trim($rid);
        if ($rid > 0) $recipient_ids[] = $rid;
    }
    if (empty($recipient_ids)) return;

    $site_name = isset($config['cf_title']) ? (string)$config['cf_title'] : '';
    $period_label = guardian_get_summary_period_label($mode);
    $date_range   = guardian_get_summary_date_range($mode);

    $tpl_data = array(
        'mode'         => $mode,
        'period'       => $period_label,
        'date_range'   => $date_range,
        'rule_name'    => isset($rule['rule_name']) ? (string)$rule['rule_name'] : '',
        'site_name'    => $site_name,
        'admin_url'    => G5_ADMIN_URL . '/guardian_log.php',
        'total'        => $stats['total'],
        'unique_count' => $stats['unique_count'],
        'fatal_count'  => $stats['fatal_count'],
        'by_level'     => $stats['by_level'],
        'top_errors'   => $stats['top_errors'],
    );

    $template_file = ($mode === 'weekly') ? 'mail_summary_weekly.html' : 'mail_summary_daily.html';
    $body = guardian_render_summary_template($template_file, $tpl_data);
    $subject = '[' . $site_name . '] ' . $period_label . ' 운영지킴이 오류 리포트';

    $rule_id = isset($rule['rule_id']) ? (int)$rule['rule_id'] : 0;

    foreach ($recipient_ids as $rid) {
        if (!function_exists('guardian_get_recipient')) continue;
        $recipient = guardian_get_recipient($rid);
        if (empty($recipient) || empty($recipient['active'])) continue;
        if (empty($recipient['email'])) continue;

        // ★ guardian_send_email() 호출 ★
        $result = function_exists('guardian_send_email')
            ? guardian_send_email((string)$recipient['email'], $subject, $body)
            : array('success' => false, 'reason' => 'guardian_send_email 미로드');

        // 발송 이력 기록 — error_hash 는 SUMMARY 식별자
        if (function_exists('guardian_log_notify_result')) {
            guardian_log_notify_result(
                'SUMMARY_' . strtoupper($mode),
                'email',
                (string)$recipient['email'],
                !empty($result['success']) ? 'success' : 'failed',
                isset($result['reason']) ? (string)$result['reason'] : '',
                $rule_id
            );
        }
    }
}

/**
 * "어제" / "지난 일주일" 라벨 생성.
 *
 * @param  string $mode
 * @return string
 */
function guardian_get_summary_period_label($mode)
{
    return ($mode === 'weekly') ? '지난 일주일' : '어제';
}

/**
 * "2026-04-14 00:00 ~ 23:59" 형태 날짜 범위 라벨.
 *
 * @param  string $mode
 * @return string
 */
function guardian_get_summary_date_range($mode)
{
    if ($mode === 'weekly') {
        $start = date('Y-m-d', strtotime('-7 days'));
        $end   = date('Y-m-d', strtotime('-1 day'));
        return $start . ' ~ ' . $end;
    }
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    return $yesterday . ' 00:00 ~ 23:59';
}

// =====================================================================
// 6. 요약 메일 템플릿 렌더링
// =====================================================================
/**
 * templates/{$template_file} 를 로드해 토큰 치환 후 반환.
 *
 * 인라인 폴백은 두지 않는다 — 요약 메일은 시각적 품질이 중요하므로 파일이
 * 없으면 발송 자체를 안 한다. (호출자가 빈 본문 검사는 알아서)
 *
 * @param  string $template_file
 * @param  array  $data
 * @return string
 */
function guardian_render_summary_template($template_file, array $data)
{
    $body = '';
    if (defined('GUARDIAN_PATH')) {
        $f = GUARDIAN_PATH . '/templates/' . $template_file;
        if (@is_readable($f)) {
            $contents = @file_get_contents($f);
            if ($contents !== false) {
                $body = (string)$contents;
            }
        }
    }
    if ($body === '') {
        // 템플릿 파일이 없으면 기본 인라인 (안전 폴백)
        $body = '<html><body><h2>{SITE_NAME} 운영 리포트</h2>'
              . '<p>{PERIOD} ({DATE_RANGE})</p>'
              . '<p>총 {TOTAL}건 / 고유 {UNIQUE_COUNT}종 / 심각 {FATAL_COUNT}건</p>'
              . '<p><a href="{ADMIN_URL}">관리자 페이지에서 상세 확인</a></p>'
              . '</body></html>';
    }

    // 등급별 행 HTML 빌드
    $level_rows = '';
    foreach ($data['by_level'] as $row) {
        $level_rows .= '<tr>'
            . '<td style="padding:8px; border:1px solid #e5e7eb;">' . htmlspecialchars($row['level'], ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td style="padding:8px; border:1px solid #e5e7eb; text-align:right;">' . (int)$row['count'] . '회</td>'
            . '</tr>';
    }
    if ($level_rows === '') {
        $level_rows = '<tr><td colspan="2" style="padding:8px; color:#888; text-align:center;">데이터 없음</td></tr>';
    }

    // 최다 오류 행 HTML 빌드
    $top_rows = '';
    foreach ($data['top_errors'] as $row) {
        $msg = isset($row['error_message']) ? (string)$row['error_message'] : '';
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($msg, 'UTF-8') > 100) {
                $msg = mb_substr($msg, 0, 97, 'UTF-8') . '...';
            }
        }
        $top_rows .= '<tr>'
            . '<td style="padding:6px 8px; border:1px solid #e5e7eb;">' . htmlspecialchars(isset($row['error_level']) ? (string)$row['error_level'] : '', ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td style="padding:6px 8px; border:1px solid #e5e7eb; word-break:break-all;">' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td style="padding:6px 8px; border:1px solid #e5e7eb; text-align:right; color:#dc2626;">' . (int)(isset($row['cnt']) ? $row['cnt'] : 0) . '</td>'
            . '</tr>';
    }
    if ($top_rows === '') {
        $top_rows = '<tr><td colspan="3" style="padding:8px; color:#888; text-align:center;">데이터 없음</td></tr>';
    }

    // 브랜드 변수 — guardian_brand.lib.php 가 로드된 환경에서만 채움.
    // 본 변수들이 누락되면 템플릿의 {SUBTITLE} / {BRAND_FOOTER} /
    // {UNRESOLVED_FATAL_ALERT} 자리가 그대로 노출되므로 빈 문자열이라도
    // 반드시 사전에 포함시킨다 (미치환 토큰 노출 방지).
    $subtitle              = function_exists('guardian_get_subtitle')            ? guardian_get_subtitle()            : '';
    $brand_footer_html     = function_exists('guardian_render_brand_footer')     ? guardian_render_brand_footer()     : '';
    $unresolved_alert_html = function_exists('guardian_render_unresolved_alert') ? guardian_render_unresolved_alert() : '';

    $vars = array(
        '{SITE_NAME}'              => isset($data['site_name'])  ? (string)$data['site_name']  : '',
        '{PERIOD}'                 => isset($data['period'])     ? (string)$data['period']     : '',
        '{DATE_RANGE}'             => isset($data['date_range']) ? (string)$data['date_range'] : '',
        '{RULE_NAME}'              => isset($data['rule_name'])  ? (string)$data['rule_name']  : '',
        '{ADMIN_URL}'              => isset($data['admin_url'])  ? (string)$data['admin_url']  : '',
        '{TOTAL}'                  => (string)(int)$data['total'],
        '{UNIQUE_COUNT}'           => (string)(int)$data['unique_count'],
        '{FATAL_COUNT}'            => (string)(int)$data['fatal_count'],
        '{LEVEL_ROWS}'             => $level_rows,
        '{TOP_ERROR_ROWS}'         => $top_rows,
        // 브랜드 레이어 변수 (일일 / 주간 요약 메일 양쪽에서 사용)
        '{SUBTITLE}'               => $subtitle,
        '{BRAND_FOOTER}'           => $brand_footer_html,
        '{UNRESOLVED_FATAL_ALERT}' => $unresolved_alert_html,
    );

    return str_replace(array_keys($vars), array_values($vars), $body);
}

// =====================================================================
// 7. 데이터 정리 (cron 또는 visitor 트리거에서 호출)
// =====================================================================
/**
 * 오래된 데이터 자동 삭제.
 *
 * - 처리된 요약 큐: 7일 이상 → 삭제
 * - 미처리 큐: 30일 이상 → 강제 삭제 (방치 방지)
 * - 매칭 로그: cf_guardian_rule_match_keep_days 기준 (기본 7일)
 * - 해결된 오류 로그: cf_guardian_log_keep_days 기준 (기본 30일)
 *
 * @return void
 */
function guardian_cleanup_old_data()
{
    global $g5, $config;

    // 1. 요약 큐 정리
    if (!empty($g5['guardian_summary_queue_table'])) {
        @sql_query(
            " DELETE FROM `{$g5['guardian_summary_queue_table']}`
              WHERE (processed = 1 AND created_dt < DATE_SUB(NOW(), INTERVAL 7 DAY))
                 OR created_dt < DATE_SUB(NOW(), INTERVAL 30 DAY) ",
            false
        );
    }

    // 2. 매칭 로그 정리
    if (!empty($g5['guardian_rule_match_log_table'])) {
        $keep = isset($config['cf_guardian_rule_match_keep_days']) && $config['cf_guardian_rule_match_keep_days'] !== ''
            ? (int)$config['cf_guardian_rule_match_keep_days']
            : 7;
        if ($keep < 1) $keep = 1;
        @sql_query(
            " DELETE FROM `{$g5['guardian_rule_match_log_table']}`
              WHERE created_dt < DATE_SUB(NOW(), INTERVAL " . $keep . " DAY) ",
            false
        );
    }

    // 3. 해결된 오류 로그 정리
    if (!empty($g5['guardian_log_table'])) {
        $log_keep = isset($config['cf_guardian_log_keep_days']) && $config['cf_guardian_log_keep_days'] !== ''
            ? (int)$config['cf_guardian_log_keep_days']
            : 30;
        if ($log_keep < 1) $log_keep = 1;
        @sql_query(
            " DELETE FROM `{$g5['guardian_log_table']}`
              WHERE resolved = 1
                AND created_dt < DATE_SUB(NOW(), INTERVAL " . $log_keep . " DAY) ",
            false
        );
    }
}
