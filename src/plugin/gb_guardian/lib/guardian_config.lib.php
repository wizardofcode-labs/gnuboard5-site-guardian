<?php
/**
 * 그누보드 운영지킴이 - 별도 설정 시스템
 *
 * g5_config 의 cf_guardian_* 컬럼을 대체하는 별도 테이블 (guardian_config) 기반
 * 설정 시스템.
 *
 * 책임:
 *   1. key/value 단위로 설정값 get/set/delete (캐시 활용)
 *   2. g5_config → guardian_config 일회성 마이그레이션
 *   3. 호환 레이어 — \$config['cf_guardian_*'] 참조도 그대로 동작 (기존 코드 변경 부담 0)
 *
 * 절대 원칙:
 *   - 모든 SQL 은 @sql_query(..., false) 안전망
 *   - 테이블 미존재 환경에서도 캐시는 빈 배열 반환 (사이트 무중단)
 *   - 한 요청 내 정적 캐시로 SQL 부하 최소화
 *
 * (c) 2026 K3SOFT / WizardOfCode
 *
 * @package gb_guardian
 * @version 1.1.2
 */
if (!defined('_GNUBOARD_')) exit;

/**
 * 캐시된 설정 사전을 반환한다 (요청 단위 정적 캐시).
 *
 * @return array key => 캐스팅된 value
 */
function guardian_config_load_cache()
{
    static $cache = null;
    if ($cache !== null) return $cache;

    global $g5;
    $cache = array();

    if (empty($g5['guardian_config_table'])) return $cache;

    // 테이블 존재 확인
    $check = sql_fetch(
        " SHOW TABLES LIKE '" . sql_escape_string($g5['guardian_config_table']) . "' ",
        false
    );
    if (empty($check)) return $cache;

    $result = @sql_query(
        " SELECT cfg_key, cfg_value, cfg_type FROM `{$g5['guardian_config_table']}` ",
        false
    );
    if (!$result) return $cache;

    while ($row = sql_fetch_array($result)) {
        $key   = isset($row['cfg_key'])   ? (string)$row['cfg_key']   : '';
        $val   = isset($row['cfg_value']) ? $row['cfg_value']         : '';
        $type  = isset($row['cfg_type'])  ? (string)$row['cfg_type']  : 'string';
        if ($key === '') continue;

        switch ($type) {
            case 'int':
                $val = (int)$val;
                break;
            case 'bool':
                $val = !empty($val) && $val !== '0';
                break;
            case 'json':
                $decoded = @json_decode((string)$val, true);
                $val = ($decoded !== null) ? $decoded : array();
                break;
            default:
                $val = (string)$val;
        }
        $cache[$key] = $val;
    }
    return $cache;
}

/**
 * 캐시 무효화 (set/delete 후 호출).
 *
 * @return void
 */
function guardian_config_invalidate_cache()
{
    // 정적 캐시 재초기화를 위해 빈 캐시 강제 — 다음 호출 시 다시 로드
    $GLOBALS['__guardian_config_cache_invalid'] = true;
}

/**
 * 단일 설정값 조회.
 *
 * @param  string $key
 * @param  mixed  $default
 * @return mixed
 */
function guardian_config_get($key, $default = '')
{
    if (!empty($GLOBALS['__guardian_config_cache_invalid'])) {
        // 강제 무효화 시 캐시 함수의 정적 변수를 우회하기 위해 직접 1회 SELECT
        $GLOBALS['__guardian_config_cache_invalid'] = false;
        guardian_config_force_reload();
    }
    // patch overlay 우선 — set 직후 같은 요청 내에서 즉시 반영 (force_reload
    // 직전 force_reload 의 결과도 patch 로 들어있다)
    if (isset($GLOBALS['__guardian_config_patch'])
        && is_array($GLOBALS['__guardian_config_patch'])
        && array_key_exists($key, $GLOBALS['__guardian_config_patch'])) {
        return $GLOBALS['__guardian_config_patch'][$key];
    }
    $cache = guardian_config_load_cache();
    return isset($cache[$key]) ? $cache[$key] : $default;
}

/**
 * 정적 캐시를 무시하고 강제로 다시 로드 (내부 헬퍼).
 *
 * 정적 변수를 직접 변경할 수 없으므로, 별도 글로벌에 캐시를 다시 만들어
 * 후속 guardian_config_load_cache() 호출이 새 데이터를 반환하도록 한다.
 *
 * 단순화 — 캐시는 한 요청 내 일관성 우선. set/delete 후 같은 요청에서
 * 변경값이 즉시 보이지 않을 수 있으나, 그 케이스는 install.php 처럼 1회성
 * 작업이라 큰 문제 없음.
 *
 * @return void
 */
function guardian_config_force_reload()
{
    // 현재 구조에서는 정적 캐시 우회가 어려움. 대신 캐시 함수가 호출되기 전에
    // GLOBALS 에 패치 데이터를 두고, get 이 그것을 우선 참조하도록 처리.
    global $g5;
    if (empty($g5['guardian_config_table'])) return;

    $patch = array();
    $check = sql_fetch(
        " SHOW TABLES LIKE '" . sql_escape_string($g5['guardian_config_table']) . "' ",
        false
    );
    if (empty($check)) return;

    $result = @sql_query(
        " SELECT cfg_key, cfg_value, cfg_type FROM `{$g5['guardian_config_table']}` ",
        false
    );
    if (!$result) return;

    while ($row = sql_fetch_array($result)) {
        $key  = isset($row['cfg_key']) ? (string)$row['cfg_key'] : '';
        if ($key === '') continue;
        $val  = isset($row['cfg_value']) ? $row['cfg_value'] : '';
        $type = isset($row['cfg_type'])  ? (string)$row['cfg_type'] : 'string';
        switch ($type) {
            case 'int':  $val = (int)$val; break;
            case 'bool': $val = !empty($val) && $val !== '0'; break;
            case 'json':
                $decoded = @json_decode((string)$val, true);
                $val = ($decoded !== null) ? $decoded : array();
                break;
            default: $val = (string)$val;
        }
        $patch[$key] = $val;
    }
    $GLOBALS['__guardian_config_patch'] = $patch;
}

/**
 * 설정값 저장 (UPSERT).
 *
 * @param  string $key
 * @param  mixed  $value
 * @param  string $type 'string' | 'int' | 'bool' | 'json'
 * @return bool
 */
function guardian_config_set($key, $value, $type = 'string')
{
    global $g5;
    if (empty($g5['guardian_config_table']) || $key === '') return false;

    // 타입별 직렬화
    switch ($type) {
        case 'int':
            $value_str = (string)(int)$value;
            break;
        case 'bool':
            $value_str = (!empty($value) && $value !== '0') ? '1' : '0';
            break;
        case 'json':
            $value_str = (string)@json_encode($value, JSON_UNESCAPED_UNICODE);
            break;
        default:
            $value_str = (string)$value;
            $type = 'string';
    }

    $key_esc   = sql_escape_string((string)$key);
    $type_esc  = sql_escape_string((string)$type);
    $value_esc = sql_escape_string($value_str);

    $sql = " INSERT INTO `{$g5['guardian_config_table']}`
             (cfg_key, cfg_value, cfg_type, updated_dt)
             VALUES ('{$key_esc}', '{$value_esc}', '{$type_esc}', NOW())
             ON DUPLICATE KEY UPDATE
                 cfg_value  = VALUES(cfg_value),
                 cfg_type   = VALUES(cfg_type),
                 updated_dt = NOW() ";

    $ok = @sql_query($sql, false);
    if ($ok) {
        // 같은 요청 내 변경값이 즉시 보이도록 강제 패치
        if (!isset($GLOBALS['__guardian_config_patch']) || !is_array($GLOBALS['__guardian_config_patch'])) {
            $GLOBALS['__guardian_config_patch'] = array();
        }
        // 캐시된 형태로 다시 캐스팅
        $cast_value = $value;
        if ($type === 'int')  $cast_value = (int)$value;
        if ($type === 'bool') $cast_value = (!empty($value) && $value !== '0');
        $GLOBALS['__guardian_config_patch'][(string)$key] = $cast_value;
    }
    return $ok ? true : false;
}

/**
 * 설정값 삭제.
 *
 * @param  string $key
 * @return bool
 */
function guardian_config_delete($key)
{
    global $g5;
    if (empty($g5['guardian_config_table']) || $key === '') return false;
    $key_esc = sql_escape_string((string)$key);
    @sql_query(
        " DELETE FROM `{$g5['guardian_config_table']}` WHERE cfg_key = '{$key_esc}' ",
        false
    );
    if (isset($GLOBALS['__guardian_config_patch'][(string)$key])) {
        unset($GLOBALS['__guardian_config_patch'][(string)$key]);
    }
    return true;
}

/**
 * 모든 설정값 반환.
 *
 * @return array
 */
function guardian_config_get_all()
{
    $cache = guardian_config_load_cache();
    if (!empty($GLOBALS['__guardian_config_patch']) && is_array($GLOBALS['__guardian_config_patch'])) {
        $cache = array_merge($cache, $GLOBALS['__guardian_config_patch']);
    }
    return $cache;
}

/**
 * 본 함수의 캐시 우선순위:
 *   1) GLOBALS 패치 (set 후 즉시 반영)
 *   2) 정적 캐시 (load_cache)
 *
 * @internal — 위 guardian_config_get 의 보조 헬퍼
 */

/**
 * cron 인증 토큰 생성. "메일주소 앞 16자(영숫자만) + 랜덤 16자 hex" 형태.
 *
 * 보안 의도:
 *   - 기존 sha1(cf_admin_email) 16자는 메일주소만 알면 추론 가능 → 폐기
 *   - 랜덤 16자 hex 만으로도 충분(64bit 엔트로피)하지만, 이메일 prefix 를
 *     덧붙여 어떤 사이트의 토큰인지 사용자가 식별하기 쉽게 한다 (충돌 방지 X)
 *
 * @param  string $email cf_admin_email 값. 빈 값이면 prefix 생략.
 * @return string 16~32자 영숫자 토큰
 */
function guardian_generate_cron_secret($email = '')
{
    // 메일 주소 prefix — 영숫자만 남겨 URL 안전하게 유지
    $email_part = '';
    if ($email !== '') {
        $clean = preg_replace('/[^A-Za-z0-9]/', '', (string)$email);
        $email_part = (string)substr($clean, 0, 16);
    }

    // 랜덤 16자 hex (8 byte = 64bit 엔트로피)
    // PHP 5.6 호환 — random_bytes 는 PHP 7+ 만 가능, openssl 폴백 후 mt_rand 폴백
    $random_part = '';
    if (function_exists('openssl_random_pseudo_bytes')) {
        $bytes = @openssl_random_pseudo_bytes(8);
        if ($bytes !== false) $random_part = bin2hex($bytes);
    }
    if ($random_part === '') {
        // 최후 폴백 — 시드 보강 후 mt_rand
        @mt_srand((int)((microtime(true) * 1000000) % PHP_INT_MAX));
        for ($i = 0; $i < 16; $i++) {
            $random_part .= dechex(mt_rand(0, 15));
        }
    }

    return $email_part . $random_part;
}

/**
 * 현재 cron 토큰을 반환한다. 없으면 자동 생성 후 저장.
 *
 * 호출 시점:
 *   - install.php : 업그레이드 시 1회 (또는 신규 설치 시)
 *   - guardian_config.php : 환경설정 페이지 접근 시 (첫 admin 방문 시 생성 보장)
 *   - summary_cron.php : 검증 시점 (저장된 토큰만 비교, 없으면 거부)
 *
 * @return string 16~32자 토큰
 */
function guardian_get_or_create_cron_secret()
{
    $secret = guardian_config_get('cron_secret', '');
    if (!empty($secret)) return (string)$secret;

    global $config;
    $email = !empty($config['cf_admin_email']) ? (string)$config['cf_admin_email'] : '';
    $new_secret = guardian_generate_cron_secret($email);
    guardian_config_set('cron_secret', $new_secret, 'string');
    return $new_secret;
}

/**
 * g5_config 의 cf_guardian_* 컬럼을 guardian_config 로 일괄 마이그레이션.
 *
 * 이미 마이그레이션 완료된 경우 ('_migrated_from_g5_config' 마커 존재) 스킵.
 *
 * @return array array('status' => 'completed'|'already_done', 'count' => int)
 */
function guardian_config_migrate_from_g5_config()
{
    // 이미 마이그레이션 완료됐는지 체크
    $migrated_marker = guardian_config_get('_migrated_from_g5_config', '');
    if (!empty($migrated_marker)) {
        return array('status' => 'already_done', 'marker' => $migrated_marker, 'count' => 0);
    }

    // 매핑: g5_config 컬럼 → guardian_config 키 + 타입
    $mapping = array(
        'cf_guardian_use'                   => array('use',                   'bool'),
        'cf_guardian_collect_levels'        => array('collect_levels',        'string'),
        'cf_guardian_log_keep_days'         => array('log_keep_days',         'int'),
        'cf_guardian_default_cooldown_min'  => array('default_cooldown_min',  'int'),
        'cf_guardian_aligo_enabled'         => array('aligo_enabled',         'bool'),
        'cf_guardian_emergency_stop'        => array('emergency_stop',        'bool'),
        'cf_guardian_email_daily_limit'     => array('email_daily_limit',     'int'),
        'cf_guardian_sms_daily_limit'       => array('sms_daily_limit',       'int'),
        'cf_guardian_kakao_daily_limit'     => array('kakao_daily_limit',     'int'),
        'cf_guardian_sms_silent_enabled'    => array('sms_silent_enabled',    'bool'),
        'cf_guardian_sms_silent_start'      => array('sms_silent_start',      'int'),
        'cf_guardian_sms_silent_end'        => array('sms_silent_end',        'int'),
        'cf_guardian_kakao_template_code'   => array('kakao_template_code',   'string'),
        'cf_guardian_kakao_emphasize_title' => array('kakao_emphasize_title', 'string'),
        'cf_guardian_rule_match_logging'    => array('rule_match_logging',    'bool'),
        'cf_guardian_rule_match_keep_days'  => array('rule_match_keep_days',  'int'),
        'cf_guardian_summary_mode'          => array('summary_mode',          'string'),
        'cf_guardian_summary_last_daily'    => array('summary_last_daily',    'string'),
        'cf_guardian_summary_last_weekly'   => array('summary_last_weekly',   'string'),
    );

    global $config;
    $migrated_count = 0;
    foreach ($mapping as $old_key => $info) {
        list($new_key, $type) = $info;
        if (isset($config[$old_key])) {
            guardian_config_set($new_key, $config[$old_key], $type);
            $migrated_count++;
        }
    }

    guardian_config_set('_migrated_from_g5_config', date('Y-m-d H:i:s'), 'string');
    guardian_config_set('_migrated_count', $migrated_count, 'int');

    return array('status' => 'completed', 'count' => $migrated_count);
}

/**
 * 호환 레이어 — guardian_config 의 값을 \$config['cf_guardian_*'] 키로 주입.
 *
 * 기존 기존 코드는 \$config['cf_guardian_use'] 같이 그누보드 코어
 * 전역 \$config 를 직접 참조한다. 본 함수가 추가 컬럼이 사라진 환경에서도
 * 같은 키로 값을 제공해 기존 코드를 변경 없이 그대로 동작시킨다.
 *
 * 주의: 이미 \$config 에 있는 키는 덮어쓰지 않는다. cleanup.php 실행 전
 * 환경에서는 g5_config 값이 우선, 실행 후 환경에서는 본 함수 주입값 사용.
 *
 * @return void
 */
function guardian_config_inject_to_global_config()
{
    global $config;
    if (!is_array($config)) return;

    $cache = guardian_config_get_all();
    foreach ($cache as $key => $value) {
        // _migrated_* 같은 내부 메타 키는 주입 대상 아님
        if (strpos((string)$key, '_') === 0) continue;

        $g5_key = 'cf_guardian_' . $key;
        if (!isset($config[$g5_key]) || $config[$g5_key] === '' || $config[$g5_key] === null) {
            // bool 은 g5 컨벤션상 0/1 문자열로 주입 (기존 코드가 empty() / (int) 캐스팅 패턴 사용)
            if (is_bool($value)) {
                $config[$g5_key] = $value ? '1' : '0';
            } else {
                $config[$g5_key] = $value;
            }
        }
    }
}
