<?php
/**
 * 그누보드 운영지킴이 - 알림 발송 이력 일괄 처리
 *
 * POST 액션:
 *   - act=delete : 선택 발송 이력 DELETE
 *
 * 입력:
 *   - notify_ids[] (체크박스)
 *   - token (CSRF)
 *   - r_channel / r_status / r_period / r_sfl / r_stx / r_page (검색 상태 복원)
 *
 * 보안:
 *   - 'd' 권한 + check_admin_token
 *   - IN(...) 절 (int) 캐스팅 + 화이트리스트 재검증
 *
 * (c) 2026 K3SOFT / WizardOfCode
 *
 * @package gb_guardian
 * @version 1.0.0
 */
$sub_menu = "700500";
include_once('./_common.php');

auth_check_menu($auth, $sub_menu, 'd');
check_admin_token();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    alert('잘못된 접근입니다.');
}

/**
 * 일괄 처리 후 redirect 할 URL 을 빌드한다.
 *
 * 폼이 r_channel / r_status / r_period / r_sfl / r_stx / r_page 의 hidden
 * input 으로 현재 검색·페이지 상태를 보내오면, 화이트리스트 검증 후 query
 * string 으로 재구성해 guardian_notify_log.php 로 redirect.
 *
 * @param  string $message
 * @return string
 */
function guardian_notify_log_redirect_url($message)
{
    $qs = array();

    // channel
    if (isset($_POST['r_channel']) && in_array((string)$_POST['r_channel'], array('email', 'sms', 'kakao'), true)) {
        $qs['channel'] = (string)$_POST['r_channel'];
    }
    // status
    if (isset($_POST['r_status']) && in_array((string)$_POST['r_status'], array('success', 'failed'), true)) {
        $qs['status'] = (string)$_POST['r_status'];
    }
    // period
    if (isset($_POST['r_period']) && $_POST['r_period'] !== '') {
        $p = (int)$_POST['r_period'];
        if (in_array($p, array(0, 1, 7, 30, 90), true)) $qs['period'] = $p;
    }
    // sfl + stx (둘 다 있어야 의미)
    $sfl = isset($_POST['r_sfl']) ? (string)$_POST['r_sfl'] : '';
    $stx = isset($_POST['r_stx']) ? trim((string)$_POST['r_stx']) : '';
    if ($stx !== '' && in_array($sfl, array('recipient', 'fail_reason'), true)) {
        if (function_exists('mb_substr')) {
            $stx = mb_substr($stx, 0, 100, 'UTF-8');
        } else {
            $stx = substr($stx, 0, 100);
        }
        $qs['sfl'] = $sfl;
        $qs['stx'] = $stx;
    }
    // page
    if (isset($_POST['r_page']) && (int)$_POST['r_page'] > 1) {
        $qs['page'] = (int)$_POST['r_page'];
    }

    if ($message !== '') $qs['msg'] = (string)$message;

    return './guardian_notify_log.php' . (empty($qs) ? '' : '?' . http_build_query($qs));
}

// ---- act 검증 ----
$valid_acts = array('delete');
$act = isset($_POST['act']) ? (string)$_POST['act'] : '';
if (!in_array($act, $valid_acts, true)) {
    alert('알 수 없는 작업입니다.');
}

// ---- 대상 ID 모으기 ----
$ids = array();
if (isset($_POST['notify_ids']) && is_array($_POST['notify_ids'])) {
    foreach ($_POST['notify_ids'] as $v) {
        $v = (int)$v;
        if ($v > 0) $ids[] = $v;
    }
}
$ids = array_unique($ids);
if (empty($ids)) {
    alert('대상이 없습니다.');
}

$ids_str = implode(',', array_map('intval', $ids));
$count   = count($ids);

// ---- 액션 실행 ----
if ($act === 'delete') {
    @sql_query(
        " DELETE FROM `{$g5['guardian_notify_log_table']}`
          WHERE notify_id IN ({$ids_str}) ",
        false
    );
    goto_url(guardian_notify_log_redirect_url($count . '건의 발송 이력을 삭제했습니다.'));
}

alert('처리할 작업이 없습니다.');
