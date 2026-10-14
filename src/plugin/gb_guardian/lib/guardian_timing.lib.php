<?php
/** 오류 요청만 측정한다. DB/네트워크/응답 출력 없이 요청 종료 시 PHP 로그 한 줄. */
if (!defined('_GNUBOARD_')) exit;

function guardian_timing_start()
{
    if (!isset($GLOBALS['guardian_request_timing'])) {
        $now = microtime(true);
        $from_server = isset($_SERVER['REQUEST_TIME_FLOAT']) && is_numeric($_SERVER['REQUEST_TIME_FLOAT']);
        $GLOBALS['guardian_request_timing'] = array(
            'started' => $from_server ? (float)$_SERVER['REQUEST_TIME_FLOAT'] : $now,
            'start_source' => $from_server ? 'REQUEST_TIME_FLOAT' : 'first_error',
            'flushed' => false, 'error_count' => 0, 'references' => array(),
            'report_ms' => 0, 'capture_ms' => 0,
            'group_lock_ms' => 0, 'notify_lock_ms' => 0,
            'notify_send_ms' => 0, 'notify_send_attempts' => 0,
        );
        register_shutdown_function('guardian_timing_flush');
    }
    return guardian_timing_now();
}

function guardian_timing_now()
{
    return isset($GLOBALS['guardian_request_timing']) && !$GLOBALS['guardian_request_timing']['flushed']
        ? microtime(true) : 0;
}

function guardian_timing_add($key, $started)
{
    if ($started <= 0 || !isset($GLOBALS['guardian_request_timing'])
        || $GLOBALS['guardian_request_timing']['flushed']) return;
    if (in_array($key, array('report_ms', 'capture_ms', 'group_lock_ms', 'notify_lock_ms', 'notify_send_ms'), true)) {
        $GLOBALS['guardian_request_timing'][$key] += max(0, (microtime(true) - $started) * 1000);
        if ($key === 'notify_send_ms') $GLOBALS['guardian_request_timing']['notify_send_attempts']++;
    }
}

function guardian_timing_note_error($code, array $context = array())
{
    if (!isset($GLOBALS['guardian_request_timing']) || $GLOBALS['guardian_request_timing']['flushed']) return;
    $GLOBALS['guardian_request_timing']['error_count']++;
    // 본문·URL·회원 ID·IP·인증 정보는 복제하지 않고 참조도 최대 5건만 남긴다.
    if (count($GLOBALS['guardian_request_timing']['references']) >= 5) return;
    $code = is_string($code) && preg_match('/^[A-Za-z0-9_.:-]{1,100}$/D', $code) ? $code : 'php_error';
    $clean = function_exists('guardian_report_context') ? guardian_report_context($context) : array();
    $GLOBALS['guardian_request_timing']['references'][] = array('error_code' => $code, 'context' => $clean);
}

function guardian_timing_flush()
{
    if (!isset($GLOBALS['guardian_request_timing']) || $GLOBALS['guardian_request_timing']['flushed']) return;
    $state = $GLOBALS['guardian_request_timing'];
    $GLOBALS['guardian_request_timing']['flushed'] = true;
    if ($state['error_count'] <= 0) return;
    $now = microtime(true);
    $ended = isset($GLOBALS['guardian_timing_main_end']) ? $GLOBALS['guardian_timing_main_end'] : $now;
    $log = array('request_elapsed_ms' => round(max(0, ($ended - $state['started']) * 1000), 3),
        'shutdown_before_log_ms' => round(max(0, ($now - $ended) * 1000), 3),
        'start_source' => $state['start_source'],
        'end_point' => isset($GLOBALS['guardian_timing_main_end']) ? 'shutdown_begin' : 'manual_flush',
        'error_count' => $state['error_count'], 'references' => $state['references']);
    foreach (array('report_ms', 'capture_ms', 'group_lock_ms', 'notify_lock_ms', 'notify_send_ms') as $key) {
        $log[$key] = round($state[$key], 3);
    }
    $log['notify_send_attempts'] = $state['notify_send_attempts'];
    $json = json_encode($log);
    if ($json !== false) @error_log('[GB Guardian timing] ' . $json);
}

function guardian_timing_mark_shutdown()
{
    $GLOBALS['guardian_timing_main_end'] = microtime(true);
}

// Guardian의 fatal 수집/방문자 정리보다 먼저 종료 시각을 표시한다.
// 정상 요청에서는 시각 한 번만 읽고 로그/DB 작업은 하지 않는다.
register_shutdown_function('guardian_timing_mark_shutdown');
