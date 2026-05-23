<?php
/**
 * 그누보드 운영지킴이 - 알림 규칙 매칭 엔진 ★ 규칙 매칭 엔진 핵심
 *
 * 책임:
 *   1. 활성 규칙 로드 (요청 단위 캐시)
 *   2. 단일 오류 vs 단일 규칙 매칭 검사 (등급 / 파일 패턴 / 시간대)
 *   3. 매칭된 규칙을 mode 에 따라 즉시 발송 또는 큐 적재
 *   4. 모든 매칭 시도를 g5_guardian_rule_match_log 에 기록 (디버깅 / 추적)
 *
 * 절대 원칙:
 *   - 외부 try-catch 로 dispatcher 자체 보호
 *   - 한 규칙의 실패가 다른 규칙을 막지 않는다
 *   - 자기 참조 방지 (재진입 차단)
 *   - 매칭 로직은 본 파일에서만 — 다른 곳에서 "혹시 알림 대상인가?" 판단 금지
 *
 * (c) 2026 K3SOFT / WizardOfCode
 *
 * @package gb_guardian
 * @version 1.0.0
 */
if (!defined('_GNUBOARD_')) exit;

// =====================================================================
// 1. 외부 진입점 (dispatcher 가 호출)
// =====================================================================
/**
 * 매칭 + 적용을 일괄 처리한다.
 *
 * @param  array $error_data
 * @return void
 */
function guardian_dispatch_error(array $error_data)
{
    // ---- 재진입 차단 ----
    static $in_dispatch = false;
    if ($in_dispatch) {
        return;
    }
    $in_dispatch = true;

    try {
        $rules = guardian_load_active_rules();
        if (empty($rules)) {
            $in_dispatch = false;
            return;
        }

        foreach ($rules as $rule) {
            try {
                $check = guardian_rule_matches_error($rule, $error_data);

                if (!empty($check['matched'])) {
                    guardian_apply_rule($rule, $error_data);
                } else {
                    // 디버깅 로깅 (옵션) — cf_guardian_rule_match_logging 가 1 일 때만
                    guardian_log_match($error_data, $rule, $check['result'], isset($check['detail']) ? $check['detail'] : '');
                }
            } catch (Exception $e) {
                guardian_log_match($error_data, $rule, 'error', 'Exception: ' . $e->getMessage());
                // 다음 규칙으로 계속
            }
        }
    } catch (Exception $e) {
        // dispatcher 자체 오류는 조용히 무시
    }

    $in_dispatch = false;
}

// =====================================================================
// 2. 활성 규칙 로드 (요청 단위 정적 캐시)
// =====================================================================
/**
 * 활성 규칙을 priority 오름차순으로 정렬해 반환. 요청 1회 동안만 캐시.
 *
 * @return array
 */
function guardian_load_active_rules()
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    global $g5;
    if (empty($g5['guardian_rule_table'])) {
        return $cached = array();
    }

    $sql = " SELECT * FROM `{$g5['guardian_rule_table']}`
             WHERE rule_active = 1
             ORDER BY priority ASC, rule_id ASC ";

    $result = @sql_query($sql, false);
    $rules = array();
    if ($result) {
        while ($row = sql_fetch_array($result)) {
            $rules[] = $row;
        }
    }
    $cached = $rules;
    return $cached;
}

// =====================================================================
// 3. 단일 규칙 매칭 검사
// =====================================================================
/**
 * 한 규칙이 한 오류에 매칭되는지 판정.
 *
 * @param  array $rule
 * @param  array $error_data
 * @return array array('matched' => bool, 'result' => string, 'detail' => string)
 */
function guardian_rule_matches_error(array $rule, array $error_data)
{
    // ---- 활성 상태 ----
    if (empty($rule['rule_active'])) {
        return array('matched' => false, 'result' => 'not_matched_inactive', 'detail' => '');
    }

    // ---- 등급 매칭 ----
    $error_level = '';
    if (isset($error_data['level']) && $error_data['level'] !== '') {
        $error_level = (string)$error_data['level'];
    } elseif (isset($error_data['error_level']) && $error_data['error_level'] !== '') {
        $error_level = (string)$error_data['error_level'];
    }

    $allowed = isset($rule['error_levels']) ? (string)$rule['error_levels'] : '';
    $allowed_arr = array_map('trim', array_map('strtoupper', explode(',', $allowed)));

    if (!in_array('ALL', $allowed_arr, true) && !in_array($error_level, $allowed_arr, true)) {
        return array(
            'matched' => false,
            'result'  => 'not_matched_level',
            'detail'  => 'rule=' . $allowed . ', error=' . $error_level,
        );
    }

    // ---- 파일 패턴 매칭 (선택) ----
    $pattern = isset($rule['file_pattern']) ? (string)$rule['file_pattern'] : '';
    if ($pattern !== '') {
        $error_file = '';
        if (isset($error_data['file']) && $error_data['file'] !== '') {
            $error_file = (string)$error_data['file'];
        } elseif (isset($error_data['error_file']) && $error_data['error_file'] !== '') {
            $error_file = (string)$error_data['error_file'];
        }

        if (!guardian_match_file_pattern($pattern, $error_file)) {
            return array(
                'matched' => false,
                'result'  => 'not_matched_pattern',
                'detail'  => 'pattern=' . $pattern . ', file=' . $error_file,
            );
        }
    }

    // ---- 시간대 매칭 (mode='timeofday' 일 때만) ----
    $mode = isset($rule['mode']) ? (string)$rule['mode'] : 'instant';
    if ($mode === 'timeofday') {
        $schedule = isset($rule['schedule_time']) ? (string)$rule['schedule_time'] : '';
        if (!guardian_is_in_timeofday($schedule)) {
            return array(
                'matched' => false,
                'result'  => 'not_matched_timeofday',
                'detail'  => 'schedule=' . $schedule . ', now=' . date('H:i'),
            );
        }
    }

    return array('matched' => true, 'result' => 'matched', 'detail' => '');
}

/**
 * SQL LIKE 스타일 패턴 매칭.
 *   % → .* (0개 이상 임의 문자)
 *   _ → .  (1개 임의 문자)
 *
 * preg_quote() 로 사용자 입력의 정규식 메타문자를 모두 이스케이프 후
 * 와일드카드만 변환한다 — 정규식 주입 차단.
 *
 * @param  string $pattern
 * @param  string $file
 * @return bool
 */
function guardian_match_file_pattern($pattern, $file)
{
    if ($pattern === '' || $pattern === null) return true;
    $pattern = (string)$pattern;
    $file = (string)$file;

    $regex = preg_quote($pattern, '/');
    // preg_quote 가 % 와 _ 는 이스케이프하지 않으므로 그대로 변환 가능
    $regex = str_replace(array('%', '_'), array('.*', '.'), $regex);
    $regex = '/^' . $regex . '$/i';

    return @preg_match($regex, $file) === 1;
}

/**
 * 현재 시각이 지정 시간대 범위 내인지.
 *
 * 형식: "HH-HH" 또는 "HH-HH,HH-HH" (예: "09-18", "09-12,14-18", "22-08")
 *
 * @param  string $schedule
 * @return bool
 */
function guardian_is_in_timeofday($schedule)
{
    $schedule = (string)$schedule;
    if ($schedule === '') return false;

    $now_min = (int)date('G') * 60 + (int)date('i');

    $ranges = explode(',', $schedule);
    foreach ($ranges as $range) {
        $parts = explode('-', trim($range));
        if (count($parts) !== 2) continue;

        $start_h = (int)$parts[0];
        $end_h   = (int)$parts[1];
        if ($start_h < 0 || $start_h > 23) continue;
        if ($end_h   < 0 || $end_h   > 24) continue;

        $start_min = $start_h * 60;
        $end_min   = $end_h   * 60;

        if ($start_min === $end_min) continue;

        if ($start_min > $end_min) {
            // 22-08 같이 자정 걸치는 범위
            if ($now_min >= $start_min || $now_min < $end_min) return true;
        } else {
            if ($now_min >= $start_min && $now_min < $end_min) return true;
        }
    }
    return false;
}

// =====================================================================
// 4. 규칙 적용 — mode 에 따라 즉시 발송 또는 큐 적재
// =====================================================================
/**
 * @param  array $rule
 * @param  array $error_data
 * @return void
 */
function guardian_apply_rule(array $rule, array $error_data)
{
    $recipient_csv = isset($rule['recipient_ids']) ? (string)$rule['recipient_ids'] : '';
    $recipient_ids = array();
    if ($recipient_csv !== '') {
        foreach (explode(',', $recipient_csv) as $rid) {
            $rid = (int)trim($rid);
            if ($rid > 0) {
                $recipient_ids[] = $rid;
            }
        }
    }

    if (empty($recipient_ids)) {
        guardian_log_match($error_data, $rule, 'error', 'No recipients defined');
        return;
    }

    $mode = isset($rule['mode']) ? (string)$rule['mode'] : 'instant';

    switch ($mode) {
        case 'instant':
        case 'timeofday':
            // timeofday 는 이미 시간 체크 통과 후 매칭됐으므로 즉시 발송
            guardian_apply_rule_instant($rule, $error_data, $recipient_ids);
            break;

        case 'daily':
        case 'weekly':
            guardian_apply_rule_summary($rule, $error_data);
            break;

        default:
            guardian_log_match($error_data, $rule, 'error', 'Unknown mode: ' . $mode);
    }
}

/**
 * 즉시 발송 모드 처리.
 *
 * @param  array $rule
 * @param  array $error_data
 * @param  array $recipient_ids
 * @return void
 */
function guardian_apply_rule_instant(array $rule, array $error_data, array $recipient_ids)
{
    $channel  = isset($rule['channel'])      ? (string)$rule['channel']      : 'email';
    $cooldown = isset($rule['cooldown_min']) ? (int)$rule['cooldown_min']    : 30;
    $dedup    = isset($rule['dedup_scope'])  ? (string)$rule['dedup_scope']  : 'rule';
    $rule_id  = isset($rule['rule_id'])      ? (int)$rule['rule_id']         : 0;
    $hash     = isset($error_data['error_hash']) ? (string)$error_data['error_hash'] : '';

    foreach ($recipient_ids as $recipient_id) {
        // ---- 글로벌 중복 제거 체크 ----
        if ($dedup === 'global'
            && guardian_is_global_dedup_blocked($hash, $recipient_id, $cooldown)) {
            guardian_log_match(
                $error_data, $rule,
                'dedup_skipped',
                'global, recipient=' . $recipient_id
            );
            continue;
        }

        // ---- guardian_notify() 호출 ----
        if (!function_exists('guardian_notify')) {
            guardian_log_match($error_data, $rule, 'error', 'guardian_notify() not loaded');
            continue;
        }

        $result = guardian_notify($error_data, $channel, $recipient_id, array(
            'cooldown_min' => $cooldown,
            'rule_id'      => $rule_id,
        ));

        if (!empty($result['success'])) {
            guardian_log_match($error_data, $rule, 'matched_sent', 'recipient=' . $recipient_id);
        } else {
            $reason = isset($result['reason']) ? (string)$result['reason'] : '';
            guardian_log_match(
                $error_data, $rule, 'matched_failed',
                'recipient=' . $recipient_id . ', reason=' . $reason
            );
        }
    }
}

/**
 * 일일/주간 요약 큐에 적재.
 *
 * @param  array $rule
 * @param  array $error_data
 * @return void
 */
function guardian_apply_rule_summary(array $rule, array $error_data)
{
    global $g5;
    if (empty($g5['guardian_summary_queue_table'])) return;

    $sql = " INSERT INTO `{$g5['guardian_summary_queue_table']}`
             (rule_id, log_id, error_hash, mode, processed, created_dt)
             VALUES (
                 " . (int)(isset($rule['rule_id']) ? $rule['rule_id'] : 0) . ",
                 " . (int)(isset($error_data['log_id']) ? $error_data['log_id'] : 0) . ",
                 '" . sql_escape_string(isset($error_data['error_hash']) ? (string)$error_data['error_hash'] : '') . "',
                 '" . sql_escape_string(isset($rule['mode']) ? (string)$rule['mode'] : 'daily') . "',
                 0,
                 NOW()
             ) ";
    @sql_query($sql, false);

    guardian_log_match(
        $error_data, $rule, 'matched_queued',
        'mode=' . (isset($rule['mode']) ? (string)$rule['mode'] : 'daily')
    );
}

// =====================================================================
// 5. 글로벌 중복 제거
// =====================================================================
/**
 * 어떤 규칙이든 같은 (error_hash + 수신자) 조합으로 최근 N분 내 성공 발송한
 * 이력이 있는지. dedup_scope='global' 일 때만 사용.
 *
 * @param  string $error_hash
 * @param  int    $recipient_id
 * @param  int    $cooldown_min
 * @return bool   true = 차단 (이미 보냈음)
 */
function guardian_is_global_dedup_blocked($error_hash, $recipient_id, $cooldown_min)
{
    global $g5;
    if ($error_hash === '' || empty($g5['guardian_notify_log_table']) || empty($g5['guardian_recipient_table'])) {
        return false;
    }

    $hash = sql_escape_string((string)$error_hash);
    $rid  = (int)$recipient_id;
    $min  = max(1, (int)$cooldown_min);

    // 수신자의 모든 채널 주소(이메일/휴대폰) 와 매칭. 글로벌이라 채널/규칙 무관.
    $row = sql_fetch(
        " SELECT n.notify_id
          FROM `{$g5['guardian_notify_log_table']}` n
          INNER JOIN `{$g5['guardian_recipient_table']}` r
                  ON r.recipient_id = " . $rid . "
                 AND (n.recipient = r.email OR n.recipient = r.mobile)
          WHERE n.error_hash = '{$hash}'
            AND n.status     = 'success'
            AND n.sent_dt > DATE_SUB(NOW(), INTERVAL {$min} MINUTE)
          LIMIT 1 ",
        false
    );

    return !empty($row['notify_id']);
}

// =====================================================================
// 6. 매칭 로그 기록
// =====================================================================
/**
 * 매칭 시도를 g5_guardian_rule_match_log 에 기록한다.
 *
 * 모든 호출은 @sql_query(..., false) 로 안전. 로깅 비활성 시 즉시 return.
 *
 * @param  array  $error_data
 * @param  array  $rule
 * @param  string $result      enum: matched_sent / matched_failed / matched_queued /
 *                             not_matched_level / not_matched_pattern /
 *                             not_matched_inactive / not_matched_timeofday /
 *                             dedup_skipped / error
 * @param  string $detail
 * @return void
 */
function guardian_log_match(array $error_data, array $rule, $result, $detail = '')
{
    global $g5, $config;

    // 로깅 비활성 시 스킵 (성공 발송 결과는 항상 기록하고 싶을 수도 있지만,
    // 작업지시서 정책상 본 토글 OFF 시 모두 스킵)
    if (empty($config['cf_guardian_rule_match_logging'])) return;
    if (empty($g5['guardian_rule_match_log_table'])) return;

    // 길이 안전망 (TEXT 컬럼이지만 64KB 한계 대비)
    $detail = (string)$detail;
    if (strlen($detail) > 60000) {
        $detail = substr($detail, 0, 59980) . '... [truncated]';
    }

    $sql = " INSERT INTO `{$g5['guardian_rule_match_log_table']}`
             (log_id, error_hash, rule_id, result, result_detail, created_dt)
             VALUES (
                 " . (int)(isset($error_data['log_id']) ? $error_data['log_id'] : 0) . ",
                 '" . sql_escape_string(isset($error_data['error_hash']) ? (string)$error_data['error_hash'] : '') . "',
                 " . (int)(isset($rule['rule_id']) ? $rule['rule_id'] : 0) . ",
                 '" . sql_escape_string((string)$result) . "',
                 '" . sql_escape_string($detail) . "',
                 NOW()
             ) ";
    @sql_query($sql, false);
}
