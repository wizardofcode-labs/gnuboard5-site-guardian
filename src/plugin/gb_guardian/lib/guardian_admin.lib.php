<?php
/**
 * 그누보드 운영지킴이 - 관리자 화면 공용 헬퍼
 *
 * 책임: adm/guardian_*.php 페이지들이 공통으로 사용하는 작은 함수들.
 *   - 시간 표기 ("3분 전" 같은 상대시각)
 *   - 등급 배지 HTML
 *   - 메시지 짧게 자르기
 *   - 미해결 카운트 (메뉴 표시용 캐시)
 *
 * (c) 2026 K3SOFT / WizardOfCode
 *
 * @package gb_guardian
 * @version 1.0.0
 */
if (!defined('_GNUBOARD_')) exit;

/**
 * DATETIME 문자열을 "분 전 / 시간 전 / 일 전" 또는 절대 시각으로 변환.
 *
 * @param  string $dt MySQL DATETIME ("YYYY-mm-dd HH:ii:ss")
 * @return string
 */
function guardian_format_datetime($dt)
{
    if (empty($dt) || $dt === '0000-00-00 00:00:00') {
        return '-';
    }
    $ts = strtotime($dt);
    if ($ts === false || $ts <= 0) {
        return '-';
    }
    $diff = time() - $ts;
    if ($diff < 0)        return date('Y-m-d H:i', $ts);
    if ($diff < 60)       return $diff . '초 전';
    if ($diff < 3600)     return (int)floor($diff / 60) . '분 전';
    if ($diff < 86400)    return (int)floor($diff / 3600) . '시간 전';
    if ($diff < 2592000)  return (int)floor($diff / 86400) . '일 전';
    return date('Y-m-d H:i', $ts);
}

/**
 * 등급 배지 HTML 을 반환한다.
 *
 * 출력은 항상 이스케이프된 등급 문자열만 포함하므로 echo 안전.
 *
 * @param  string $level 등급
 * @return string        <span> HTML
 */
function guardian_level_badge($level)
{
    static $colors = null;
    if ($colors === null) {
        $colors = array(
            'FATAL'      => array('#fee',     '#c33'),
            'ERROR'      => array('#fee',     '#c33'),
            'EXCEPTION'  => array('#fee',     '#c33'),
            'WARNING'    => array('#fffbe6',  '#a80'),
            'DB'         => array('#fff0e6',  '#c63'),
            'NOTICE'     => array('#f0f4ff',  '#557'),
            'DEPRECATED' => array('#f5f5f5',  '#888'),
        );
    }
    $level = (string)$level;
    $c = isset($colors[$level]) ? $colors[$level] : array('#eee', '#666');
    return sprintf(
        '<span style="display:inline-block;padding:2px 8px;border-radius:3px;background:%s;color:%s;font-size:11px;font-weight:bold;">%s</span>',
        $c[0], $c[1], get_text($level)
    );
}

/**
 * 메시지를 지정 길이까지 자르고 ... 을 붙인다 (이스케이프 적용).
 *
 * @param  string $text
 * @param  int    $len
 * @return string
 */
function guardian_truncate_text($text, $len = 80)
{
    $text = (string)$text;
    $len  = (int)$len;
    if ($len < 1) $len = 1;

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($text, 'UTF-8') <= $len) {
            return get_text($text);
        }
        return get_text(mb_substr($text, 0, $len, 'UTF-8')) . '...';
    }
    if (strlen($text) <= $len) {
        return get_text($text);
    }
    return get_text(substr($text, 0, $len)) . '...';
}

/**
 * 미해결 Fatal/Error/Exception 건수를 반환한다 (요청 단위 캐시).
 *
 * 대시보드와 헤더 등 여러 곳에서 호출돼도 SQL 은 1회만 실행.
 *
 * @return int
 */
function guardian_count_unresolved()
{
    global $g5;
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    if (empty($g5['guardian_log_table'])) {
        return $cache = 0;
    }
    $row = sql_fetch(
        " SELECT COUNT(*) AS cnt
          FROM `{$g5['guardian_log_table']}`
          WHERE resolved = 0
            AND error_level IN ('FATAL', 'ERROR', 'EXCEPTION') ",
        false
    );
    return $cache = (!empty($row['cnt']) ? (int)$row['cnt'] : 0);
}

/**
 * 알리고 SMS 솔루션 설치 여부.
 *
 * 함수 정의 / 라이브러리 파일 / 플러그인 폴더 3가지를 순차 확인.
 *
 * @return bool
 */
function guardian_is_aligo_installed()
{
    if (function_exists('aligo_send_sms')) {
        return true;
    }
    if (defined('G5_PLUGIN_PATH')) {
        if (file_exists(G5_PLUGIN_PATH . '/aligosms/aligosms.lib.php')) {
            return true;
        }
        if (file_exists(G5_PLUGIN_PATH . '/aligo/lib/aligo.lib.php')) {
            return true;
        }
        if (is_dir(G5_PLUGIN_PATH . '/aligosms')) {
            return true;
        }
    }
    return false;
}

/**
 * 등급 select 옵션 HTML 을 반환한다 (검색 폼용).
 *
 * @param  string $current 현재 선택된 값
 * @return string          <option> 들
 */
function guardian_level_options($current = '')
{
    $current = (string)$current;
    $levels = array('', 'FATAL', 'ERROR', 'EXCEPTION', 'WARNING', 'DB', 'NOTICE', 'DEPRECATED');
    $labels = array(
        ''           => '전체 등급',
        'FATAL'      => 'FATAL',
        'ERROR'      => 'ERROR',
        'EXCEPTION'  => 'EXCEPTION',
        'WARNING'    => 'WARNING',
        'DB'         => 'DB',
        'NOTICE'     => 'NOTICE',
        'DEPRECATED' => 'DEPRECATED',
    );
    $html = '';
    foreach ($levels as $lv) {
        $sel = ($lv === $current) ? ' selected' : '';
        $html .= '<option value="' . get_text($lv) . '"' . $sel . '>'
              . get_text($labels[$lv]) . '</option>';
    }
    return $html;
}
