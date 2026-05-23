<?php
/**
 * 그누보드 운영지킴이 - 차트 데이터 가공 라이브러리
 *
 * 책임: 대시보드 Chart.js 그래프에 넘길 데이터를 SQL 결과에서 가공.
 *
 * 본 라이브러리는 DB 조회만 하고 HTML / JS 출력은 호출자가 담당한다.
 *
 * (c) 2026 K3SOFT / WizardOfCode
 *
 * @package gb_guardian
 * @version 1.0.0
 */
if (!defined('_GNUBOARD_')) exit;

/**
 * 일별 등급별 발생 건수를 반환한다.
 *
 * 구조: $result['labels'][날짜], $result['datasets'][등급][날짜] = 건수
 * (등급별 라인을 그리기 위해 등급을 시리즈로 분리)
 *
 * @param  int $days 최근 N 일
 * @return array
 */
function guardian_chart_daily_data($days = 7)
{
    global $g5;

    $days = (int)$days;
    if ($days < 1)   $days = 1;
    if ($days > 365) $days = 365;

    $result = array(
        'labels'   => array(),
        'datasets' => array(),
    );

    if (empty($g5['guardian_log_table'])) {
        return $result;
    }

    // 1) X축 — 오늘부터 N일 전까지 빈 날짜라도 0 으로 채워 표시
    $today = strtotime(date('Y-m-d'));
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', $today - ($i * 86400));
        $result['labels'][] = $d;
    }

    // 2) 데이터 조회
    $sql = " SELECT DATE(created_dt) AS d,
                    error_level,
                    SUM(occurrence_count) AS total
             FROM `{$g5['guardian_log_table']}`
             WHERE created_dt >= DATE_SUB(CURDATE(), INTERVAL " . ($days - 1) . " DAY)
             GROUP BY DATE(created_dt), error_level
             ORDER BY d ASC ";

    $res = @sql_query($sql, false);
    if (!$res) {
        return $result;
    }

    // 3) 등급별 시리즈로 정리
    $series = array();
    while ($row = sql_fetch_array($res)) {
        $d  = isset($row['d']) ? (string)$row['d'] : '';
        $lv = isset($row['error_level']) ? (string)$row['error_level'] : 'UNKNOWN';
        $tt = isset($row['total']) ? (int)$row['total'] : 0;
        if ($d === '') continue;
        if (!isset($series[$lv])) {
            $series[$lv] = array();
        }
        $series[$lv][$d] = $tt;
    }

    // 4) 모든 날짜에 대해 누락된 등급은 0 채움
    foreach ($series as $lv => $by_date) {
        $arr = array();
        foreach ($result['labels'] as $d) {
            $arr[] = isset($by_date[$d]) ? $by_date[$d] : 0;
        }
        $result['datasets'][$lv] = $arr;
    }

    return $result;
}

/**
 * 최다 발생 오류 TOP N (error_hash 그룹).
 *
 * @param  int $limit
 * @param  int $days
 * @return array       SELECT 결과 배열
 */
function guardian_chart_top_errors($limit = 5, $days = 7)
{
    global $g5;
    $limit = (int)$limit;
    $days  = (int)$days;
    if ($limit < 1)  $limit = 5;
    if ($limit > 50) $limit = 50;
    if ($days < 1)   $days  = 1;
    if ($days > 365) $days  = 365;

    if (empty($g5['guardian_log_table'])) {
        return array();
    }

    $sql = " SELECT error_hash,
                    error_level,
                    error_message,
                    error_file,
                    error_line,
                    SUM(occurrence_count) AS total,
                    MAX(last_occurred_dt) AS last_dt
             FROM `{$g5['guardian_log_table']}`
             WHERE created_dt >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
             GROUP BY error_hash
             ORDER BY total DESC
             LIMIT {$limit} ";

    $res = @sql_query($sql, false);
    if (!$res) return array();

    $rows = array();
    while ($row = sql_fetch_array($res)) {
        $rows[] = $row;
    }
    return $rows;
}

/**
 * 미처리 Fatal/Error/Exception 최근 N 건.
 *
 * @param  int $limit
 * @return array
 */
function guardian_chart_recent_unresolved($limit = 10)
{
    global $g5;
    $limit = (int)$limit;
    if ($limit < 1)  $limit = 10;
    if ($limit > 100) $limit = 100;

    if (empty($g5['guardian_log_table'])) {
        return array();
    }

    $sql = " SELECT log_id, error_level, error_message, error_file, error_line,
                    occurrence_count, created_dt, last_occurred_dt
             FROM `{$g5['guardian_log_table']}`
             WHERE resolved = 0
               AND error_level IN ('FATAL','ERROR','EXCEPTION')
             ORDER BY COALESCE(last_occurred_dt, created_dt) DESC
             LIMIT {$limit} ";

    $res = @sql_query($sql, false);
    if (!$res) return array();

    $rows = array();
    while ($row = sql_fetch_array($res)) {
        $rows[] = $row;
    }
    return $rows;
}
