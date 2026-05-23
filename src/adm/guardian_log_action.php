<?php
/**
 * 그누보드 운영지킴이 - 로그 일괄 / 단건 처리
 *
 * POST 액션:
 *   - act=toggle_resolve : 해결됨 토글 (resolved 0↔1)
 *   - act=delete         : DELETE
 *
 * 입력:
 *   - log_id (단건) 또는 log_ids[] (일괄)
 *   - token (CSRF)
 *   - single=1 이면 JSON 응답, 아니면 목록으로 redirect
 *
 * (c) 2026 K3SOFT / WizardOfCode
 *
 * @package gb_guardian
 * @version 1.0.0
 */
$sub_menu = "700200";
include_once('./_common.php');

auth_check_menu($auth, $sub_menu, 'd');
check_admin_token();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    alert('잘못된 접근입니다.');
}

$is_single = !empty($_POST['single']);

// JSON 응답 헬퍼 (단건 모드)
if ($is_single) {
    header('Content-Type: application/json; charset=utf-8');
}

/**
 * 일괄 처리 후 redirect 할 URL 을 빌드한다.
 *
 * 폼이 r_level / r_period / r_resolved / r_sfl / r_stx / r_page 의 hidden
 * input 으로 현재 검색·페이지 상태를 보내오면, 화이트리스트 검증 후 query
 * string 으로 재구성해 guardian_log.php 로 redirect 한다.
 *
 * @param  string $message 사용자에게 표시할 메시지 (msg 파라미터)
 * @return string          "./guardian_log.php?level=...&page=..." 형태
 */
function guardian_build_redirect_url($message)
{
    $qs = array();

    // level — 화이트리스트
    $valid_levels = array('FATAL', 'ERROR', 'EXCEPTION', 'DB', 'WARNING', 'NOTICE', 'DEPRECATED');
    if (isset($_POST['r_level']) && $_POST['r_level'] !== '') {
        $lv = strtoupper(trim((string)$_POST['r_level']));
        if (in_array($lv, $valid_levels, true)) $qs['level'] = $lv;
    }

    // period — 화이트리스트 (1/7/30/90/0)
    if (isset($_POST['r_period']) && $_POST['r_period'] !== '') {
        $p = (int)$_POST['r_period'];
        if (in_array($p, array(0, 1, 7, 30, 90), true)) $qs['period'] = $p;
    }

    // resolved — '0' / '1' 만
    if (isset($_POST['r_resolved']) && in_array((string)$_POST['r_resolved'], array('0', '1'), true)) {
        $qs['resolved'] = (string)$_POST['r_resolved'];
    }

    // sfl — 화이트리스트
    if (isset($_POST['r_sfl']) && $_POST['r_sfl'] !== '') {
        $sfl = (string)$_POST['r_sfl'];
        if (in_array($sfl, array('error_message', 'error_file'), true)) $qs['sfl'] = $sfl;
    }

    // stx — 길이 제한
    if (isset($_POST['r_stx']) && $_POST['r_stx'] !== '') {
        $stx = trim((string)$_POST['r_stx']);
        if (function_exists('mb_substr')) {
            $stx = mb_substr($stx, 0, 100, 'UTF-8');
        } else {
            $stx = substr($stx, 0, 100);
        }
        if ($stx !== '') $qs['stx'] = $stx;
    }

    // page — 양수 정수
    if (isset($_POST['r_page']) && (int)$_POST['r_page'] > 1) {
        $qs['page'] = (int)$_POST['r_page'];
    }

    if ($message !== '') $qs['msg'] = (string)$message;

    return './guardian_log.php' . (empty($qs) ? '' : '?' . http_build_query($qs));
}

function guardian_action_respond($success, $message = '', $extra = array(), $is_json = false)
{
    if ($is_json) {
        echo json_encode(array_merge(
            array('success' => $success ? true : false, 'message' => (string)$message),
            $extra
        ), JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($success) {
        goto_url(guardian_build_redirect_url($message));
    } else {
        alert($message);
    }
}

// ---- 1. act 검증 ----
$valid_acts = array('toggle_resolve', 'delete');
$act = isset($_POST['act']) ? (string)$_POST['act'] : '';
if (!in_array($act, $valid_acts, true)) {
    guardian_action_respond(false, '알 수 없는 작업입니다.', array(), $is_single);
}

// ---- 2. 대상 ID 모으기 + 정수 캐스팅 ----
$ids = array();
if (isset($_POST['log_id'])) {
    $ids[] = (int)$_POST['log_id'];
}
if (isset($_POST['log_ids']) && is_array($_POST['log_ids'])) {
    foreach ($_POST['log_ids'] as $v) {
        $ids[] = (int)$v;
    }
}
// 양수 정수만 남기기
$ids = array_unique($ids);
$ids = array_filter($ids, function ($v) { return ((int)$v) > 0; });
if (empty($ids)) {
    guardian_action_respond(false, '대상이 없습니다.', array(), $is_single);
}

$ids_str = implode(',', array_map('intval', $ids));
$count   = count($ids);

// ---- 3. 액션 실행 ----
switch ($act) {
    case 'toggle_resolve':
        @sql_query(
            " UPDATE `{$g5['guardian_log_table']}`
              SET resolved = IF(resolved = 1, 0, 1)
              WHERE log_id IN ({$ids_str}) ",
            false
        );
        guardian_action_respond(
            true,
            $count . '건의 해결됨 상태를 토글했습니다.',
            array('count' => $count),
            $is_single
        );
        break;

    case 'delete':
        @sql_query(
            " DELETE FROM `{$g5['guardian_log_table']}`
              WHERE log_id IN ({$ids_str}) ",
            false
        );
        guardian_action_respond(
            true,
            $count . '건을 삭제했습니다.',
            array('count' => $count),
            $is_single
        );
        break;
}
