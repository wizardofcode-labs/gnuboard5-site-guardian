<?php
/**
 * 그누보드 운영지킴이 - 통합 발송 엔진 핵심 진입점
 *
 * 외부에서 호출하는 유일한 함수: guardian_notify($error_data, $channel, $recipient_id, $options)
 *
 * 발송 전 8단계 검증:
 *   1. 운영지킴이 활성화 (cf_guardian_use)
 *   2. 비상 정지 (cf_guardian_emergency_stop)
 *   3. 채널 사용 가능 (어댑터 / 알리고 / 함수 존재)
 *   4. 수신자 활성 + 채널별 주소 보유
 *   5. 야간 무음 시간대 (SMS / 카톡만)
 *   6. 일일 한도
 *   7. 동일 오류 쿨다운
 *   8. 재진입 락
 *
 * 모든 발송은 try-catch + finally 락 해제로 보호된다.
 *
 * (c) 2026 K3SOFT / WizardOfCode
 *
 * @package gb_guardian
 * @version 1.0.0
 */
if (!defined('_GNUBOARD_')) exit;

// =====================================================================
// 1. 통합 발송 진입점
// =====================================================================
/**
 * 단일 채널, 단일 수신자에게 알림을 발송한다.
 *
 * @param  array  $error_data    캡처 데이터 (level/message/file/line/error_hash 등)
 * @param  string $channel       'email' | 'sms' | 'kakao'
 * @param  int    $recipient_id  guardian_recipient.recipient_id
 * @param  array  $options       선택 옵션:
 *                                 - cooldown_min        : 쿨다운 분 (default 30)
 *                                 - silent_fallback_to_email : 야간 무음 시 메일 폴백 (default false)
 *                                 - rule_id             : 규칙 ID (default 0)
 * @return array                array('success' => bool, 'reason' => string, 'channel' => string)
 */
function guardian_notify(array $error_data, $channel, $recipient_id, array $options = array())
{
    global $g5, $config;

    $channel = (string)$channel;
    $recipient_id = (int)$recipient_id;
    $rule_id = isset($options['rule_id']) ? (int)$options['rule_id'] : 0;

    // ---- 1. 운영지킴이 활성화 ----
    if (empty($config['cf_guardian_use'])) {
        return array('success' => false, 'reason' => '운영지킴이 비활성화', 'channel' => $channel);
    }

    // ---- 2. 비상 정지 ----
    if (!guardian_emergency_stop_check()) {
        return array('success' => false, 'reason' => '비상 정지 활성화', 'channel' => $channel);
    }

    // ---- 3. 채널 사용 가능 여부 ----
    if (!guardian_is_channel_available($channel)) {
        return array('success' => false, 'reason' => '채널 사용 불가: ' . $channel, 'channel' => $channel);
    }

    // ---- 4. 수신자 조회 ----
    $recipient = guardian_get_recipient($recipient_id);
    if (empty($recipient)) {
        return array('success' => false, 'reason' => '수신자 없음', 'channel' => $channel);
    }
    if (empty($recipient['active'])) {
        return array('success' => false, 'reason' => '수신자 비활성', 'channel' => $channel);
    }
    $recipient_addr = guardian_get_recipient_address($recipient, $channel);
    if ($recipient_addr === '') {
        return array('success' => false, 'reason' => '수신자 ' . $channel . ' 주소 미입력', 'channel' => $channel);
    }

    // ---- 5. 야간 무음 (SMS / 카톡만) ----
    if (($channel === 'sms' || $channel === 'kakao') && guardian_is_silent_hours()) {
        // 옵션에 폴백 지정 시 메일로 재시도
        if (!empty($options['silent_fallback_to_email']) && !empty($recipient['email'])) {
            $opt2 = $options;
            unset($opt2['silent_fallback_to_email']); // 한 번만 폴백 (재귀 방지)
            return guardian_notify($error_data, 'email', $recipient_id, $opt2);
        }
        return array('success' => false, 'reason' => '야간 무음 시간대', 'channel' => $channel);
    }

    // ---- 6. 일일 한도 ----
    if (!guardian_check_daily_limit($channel)) {
        return array('success' => false, 'reason' => '일일 한도 초과', 'channel' => $channel);
    }

    // ---- 7. 쿨다운 ----
    // cooldown_min 옵션은 호출자가 항상 명시 전달 (매칭 엔진 = 규칙 cooldown_min,
    // 테스트 발송 = 0). 폴백 30 분은 외부에서 직접 호출 시 안전 기본값.
    $cooldown_min = isset($options['cooldown_min']) ? (int)$options['cooldown_min'] : 30;

    $error_hash = isset($error_data['error_hash']) ? (string)$error_data['error_hash'] : '';
    if (!guardian_check_notify_cooldown($error_hash, $channel, $recipient_addr, $cooldown_min)) {
        // 쿨다운은 조용한 차단 — 결과 반환만 하고 notify_log 에 기록 안 함
        return array('success' => false, 'reason' => '쿨다운 중 (조용한 차단)', 'channel' => $channel);
    }

    // ---- 8. 재진입 락 ----
    if (!guardian_acquire_lock($channel)) {
        return array('success' => false, 'reason' => '재진입 차단', 'channel' => $channel);
    }

    // ---- 외부 확장점 (외부 모듈이 활용) ----
    // 그누보드 훅 트리거 함수는 run_event(). do_action 은 워드프레스 컨벤션이라
    // 그누보드 환경에서는 호출되지 않는 죽은 훅이 된다.
    if (function_exists('run_event')) {
        run_event('guardian_before_notify', $channel, $recipient, $error_data, $options);
    }

    // ---- 실제 발송 ----
    // try-catch-finally 패턴 (PHP 5.5+) — finally 가 락 해제를 보장한다.
    // catch 안에서 또 throw 가 발생해도 락이 풀린다.
    $result = array('success' => false, 'reason' => '발송 미시도', 'channel' => $channel);
    try {
        $rendered = guardian_render_template($channel, $error_data);

        $route = guardian_route_to_channel(
            $channel,
            $recipient_addr,
            isset($rendered['subject']) ? (string)$rendered['subject'] : '',
            isset($rendered['body'])    ? (string)$rendered['body']    : '',
            $error_data
        );

        $result = array(
            'success' => !empty($route['success']),
            'reason'  => isset($route['reason']) ? (string)$route['reason'] : '',
            'channel' => $channel,
        );

        // 결과 기록
        guardian_log_notify_result(
            $error_hash,
            $channel,
            $recipient_addr,
            $result['success'] ? 'success' : 'failed',
            $result['reason'],
            $rule_id
        );

        // g5_guardian_log.notified 플래그 갱신
        if ($result['success']
            && !empty($error_data['log_id'])
            && !empty($g5['guardian_log_table'])) {
            @sql_query(
                " UPDATE `{$g5['guardian_log_table']}`
                  SET notified = 1
                  WHERE log_id = " . (int)$error_data['log_id'] . " ",
                false
            );
        }

    } catch (Exception $e) {
        $result = array('success' => false, 'reason' => 'Exception: ' . $e->getMessage(), 'channel' => $channel);
        // 결과 기록 자체도 try-catch 로 한 번 더 보호
        try {
            guardian_log_notify_result(
                $error_hash, $channel, $recipient_addr,
                'failed', 'Exception: ' . $e->getMessage(), $rule_id
            );
        } catch (Exception $e2) {
            // 의도적 무시 — 사이트 절대 안 죽이기
        }
    } finally {
        guardian_release_lock($channel);
    }

    if (function_exists('run_event')) {
        run_event('guardian_after_notify', $result, $channel, $recipient, $error_data);
    }

    return $result;
}

// =====================================================================
// 2. 채널 라우팅
// =====================================================================
/**
 * 채널별 어댑터 함수 호출.
 *
 * @param  string $channel
 * @param  string $recipient_addr 이메일/전화/카톡ID
 * @param  string $subject
 * @param  string $body
 * @param  array  $error_data
 * @return array  array('success' => bool, 'reason' => string)
 */
function guardian_route_to_channel($channel, $recipient_addr, $subject, $body, array $error_data)
{
    switch ($channel) {
        case 'email':
            if (!function_exists('guardian_send_email')) {
                return array('success' => false, 'reason' => '메일 어댑터 미로드');
            }
            return guardian_send_email($recipient_addr, $subject, $body);

        case 'sms':
            if (!function_exists('guardian_send_sms')) {
                return array('success' => false, 'reason' => 'SMS 어댑터 미로드');
            }
            return guardian_send_sms($recipient_addr, $body);

        case 'kakao':
            if (!function_exists('guardian_send_kakao')) {
                return array('success' => false, 'reason' => '카톡 어댑터 미로드');
            }
            return guardian_send_kakao($recipient_addr, $body, $error_data);

        default:
            return array('success' => false, 'reason' => '알 수 없는 채널: ' . $channel);
    }
}

// =====================================================================
// 3. 채널 사용 가능 여부
// =====================================================================
/**
 * 채널이 현재 환경에서 발송 가능한 상태인지 판정.
 *
 * @param  string $channel
 * @return bool
 */
function guardian_is_channel_available($channel)
{
    global $config;
    $channel = (string)$channel;

    switch ($channel) {
        case 'email':
            // 메일은 항상 가능 (PHP mail() 폴백)
            return true;

        case 'sms':
            if (empty($config['cf_guardian_aligo_enabled'])) return false;
            // 알리고 SMS 클래스 + 어댑터 로드 확인
            if (!guardian_is_aligo_sms_available()) return false;
            return true;

        case 'kakao':
            if (empty($config['cf_guardian_aligo_enabled'])) return false;
            if (!guardian_is_aligo_kakao_available()) return false;
            return true;
    }
    return false;
}

/**
 * 알리고 SMS 클래스 사용 가능 여부 (어댑터에서 헬퍼로도 사용).
 *
 * @return bool
 */
function guardian_is_aligo_sms_available()
{
    if (class_exists('AligoSMS')) return true;

    // lib 자동 로드 시도
    if (defined('G5_PLUGIN_PATH')) {
        $f = G5_PLUGIN_PATH . '/aligosms/aligosms.lib.php';
        if (@is_readable($f)) {
            @include_once($f);
            return class_exists('AligoSMS');
        }
    }
    return false;
}

/**
 * 알리고 카톡 클래스 사용 가능 여부.
 *
 * @return bool
 */
function guardian_is_aligo_kakao_available()
{
    if (class_exists('KakaoAlimtalk')) return true;

    if (defined('G5_PLUGIN_PATH')) {
        $f = G5_PLUGIN_PATH . '/aligo_kakao/aligo_alimtalk.lib.php';
        if (@is_readable($f)) {
            @include_once($f);
            return class_exists('KakaoAlimtalk');
        }
    }
    return false;
}

// =====================================================================
// 4. 수신자 조회
// =====================================================================
/**
 * 수신자 ID 로 row 조회. 캐시는 두지 않는다 (active 변경 즉시 반영).
 *
 * @param  int $recipient_id
 * @return array|null
 */
function guardian_get_recipient($recipient_id)
{
    global $g5;
    $recipient_id = (int)$recipient_id;
    if ($recipient_id <= 0 || empty($g5['guardian_recipient_table'])) {
        return null;
    }
    $row = sql_fetch(
        " SELECT recipient_id, name, email, mobile, active
          FROM `{$g5['guardian_recipient_table']}`
          WHERE recipient_id = " . $recipient_id . "
          LIMIT 1 ",
        false
    );
    return !empty($row) ? $row : null;
}

/**
 * 수신자 row 에서 채널별 발송 주소를 추출.
 *
 * @param  array  $recipient
 * @param  string $channel
 * @return string '' 이면 채널 주소 미입력
 */
function guardian_get_recipient_address(array $recipient, $channel)
{
    switch ((string)$channel) {
        case 'email':
            return !empty($recipient['email'])     ? (string)$recipient['email']    : '';
        case 'sms':
            return !empty($recipient['mobile'])    ? (string)$recipient['mobile']   : '';
        case 'kakao':
            // 카톡은 번호 기반 (알리고 알림톡은 receiver = 수신자 휴대폰)
            return !empty($recipient['mobile'])    ? (string)$recipient['mobile']   : '';
    }
    return '';
}

// =====================================================================
// 5. 결과 기록
// =====================================================================
/**
 * guardian_notify_log 테이블에 발송 결과 INSERT.
 *
 * 모든 SQL 은 @sql_query(..., false) 로 보호 — 로그 INSERT 실패가
 * 사이트나 발송 호출자에게 영향을 주지 않는다.
 *
 * @param  string $error_hash
 * @param  string $channel
 * @param  string $recipient_addr
 * @param  string $status        'success' | 'failed'
 * @param  string $reason
 * @param  int    $rule_id        규칙 ID (현재는 0)
 * @return void
 */
function guardian_log_notify_result($error_hash, $channel, $recipient_addr, $status, $reason = '', $rule_id = 0)
{
    global $g5;
    if (empty($g5['guardian_notify_log_table'])) return;

    $sql = " INSERT INTO `{$g5['guardian_notify_log_table']}`
             (rule_id, error_hash, channel, recipient, status, fail_reason, sent_dt)
             VALUES (
                 " . (int)$rule_id . ",
                 '" . sql_escape_string((string)$error_hash) . "',
                 '" . sql_escape_string((string)$channel) . "',
                 '" . sql_escape_string((string)$recipient_addr) . "',
                 '" . sql_escape_string((string)$status) . "',
                 '" . sql_escape_string((string)$reason) . "',
                 NOW()
             ) ";
    @sql_query($sql, false);
}

// =====================================================================
// 6. 테스트 발송 (관리자 화면용)
// =====================================================================
/**
 * 가상의 오류 데이터로 테스트 발송. 비용 보호는 모두 통과해야 한다.
 *
 * @param  string $channel
 * @param  int    $recipient_id
 * @return array
 */
function guardian_send_test_notification($channel, $recipient_id)
{
    $fake = array(
        'log_id'           => 0,
        'error_hash'       => 'TEST_' . bin2hex(function_exists('random_bytes') ? random_bytes(8) : pack('N', mt_rand()) . pack('N', mt_rand())),
        'error_level'      => 'TEST',
        'error_message'    => '운영지킴이 테스트 알림입니다. 실제 오류가 아닙니다.',
        'error_file'       => '[TEST]',
        'error_line'       => 0,
        'created_dt'       => date('Y-m-d H:i:s'),
        'request_url'      => defined('G5_URL') ? G5_URL : '',
        'occurrence_count' => 1,
    );

    // 테스트는 매번 다른 해시 → 쿨다운 자연 회피.
    // 단, 일일 한도 / 비상 정지 / 야간 무음 등 다른 보호는 모두 통과해야 한다.
    return guardian_notify($fake, $channel, $recipient_id, array(
        'cooldown_min' => 0,
    ));
}
