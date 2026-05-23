<?php
/**
 * 그누보드 운영지킴이 - 오류 로그 상세보기 (AJAX 응답)
 *
 * GET /adm/guardian_log_view.php?log_id={int}
 *
 * 출력: application/json
 *   - 정상  : 로그 row 의 모든 필드를 마스킹된 그대로 반환
 *   - 오류  : { "error": "..." }
 *
 * 모든 출력 필드는 캡처 단계에서 이미 마스킹된 값이므로
 * 추가 마스킹은 하지 않는다.
 *
 * (c) 2026 K3SOFT / WizardOfCode
 *
 * @package gb_guardian
 * @version 1.0.0
 */
$sub_menu = "700200";
include_once('./_common.php');

auth_check_menu($auth, $sub_menu, 'r');

if (defined('GUARDIAN_LIB_PATH')) {
    include_once(GUARDIAN_LIB_PATH . '/guardian_admin.lib.php');
} else {
    include_once(G5_PLUGIN_PATH . '/gb_guardian/lib/guardian_admin.lib.php');
}

header('Content-Type: application/json; charset=utf-8');

$log_id = isset($_GET['log_id']) ? (int)$_GET['log_id'] : 0;
if ($log_id <= 0) {
    echo json_encode(array('error' => 'log_id 가 누락되었습니다.'), JSON_UNESCAPED_UNICODE);
    exit;
}

$row = sql_fetch(
    " SELECT log_id, error_hash, error_level, error_message, error_file, error_line,
             error_trace, request_url, request_method, user_id, user_ip,
             occurrence_count, last_occurred_dt, created_dt, resolved, notified
      FROM `{$g5['guardian_log_table']}`
      WHERE log_id = " . (int)$log_id . "
      LIMIT 1 ",
    false
);

if (empty($row) || empty($row['log_id'])) {
    echo json_encode(array('error' => '로그를 찾을 수 없습니다.'), JSON_UNESCAPED_UNICODE);
    exit;
}

// 클라이언트가 그대로 textContent 로 출력하므로 추가 escape 불필요.
// 단, 등급 배지는 HTML 이라 별도 키로 미리 만들어 보낸다.
$payload = array(
    'log_id'           => (int)$row['log_id'],
    'error_hash'       => (string)$row['error_hash'],
    'error_level'      => (string)$row['error_level'],
    'error_message'    => (string)$row['error_message'],
    'error_file'       => (string)$row['error_file'],
    'error_line'       => (int)$row['error_line'],
    'error_trace'      => (string)$row['error_trace'],
    'request_url'      => (string)$row['request_url'],
    'request_method'   => (string)$row['request_method'],
    'user_id'          => (string)$row['user_id'],
    'user_ip'          => (string)$row['user_ip'],
    'occurrence_count' => (int)$row['occurrence_count'],
    'last_occurred_dt' => (string)$row['last_occurred_dt'],
    'created_dt'       => (string)$row['created_dt'],
    'resolved'         => (int)$row['resolved'],
    'notified'         => (int)$row['notified'],
    'level_badge'      => guardian_level_badge($row['error_level']),
);

echo json_encode($payload, JSON_UNESCAPED_UNICODE);
