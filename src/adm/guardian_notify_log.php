<?php
/**
 * 그누보드 운영지킴이 - 알림 발송 이력
 *
 * 수신자 등록 / 활성화 토글 / 일괄 처리 화면.
 *
 * 화면 구성:
 *   - 채널 / 상태 / 기간 필터 + 텍스트 검색 (수신자/실패사유)
 *   - 채널별 오늘 발송 카운트 표시 (일일 한도 모니터링)
 *   - 페이징된 발송 이력 테이블
 *
 * 화면은 조회 전용이며 위험한 작업이 없으므로 'r' 권한만 요구.
 *
 * (c) 2026 K3SOFT / WizardOfCode
 *
 * @package gb_guardian
 * @version 1.0.0
 */
$sub_menu = "700500";
include_once('./_common.php');

auth_check_menu($auth, $sub_menu, 'r');

if (defined('GUARDIAN_LIB_PATH')) {
    include_once(GUARDIAN_LIB_PATH . '/guardian_admin.lib.php');
    include_once(GUARDIAN_LIB_PATH . '/guardian_protector.lib.php');
} else {
    include_once(G5_PLUGIN_PATH . '/gb_guardian/lib/guardian_admin.lib.php');
    include_once(G5_PLUGIN_PATH . '/gb_guardian/lib/guardian_protector.lib.php');
}

// ---- 입력값 정제 ----
$f_channel = isset($_GET['channel']) ? (string)$_GET['channel'] : '';
if (!in_array($f_channel, array('', 'email', 'sms', 'kakao'), true)) $f_channel = '';

$f_status = isset($_GET['status']) ? (string)$_GET['status'] : '';
if (!in_array($f_status, array('', 'success', 'failed'), true)) $f_status = '';

$f_period = isset($_GET['period']) ? (int)$_GET['period'] : 7;
if (!in_array($f_period, array(0, 1, 7, 30, 90), true)) $f_period = 7;

$valid_sfl = array('recipient', 'fail_reason');
$f_sfl = isset($_GET['sfl']) ? (string)$_GET['sfl'] : 'recipient';
if (!in_array($f_sfl, $valid_sfl, true)) $f_sfl = 'recipient';

$f_stx = isset($_GET['stx']) ? trim((string)$_GET['stx']) : '';
$page  = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$rows = 30;

// ---- WHERE ----
$where = array('1=1');
if ($f_channel !== '') {
    $where[] = "channel = '" . sql_escape_string($f_channel) . "'";
}
if ($f_status !== '') {
    $where[] = "status = '" . sql_escape_string($f_status) . "'";
}
if ($f_period > 0) {
    $where[] = "sent_dt >= DATE_SUB(NOW(), INTERVAL " . (int)$f_period . " DAY)";
}
if ($f_stx !== '') {
    $where[] = "`{$f_sfl}` LIKE '%" . sql_escape_string($f_stx) . "%'";
}
$where_clause = implode(' AND ', $where);

// ---- 카운트 + 데이터 ----
$total_count = 0;
$row_total = sql_fetch(
    " SELECT COUNT(*) AS cnt FROM `{$g5['guardian_notify_log_table']}` WHERE {$where_clause} ",
    false
);
if (!empty($row_total['cnt'])) $total_count = (int)$row_total['cnt'];

$total_page = ($rows > 0) ? (int)ceil($total_count / $rows) : 1;
if ($total_page < 1) $total_page = 1;
if ($page > $total_page) $page = $total_page;
$from = ($page - 1) * $rows;

// 페이지의 데이터를 한 번에 가져온 뒤 — 어떤 알림이 발송됐는지 식별 가능한
// 정보(규칙명·오류 등급·오류 메시지)를 보조 SELECT 로 보강한다.
//
//   1) 본 SELECT: notify_log + rule_name (LEFT JOIN guardian_rule)
//   2) 보조 SELECT: 본 페이지의 error_hash 들의 가장 최신 등급/메시지를
//      guardian_log 에서 일괄 조회 (페이지당 최대 30건이라 부담 없음)
$result = @sql_query(
    " SELECT n.notify_id, n.rule_id, n.error_hash, n.channel, n.recipient,
             n.status, n.fail_reason, n.sent_dt,
             r.rule_name AS rule_name
      FROM `{$g5['guardian_notify_log_table']}` n
      LEFT JOIN `{$g5['guardian_rule_table']}` r ON r.rule_id = n.rule_id
      WHERE {$where_clause}
      ORDER BY n.sent_dt DESC, n.notify_id DESC
      LIMIT {$from}, {$rows} ",
    false
);

// row 들을 PHP 배열로 펴고, error_hash 모음 → 보조 조회 → row 에 등급/메시지 머지
$rows_data = array();
$page_hashes = array();
if ($result) {
    while ($r = sql_fetch_array($result)) {
        $rows_data[] = $r;
        if (!empty($r['error_hash'])) {
            // SUMMARY_*/TEST_* 같은 합성 hash 는 guardian_log 에 없으므로 보조 조회 대상 아님
            $h = (string)$r['error_hash'];
            if (strpos($h, 'SUMMARY_') !== 0 && strpos($h, 'TEST_') !== 0) {
                $page_hashes[$h] = true;
            }
        }
    }
}

$hash_info = array();
if (!empty($page_hashes) && !empty($g5['guardian_log_table'])) {
    $hash_in = "'" . implode("','", array_map('sql_escape_string', array_keys($page_hashes))) . "'";
    $h_res = @sql_query(
        " SELECT l.error_hash, l.error_level, l.error_message
          FROM `{$g5['guardian_log_table']}` l
          INNER JOIN (
              SELECT error_hash, MAX(log_id) AS max_id
              FROM `{$g5['guardian_log_table']}`
              WHERE error_hash IN ({$hash_in})
              GROUP BY error_hash
          ) m ON m.error_hash = l.error_hash AND m.max_id = l.log_id ",
        false
    );
    if ($h_res) {
        while ($hr = sql_fetch_array($h_res)) {
            $hash_info[(string)$hr['error_hash']] = array(
                'level'   => isset($hr['error_level'])   ? (string)$hr['error_level']   : '',
                'message' => isset($hr['error_message']) ? (string)$hr['error_message'] : '',
            );
        }
    }
}

$qs_arr = array();
if ($f_channel !== '') $qs_arr[] = 'channel=' . urlencode($f_channel);
if ($f_status  !== '') $qs_arr[] = 'status='  . urlencode($f_status);
if ($f_period  !== '') $qs_arr[] = 'period='  . (int)$f_period;
if ($f_stx     !== '') {
    $qs_arr[] = 'sfl=' . urlencode($f_sfl);
    $qs_arr[] = 'stx=' . urlencode($f_stx);
}
$qstr = implode('&amp;', $qs_arr);
$pagenav = function_exists('get_paging')
    ? get_paging(10, $page, $total_page, '?' . $qstr . '&amp;page=')
    : '';

// 오늘 채널별 발송 카운트 (일일 한도 모니터링)
$today_email = guardian_today_sent_count('email');
$today_sms   = guardian_today_sent_count('sms');
$today_kakao = guardian_today_sent_count('kakao');

$limit_email = isset($config['cf_guardian_email_daily_limit']) && $config['cf_guardian_email_daily_limit'] !== ''
    ? (int)$config['cf_guardian_email_daily_limit'] : 500;
$limit_sms   = isset($config['cf_guardian_sms_daily_limit'])   && $config['cf_guardian_sms_daily_limit']   !== ''
    ? (int)$config['cf_guardian_sms_daily_limit']   : 50;
$limit_kakao = isset($config['cf_guardian_kakao_daily_limit']) && $config['cf_guardian_kakao_daily_limit'] !== ''
    ? (int)$config['cf_guardian_kakao_daily_limit'] : 100;

$g5['title'] = '운영지킴이 — 그누보드5/영카트5 운영 진단키트 — 알림 발송 이력';

// CSRF 토큰은 페이지당 1회만 발급한다 (일괄 폼 + 향후 모달 등에서 동일 토큰 재사용)
$g_admin_token = get_admin_token();

include_once(G5_ADMIN_PATH . '/admin.head.php');
?>
<style>a.btn,a.btn_01,a.btn_02,a.btn_03,a.btn_submit,a.btn_frmline{text-decoration:none}a.btn:hover,a.btn_01:hover,a.btn_02:hover,a.btn_03:hover{text-decoration:none}</style>

<!-- ===== 채널별 오늘 발송 현황 ===== -->
<div style="display:grid; grid-template-columns:repeat(3, 1fr); gap:15px; margin-bottom:20px;">
    <div style="background:#fff; border:1px solid #e0e0e0; border-left:4px solid #1976d2; padding:14px 18px; border-radius:4px;">
        <div style="color:#888; font-size:12px;">오늘 이메일 발송</div>
        <div style="font-size:22px; font-weight:bold; color:#1976d2;">
            <?php echo number_format($today_email); ?>
            <span style="font-size:13px; color:#888;">/ <?php echo number_format($limit_email); ?>건</span>
        </div>
    </div>
    <div style="background:#fff; border:1px solid #e0e0e0; border-left:4px solid #d32f2f; padding:14px 18px; border-radius:4px;">
        <div style="color:#888; font-size:12px;">오늘 SMS 발송 <span style="color:#c63;">(비용 발생)</span></div>
        <div style="font-size:22px; font-weight:bold; color:#d32f2f;">
            <?php echo number_format($today_sms); ?>
            <span style="font-size:13px; color:#888;">/ <?php echo number_format($limit_sms); ?>건</span>
        </div>
    </div>
    <div style="background:#fff; border:1px solid #e0e0e0; border-left:4px solid #f9a825; padding:14px 18px; border-radius:4px;">
        <div style="color:#888; font-size:12px;">오늘 카톡 발송</div>
        <div style="font-size:22px; font-weight:bold; color:#f9a825;">
            <?php echo number_format($today_kakao); ?>
            <span style="font-size:13px; color:#888;">/ <?php echo number_format($limit_kakao); ?>건</span>
        </div>
    </div>
</div>

<form id="fsearch" name="fsearch" class="local_sch01 local_sch" method="get" action="">
    <select name="channel">
        <option value=""<?php if ($f_channel === '') echo ' selected'; ?>>전체 채널</option>
        <option value="email"<?php if ($f_channel === 'email') echo ' selected'; ?>>이메일</option>
        <option value="sms"  <?php if ($f_channel === 'sms')   echo ' selected'; ?>>SMS</option>
        <option value="kakao"<?php if ($f_channel === 'kakao') echo ' selected'; ?>>카톡</option>
    </select>
    <select name="status">
        <option value=""<?php if ($f_status === '') echo ' selected'; ?>>전체 상태</option>
        <option value="success"<?php if ($f_status === 'success') echo ' selected'; ?>>성공</option>
        <option value="failed" <?php if ($f_status === 'failed')  echo ' selected'; ?>>실패</option>
    </select>
    <select name="period">
        <?php
        $periods = array(1 => '오늘', 7 => '7일', 30 => '30일', 90 => '90일', 0 => '전체');
        foreach ($periods as $v => $label) {
            $sel = ($f_period === $v) ? ' selected' : '';
            echo '<option value="' . (int)$v . '"' . $sel . '>' . get_text($label) . '</option>';
        }
        ?>
    </select>
    <select name="sfl">
        <option value="recipient"<?php if ($f_sfl === 'recipient') echo ' selected'; ?>>수신자</option>
        <option value="fail_reason"<?php if ($f_sfl === 'fail_reason') echo ' selected'; ?>>실패 사유</option>
    </select>
    <input type="text" name="stx" value="<?php echo get_text($f_stx); ?>" maxlength="100" class="frm_input">
    <button type="submit" class="btn_submit">검색</button>
    <a href="./guardian_notify_log.php" class="btn_02 btn">초기화</a>
</form>

<?php
$msg = isset($_GET['msg']) ? (string)$_GET['msg'] : '';
if ($msg !== '') {
?>
<div class="local_desc01 local_desc" style="background:#e8f5e9;color:#2e7d32;border-left:4px solid #2e7d32;">
    <?php echo get_text($msg); ?>
</div>
<?php } ?>

<form id="flist" name="flist" method="post" action="./guardian_notify_log_action.php">
<input type="hidden" name="token" value="<?php echo $g_admin_token; ?>">
<input type="hidden" name="act" id="act_field" value="">
<!-- 처리 후 redirect 시 현재 검색/페이지 상태 복원용 -->
<input type="hidden" name="r_channel" value="<?php echo get_text($f_channel); ?>">
<input type="hidden" name="r_status"  value="<?php echo get_text($f_status); ?>">
<input type="hidden" name="r_period"  value="<?php echo (int)$f_period; ?>">
<input type="hidden" name="r_sfl"     value="<?php echo get_text($f_sfl); ?>">
<input type="hidden" name="r_stx"     value="<?php echo get_text($f_stx); ?>">
<input type="hidden" name="r_page"    value="<?php echo (int)$page; ?>">

<div class="local_desc01 local_desc" style="display:flex; justify-content:space-between; align-items:center;">
    <div>총 <strong><?php echo number_format($total_count); ?></strong>건</div>
    <div>
        <button type="button" onclick="notifyLogBulk('delete')" class="btn btn_03" style="background:#d32f2f;color:#fff;">선택 삭제</button>
    </div>
</div>

<div class="tbl_head01 tbl_wrap">
<table>
<caption>알림 발송 이력</caption>
<colgroup>
    <col style="width:36px;">
    <col style="width:70px;">
    <col style="width:70px;">
    <col>
    <col style="width:180px;">
    <col style="width:200px;">
    <col style="width:120px;">
</colgroup>
<thead>
<tr>
    <th><input type="checkbox" id="chkAll" onclick="notifyLogToggleAll(this)"></th>
    <th>채널</th>
    <th>상태</th>
    <th>발송 내용</th>
    <th>수신자</th>
    <th>실패 사유</th>
    <th>시각</th>
</tr>
</thead>
<tbody>
<?php
$row_count = count($rows_data);
foreach ($rows_data as $row) {
    $nid = (int)$row['notify_id'];
    $is_ok = ($row['status'] === 'success');
    $ch_color = ($row['channel'] === 'email') ? '#1976d2'
              : (($row['channel'] === 'sms') ? '#d32f2f' : '#f9a825');

    // ---- "발송 내용" 컬럼 빌드 ----
    // SUMMARY_DAILY / SUMMARY_WEEKLY / TEST_* / 일반 발송 4가지 케이스
    $hash = isset($row['error_hash']) ? (string)$row['error_hash'] : '';
    $rule_name = isset($row['rule_name']) ? (string)$row['rule_name'] : '';

    $content_html = '';
    if ($hash === 'SUMMARY_DAILY') {
        $content_html = '<span style="display:inline-block;padding:2px 8px;border-radius:3px;background:#e3f2fd;color:#1565c0;font-size:11px;font-weight:bold;">📅 일일 요약</span>';
        if ($rule_name !== '') {
            $content_html .= ' <span style="font-size:12px; color:#444;">' . get_text($rule_name) . '</span>';
        }
    } elseif ($hash === 'SUMMARY_WEEKLY') {
        $content_html = '<span style="display:inline-block;padding:2px 8px;border-radius:3px;background:#ede7f6;color:#5e35b1;font-size:11px;font-weight:bold;">📆 주간 요약</span>';
        if ($rule_name !== '') {
            $content_html .= ' <span style="font-size:12px; color:#444;">' . get_text($rule_name) . '</span>';
        }
    } elseif (strpos($hash, 'TEST_') === 0) {
        $content_html = '<span style="display:inline-block;padding:2px 8px;border-radius:3px;background:#fff3e0;color:#ef6c00;font-size:11px;font-weight:bold;">🔬 테스트 발송</span>';
    } else {
        // 일반 즉시 / 시간대 발송 — 규칙명 + 등급 배지 + 오류 메시지 prefix
        $info = isset($hash_info[$hash]) ? $hash_info[$hash] : null;
        if ($rule_name !== '') {
            $content_html .= '<div style="font-size:12px; color:#1a1a2e; font-weight:600; margin-bottom:2px;">'
                          . get_text($rule_name) . '</div>';
        }
        if ($info !== null) {
            if (!empty($info['level'])) {
                $content_html .= guardian_level_badge($info['level']) . ' ';
            }
            if (!empty($info['message'])) {
                $content_html .= '<span style="font-size:11px; color:#666; word-break:break-all;">'
                              . guardian_truncate_text($info['message'], 60) . '</span>';
            }
        } elseif ($content_html === '') {
            // rule_name 도 hash 정보도 없는 케이스 (규칙 삭제됨 등)
            $content_html = '<span style="font-size:11px; color:#bbb;">-</span>';
        }
    }
?>
<tr>
    <td><input type="checkbox" name="notify_ids[]" value="<?php echo $nid; ?>"></td>
    <td style="text-align:center;">
        <span style="display:inline-block;padding:2px 8px;border-radius:3px;background:<?php echo $ch_color; ?>22;color:<?php echo $ch_color; ?>;font-size:11px;font-weight:bold;">
            <?php echo get_text($row['channel']); ?>
        </span>
    </td>
    <td style="text-align:center;">
        <?php if ($is_ok) { ?>
            <span style="color:#2e7d32;font-weight:bold;">✓ 성공</span>
        <?php } else { ?>
            <span style="color:#c33;font-weight:bold;">✗ 실패</span>
        <?php } ?>
    </td>
    <td><?php echo $content_html; ?></td>
    <td style="font-size:12px; word-break:break-all;">
        <?php echo guardian_truncate_text($row['recipient'], 50); ?>
    </td>
    <td style="font-size:11px; color:<?php echo $is_ok ? '#888' : '#c33'; ?>; word-break:break-all;">
        <?php
        if ($is_ok) {
            echo '-';
        } else {
            echo guardian_truncate_text($row['fail_reason'], 80);
        }
        ?>
    </td>
    <td title="<?php echo get_text($row['sent_dt']); ?>" style="font-size:12px; color:#666; text-align:center;">
        <?php echo guardian_format_datetime($row['sent_dt']); ?>
    </td>
</tr>
<?php
}
if ($row_count === 0) {
?>
<tr>
    <td colspan="7" style="text-align:center; color:#888; padding:60px 0;">
        조건에 맞는 발송 이력이 없습니다.
    </td>
</tr>
<?php } ?>
</tbody>
</table>
</div>

</form>

<?php if ($pagenav !== '') { ?>
<div class="pg_wrap"><?php echo $pagenav; ?></div>
<?php } ?>

<script>
(function () {
    window.notifyLogToggleAll = function (cb) {
        var boxes = document.querySelectorAll('input[name="notify_ids[]"]');
        for (var i = 0; i < boxes.length; i++) boxes[i].checked = cb.checked;
    };
    window.notifyLogBulk = function (act) {
        var boxes = document.querySelectorAll('input[name="notify_ids[]"]:checked');
        if (boxes.length === 0) { alert('대상을 선택하세요.'); return; }
        var msg = (act === 'delete')
            ? '선택한 ' + boxes.length + '건의 발송 이력을 삭제하시겠습니까? 되돌릴 수 없습니다.'
            : '선택한 ' + boxes.length + '건을 처리합니다.';
        if (!confirm(msg)) return;

        // 검색 폼의 현재 값을 redirect 용 hidden input 으로 동기화
        var fsearch = document.getElementById('fsearch');
        if (fsearch) {
            var fields = ['channel', 'status', 'period', 'sfl', 'stx'];
            for (var i = 0; i < fields.length; i++) {
                var src = fsearch.elements[fields[i]];
                var dst = document.querySelector('input[name="r_' + fields[i] + '"]');
                if (src && dst) dst.value = src.value;
            }
        }

        document.getElementById('act_field').value = act;
        document.forms['flist'].submit();
    };
})();
</script>

<?php
include_once(G5_ADMIN_PATH . '/admin.tail.php');
