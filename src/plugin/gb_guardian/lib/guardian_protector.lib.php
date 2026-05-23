<?php
/**
 * 그누보드 운영지킴이 - 알림 발송 보호 시스템 핵심
 *
 * 책임 5종:
 *   1. 야간 무음 시간대         — guardian_is_silent_hours()
 *   2. 일일 발송 한도           — guardian_check_daily_limit()
 *   3. 동일 오류 쿨다운          — guardian_check_notify_cooldown()
 *   4. 무한 루프 방지 (재진입 락) — guardian_acquire_lock() / release_lock()
 *   5. 비상 정지 스위치          — guardian_emergency_stop_check()
 *
 * 절대 원칙:
 *   - 본 라이브러리 함수 중 어떤 것도 die() / throw 하지 않는다.
 *   - 의심스러우면 차단(false) 쪽으로 결정한다 — "조용한 실패"가 비용 폭탄보다 안전.
 *
 * (c) 2026 K3SOFT / WizardOfCode
 *
 * @package gb_guardian
 * @version 1.0.0
 */
if (!defined('_GNUBOARD_')) exit;

// =====================================================================
// 1. 야간 무음 시간대 (SMS / 카톡 전용)
// =====================================================================
/**
 * 현재 시각이 야간 무음 시간대에 해당하는지 판정.
 *
 * cf_guardian_sms_silent_enabled = 0 이면 항상 false 반환 (기능 OFF).
 * 시간 비교는 그누보드 서버 로컬 시각 기준 (date('G')).
 *
 * @return bool true = 무음 시간대 (발송 차단)
 */
function guardian_is_silent_hours()
{
    global $config;

    if (empty($config['cf_guardian_sms_silent_enabled'])) {
        return false;
    }

    $start = isset($config['cf_guardian_sms_silent_start']) && $config['cf_guardian_sms_silent_start'] !== ''
        ? (int)$config['cf_guardian_sms_silent_start']
        : 22;
    $end = isset($config['cf_guardian_sms_silent_end']) && $config['cf_guardian_sms_silent_end'] !== ''
        ? (int)$config['cf_guardian_sms_silent_end']
        : 8;

    // 잘못된 값(0~23 외) 안전 클램프
    if ($start < 0 || $start > 23) $start = 22;
    if ($end   < 0 || $end   > 23) $end   = 8;

    $now_hour = (int)date('G');

    if ($start === $end) {
        // 동일 시 — 의미 없음. 전부 무음으로 해석하면 사이트 알림 끊김 위험.
        // 안전 쪽으로 false (무음 OFF) 처리.
        return false;
    }
    if ($start > $end) {
        // 22~08 같이 자정 걸치는 경우
        return ($now_hour >= $start || $now_hour < $end);
    }
    // 09~17 같이 일반 범위
    return ($now_hour >= $start && $now_hour < $end);
}

// =====================================================================
// 2. 일일 발송 한도
// =====================================================================
/**
 * 채널별 오늘 발송 건수가 일일 한도 미만인지 판정.
 *
 * 실패 발송도 한도에 카운트한다 — 알리고 일시 장애로 실패가 폭주하면
 * 한도 보호가 무력화되기 때문.
 *
 * daily_limit = 0 이면 차단 (관리자 비상 스위치).
 *
 * @param  string $channel "email" / "sms" / "kakao"
 * @return bool            true = 발송 가능 / false = 차단
 */
function guardian_check_daily_limit($channel)
{
    global $g5, $config;

    static $limit_keys = array(
        'sms'   => 'cf_guardian_sms_daily_limit',
        'kakao' => 'cf_guardian_kakao_daily_limit',
        'email' => 'cf_guardian_email_daily_limit',
    );
    static $defaults = array(
        'sms'   => 50,
        'kakao' => 100,
        'email' => 500,
    );

    $channel = (string)$channel;
    if (!isset($limit_keys[$channel])) {
        return false; // 알 수 없는 채널은 차단
    }

    $key   = $limit_keys[$channel];
    $limit = isset($config[$key]) && $config[$key] !== '' ? (int)$config[$key] : $defaults[$channel];

    if ($limit <= 0) {
        return false; // 0 이면 완전 차단
    }

    if (empty($g5['guardian_notify_log_table'])) {
        return false; // 테이블 미설치 — 안전 쪽으로 차단
    }

    $row = sql_fetch(
        " SELECT COUNT(*) AS cnt
          FROM `{$g5['guardian_notify_log_table']}`
          WHERE channel = '" . sql_escape_string($channel) . "'
            AND DATE(sent_dt) = CURDATE() ",
        false
    );
    $today = !empty($row['cnt']) ? (int)$row['cnt'] : 0;

    return ($today < $limit);
}

/**
 * 오늘 채널별 발송 건수 (관리 화면 표시용).
 *
 * @param  string $channel
 * @return int
 */
function guardian_today_sent_count($channel)
{
    global $g5;
    if (empty($g5['guardian_notify_log_table'])) return 0;
    $row = sql_fetch(
        " SELECT COUNT(*) AS cnt
          FROM `{$g5['guardian_notify_log_table']}`
          WHERE channel = '" . sql_escape_string($channel) . "'
            AND DATE(sent_dt) = CURDATE() ",
        false
    );
    return !empty($row['cnt']) ? (int)$row['cnt'] : 0;
}

// =====================================================================
// 3. 동일 오류 쿨다운
// =====================================================================
/**
 * 동일 (error_hash, channel, recipient_addr) 조합으로 최근 N분 내
 * 성공 발송한 이력이 있는지 확인.
 *
 * "DB 저장 디바운싱" (캡처 단계) 과는 다른 층의 보호:
 *   - 저장 디바운싱: 같은 오류 100회 발생 → log row 1개
 *   - 알림 디바운싱: 저장된 오류 1건 → 30분간 알림 1통
 *
 * status = 'success' 만 대상 — 실패한 발송은 재시도 가능하도록.
 *
 * @param  string $error_hash    오류 그룹 해시
 * @param  string $channel       채널
 * @param  string $recipient_addr 실제 수신자 주소 (이메일/번호/카톡ID)
 * @param  int    $cooldown_min  쿨다운 분
 * @return bool                  true = 발송 가능 / false = 쿨다운 중
 */
function guardian_check_notify_cooldown($error_hash, $channel, $recipient_addr, $cooldown_min = 30)
{
    global $g5;

    if ($error_hash === '' || $error_hash === null) {
        return true; // 해시 없으면 그룹화 불가, 쿨다운 적용 안 함
    }
    if (empty($g5['guardian_notify_log_table'])) {
        return true; // 테이블 없음 — 쿨다운 미적용 (실 발송 단계에서 별도 보호)
    }

    $cooldown_min = (int)$cooldown_min;
    if ($cooldown_min < 1) {
        return true; // 쿨다운 0/음수 = 비활성 (테스트 발송용)
    }

    $hash = sql_escape_string($error_hash);
    $ch   = sql_escape_string($channel);
    $addr = sql_escape_string($recipient_addr);

    $row = sql_fetch(
        " SELECT notify_id
          FROM `{$g5['guardian_notify_log_table']}`
          WHERE error_hash = '{$hash}'
            AND channel    = '{$ch}'
            AND recipient  = '{$addr}'
            AND status     = 'success'
            AND sent_dt > DATE_SUB(NOW(), INTERVAL {$cooldown_min} MINUTE)
          LIMIT 1 ",
        false
    );

    return empty($row['notify_id']);
}

// =====================================================================
// 4. 무한 루프 방지 (재진입 락)
// =====================================================================
/**
 * 채널별 발송 락을 획득한다.
 *
 * 같은 요청 내에서 같은 채널의 발송이 재진입하면 false 반환.
 * (정적 변수라 PHP 프로세스 단위 — DB 락이 아님)
 *
 * 사용 패턴:
 *   if (!guardian_acquire_lock('sms')) return false;
 *   try { ... } finally { guardian_release_lock('sms'); }
 *
 * @param  string $channel
 * @return bool   true = 획득 성공 / false = 이미 락 잡힘
 */
function guardian_acquire_lock($channel)
{
    if (!isset($GLOBALS['__guardian_locks']) || !is_array($GLOBALS['__guardian_locks'])) {
        $GLOBALS['__guardian_locks'] = array();
    }
    $channel = (string)$channel;
    if (!empty($GLOBALS['__guardian_locks'][$channel])) {
        return false;
    }
    $GLOBALS['__guardian_locks'][$channel] = true;
    return true;
}

/**
 * guardian_acquire_lock 으로 잡은 락을 해제한다.
 *
 * @param  string $channel
 * @return void
 */
function guardian_release_lock($channel)
{
    if (!isset($GLOBALS['__guardian_locks']) || !is_array($GLOBALS['__guardian_locks'])) {
        $GLOBALS['__guardian_locks'] = array();
    }
    $GLOBALS['__guardian_locks'][(string)$channel] = false;
}

// =====================================================================
// 5. 비상 정지
// =====================================================================
/**
 * 비상 정지 스위치가 OFF 상태인지 (= 발송 가능한지) 판정.
 *
 * cf_guardian_emergency_stop = 1 이면 모든 채널 발송 즉시 차단.
 *
 * @return bool true = 발송 가능 / false = 비상 정지 활성
 */
function guardian_emergency_stop_check()
{
    global $config;
    return empty($config['cf_guardian_emergency_stop']);
}
