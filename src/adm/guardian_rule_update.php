<?php
/**
 * 그누보드 운영지킴이 - 알림 규칙 처리 페이지
 *
 * 액션:
 *   - insert       : 신규 규칙 등록
 *   - update       : 기존 규칙 수정
 *   - delete_one   : 단건 삭제 (폼에서)
 *   - delete       : 일괄 삭제 (목록에서)
 *   - toggle_active: 일괄 활성 토글 (목록에서)
 *
 * 보안: auth_check_menu / check_admin_token / sql_escape_string / IN(...) (int) 캐스팅
 *
 * (c) 2026 K3SOFT / WizardOfCode
 *
 * @package gb_guardian
 * @version 1.0.0
 */
$sub_menu = "700300";
include_once('./_common.php');

auth_check_menu($auth, $sub_menu, 'w');
check_admin_token();

$valid_acts = array('insert', 'update', 'delete_one', 'delete', 'toggle_active');
$act = isset($_POST['act']) ? (string)$_POST['act'] : '';
if (!in_array($act, $valid_acts, true)) {
    alert('알 수 없는 작업입니다.');
}

// 등급 화이트리스트
$valid_levels = array('FATAL', 'ERROR', 'EXCEPTION', 'DB', 'WARNING', 'NOTICE', 'DEPRECATED', 'ALL');
// 채널 / 모드 / 중복범위 화이트리스트
$valid_channels = array('email', 'sms', 'kakao');
$valid_modes    = array('instant', 'daily', 'weekly', 'timeofday');
$valid_dedups   = array('rule', 'global');

// =====================================================================
// insert / update — 단건 폼 저장
// =====================================================================
if ($act === 'insert' || $act === 'update') {
    $rid = isset($_POST['rule_id']) ? (int)$_POST['rule_id'] : 0;

    $rule_name = isset($_POST['rule_name']) ? trim((string)$_POST['rule_name']) : '';
    if ($rule_name === '') alert('규칙명은 필수입니다.');

    $rule_active = !empty($_POST['rule_active']) ? 1 : 0;

    $priority = isset($_POST['priority']) ? (int)$_POST['priority'] : 50;
    if ($priority < 1) $priority = 1;
    if ($priority > 100) $priority = 100;

    // 등급 — 화이트리스트 적용
    $levels_in = isset($_POST['error_levels']) && is_array($_POST['error_levels']) ? $_POST['error_levels'] : array();
    $levels_clean = array();
    foreach ($levels_in as $lv) {
        $lv = strtoupper(trim((string)$lv));
        if (in_array($lv, $valid_levels, true)) $levels_clean[] = $lv;
    }
    if (empty($levels_clean)) alert('오류 등급을 1개 이상 선택하세요.');
    // ALL 이 있으면 다른 항목 무시 (단순화)
    if (in_array('ALL', $levels_clean, true)) {
        $levels_clean = array('ALL');
    }
    $levels_str = implode(',', array_unique($levels_clean));

    // 파일 패턴 — 길이 클램프 + \r\n 차단
    $file_pattern = isset($_POST['file_pattern']) ? trim((string)$_POST['file_pattern']) : '';
    $file_pattern = str_replace(array("\r", "\n"), '', $file_pattern);
    if (function_exists('mb_substr')) {
        $file_pattern = mb_substr($file_pattern, 0, 200, 'UTF-8');
    } else {
        $file_pattern = substr($file_pattern, 0, 200);
    }

    // 채널
    $channel = isset($_POST['channel']) ? (string)$_POST['channel'] : 'email';
    if (!in_array($channel, $valid_channels, true)) $channel = 'email';

    // 수신자 ID — (int) 캐스팅 + 0 제거
    $recipient_ids = array();
    if (isset($_POST['recipient_ids']) && is_array($_POST['recipient_ids'])) {
        foreach ($_POST['recipient_ids'] as $r) {
            $r = (int)$r;
            if ($r > 0) $recipient_ids[] = $r;
        }
    }
    $recipient_ids = array_values(array_unique($recipient_ids));
    $recipient_csv = implode(',', $recipient_ids);

    // 모드
    $mode = isset($_POST['mode']) ? (string)$_POST['mode'] : 'instant';
    if (!in_array($mode, $valid_modes, true)) $mode = 'instant';

    // schedule_time — 모드별 조립
    $schedule_time = '';
    switch ($mode) {
        case 'instant':
            $schedule_time = '';
            break;

        case 'daily':
            $st = isset($_POST['schedule_time_daily']) ? trim((string)$_POST['schedule_time_daily']) : '09:00';
            // HH:MM 형식 검증
            if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $st)) $st = '09:00';
            $schedule_time = $st;
            break;

        case 'weekly':
            $dow = isset($_POST['schedule_dow']) ? (int)$_POST['schedule_dow'] : 1;
            if ($dow < 1 || $dow > 7) $dow = 1;
            $st = isset($_POST['schedule_time_weekly']) ? trim((string)$_POST['schedule_time_weekly']) : '09:00';
            if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $st)) $st = '09:00';
            $schedule_time = $dow . '|' . $st;
            break;

        case 'timeofday':
            $st = isset($_POST['schedule_timeofday']) ? trim((string)$_POST['schedule_timeofday']) : '09-18';
            // 형식: "HH-HH" 또는 "HH-HH,HH-HH" 만 허용 (영숫자/하이픈/콤마/공백)
            if (!preg_match('/^[\d\-,\s]+$/', $st)) $st = '09-18';
            // 길이 클램프
            $st = substr(str_replace(' ', '', $st), 0, 100);
            $schedule_time = $st;
            break;
    }

    // 쿨다운
    $cooldown = isset($_POST['cooldown_min']) ? (int)$_POST['cooldown_min'] : 30;
    if ($cooldown < 1) $cooldown = 1;
    if ($cooldown > 1440) $cooldown = 1440;

    // 중복 제거 범위
    $dedup = isset($_POST['dedup_scope']) ? (string)$_POST['dedup_scope'] : 'rule';
    if (!in_array($dedup, $valid_dedups, true)) $dedup = 'rule';

    // 길이 클램프
    if (function_exists('mb_substr')) {
        $rule_name = mb_substr($rule_name, 0, 100, 'UTF-8');
    } else {
        $rule_name = substr($rule_name, 0, 100);
    }

    if ($act === 'insert') {
        @sql_query(
            " INSERT INTO `{$g5['guardian_rule_table']}`
              (rule_name, rule_active, priority, error_levels, file_pattern,
               channel, recipient_ids, `mode`, schedule_time, cooldown_min,
               daily_limit, dedup_scope, created_dt)
              VALUES (
                  '" . sql_escape_string($rule_name)     . "',
                  " . (int)$rule_active . ",
                  " . (int)$priority . ",
                  '" . sql_escape_string($levels_str)    . "',
                  '" . sql_escape_string($file_pattern)  . "',
                  '" . sql_escape_string($channel)       . "',
                  '" . sql_escape_string($recipient_csv) . "',
                  '" . sql_escape_string($mode)          . "',
                  '" . sql_escape_string($schedule_time) . "',
                  " . (int)$cooldown . ",
                  50,
                  '" . sql_escape_string($dedup)         . "',
                  NOW()
              ) ",
            false
        );
        goto_url('./guardian_rule.php?msg=' . urlencode('규칙이 등록되었습니다.'));
    }

    // update
    if ($rid <= 0) alert('수정 대상이 잘못되었습니다.');
    @sql_query(
        " UPDATE `{$g5['guardian_rule_table']}`
          SET rule_name      = '" . sql_escape_string($rule_name)     . "',
              rule_active    = " . (int)$rule_active . ",
              priority       = " . (int)$priority . ",
              error_levels   = '" . sql_escape_string($levels_str)    . "',
              file_pattern   = '" . sql_escape_string($file_pattern)  . "',
              channel        = '" . sql_escape_string($channel)       . "',
              recipient_ids  = '" . sql_escape_string($recipient_csv) . "',
              `mode`         = '" . sql_escape_string($mode)          . "',
              schedule_time  = '" . sql_escape_string($schedule_time) . "',
              cooldown_min   = " . (int)$cooldown . ",
              dedup_scope    = '" . sql_escape_string($dedup)         . "',
              updated_dt     = NOW()
          WHERE rule_id = " . (int)$rid . " ",
        false
    );
    goto_url('./guardian_rule.php?msg=' . urlencode('규칙이 수정되었습니다.'));
}

// =====================================================================
// delete_one — 단건 삭제 (폼)
// =====================================================================
if ($act === 'delete_one') {
    auth_check_menu($auth, $sub_menu, 'd');
    $rid = isset($_POST['rule_id']) ? (int)$_POST['rule_id'] : 0;
    if ($rid <= 0) alert('삭제 대상이 없습니다.');
    @sql_query(
        " DELETE FROM `{$g5['guardian_rule_table']}` WHERE rule_id = " . (int)$rid . " ",
        false
    );
    goto_url('./guardian_rule.php?msg=' . urlencode('규칙이 삭제되었습니다.'));
}

// =====================================================================
// delete / toggle_active — 일괄 (목록)
// =====================================================================
if ($act === 'delete' || $act === 'toggle_active') {
    if ($act === 'delete') {
        auth_check_menu($auth, $sub_menu, 'd');
    }

    $ids = array();
    if (isset($_POST['rule_ids']) && is_array($_POST['rule_ids'])) {
        foreach ($_POST['rule_ids'] as $i) {
            $i = (int)$i;
            if ($i > 0) $ids[] = $i;
        }
    }
    if (empty($ids)) alert('대상이 없습니다.');
    $ids_str = implode(',', array_unique($ids));

    if ($act === 'delete') {
        @sql_query(
            " DELETE FROM `{$g5['guardian_rule_table']}` WHERE rule_id IN ({$ids_str}) ",
            false
        );
        goto_url('./guardian_rule.php?msg=' . urlencode(count($ids) . '개 규칙이 삭제되었습니다.'));
    }

    // toggle_active
    @sql_query(
        " UPDATE `{$g5['guardian_rule_table']}`
          SET rule_active = IF(rule_active = 1, 0, 1),
              updated_dt = NOW()
          WHERE rule_id IN ({$ids_str}) ",
        false
    );
    goto_url('./guardian_rule.php?msg=' . urlencode(count($ids) . '개 규칙의 활성 상태를 변경했습니다.'));
}

// 도달 안 함
alert('처리할 작업이 없습니다.');
