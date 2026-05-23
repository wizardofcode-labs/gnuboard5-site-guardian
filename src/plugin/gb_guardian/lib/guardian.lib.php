<?php
/**
 * 그누보드 운영지킴이 - 공용 헬퍼 라이브러리
 *
 * 책임: 핸들러 등록, 컨텍스트 수집, 등급 매핑, 핸들러 체이닝.
 *
 * 본 라이브러리에 포함된 함수는 모두 다음 조건을 만족한다.
 *   - PHP 5.6 ~ 8.3 모두에서 동작 (Throwable / null 안전 연산자 등 미사용)
 *   - 외부 사이드 이펙트 없음 (DB 접근은 guardian_db.lib.php 가 담당)
 *
 * (c) 2026 K3SOFT / WizardOfCode
 *
 * @package gb_guardian
 * @version 1.0.0
 */
if (!defined('_GNUBOARD_')) exit;

// =====================================================================
// 1. 핸들러 등록 (entry point)
// =====================================================================
/**
 * PHP 오류 / 예외 / shutdown 핸들러 3종을 모두 등록한다.
 *
 * 기존 핸들러 레퍼런스를 $GLOBALS 에 저장해 체이닝 호출에 활용한다.
 *
 * @return void
 */
function guardian_register_handlers()
{
    // 직전 등록된 핸들러를 보관 — 체이닝 시 위임 호출에 사용
    $GLOBALS['guardian_prev_error_handler']     = set_error_handler('guardian_on_error');
    $GLOBALS['guardian_prev_exception_handler'] = set_exception_handler('guardian_on_exception');
    register_shutdown_function('guardian_on_shutdown');
}

/**
 * 기존 PHP 에러 핸들러로 위임한다.
 *
 * 운영지킴이가 기존 핸들러를 덮어쓴 게 아니라 "추가로 끼어든" 형태가
 * 되도록 항상 마지막에 호출.
 *
 * @param  int    $errno
 * @param  string $errstr
 * @param  string $errfile
 * @param  int    $errline
 * @return mixed  기존 핸들러 결과 또는 false (PHP 기본 폴백)
 */
function guardian_chain_error_handler($errno, $errstr, $errfile, $errline)
{
    if (!empty($GLOBALS['guardian_prev_error_handler'])
        && is_callable($GLOBALS['guardian_prev_error_handler'])) {
        return call_user_func(
            $GLOBALS['guardian_prev_error_handler'],
            $errno, $errstr, $errfile, $errline
        );
    }
    // false = PHP 기본 핸들러로 폴백
    return false;
}

// =====================================================================
// 2. 컨텍스트 수집
// =====================================================================
/**
 * 현재 요청 URL 을 마스킹된 형태로 반환한다.
 *
 * CLI 환경에서는 'CLI: 명령행' 형태로 반환.
 *
 * @return string
 */
function guardian_get_request_url()
{
    if (PHP_SAPI === 'cli') {
        $argv = isset($_SERVER['argv']) && is_array($_SERVER['argv'])
            ? implode(' ', $_SERVER['argv'])
            : '';
        return 'CLI: ' . $argv;
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = isset($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : 'unknown';
    $uri    = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';

    return guardian_mask_pii($scheme . '://' . $host . $uri);
}

/**
 * 클라이언트 IP 주소를 반환한다.
 *
 * Cloudflare / 일반 프록시 헤더를 우선 확인하고, 그누보드 자체 함수가
 * 있으면 그쪽을 위임 사용한다 (사이트별 설정 일관성 유지).
 *
 * @return string
 */
function guardian_get_user_ip()
{
    // 그누보드 코어 함수 우선 사용
    if (function_exists('get_real_client_ip')) {
        $ip = get_real_client_ip();
        if (!empty($ip)) {
            return (string)$ip;
        }
    }
    $candidates = array(
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_REAL_IP',
        'HTTP_X_FORWARDED_FOR',
        'REMOTE_ADDR',
    );
    foreach ($candidates as $key) {
        if (empty($_SERVER[$key])) {
            continue;
        }
        $raw = (string)$_SERVER[$key];
        // X-Forwarded-For 는 콤마 구분, 첫 항목이 클라이언트
        $parts = explode(',', $raw);
        $ip = trim($parts[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    return '0.0.0.0';
}

/**
 * 현재 로그인 회원의 mb_id 를 반환. 비로그인 시 'guest'.
 *
 * @return string
 */
function guardian_get_current_user()
{
    global $member;
    if (!empty($member['mb_id'])) {
        return (string)$member['mb_id'];
    }
    return 'guest';
}

/**
 * debug_backtrace 결과를 짧은 문자열 형태로 반환한다.
 *
 * @param  int   $skip 앞에서 스킵할 프레임 수 (핸들러 자기 자신 제외용)
 * @return string
 */
function guardian_get_trace($skip = 2)
{
    // 인자값 미저장으로 메모리 절약
    $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
    $skip  = (int)$skip;
    if ($skip < 0) $skip = 0;

    $lines = array();
    $count = count($trace);
    for ($i = $skip; $i < $count; $i++) {
        $t = $trace[$i];
        $file = isset($t['file']) ? $t['file'] : '[internal]';
        $line = isset($t['line']) ? (int)$t['line'] : 0;
        if (isset($t['class'])) {
            $type = isset($t['type']) ? $t['type'] : '::';
            $func = $t['class'] . $type . (isset($t['function']) ? $t['function'] : '');
        } else {
            $func = isset($t['function']) ? $t['function'] : '';
        }
        $lines[] = '#' . ($i - $skip) . ' ' . $file . '(' . $line . '): ' . $func . '()';
    }
    return implode("\n", $lines);
}

// =====================================================================
// 3. 등급 매핑
// =====================================================================
/**
 * PHP 에러 상수를 운영지킴이 등급 문자열로 변환한다.
 *
 * @param  int $errno PHP 에러 상수
 * @return string     "FATAL" / "ERROR" / "WARNING" / "NOTICE" / "DEPRECATED" / "UNKNOWN"
 */
function guardian_errno_to_level($errno)
{
    static $map = null;
    if ($map === null) {
        // E_STRICT 는 PHP 8.4 부터 제거 예정 — 사용 금지
        $map = array(
            E_ERROR             => 'FATAL',
            E_PARSE             => 'FATAL',
            E_CORE_ERROR        => 'FATAL',
            E_COMPILE_ERROR     => 'FATAL',
            E_USER_ERROR        => 'ERROR',
            E_RECOVERABLE_ERROR => 'ERROR',
            E_WARNING           => 'WARNING',
            E_CORE_WARNING      => 'WARNING',
            E_COMPILE_WARNING   => 'WARNING',
            E_USER_WARNING      => 'WARNING',
            E_NOTICE            => 'NOTICE',
            E_USER_NOTICE       => 'NOTICE',
            E_DEPRECATED        => 'DEPRECATED',
            E_USER_DEPRECATED   => 'DEPRECATED',
        );
    }
    $errno = (int)$errno;
    return isset($map[$errno]) ? $map[$errno] : 'UNKNOWN';
}

/**
 * 해당 에러 등급이 수집 대상인지 판정한다.
 *
 * cf_guardian_collect_levels (기본 'FATAL,ERROR,WARNING,EXCEPTION,DB') 에
 * 포함된 등급만 수집한다. 매 호출마다 파싱하지 않도록 정적 캐시.
 *
 * @param  int  $errno PHP 에러 상수
 * @return bool        수집 대상 여부
 */
function guardian_should_collect($errno)
{
    global $config;
    static $collect_set = null;

    if ($collect_set === null) {
        $levels = (isset($config['cf_guardian_collect_levels']) && $config['cf_guardian_collect_levels'] !== '')
            ? (string)$config['cf_guardian_collect_levels']
            : 'FATAL,ERROR,WARNING,EXCEPTION,DB';
        $arr = array_map('trim', explode(',', $levels));
        $arr = array_map('strtoupper', $arr);
        $collect_set = array_flip($arr);
    }

    $level = guardian_errno_to_level($errno);
    return isset($collect_set[$level]);
}

// =====================================================================
// 4. 관리자 메뉴 등록
// =====================================================================
/**
 * 그누보드 관리자 그룹 매핑(`$amenu`) 에 운영지킴이 그룹 700 을 추가한다.
 *
 * 중요: 그누보드는 `admin.menu{NNN}.*.php` 파일을 자동 스캔해 `$amenu` 를
 * 채우고, `admin.head.php` 의 메뉴 그리기 루프(`foreach ($amenu as ...)`)
 * 가 이 배열을 순회한다. `admin.menu700.guardian.php` 같은 파일을 만들지
 * 않는 대신 본 훅으로 동적 등록한다.
 *
 * `$amenu` 의 value 는 메뉴 그리기 로직에서 실제로 사용되지 않고 key 만
 * 사용된다 (admin.head.php 152~170 라인 참조). 자리표시자 문자열로 채운다.
 *
 * @param  array $amenu 그누보드 관리자 그룹 매핑 배열
 * @return array
 */
function guardian_admin_amenu($amenu)
{
    if (!is_array($amenu)) {
        $amenu = array();
    }
    if (!isset($amenu['700'])) {
        // 값은 admin.head.php 의 메뉴 그리기 로직에서 사용되지 않으므로
        // 디버깅 식별자 정도로 둔다 (실제 파일이 존재할 필요 없음).
        $amenu['700'] = 'gb_guardian_extend';
    }
    return $amenu;
}

/**
 * 그누보드 관리자 메뉴 배열에 운영지킴이 메뉴 그룹을 추가한다.
 *
 * add_replace('admin_menu', ...) 콜백 — $menu 를 받아 변경 후 반환한다.
 *
 * 그룹 코드 700 사용 근거:
 *   - 그누보드 코어: 100/200/300/400/500/900 사용 중
 *   - SMTP Manager: menu100 그룹에 100940~ 추가
 *   - 700 그룹은 코어/주요 플러그인에서 미사용 → 충돌 가능성 가장 낮음
 *
 * 메뉴 항목 형식 (그누보드 5.6.x):
 *   array('코드', '메뉴명', URL, 'auth_key' [, $left_skip])
 *
 * 그룹 헤더는 [0] 인덱스에, 실제 메뉴 항목은 [1] 부터 입력해야 한다.
 * (admin.head.php 의 print_menu2 가 `for ($i = 1; ...)` 로 순회)
 *
 * @param  array $menu 그누보드 관리자 메뉴 배열
 * @return array       운영지킴이 메뉴가 추가된 배열
 */
function guardian_admin_menu($menu)
{
    if (!is_array($menu)) {
        $menu = array();
    }
    if (!isset($menu['menu700']) || !is_array($menu['menu700'])) {
        $menu['menu700'] = array();
    }

    // 그룹 헤더 (코드 끝 5자리가 0이면 그룹 헤더로 인식됨)
    $menu['menu700'][] = array('700000', '운영지킴이', G5_ADMIN_URL . '/guardian_dashboard.php', 'guardian');

    // 본 화면들
    $menu['menu700'][] = array('700100', '대시보드',          G5_ADMIN_URL . '/guardian_dashboard.php',  'guardian_dashboard');
    $menu['menu700'][] = array('700200', '오류 로그',         G5_ADMIN_URL . '/guardian_log.php',        'guardian_log');

    // 알림 규칙 엔진
    $menu['menu700'][] = array('700300', '알림 규칙 관리',     G5_ADMIN_URL . '/guardian_rule.php',           'guardian_rule');
    $menu['menu700'][] = array('700310', '규칙 매칭 추적 로그', G5_ADMIN_URL . '/guardian_rule_match_log.php', 'guardian_rule_match_log');

    // 수신자/알림 이력 — 본격 구현됨
    $menu['menu700'][] = array('700400', '수신자 관리',        G5_ADMIN_URL . '/guardian_recipient.php',  'guardian_recipient');
    $menu['menu700'][] = array('700500', '알림 발송 이력',     G5_ADMIN_URL . '/guardian_notify_log.php', 'guardian_notify_log');
    $menu['menu700'][] = array('700600', '테스트 발송',        G5_ADMIN_URL . '/guardian_notify_test.php', 'guardian_notify_test');

    // 환경설정
    $menu['menu700'][] = array('700900', '환경설정',           G5_ADMIN_URL . '/guardian_config.php',     'guardian_config');

    // 설치 테스트 — 강제 경고 발생으로 캡처/알림 흐름 검증
    $menu['menu700'][] = array('700990', '설치 테스트',         G5_ADMIN_URL . '/guardian_test_warning.php', 'guardian_test_warning');

    return $menu;
}
