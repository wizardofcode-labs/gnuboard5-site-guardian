<?php
/**
 * 그누보드 운영지킴이 - 수신자 처리
 *
 * POST 액션:
 *   - insert         : 신규 등록
 *   - update         : 기존 수정
 *   - delete         : 일괄 삭제
 *   - toggle_active  : 일괄 활성 토글
 *
 * 보안:
 *   - check_admin_token (CSRF)
 *   - 이메일 검증, 휴대폰 정규화/검증
 *   - 화이트리스트 act
 *   - IN(...) 절은 (int) 캐스팅 후 사용
 *
 * (c) 2026 K3SOFT / WizardOfCode
 *
 * @package gb_guardian
 * @version 1.0.0
 */
$sub_menu = "700400";
include_once('./_common.php');

auth_check_menu($auth, $sub_menu, 'w');
check_admin_token();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    alert('잘못된 접근입니다.');
}

if (defined('GUARDIAN_LIB_PATH')) {
    include_once(GUARDIAN_LIB_PATH . '/guardian_aligo_sms.lib.php');
} else {
    include_once(G5_PLUGIN_PATH . '/gb_guardian/lib/guardian_aligo_sms.lib.php');
}

$valid_acts = array('insert', 'update', 'delete', 'toggle_active');
$act = isset($_POST['act']) ? (string)$_POST['act'] : '';
if (!in_array($act, $valid_acts, true)) {
    alert('알 수 없는 작업입니다.');
}

// =====================================================================
// insert / update — 단건 폼 저장
// =====================================================================
if ($act === 'insert' || $act === 'update') {
    $rid = isset($_POST['recipient_id']) ? (int)$_POST['recipient_id'] : 0;

    $name   = isset($_POST['name'])   ? trim((string)$_POST['name'])   : '';
    $email  = isset($_POST['email'])  ? trim((string)$_POST['email'])  : '';
    $mobile = isset($_POST['mobile']) ? trim((string)$_POST['mobile']) : '';
    $active = !empty($_POST['active']) ? 1 : 0;

    // 길이 클램프 (DB 컬럼 보호)
    if (function_exists('mb_substr')) {
        $name  = mb_substr($name,  0, 50,  'UTF-8');
        $email = mb_substr($email, 0, 100, 'UTF-8');
    } else {
        $name  = substr($name,  0, 50);
        $email = substr($email, 0, 100);
    }

    // 검증
    if ($name === '') {
        alert('이름은 필수입니다.');
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        alert('유효한 이메일을 입력해주세요.');
    }

    // 휴대폰 정규화 + 검증 (입력 시에만)
    if ($mobile !== '') {
        $mobile = guardian_normalize_phone($mobile);
        if (!guardian_is_valid_phone($mobile)) {
            alert('휴대폰 번호 형식이 올바르지 않습니다.');
        }
    }

    if ($act === 'insert') {
        @sql_query(
            " INSERT INTO `{$g5['guardian_recipient_table']}`
              (name, email, mobile, active, created_dt)
              VALUES (
                  '" . sql_escape_string($name)   . "',
                  '" . sql_escape_string($email)  . "',
                  '" . sql_escape_string($mobile) . "',
                  " . (int)$active . ",
                  NOW()
              ) ",
            false
        );
        goto_url('./guardian_recipient.php?msg=' . urlencode('수신자가 등록되었습니다.'));
    }

    // update
    if ($rid <= 0) {
        alert('수정 대상이 잘못되었습니다.');
    }
    @sql_query(
        " UPDATE `{$g5['guardian_recipient_table']}`
          SET name   = '" . sql_escape_string($name)   . "',
              email  = '" . sql_escape_string($email)  . "',
              mobile = '" . sql_escape_string($mobile) . "',
              active = " . (int)$active . "
          WHERE recipient_id = " . (int)$rid . " ",
        false
    );
    goto_url('./guardian_recipient.php?msg=' . urlencode('수신자가 수정되었습니다.'));
}

// =====================================================================
// delete / toggle_active — 일괄 처리
// =====================================================================
auth_check_menu($auth, $sub_menu, 'd');

$ids = array();
if (isset($_POST['recipient_ids']) && is_array($_POST['recipient_ids'])) {
    foreach ($_POST['recipient_ids'] as $v) {
        $ids[] = (int)$v;
    }
}
$ids = array_unique($ids);
$ids = array_filter($ids, function ($v) { return ((int)$v) > 0; });
if (empty($ids)) {
    alert('대상이 선택되지 않았습니다.');
}
$ids_str = implode(',', array_map('intval', $ids));
$count   = count($ids);

if ($act === 'delete') {
    @sql_query(
        " DELETE FROM `{$g5['guardian_recipient_table']}` WHERE recipient_id IN ({$ids_str}) ",
        false
    );
    goto_url('./guardian_recipient.php?msg=' . urlencode($count . '명을 삭제했습니다.'));
}

// toggle_active
@sql_query(
    " UPDATE `{$g5['guardian_recipient_table']}`
      SET active = IF(active = 1, 0, 1)
      WHERE recipient_id IN ({$ids_str}) ",
    false
);
goto_url('./guardian_recipient.php?msg=' . urlencode($count . '명의 활성 상태를 토글했습니다.'));
