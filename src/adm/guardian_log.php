<?php
/**
 * 그누보드 운영지킴이 - 오류 로그 목록
 *
 * 화면 구성:
 *   - 검색 폼 (등급 / 기간 / 해결여부 / 텍스트)
 *   - 결과 카운트 + 일괄 액션 버튼 (선택삭제, 선택해결)
 *   - 페이징된 로그 테이블 (20건/페이지)
 *   - 행 클릭 시 AJAX 모달로 상세보기
 *
 * 보안:
 *   - 검색 필드(sfl) 화이트리스트 검증
 *   - 모든 SQL 입력 sql_escape_string / (int) 캐스팅
 *   - 출력은 모두 get_text()
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

// ---------------------------------------------------------------------
// 1. 입력값 정제
// ---------------------------------------------------------------------
$f_level    = isset($_GET['level']) ? strtoupper(trim((string)$_GET['level'])) : '';
$f_period   = isset($_GET['period']) ? (int)$_GET['period'] : 7;
$f_resolved = isset($_GET['resolved']) ? (string)$_GET['resolved'] : '';
$f_sfl      = isset($_GET['sfl']) ? (string)$_GET['sfl'] : 'error_message';
$f_stx      = isset($_GET['stx']) ? trim((string)$_GET['stx']) : '';

// 화이트리스트 검증
$valid_levels = array('', 'FATAL', 'ERROR', 'WARNING', 'EXCEPTION', 'DB', 'NOTICE', 'DEPRECATED');
if (!in_array($f_level, $valid_levels, true)) $f_level = '';

$valid_periods = array(0, 1, 7, 30, 90);
if (!in_array($f_period, $valid_periods, true)) $f_period = 7;

if (!in_array($f_resolved, array('', '0', '1'), true)) $f_resolved = '';

$valid_sfl = array('error_message', 'error_file');
if (!in_array($f_sfl, $valid_sfl, true)) $f_sfl = 'error_message';

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$rows = 20;

// ---------------------------------------------------------------------
// 2. WHERE 빌드
// ---------------------------------------------------------------------
$where = array('1=1');

if ($f_level !== '') {
    $where[] = "error_level = '" . sql_escape_string($f_level) . "'";
}
if ($f_period > 0) {
    $where[] = "created_dt >= DATE_SUB(NOW(), INTERVAL " . (int)$f_period . " DAY)";
}
if ($f_resolved !== '') {
    $where[] = "resolved = " . (int)$f_resolved;
}
if ($f_stx !== '') {
    $stx_esc = sql_escape_string($f_stx);
    $where[] = "`{$f_sfl}` LIKE '%{$stx_esc}%'";
}
$where_clause = implode(' AND ', $where);

// ---------------------------------------------------------------------
// 3. 카운트 + 데이터 조회
// ---------------------------------------------------------------------
$total_count = 0;
$row_total = sql_fetch(
    " SELECT COUNT(*) AS cnt FROM `{$g5['guardian_log_table']}` WHERE {$where_clause} ",
    false
);
if (!empty($row_total['cnt'])) {
    $total_count = (int)$row_total['cnt'];
}

$total_page = ($rows > 0) ? (int)ceil($total_count / $rows) : 1;
if ($total_page < 1) $total_page = 1;
if ($page > $total_page) $page = $total_page;
$from = ($page - 1) * $rows;

$result = @sql_query(
    " SELECT log_id, error_level, error_message, error_file, error_line,
             occurrence_count, last_occurred_dt, created_dt, resolved
      FROM `{$g5['guardian_log_table']}`
      WHERE {$where_clause}
      ORDER BY COALESCE(last_occurred_dt, created_dt) DESC
      LIMIT {$from}, {$rows} ",
    false
);

// ---------------------------------------------------------------------
// 4. 페이징 쿼리 문자열
// ---------------------------------------------------------------------
$qs_arr = array();
if ($f_level    !== '') $qs_arr[] = 'level='    . urlencode($f_level);
if ($f_period   !== '') $qs_arr[] = 'period='   . (int)$f_period;
if ($f_resolved !== '') $qs_arr[] = 'resolved=' . (int)$f_resolved;
if ($f_stx      !== '') {
    $qs_arr[] = 'sfl=' . urlencode($f_sfl);
    $qs_arr[] = 'stx=' . urlencode($f_stx);
}
$qstr = implode('&amp;', $qs_arr);

$pagenav = function_exists('get_paging')
    ? get_paging(10, $page, $total_page, '?' . $qstr . '&amp;page=')
    : '';

$g5['title'] = '운영지킴이 — 그누보드5/영카트5 운영 진단키트 — 오류 로그';

// CSRF 토큰은 페이지당 1회만 발급한다. get_admin_token() 은 호출할 때마다
// 새 토큰을 만들어 세션을 덮어쓰므로 hidden input 과 JS 변수에 같은 값을
// 보내려면 PHP 변수에 한 번 받아 양쪽에서 재사용해야 한다.
$g_admin_token = get_admin_token();

include_once(G5_ADMIN_PATH . '/admin.head.php');
?>
<style>a.btn,a.btn_01,a.btn_02,a.btn_03,a.btn_submit,a.btn_frmline{text-decoration:none}a.btn:hover,a.btn_01:hover,a.btn_02:hover,a.btn_03:hover{text-decoration:none}</style>

<!-- ===== 검색 폼 ===== -->
<form id="fsearch" name="fsearch" class="local_sch01 local_sch" method="get" action="">
<label for="level" class="sound_only">등급</label>
<select name="level" id="level">
    <?php echo guardian_level_options($f_level); ?>
</select>

<label for="period" class="sound_only">기간</label>
<select name="period" id="period">
<?php
$periods = array(1 => '오늘', 7 => '7일', 30 => '30일', 90 => '90일', 0 => '전체');
foreach ($periods as $v => $label) {
    $sel = ($f_period === $v) ? ' selected' : '';
    echo '<option value="' . (int)$v . '"' . $sel . '>' . get_text($label) . '</option>';
}
?>
</select>

<label for="resolved" class="sound_only">해결여부</label>
<select name="resolved" id="resolved">
    <option value=""<?php if ($f_resolved === '') echo ' selected'; ?>>전체</option>
    <option value="0"<?php if ($f_resolved === '0') echo ' selected'; ?>>미해결</option>
    <option value="1"<?php if ($f_resolved === '1') echo ' selected'; ?>>해결됨</option>
</select>

<label for="sfl" class="sound_only">검색필드</label>
<select name="sfl" id="sfl">
    <option value="error_message"<?php if ($f_sfl === 'error_message') echo ' selected'; ?>>메시지</option>
    <option value="error_file"<?php if ($f_sfl === 'error_file') echo ' selected'; ?>>파일</option>
</select>

<label for="stx" class="sound_only">검색어</label>
<input type="text" name="stx" id="stx" value="<?php echo get_text($f_stx); ?>" maxlength="100" class="frm_input">
<button type="submit" class="btn_submit">검색</button>
<a href="./guardian_log.php" class="btn_02 btn">초기화</a>
</form>

<!-- ===== 일괄 액션 ===== -->
<form id="flist" name="flist" method="post" action="./guardian_log_action.php">
<input type="hidden" name="token" value="<?php echo $g_admin_token; ?>">
<input type="hidden" name="act" id="act_field" value="">
<!-- 처리 후 redirect 시 현재 검색/페이지 상태 복원용 (action.php 에서 재검증) -->
<input type="hidden" name="r_level"    value="<?php echo get_text($f_level); ?>">
<input type="hidden" name="r_period"   value="<?php echo (int)$f_period; ?>">
<input type="hidden" name="r_resolved" value="<?php echo get_text($f_resolved); ?>">
<input type="hidden" name="r_sfl"      value="<?php echo get_text($f_sfl); ?>">
<input type="hidden" name="r_stx"      value="<?php echo get_text($f_stx); ?>">
<input type="hidden" name="r_page"     value="<?php echo (int)$page; ?>">

<div class="local_desc01 local_desc" style="display:flex; justify-content:space-between; align-items:center;">
    <div>총 <strong><?php echo number_format($total_count); ?></strong>건</div>
    <div>
        <button type="button" onclick="guardianBulkAction('toggle_resolve')" class="btn btn_02">선택 해결됨 토글</button>
        <button type="button" onclick="guardianBulkAction('delete')" class="btn btn_03" style="background:#d32f2f;color:#fff;">선택 삭제</button>
    </div>
</div>

<div class="tbl_head01 tbl_wrap">
<table>
<caption>오류 로그 목록</caption>
<colgroup>
    <col style="width:36px;">
    <col style="width:80px;">
    <col>
    <col style="width:200px;">
    <col style="width:80px;">
    <col style="width:120px;">
    <col style="width:60px;">
</colgroup>
<thead>
<tr>
    <th scope="col"><input type="checkbox" id="chkAll" onclick="guardianToggleAll(this)"></th>
    <th scope="col">등급</th>
    <th scope="col">메시지</th>
    <th scope="col">파일:라인</th>
    <th scope="col">발생</th>
    <th scope="col">시간</th>
    <th scope="col">상태</th>
</tr>
</thead>
<tbody>
<?php
$row_count = 0;
if ($result) {
    while ($row = sql_fetch_array($result)) {
        $row_count++;
        $log_id = (int)$row['log_id'];
        $resolved_text = !empty($row['resolved']) ? '✅ 해결' : '⏳ 미해결';
        $resolved_color = !empty($row['resolved']) ? '#2e7d32' : '#c63';
        $occured_dt = !empty($row['last_occurred_dt']) ? $row['last_occurred_dt'] : $row['created_dt'];
?>
<tr>
    <td><input type="checkbox" name="log_ids[]" value="<?php echo $log_id; ?>"></td>
    <td><?php echo guardian_level_badge($row['error_level']); ?></td>
    <td>
        <a href="javascript:void(0);" onclick="showGuardianLog(<?php echo $log_id; ?>)" style="color:#222; text-decoration:none;">
            <?php echo guardian_truncate_text($row['error_message'], 100); ?>
        </a>
    </td>
    <td style="color:#555; font-size:12px;">
        <?php echo guardian_truncate_text($row['error_file'], 30); ?>:<?php echo (int)$row['error_line']; ?>
    </td>
    <td style="text-align:center;"><?php echo number_format((int)$row['occurrence_count']); ?>회</td>
    <td title="<?php echo get_text($occured_dt); ?>" style="font-size:12px; color:#666;">
        <?php echo guardian_format_datetime($occured_dt); ?>
    </td>
    <td style="text-align:center; color:<?php echo $resolved_color; ?>; font-size:12px;">
        <?php echo $resolved_text; ?>
    </td>
</tr>
<?php
    }
}
if ($row_count === 0) {
?>
<tr>
    <td colspan="7" style="text-align:center; color:#888; padding:60px 0;">
        조회된 오류 로그가 없습니다.
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

<!-- ===== 상세보기 모달 ===== -->
<div id="guardian_modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999;">
    <div style="position:relative; width:90%; max-width:860px; margin:40px auto; background:#fff; border-radius:8px; padding:30px; max-height:85vh; overflow-y:auto;">
        <h2 style="margin-top:0;">오류 상세</h2>
        <button type="button" onclick="closeGuardianModal()" style="position:absolute; top:15px; right:15px; background:none; border:0; font-size:20px; cursor:pointer;">✕</button>

        <table class="tbl_row01 tbl_wrap" style="margin-top:10px;">
        <colgroup><col style="width:130px;"><col></colgroup>
        <tbody>
            <tr><th scope="row">등급</th><td id="m_level"></td></tr>
            <tr><th scope="row">메시지</th><td id="m_message" style="word-break:break-all;"></td></tr>
            <tr><th scope="row">파일:라인</th><td id="m_file"></td></tr>
            <tr><th scope="row">발생 횟수</th><td id="m_count"></td></tr>
            <tr><th scope="row">요청 URL</th><td id="m_url" style="word-break:break-all;"></td></tr>
            <tr><th scope="row">요청 메서드</th><td id="m_method"></td></tr>
            <tr><th scope="row">사용자 / IP</th><td id="m_user"></td></tr>
            <tr><th scope="row">최초 발생</th><td id="m_created"></td></tr>
            <tr><th scope="row">최근 발생</th><td id="m_last"></td></tr>
            <tr><th scope="row">해결 여부</th><td id="m_resolved"></td></tr>
            <tr><th scope="row">스택 트레이스</th><td><pre id="m_trace" style="max-height:300px; overflow:auto; background:#f5f5f5; padding:10px; font-size:12px; line-height:1.5; white-space:pre-wrap; word-break:break-all;"></pre></td></tr>
        </tbody>
        </table>

        <div style="text-align:right; margin-top:20px;">
            <button type="button" onclick="guardianSingleAction('toggle_resolve')" id="btn_resolve" class="btn btn_02">해결됨 토글</button>
            <button type="button" onclick="guardianSingleAction('delete')" class="btn" style="background:#d32f2f;color:#fff;">삭제</button>
            <button type="button" onclick="closeGuardianModal()" class="btn btn_01">닫기</button>
        </div>
    </div>
</div>

<script>
(function () {
    var ADMIN_TOKEN = '<?php echo $g_admin_token; ?>';
    window._guardianCurrentLogId = 0;

    window.guardianToggleAll = function (cb) {
        var boxes = document.querySelectorAll('input[name="log_ids[]"]');
        for (var i = 0; i < boxes.length; i++) {
            boxes[i].checked = cb.checked;
        }
    };

    window.guardianBulkAction = function (act) {
        var boxes = document.querySelectorAll('input[name="log_ids[]"]:checked');
        if (boxes.length === 0) {
            alert('대상을 선택해주세요.');
            return;
        }
        var msg = (act === 'delete')
            ? '선택한 ' + boxes.length + '건을 삭제하시겠습니까? 되돌릴 수 없습니다.'
            : '선택한 ' + boxes.length + '건의 해결됨 상태를 토글합니다. 진행할까요?';
        if (!confirm(msg)) return;

        // 검색 폼의 현재 select / 입력값을 redirect 용 hidden input 으로 동기화.
        // 사용자가 검색 옵션만 변경하고 [검색] 버튼을 누르지 않은 채 일괄 토글을
        // 누르는 케이스에서도 변경된 옵션이 redirect 후 페이지에 반영되도록 한다.
        var fsearch = document.getElementById('fsearch');
        if (fsearch) {
            var sync_fields = ['level', 'period', 'resolved', 'sfl', 'stx'];
            for (var i = 0; i < sync_fields.length; i++) {
                var name = sync_fields[i];
                var src = fsearch.elements[name];
                var dst = document.querySelector('input[name="r_' + name + '"]');
                if (src && dst) dst.value = src.value;
            }
        }

        document.getElementById('act_field').value = act;
        document.forms['flist'].submit();
    };

    window.showGuardianLog = function (log_id) {
        fetch('./guardian_log_view.php?log_id=' + encodeURIComponent(log_id))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.error) { alert(data.error); return; }
                document.getElementById('m_level').innerHTML = data.level_badge || data.error_level || '';
                document.getElementById('m_message').textContent = data.error_message || '';
                document.getElementById('m_file').textContent = (data.error_file || '') + ':' + (data.error_line || 0);
                document.getElementById('m_count').textContent = (data.occurrence_count || 0) + '회';
                document.getElementById('m_url').textContent = data.request_url || '(없음)';
                document.getElementById('m_method').textContent = data.request_method || '-';
                document.getElementById('m_user').textContent = (data.user_id || '-') + ' / ' + (data.user_ip || '-');
                document.getElementById('m_created').textContent = data.created_dt || '-';
                document.getElementById('m_last').textContent = data.last_occurred_dt || data.created_dt || '-';
                document.getElementById('m_resolved').textContent = (data.resolved == 1) ? '✅ 해결' : '⏳ 미해결';
                document.getElementById('m_trace').textContent = data.error_trace || '(스택 트레이스 없음)';
                document.getElementById('btn_resolve').textContent = (data.resolved == 1) ? '미해결로 변경' : '해결됨 표시';
                window._guardianCurrentLogId = parseInt(data.log_id, 10) || 0;
                document.getElementById('guardian_modal').style.display = 'block';
            })
            .catch(function (e) { alert('조회 실패: ' + e.message); });
    };

    window.closeGuardianModal = function () {
        document.getElementById('guardian_modal').style.display = 'none';
        window._guardianCurrentLogId = 0;
    };

    window.guardianSingleAction = function (act) {
        var id = window._guardianCurrentLogId;
        if (!id) return;
        var msg = (act === 'delete')
            ? '이 로그를 삭제하시겠습니까? 되돌릴 수 없습니다.'
            : '해결됨 상태를 토글합니다.';
        if (!confirm(msg)) return;

        var fd = new FormData();
        fd.append('act', act);
        fd.append('log_id', id);
        fd.append('token', ADMIN_TOKEN);
        fd.append('single', '1');

        fetch('./guardian_log_action.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    location.reload();
                } else {
                    alert(data.message || '처리 실패');
                }
            })
            .catch(function (e) { alert('요청 실패: ' + e.message); });
    };

    // 모달 바깥 클릭 시 닫기
    document.getElementById('guardian_modal').addEventListener('click', function (e) {
        if (e.target === this) closeGuardianModal();
    });

    // ESC 닫기
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeGuardianModal();
    });
})();
</script>

<?php
include_once(G5_ADMIN_PATH . '/admin.tail.php');
