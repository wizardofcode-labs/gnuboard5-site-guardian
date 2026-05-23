<?php
/**
 * 그누보드 운영지킴이 - DB I/O 및 마스킹 라이브러리
 *
 * 책임:
 *   1. 개인정보 마스킹 — guardian_mask_pii(), guardian_mask_path()
 *   2. 안전한 길이 컷 — guardian_truncate()
 *   3. DB 디바운싱 / INSERT — guardian_db_*()
 *
 * 모든 SQL 은 그누보드 sql_* 함수를 사용하며, 두 번째 인자로 false 를
 * 넘겨 SQL 오류 시 사이트가 죽지 않도록 보호한다.
 *
 * (c) 2026 K3SOFT / WizardOfCode
 *
 * @package gb_guardian
 * @version 1.0.0
 */
if (!defined('_GNUBOARD_')) exit;

// =====================================================================
// 1. 마스킹
// =====================================================================

/**
 * 텍스트에서 개인정보(이메일, 휴대폰, 주민번호, 카드, 비밀번호)를 마스킹한다.
 *
 * 호출 빈도가 매우 높으므로 패턴 배열은 정적 캐시한다.
 * 패턴 순서는 "더 구체적인 것 먼저" 원칙 — 카드/주민번호가 먼저
 * 매칭돼야 일반 숫자열로 흡수되지 않는다.
 *
 * @param  string $text 원본 텍스트 (NULL/빈 문자열 안전)
 * @return string       마스킹된 텍스트
 */
function guardian_mask_pii($text)
{
    if ($text === null || $text === '') {
        return $text;
    }
    // 강제 문자열화 (객체/배열이 들어와도 죽지 않도록)
    $text = (string)$text;

    static $patterns = null;
    if ($patterns === null) {
        $patterns = array(
            // 신용카드 (4-4-4-4)
            '/\b\d{4}[\s-]?\d{4}[\s-]?\d{4}[\s-]?\d{4}\b/'              => '****-****-****-****',
            // 한국 주민등록번호 (6자리-7자리)
            '/\b\d{6}[\s-]?[1-4]\d{6}\b/'                                => '******-*******',
            // 이메일
            '/[\w._%+\-]+@[\w.\-]+\.[A-Za-z]{2,}/'                       => '***@***.***',
            // 한국 휴대폰
            '/\b01[016-9][\s\-]?\d{3,4}[\s\-]?\d{4}\b/'                  => '010-****-****',
            // 패스워드 = '...' / "..." (SQL/PHP 양쪽 케이스)
            '/(password|passwd|pw|pwd|pass)\s*=\s*([\'"])(?:(?!\2).)*\2/i'
                                                                          => '$1=$2***$2',
        );
    }

    foreach ($patterns as $pattern => $replacement) {
        $result = @preg_replace($pattern, $replacement, $text);
        // preg_replace 가 NULL 을 반환하면 (catastrophic backtrack 등) 원본 유지
        if ($result !== null) {
            $text = $result;
        }
    }

    return $text;
}

/**
 * 절대 경로(파일 시스템 경로)를 [ROOT] 토큰으로 마스킹한다.
 *
 * 운영자 디스크 구조 노출은 보안 위험이므로 로그에는 상대 경로만 남긴다.
 * cPanel / Plesk / XAMPP / 일반 nginx-Apache 등 흔한 패턴 모두 커버.
 *
 * @param  string $path 원본 경로
 * @return string       [ROOT]/... 형태로 마스킹된 경로
 */
function guardian_mask_path($path)
{
    if ($path === null || $path === '') {
        return $path;
    }
    $path = (string)$path;

    static $patterns = null;
    if ($patterns === null) {
        $patterns = array(
            // 리눅스 — cPanel
            '#^/home/[^/]+/public_html#'      => '[ROOT]',
            // 리눅스 — 일반 nginx/apache
            '#^/var/www/html#'                => '[ROOT]',
            '#^/var/www/[^/]+/public_html#'   => '[ROOT]',
            '#^/var/www/[^/]+#'               => '[ROOT]',
            '#^/usr/local/[^/]+/htdocs#'      => '[ROOT]',
            // macOS / Plesk
            '#^/Users/[^/]+/[^/]+#'           => '[ROOT]',
            // 윈도우 (XAMPP / WAMP) — 백슬래시 / 슬래시 모두
            '#^[A-Z]:\\\\xampp\\\\htdocs#i'   => '[ROOT]',
            '#^[A-Z]:/xampp/htdocs#i'         => '[ROOT]',
            '#^[A-Z]:\\\\wamp\\d*\\\\www#i'   => '[ROOT]',
            '#^[A-Z]:/wamp\d*/www#i'          => '[ROOT]',
        );
    }

    foreach ($patterns as $pattern => $replacement) {
        $result = @preg_replace($pattern, $replacement, $path);
        if ($result !== null) {
            $path = $result;
        }
    }
    return $path;
}

/**
 * 텍스트를 지정 길이까지 자르고 끝에 [truncated] 를 붙인다.
 *
 * mbstring 이 설치돼 있지 않은 환경에서도 동작하도록 폴백을 둔다.
 * (mbstring 미설치 시에는 바이트 단위 절단 — UTF-8 깨짐 가능성은 있으나
 *  로그 데이터 무결성보다 설치 가능성 우선)
 *
 * @param  string $text     원본
 * @param  int    $max_len  최대 글자 수
 * @return string           잘린 텍스트
 */
function guardian_truncate($text, $max_len)
{
    if ($text === null || $text === '') {
        return $text;
    }
    $text = (string)$text;
    $max_len = (int)$max_len;
    if ($max_len <= 0) {
        return $text;
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($text, 'UTF-8') <= $max_len) {
            return $text;
        }
        // 안전 마진 확보 — 끝에 ... [truncated] 토큰을 붙일 자리
        $cut = $max_len - 16;
        if ($cut < 1) $cut = 1;
        return mb_substr($text, 0, $cut, 'UTF-8') . '... [truncated]';
    }

    // mbstring 폴백
    if (strlen($text) <= $max_len) {
        return $text;
    }
    $cut = $max_len - 16;
    if ($cut < 1) $cut = 1;
    return substr($text, 0, $cut) . '... [truncated]';
}

// =====================================================================
// 2. DB 작업
// =====================================================================

/**
 * 동일 error_hash 의 로그가 쿨다운 시간 내에 이미 존재하는지 확인한다.
 *
 * 쿨다운은 cf_guardian_default_cooldown_min 설정값 (기본 30분).
 * 디바운싱 차단을 위한 핵심 함수.
 *
 * @param  string $error_hash 64자 sha256 해시
 * @return bool                true = 쿨다운 중 (INSERT 하지 말 것)
 */
function guardian_db_is_in_cooldown($error_hash)
{
    global $g5, $config;

    if (empty($g5['guardian_log_table']) || $error_hash === '') {
        return false;
    }

    $cooldown_min = (isset($config['cf_guardian_default_cooldown_min']) && $config['cf_guardian_default_cooldown_min'] !== '')
        ? (int)$config['cf_guardian_default_cooldown_min']
        : 30;
    if ($cooldown_min < 1) {
        $cooldown_min = 1;
    }

    $hash = sql_escape_string($error_hash);
    $sql  = " SELECT log_id FROM `{$g5['guardian_log_table']}`
              WHERE error_hash = '{$hash}'
                AND created_dt > DATE_SUB(NOW(), INTERVAL {$cooldown_min} MINUTE)
              ORDER BY log_id DESC
              LIMIT 1 ";
    $row = sql_fetch($sql, false);
    return !empty($row['log_id']);
}

/**
 * 가장 최근 동일 해시 로그의 occurrence_count 를 +1 하고
 * last_occurred_dt 를 NOW() 로 갱신한다.
 *
 * 디바운싱이 동작 중일 때 호출된다.
 *
 * @param  string $error_hash 64자 sha256 해시
 * @return void
 */
function guardian_db_increment_count($error_hash)
{
    global $g5;

    if (empty($g5['guardian_log_table']) || $error_hash === '') {
        return;
    }

    $hash = sql_escape_string($error_hash);
    $sql  = " UPDATE `{$g5['guardian_log_table']}`
              SET occurrence_count = occurrence_count + 1,
                  last_occurred_dt = NOW()
              WHERE error_hash = '{$hash}'
              ORDER BY log_id DESC
              LIMIT 1 ";
    @sql_query($sql, false);
}

/**
 * 신규 오류 로그를 guardian_log 테이블에 INSERT 한다.
 *
 * INSERT 가 성공하면 새로 생성된 log_id 를 반환한다. 이 값은 호출자가
 * 디스패처로 넘기는 $data['log_id'] 에 채워, 매칭 추적 로그 /
 * 요약 큐 / notified 플래그 갱신 등에서 정확히 참조할 수 있게 한다.
 *
 * @param  array $data 정규화된 데이터 (guardian_capture 가 만들어 넘겨줌)
 * @return int          새 log_id (실패 시 0)
 */
function guardian_db_insert(array $data)
{
    global $g5;

    if (empty($g5['guardian_log_table'])) {
        return 0;
    }

    $sql = " INSERT INTO `{$g5['guardian_log_table']}`
             (error_hash, error_level, error_message, error_file, error_line,
              error_trace, request_url, request_method, user_id, user_ip,
              occurrence_count, last_occurred_dt, resolved, notified, created_dt)
             VALUES
             ('" . sql_escape_string(isset($data['error_hash'])     ? $data['error_hash']     : '') . "',
              '" . sql_escape_string(isset($data['level'])          ? $data['level']          : 'NOTICE') . "',
              '" . sql_escape_string(isset($data['message'])        ? $data['message']        : '') . "',
              '" . sql_escape_string(isset($data['file'])           ? $data['file']           : '') . "',
              " . (int)(isset($data['line']) ? $data['line'] : 0) . ",
              '" . sql_escape_string(isset($data['trace'])          ? $data['trace']          : '') . "',
              '" . sql_escape_string(isset($data['request_url'])    ? $data['request_url']    : '') . "',
              '" . sql_escape_string(isset($data['request_method']) ? $data['request_method'] : '') . "',
              '" . sql_escape_string(isset($data['user_id'])        ? $data['user_id']        : '') . "',
              '" . sql_escape_string(isset($data['user_ip'])        ? $data['user_ip']        : '') . "',
              1,
              NOW(),
              0,
              0,
              NOW()) ";
    @sql_query($sql, false);

    // 새로 생성된 AUTO_INCREMENT 값을 가져온다. mysqli / mysql 양쪽 폴백.
    $new_id = 0;
    if (function_exists('sql_insert_id')) {
        $new_id = (int)sql_insert_id();
    } elseif (function_exists('mysqli_insert_id') && !empty($g5['connect_db'])) {
        $new_id = (int)@mysqli_insert_id($g5['connect_db']);
    } elseif (function_exists('mysql_insert_id')) {
        $new_id = (int)@mysql_insert_id();
    }

    return $new_id;
}

/**
 * 동일 해시의 가장 최근 log_id 를 반환한다 (디바운싱 케이스용).
 *
 * 디바운싱 시 increment_count 가 새 row 를 만들지 않으므로, 호출자가
 * dispatcher 로 넘길 log_id 가 필요할 때 본 함수로 기존 row 의 id 를
 * 가져온다.
 *
 * @param  string $error_hash 64자 sha256 해시
 * @return int                기존 최신 log_id (없으면 0)
 */
function guardian_db_get_latest_log_id($error_hash)
{
    global $g5;

    if (empty($g5['guardian_log_table']) || $error_hash === '') {
        return 0;
    }
    $hash = sql_escape_string((string)$error_hash);
    $row = sql_fetch(
        " SELECT log_id FROM `{$g5['guardian_log_table']}`
          WHERE error_hash = '{$hash}'
          ORDER BY log_id DESC
          LIMIT 1 ",
        false
    );
    return !empty($row['log_id']) ? (int)$row['log_id'] : 0;
}
