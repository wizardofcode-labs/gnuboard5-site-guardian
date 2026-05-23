<?php
/**
 * 그누보드 운영지킴이 - 규칙 매칭 추적 로그 ★ 차별화 포인트
 *
 * "왜 알림이 왔지?" 또는 "왜 이 오류는 알림이 안 왔지?" 를 사용자가 직접
 * 추적할 수 있는 디버깅 화면. 다른 모니터링 솔루션에 없는 차별점.
 *
 * (c) 2026 K3SOFT / WizardOfCode
 *
 * @package gb_guardian
 * @version 1.0.0
 */
$sub_menu = "700310";
include_once('./_common.php');

auth_check_menu($auth, $sub_menu, 'r');

if (defined('GUARDIAN_LIB_PATH')) {
    include_once(GUARDIAN_LIB_PATH . '/guardian_admin.lib.php');
} else {
    include_once(G5_PLUGIN_PATH . '/gb_guardian/lib/guardian_admin.lib.php');
}

// ---- 입력값 정제 ----
$f_rule_id = isset($_GET['rule_id']) ? (int)$_GET['rule_id'] : 0;
$f_result  = isset($_GET['result'])  ? (string)$_GET['result'] : '';
$f_period  = isset($_GET['period'])  ? (int)$_GET['period']  : 1;
if ($f_period < 1) $f_period = 1;
if ($f_period > 30) $f_period = 30;
$f_hash = isset($_GET['hash']) ? trim((string)$_GET['hash']) : '';

// 결과 화이트리스트
$valid_results = array(
    '', 'matched_sent', 'matched_failed', 'matched_queued',
    'not_matched_level', 'not_matched_pattern', 'not_matched_inactive',
    'not_matched_timeofday', 'dedup_skipped', 'error',
);
if (!in_array($f_result, $valid_results, true)) $f_result = '';

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$rows = 50;

// ---- WHERE 빌드 ----
// JOIN 쿼리에서 created_dt / error_hash 가 m / l 양쪽 테이블에 모두 존재하므로
// 'ambiguous column' SQL 오류를 막기 위해 m. prefix 를 명시한다.
// 카운트 쿼리도 alias 'm' 을 두어 동일 WHERE 절을 재사용한다.
$where = array("m.created_dt >= DATE_SUB(NOW(), INTERVAL " . (int)$f_period . " DAY)");
if ($f_rule_id > 0)   $where[] = "m.rule_id = " . (int)$f_rule_id;
if ($f_result !== '') $where[] = "m.result = '" . sql_escape_string($f_result) . "'";
if ($f_hash !== '')   $where[] = "m.error_hash = '" . sql_escape_string($f_hash) . "'";
$where_clause = implode(' AND ', $where);

// ---- 카운트 ----
$total_count = 0;
$row_total = sql_fetch(
    " SELECT COUNT(*) AS cnt FROM `{$g5['guardian_rule_match_log_table']}` m WHERE {$where_clause} ",
    false
);
if (!empty($row_total['cnt'])) $total_count = (int)$row_total['cnt'];

$total_page = ($rows > 0) ? (int)ceil($total_count / $rows) : 1;
if ($total_page < 1) $total_page = 1;
if ($page > $total_page) $page = $total_page;
$from = ($page - 1) * $rows;

// ---- 데이터 조회 (규칙명 + 오류 정보 JOIN) ----
$result = @sql_query(
    " SELECT m.*,
             r.rule_name,
             l.error_level AS log_level,
             l.error_message AS log_message,
             l.error_file AS log_file
      FROM `{$g5['guardian_rule_match_log_table']}` m
      LEFT JOIN `{$g5['guardian_rule_table']}` r ON r.rule_id = m.rule_id
      LEFT JOIN `{$g5['guardian_log_table']}`  l ON l.log_id  = m.log_id
      WHERE {$where_clause}
      ORDER BY m.created_dt DESC
      LIMIT {$from}, {$rows} ",
    false
);

// ---- 빠른 진단 패널: 최근 1일 통계 ----
$diag = array(
    'last_match_dt' => '',
    'today_total'   => 0,
    'today_sent'    => 0,
    'today_skipped' => 0,
    'today_error'   => 0,
);
$row = sql_fetch(
    " SELECT COUNT(*) AS cnt,
             SUM(CASE WHEN result = 'matched_sent' THEN 1 ELSE 0 END) AS sent,
             SUM(CASE WHEN result LIKE 'not_matched_%' OR result = 'dedup_skipped' THEN 1 ELSE 0 END) AS skipped,
             SUM(CASE WHEN result = 'error' THEN 1 ELSE 0 END) AS errors,
             MAX(created_dt) AS last_dt
      FROM `{$g5['guardian_rule_match_log_table']}`
      WHERE created_dt >= DATE_SUB(NOW(), INTERVAL 1 DAY) ",
    false
);
if (!empty($row)) {
    $diag['today_total']   = (int)$row['cnt'];
    $diag['today_sent']    = (int)$row['sent'];
    $diag['today_skipped'] = (int)$row['skipped'];
    $diag['today_error']   = (int)$row['errors'];
    $diag['last_match_dt'] = isset($row['last_dt']) ? (string)$row['last_dt'] : '';
}

// 활성 규칙 목록 (필터 select 용)
$rules_for_filter = array();
$res = @sql_query(
    " SELECT rule_id, rule_name FROM `{$g5['guardian_rule_table']}` ORDER BY rule_id ASC ",
    false
);
if ($res) {
    while ($r = sql_fetch_array($res)) {
        $rules_for_filter[] = $r;
    }
}

// 결과 → 아이콘 / 색상 / 라벨 / 한 줄 설명(툴팁용)
function guardian_match_log_badge($result_code) {
    static $map = null;
    if ($map === null) {
        $map = array(
            'matched_sent'         => array('✅', '#2e7d32', '매칭+발송',     '규칙이 매칭되어 알림이 정상 발송됨'),
            'matched_failed'       => array('⚠️', '#ef6c00', '매칭+발송실패', '규칙은 매칭됐지만 보호 시스템 또는 외부 API 오류로 발송 실패'),
            'matched_queued'       => array('📦', '#1976d2', '큐 적재',       '일일/주간 요약 큐에 적재됨 (즉시 발송 X)'),
            'not_matched_level'    => array('❌', '#888',    '등급 불일치',    '오류 등급이 규칙의 감시 대상 등급에 없음'),
            'not_matched_pattern'  => array('❌', '#888',    '파일 패턴 불일치','오류 파일 경로가 규칙의 파일 패턴과 일치하지 않음'),
            'not_matched_inactive' => array('⏸',  '#888',    '비활성 규칙',    '규칙이 비활성 상태 (rule_active=0)'),
            'not_matched_timeofday'=> array('🕐', '#888',    '시간대 외',     '시간대 제한 모드의 활성 시간대 밖에서 발생'),
            'dedup_skipped'        => array('🔁', '#9c27b0', '중복 제거',     '같은 오류가 이미 같은 수신자에게 발송돼 스킵됨'),
            'error'                => array('🚨', '#c62828', '예외 발생',     '매칭/발송 도중 예외 발생. 상세 메시지로 원인 파악 필요'),
        );
    }
    $r = (string)$result_code;
    return isset($map[$r]) ? $map[$r] : array('❔', '#666', $r, '');
}

// ---- 페이징 ----
$qs_arr = array();
if ($f_rule_id > 0)   $qs_arr[] = 'rule_id=' . $f_rule_id;
if ($f_result !== '') $qs_arr[] = 'result='  . urlencode($f_result);
if ($f_hash   !== '') $qs_arr[] = 'hash='    . urlencode($f_hash);
$qs_arr[] = 'period=' . (int)$f_period;
$qstr = implode('&amp;', $qs_arr);
$pagenav = function_exists('get_paging')
    ? get_paging(10, $page, $total_page, '?' . $qstr . '&amp;page=')
    : '';

$g5['title'] = '운영지킴이 — 그누보드5/영카트5 운영 진단키트 — 규칙 매칭 추적 로그';
include_once(G5_ADMIN_PATH . '/admin.head.php');
?>
<style>a.btn,a.btn_01,a.btn_02,a.btn_03,a.btn_submit,a.btn_frmline{text-decoration:none}a.btn:hover,a.btn_01:hover,a.btn_02:hover,a.btn_03:hover{text-decoration:none}</style>

<div class="local_desc01 local_desc">
    <strong>"왜 알림이 왔지?" 또는 "왜 이 오류는 알림이 안 왔지?"</strong> 를 추적하기 위한 디버깅 화면입니다.
    매칭 로깅이 OFF 상태이거나 보관 기간을 지난 로그는 표시되지 않습니다.
    (환경설정에서 토글 / 보관 기간 변경 가능)
</div>

<!-- 빠른 진단 패널 -->
<div style="display:flex; gap:12px; margin-bottom:20px;">
    <div style="flex:1; padding:15px; background:#f5f5f5; border-radius:6px; text-align:center;">
        <div style="font-size:11px; color:#666;">마지막 매칭</div>
        <div style="font-size:14px; font-weight:bold; color:#0f3460; margin-top:4px;">
            <?php
            if ($diag['last_match_dt']) echo guardian_format_datetime($diag['last_match_dt']);
            else echo '<span style="color:#c33;">기록 없음</span>';
            ?>
        </div>
    </div>
    <div style="flex:1; padding:15px; background:#f5f5f5; border-radius:6px; text-align:center;">
        <div style="font-size:11px; color:#666;">오늘 매칭 시도</div>
        <div style="font-size:18px; font-weight:bold; color:#1a1a2e; margin-top:4px;">
            <?php echo number_format($diag['today_total']); ?>건
        </div>
    </div>
    <div style="flex:1; padding:15px; background:#e8f5e9; border-radius:6px; text-align:center;">
        <div style="font-size:11px; color:#2e7d32;">발송 성공</div>
        <div style="font-size:18px; font-weight:bold; color:#2e7d32; margin-top:4px;">
            <?php echo number_format($diag['today_sent']); ?>건
        </div>
    </div>
    <div style="flex:1; padding:15px; background:#f3f4f6; border-radius:6px; text-align:center;">
        <div style="font-size:11px; color:#666;">스킵 (불일치/중복)</div>
        <div style="font-size:18px; font-weight:bold; color:#666; margin-top:4px;">
            <?php echo number_format($diag['today_skipped']); ?>건
        </div>
    </div>
    <?php if ($diag['today_error'] > 0) { ?>
    <div style="flex:1; padding:15px; background:#fef2f2; border-radius:6px; text-align:center;">
        <div style="font-size:11px; color:#c62828;">예외</div>
        <div style="font-size:18px; font-weight:bold; color:#c62828; margin-top:4px;">
            <?php echo number_format($diag['today_error']); ?>건
        </div>
    </div>
    <?php } ?>
</div>

<!-- 현재 정책 안내 -->
<?php
$policy_logging  = !empty($config['cf_guardian_rule_match_logging']) ? 'ON' : 'OFF';
$policy_keepdays = isset($config['cf_guardian_rule_match_keep_days']) && $config['cf_guardian_rule_match_keep_days'] !== ''
    ? (int)$config['cf_guardian_rule_match_keep_days'] : 7;
$policy_color = ($policy_logging === 'ON') ? '#2e7d32' : '#888';
?>
<div style="background:#f0f4ff; border:1px solid #c5d3e8; border-radius:4px; padding:10px 14px; margin-bottom:15px; font-size:12px; color:#444;">
    🗂️ <strong>현재 정책:</strong>
    매칭 로깅
    <span style="color:<?php echo $policy_color; ?>; font-weight:bold;">[<?php echo $policy_logging; ?>]</span>
    · 보관 기간 <strong><?php echo $policy_keepdays; ?>일</strong> (자동 삭제)
    <span style="color:#888;">·</span>
    <a href="<?php echo G5_ADMIN_URL; ?>/guardian_config.php" style="color:#1976d2;">환경설정에서 변경</a>
</div>

<form id="fsearch" name="fsearch" class="local_sch01 local_sch" method="get" action="">
    <select name="rule_id">
        <option value="0">전체 규칙</option>
        <?php foreach ($rules_for_filter as $r) { ?>
            <option value="<?php echo (int)$r['rule_id']; ?>" <?php echo ($f_rule_id === (int)$r['rule_id']) ? 'selected' : ''; ?>>
                #<?php echo (int)$r['rule_id']; ?> <?php echo get_text($r['rule_name']); ?>
            </option>
        <?php } ?>
    </select>

    <select name="result">
        <option value="">결과 전체</option>
        <?php foreach ($valid_results as $rv) {
            if ($rv === '') continue;
            $b = guardian_match_log_badge($rv);
        ?>
            <option value="<?php echo $rv; ?>" <?php echo ($f_result === $rv) ? 'selected' : ''; ?>>
                <?php echo $b[0] . ' ' . $b[2]; ?>
            </option>
        <?php } ?>
    </select>

    <select name="period">
        <option value="1"  <?php echo ($f_period === 1)  ? 'selected' : ''; ?>>최근 1일</option>
        <option value="3"  <?php echo ($f_period === 3)  ? 'selected' : ''; ?>>최근 3일</option>
        <option value="7"  <?php echo ($f_period === 7)  ? 'selected' : ''; ?>>최근 7일</option>
        <option value="14" <?php echo ($f_period === 14) ? 'selected' : ''; ?>>최근 14일</option>
        <option value="30" <?php echo ($f_period === 30) ? 'selected' : ''; ?>>최근 30일</option>
    </select>

    <input type="text" name="hash" value="<?php echo get_text($f_hash); ?>" maxlength="64" class="frm_input" placeholder="error_hash" style="width:200px;">
    <button type="submit" class="btn_submit">검색</button>
    <a href="./guardian_rule_match_log.php" class="btn btn_02">초기화</a>
</form>

<!-- 결과 코드 의미 가이드 (펼치기/접기) -->
<details style="margin-bottom:15px; background:#f9fafb; border:1px solid #e5e7eb; border-radius:6px;">
    <summary style="padding:10px 15px; font-size:13px; font-weight:bold; color:#0f3460; cursor:pointer; user-select:none;">
        💡 결과 코드 의미 (클릭하여 펼치기)
    </summary>
    <div style="padding:0 15px 15px 15px; font-size:12px; color:#444; line-height:1.7;">
        <table style="width:100%; border-collapse:collapse; margin-top:5px;">
            <tbody>
                <tr><td style="padding:6px 8px; border:1px solid #e5e7eb; width:160px; white-space:nowrap;"><span style="display:inline-block; padding:2px 8px; border-radius:3px; background:#2e7d32; color:#fff; font-size:11px; font-weight:bold;">✅ 매칭+발송</span></td><td style="padding:6px 8px; border:1px solid #e5e7eb;">규칙이 오류와 매칭되어 알림이 정상 발송됨.</td></tr>
                <tr><td style="padding:6px 8px; border:1px solid #e5e7eb;"><span style="display:inline-block; padding:2px 8px; border-radius:3px; background:#ef6c00; color:#fff; font-size:11px; font-weight:bold;">⚠️ 매칭+발송실패</span></td><td style="padding:6px 8px; border:1px solid #e5e7eb;">규칙은 매칭됐지만 발송 단계에서 실패. 보호 시스템(비상정지 / 일일한도 / 야간무음 등) 에 의한 차단이거나 알리고/메일 서버 외부 오류.</td></tr>
                <tr><td style="padding:6px 8px; border:1px solid #e5e7eb;"><span style="display:inline-block; padding:2px 8px; border-radius:3px; background:#1976d2; color:#fff; font-size:11px; font-weight:bold;">📦 큐 적재</span></td><td style="padding:6px 8px; border:1px solid #e5e7eb;">일일/주간 요약 모드라 큐에 적재됨. 즉시 발송하지 않고 지정 시각에 모아서 보냄.</td></tr>
                <tr><td style="padding:6px 8px; border:1px solid #e5e7eb;"><span style="display:inline-block; padding:2px 8px; border-radius:3px; background:#888; color:#fff; font-size:11px; font-weight:bold;">❌ 등급 불일치</span></td><td style="padding:6px 8px; border:1px solid #e5e7eb;">오류 등급(FATAL/ERROR/WARNING 등) 이 규칙이 감시하는 등급 목록에 없음.</td></tr>
                <tr><td style="padding:6px 8px; border:1px solid #e5e7eb;"><span style="display:inline-block; padding:2px 8px; border-radius:3px; background:#888; color:#fff; font-size:11px; font-weight:bold;">❌ 파일 패턴 불일치</span></td><td style="padding:6px 8px; border:1px solid #e5e7eb;">오류 발생 파일 경로가 규칙의 파일 패턴(LIKE 스타일)과 일치하지 않음.</td></tr>
                <tr><td style="padding:6px 8px; border:1px solid #e5e7eb;"><span style="display:inline-block; padding:2px 8px; border-radius:3px; background:#888; color:#fff; font-size:11px; font-weight:bold;">⏸ 비활성 규칙</span></td><td style="padding:6px 8px; border:1px solid #e5e7eb;">규칙이 비활성 상태(rule_active=0). 활성 토글로 켜야 동작.</td></tr>
                <tr><td style="padding:6px 8px; border:1px solid #e5e7eb;"><span style="display:inline-block; padding:2px 8px; border-radius:3px; background:#888; color:#fff; font-size:11px; font-weight:bold;">🕐 시간대 외</span></td><td style="padding:6px 8px; border:1px solid #e5e7eb;">시간대 제한 모드인데 현재 시각이 활성 시간대 밖. 예: 09-18 시 모드인데 새벽 3시에 발생.</td></tr>
                <tr><td style="padding:6px 8px; border:1px solid #e5e7eb;"><span style="display:inline-block; padding:2px 8px; border-radius:3px; background:#9c27b0; color:#fff; font-size:11px; font-weight:bold;">🔁 중복 제거</span></td><td style="padding:6px 8px; border:1px solid #e5e7eb;">중복 제거 정책으로 스킵. 전역(global) 모드에서 같은 오류가 다른 규칙으로 이미 같은 수신자에게 보내진 경우.</td></tr>
                <tr><td style="padding:6px 8px; border:1px solid #e5e7eb;"><span style="display:inline-block; padding:2px 8px; border-radius:3px; background:#c62828; color:#fff; font-size:11px; font-weight:bold;">🚨 예외 발생</span></td><td style="padding:6px 8px; border:1px solid #e5e7eb;">매칭 또는 발송 도중 예외 발생. 본 결과가 보이면 "상세" 컬럼의 메시지를 확인해 원인 파악 필요.</td></tr>
            </tbody>
        </table>
    </div>
</details>

<div class="local_desc01 local_desc">총 <strong><?php echo number_format($total_count); ?></strong>건</div>

<div class="tbl_head01 tbl_wrap">
<table>
<caption>규칙 매칭 추적 로그</caption>
<colgroup>
    <col style="width:110px;">
    <col style="width:160px;">
    <col style="width:80px;">
    <col style="width:280px;">
    <col>
</colgroup>
<thead>
<tr>
    <th>시간</th>
    <th>규칙</th>
    <th>등급</th>
    <th>결과 / 메시지</th>
    <th>상세</th>
</tr>
</thead>
<tbody>
<?php
$row_count = 0;
if ($result) {
    while ($row = sql_fetch_array($result)) {
        $row_count++;
        $badge = guardian_match_log_badge($row['result']);
?>
<tr>
    <td style="font-size:11px; color:#666;">
        <?php echo guardian_format_datetime($row['created_dt']); ?>
    </td>
    <td style="font-size:12px;">
        <?php if ($row['rule_id'] > 0 && !empty($row['rule_name'])) { ?>
            <a href="./guardian_rule_form.php?rule_id=<?php echo (int)$row['rule_id']; ?>" style="text-decoration:none; color:#1a1a2e;">
                #<?php echo (int)$row['rule_id']; ?> <?php echo get_text($row['rule_name']); ?>
            </a>
        <?php } else { ?>
            <span style="color:#888;">-</span>
        <?php } ?>
    </td>
    <td style="text-align:center; font-size:11px;">
        <?php
        if (!empty($row['log_level'])) {
            echo guardian_level_badge($row['log_level']);
        } else {
            echo '<span style="color:#888;">-</span>';
        }
        ?>
    </td>
    <td>
        <span title="<?php echo isset($badge[3]) ? get_text($badge[3]) : ''; ?>"
              style="display:inline-block; padding:2px 8px; border-radius:3px; background:<?php echo $badge[1]; ?>; color:#fff; font-size:11px; font-weight:bold; cursor:help;">
            <?php echo $badge[0]; ?> <?php echo get_text($badge[2]); ?>
        </span>
        <?php if (!empty($row['log_message'])) { ?>
            <div style="font-size:11px; color:#666; margin-top:4px; word-break:break-all;">
                <?php echo guardian_truncate_text($row['log_message'], 80); ?>
            </div>
        <?php } ?>
    </td>
    <td style="font-size:11px; color:#444; word-break:break-all; line-height:1.5;">
        <?php
        if (!empty($row['result_detail'])) {
            // 상세 컬럼은 너비 여유가 생겼으니 더 길게 표시 (기존 60 → 200)
            echo guardian_truncate_text($row['result_detail'], 200);
        } else {
            echo '<span style="color:#bbb;">-</span>';
        }
        ?>
    </td>
</tr>
<?php
    }
}
if ($row_count === 0) {
?>
<tr>
    <td colspan="5" style="text-align:center; color:#888; padding:60px 0;">
        조건에 맞는 매칭 로그가 없습니다.<br>
        규칙이 비활성 상태이거나, 매칭 로깅이 OFF 상태일 수 있습니다.
    </td>
</tr>
<?php } ?>
</tbody>
</table>
</div>

<?php if ($pagenav !== '') { ?>
<div class="pg_wrap"><?php echo $pagenav; ?></div>
<?php } ?>

<?php
include_once(G5_ADMIN_PATH . '/admin.tail.php');
