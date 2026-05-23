<?php
/**
 * 그누보드 운영지킴이 — 강제 경고 발생 테스트 페이지
 *
 * 본 페이지는 설치 직후 운영지킴이가 정상 동작하는지 확인하기 위해
 * 의도적으로 PHP 경고(WARNING) / 알림(NOTICE) / 예외(EXCEPTION) 를
 * 발생시킵니다. 발생한 오류는 다음 흐름으로 처리되어야 정상입니다.
 *
 *   1) 운영지킴이가 오류를 캡처하여 g5_guardian_log 에 INSERT
 *   2) 등록된 알림 규칙 (guardian_rule) 과 매칭
 *   3) 매칭 시 메일 / SMS / 카톡으로 알림 발송 (instant 모드 기준)
 *   4) 매칭 추적 로그 (guardian_rule_match_log) 에 결과 기록
 *
 * 테스트 후 다음 화면에서 결과를 확인하세요.
 *   - 관리자 → 운영지킴이 → 오류 로그
 *   - 관리자 → 운영지킴이 → 매칭 추적 로그
 *   - 관리자 → 운영지킴이 → 알림 발송 이력
 *
 * ─────────────────────────────────────────────────────────────────
 * ▶ 개발자 커스텀 경고 — 본인만의 경고 위치를 추가하는 방법
 * ─────────────────────────────────────────────────────────────────
 * 운영지킴이는 PHP 의 표준 오류 핸들러(set_error_handler) 와 예외 핸들러
 * (set_exception_handler), 종료 핸들러(register_shutdown_function) 를 등록해
 * 어떤 코드 위치에서 발생하는 오류든 자동으로 캡처합니다. 따라서 개발자가
 * "이 시점을 알림 받고 싶다" 면 본 파일의 패턴을 그대로 자기 코드에 복사하시면
 * 됩니다. 캡처에 추가 함수 호출은 필요 없습니다.
 *
 *   ── 패턴 1. 사용자 정의 경고 ──
 *   trigger_error('결제 모듈 응답 지연 (5s 초과)', E_USER_WARNING);
 *
 *   ── 패턴 2. 사용자 정의 알림 ──
 *   trigger_error('재고 부족 임박 - SKU=' . $sku, E_USER_NOTICE);
 *
 *   ── 패턴 3. 예외로 알림 ──
 *   if ($payment_failed) {
 *       throw new Exception('PG 응답 코드 비정상: ' . $resp_code);
 *   }
 *
 * 권장 사용처 예시:
 *   - 결제 PG 응답 시간 임계치 초과 / 응답 코드 비정상
 *   - 외부 API 호출 실패 (재시도 후에도)
 *   - 재고 / 잔액 / 한도 임박
 *   - 관리자만 접근해야 하는 영역에 일반 회원 접근 시도
 *   - 설치형 솔루션의 환경 점검 실패
 *
 * 주의:
 *   - 환경설정의 "수집 등급" 에서 해당 등급(WARNING / NOTICE 등) 이 체크돼 있어야
 *     캡처됩니다. NOTICE 는 노이즈가 많아 기본 OFF 입니다.
 *   - 알림으로 받으시려면 "알림 규칙" 화면에서 매칭 규칙(등급/파일패턴/채널) 을
 *     등록해 두셔야 합니다.
 *   - 같은 오류가 짧은 시간에 반복되면 디바운싱 / 쿨다운으로 발송이 1번만
 *     일어납니다 (의도된 동작 — SMS 비용 폭탄 방지).
 * ─────────────────────────────────────────────────────────────────
 *
 * (c) 2026 K3SOFT / WizardOfCode
 *
 * @package gb_guardian
 * @version 1.1.0
 */
$sub_menu = "700990";
include_once('./_common.php');

auth_check_menu($auth, $sub_menu, 'r');

$action = isset($_POST['action']) ? (string)$_POST['action'] : '';
$result_msg = '';
$result_color = '#0f3460';

if ($action !== '') {
    // CSRF 토큰 확인
    if (!check_admin_token(false)) {
        $result_msg = '잘못된 토큰입니다. 페이지를 새로고침 후 다시 시도하세요.';
        $result_color = '#c33';
    } else {
        // 테스트로 발생시킨 PHP 오류는 운영지킴이 핸들러가 정상 캡처하지만
        // PHP 의 기본 동작상 display_errors=on 환경에서는 화면에도 출력된다.
        // 본 출력이 admin.head.php 의 HTML 렌더링보다 앞에 끼어들면 레이아웃이
        // 깨지므로 ob_start / ob_end_clean 으로 표시 출력만 버린다.
        // ★ 캡처는 핸들러에서 이미 실행됨 — 본 처리는 화면 출력만 막을 뿐 ★
        ob_start();
        switch ($action) {
            case 'warning':
                // ★ 강제 WARNING — 운영지킴이가 캡처해야 정상
                // 주의: '@' 연산자를 절대 붙이지 말 것. 운영지킴이는 @ 억제를
                // 사용자 의도로 존중해 캡처하지 않는다 (테스트 시에도 동일).
                trigger_error('운영지킴이 테스트 — 강제 경고 (E_USER_WARNING) 발생', E_USER_WARNING);
                $result_msg = '✅ E_USER_WARNING 을 발생시켰습니다. 잠시 후 "오류 로그" 화면에서 확인하세요.';
                break;

            case 'notice':
                // ★ 강제 NOTICE — 수집 등급에 NOTICE 가 켜져 있어야 캡처됨
                // 주의: '@' 연산자 사용 금지 (위와 동일 사유)
                trigger_error('운영지킴이 테스트 — 강제 알림 (E_USER_NOTICE) 발생', E_USER_NOTICE);
                $result_msg = '✅ E_USER_NOTICE 을 발생시켰습니다. 환경설정의 "수집 등급" 에 NOTICE 가 체크돼 있어야 캡처됩니다.';
                break;

            case 'exception':
                // ★ 강제 EXCEPTION — try/catch 로 묶어 페이지 다운은 방지하되 캡처는 시킨다
                try {
                    throw new Exception('운영지킴이 테스트 — 강제 예외 발생');
                } catch (Exception $e) {
                    // 그누보드 페이지가 죽지 않도록 try/catch 로 잡되, 운영지킴이는 직접
                    // 캡처 함수를 통해 EXCEPTION 등급으로 기록한다.
                    if (function_exists('guardian_capture_exception')) {
                        guardian_capture_exception($e);
                    } else {
                        // 폴백 — capture 함수가 없는 환경에서는 trigger_error 로 대체
                        trigger_error('테스트 예외: ' . $e->getMessage(), E_USER_WARNING);
                    }
                }
                $result_msg = '✅ Exception 을 발생시켰습니다 (try/catch 로 안전 캡처). 잠시 후 "오류 로그" 화면에서 확인하세요.';
                break;

            case 'undefined':
                // ★ 정의되지 않은 변수 사용 — PHP 8 에서 WARNING, PHP 7 이하에서 NOTICE
                // '@' 연산자 사용 금지 — 억제되면 운영지킴이가 캡처하지 않음
                $undef_msg = $undefined_test_variable_xyz;
                $result_msg = '✅ 정의되지 않은 변수에 접근했습니다. PHP 버전에 따라 NOTICE / WARNING 으로 캡처됩니다.';
                break;

            case 'division':
                // ★ 0 으로 나누기 — PHP 8 에서 DivisionByZeroError(EXCEPTION), PHP 7 에서 WARNING
                // PHP 8 의 DivisionByZeroError 는 try/catch 로 받아서 명시 캡처
                if (PHP_MAJOR_VERSION >= 8) {
                    try {
                        $r = 1 / 0;
                    } catch (\Throwable $e) {
                        if (function_exists('guardian_capture_exception')) {
                            guardian_capture_exception($e);
                        }
                    }
                } else {
                    // PHP 7 이하 — 그대로 발생시키면 WARNING 으로 캡처됨 ('@' 사용 금지)
                    $tmp = 0;
                    $result_div = 100 / $tmp;
                }
                $result_msg = '✅ 0 으로 나누기를 시도했습니다. PHP 8 환경에서는 EXCEPTION 으로, PHP 7 이하에서는 WARNING 으로 캡처됩니다.';
                break;

            default:
                $result_msg = '알 수 없는 액션입니다.';
                $result_color = '#c33';
        }
        ob_end_clean();  // PHP 의 기본 오류 출력 폐기 (캡처는 이미 완료)
    }
}

$g5['title'] = '운영지킴이 — 그누보드5/영카트5 운영 진단키트 — 설치 테스트';
include_once(G5_ADMIN_PATH . '/admin.head.php');
?>
<style>
a.btn,a.btn_01,a.btn_02,a.btn_03,a.btn_submit{text-decoration:none}
a.btn:hover,a.btn_01:hover,a.btn_02:hover,a.btn_03:hover{text-decoration:none}
.gtest_grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(260px,1fr)); gap:14px; margin:18px 0; }
.gtest_card { background:#f9fafb; border:1px solid #e5e7eb; border-radius:10px; padding:16px 18px; }
.gtest_card h3 { margin:0 0 6px 0; font-size:15px; color:#0F3460; }
.gtest_card p { font-size:12px; color:#555; line-height:1.6; margin:0 0 10px 0; min-height:50px; }
.gtest_btn { display:inline-block; padding:8px 14px; background:#0F3460; color:#fff !important; border:0; border-radius:5px; font-size:12px; cursor:pointer; }
.gtest_btn:hover { background:#16315f; }
.gtest_btn.warn { background:#f57c00; }
.gtest_btn.danger { background:#c33; }
.gtest_links { background:#fff8e1; border-left:4px solid #fbc02d; padding:14px 18px; margin-top:24px; font-size:13px; color:#5d4037; line-height:1.7; border-radius:4px; }
.gtest_links a { color:#0F3460; font-weight:bold; }
</style>

<div class="local_desc01 local_desc">
    <strong>📋 설치 테스트</strong> — 운영지킴이가 정상적으로 오류를 캡처하고 알림을 발송하는지 확인할 수 있습니다.
    아래 버튼을 클릭하면 의도적으로 PHP 오류를 발생시키며, 캡처된 오류는 좌측 메뉴의 <strong>오류 로그 / 매칭 추적 로그 / 알림 발송 이력</strong> 화면에서 확인할 수 있습니다.
</div>

<?php if ($result_msg !== '') { ?>
<div class="local_desc01 local_desc" style="background:#e8f5e9; color:<?php echo get_text($result_color); ?>; border-left:4px solid <?php echo get_text($result_color); ?>;">
    <?php echo get_text($result_msg); ?>
</div>
<?php } ?>

<form method="post" action="">
<input type="hidden" name="token" value="<?php echo get_admin_token(); ?>">

<div class="gtest_grid">

    <div class="gtest_card">
        <h3>⚠️ E_USER_WARNING 발생</h3>
        <p><code>trigger_error(..., E_USER_WARNING)</code> 호출. 가장 일반적인 테스트 — 수집 등급 기본값에 WARNING 이 포함되어 있어 별도 설정 없이 캡처됩니다.</p>
        <button type="submit" name="action" value="warning" class="gtest_btn warn">WARNING 발생시키기</button>
    </div>

    <div class="gtest_card">
        <h3>ℹ️ E_USER_NOTICE 발생</h3>
        <p><code>trigger_error(..., E_USER_NOTICE)</code> 호출. <strong>환경설정 → 수집 등급</strong> 에서 NOTICE 를 체크해야 캡처됩니다 (기본 OFF).</p>
        <button type="submit" name="action" value="notice" class="gtest_btn">NOTICE 발생시키기</button>
    </div>

    <div class="gtest_card">
        <h3>💥 Exception 발생</h3>
        <p><code>throw new Exception(...)</code> 호출. try/catch 로 안전하게 잡아 페이지 다운 없이 EXCEPTION 등급으로 캡처합니다.</p>
        <button type="submit" name="action" value="exception" class="gtest_btn danger">EXCEPTION 발생시키기</button>
    </div>

    <div class="gtest_card">
        <h3>❓ 정의되지 않은 변수 접근</h3>
        <p>PHP 8 환경에서는 WARNING, PHP 7 이하에서는 NOTICE 로 캡처됩니다. 실제 운영 중 가장 흔한 오류 패턴입니다.</p>
        <button type="submit" name="action" value="undefined" class="gtest_btn">변수 미정의 오류 발생시키기</button>
    </div>

    <div class="gtest_card">
        <h3>➗ 0 으로 나누기</h3>
        <p>PHP 8 환경에서는 DivisionByZeroError(EXCEPTION), PHP 7 이하에서는 WARNING 으로 캡처됩니다.</p>
        <button type="submit" name="action" value="division" class="gtest_btn">0 나누기 발생시키기</button>
    </div>

</div>

</form>

<div class="gtest_links">
    <strong>🔍 결과 확인 위치</strong><br>
    1. <a href="./guardian_log.php">관리자 → 운영지킴이 → 오류 로그</a> — 캡처된 오류 자체 확인<br>
    2. <a href="./guardian_rule_match_log.php">관리자 → 운영지킴이 → 매칭 추적 로그</a> — 어떤 알림 규칙과 매칭됐는지 / 차단됐다면 사유<br>
    3. <a href="./guardian_notify_log.php">관리자 → 운영지킴이 → 알림 발송 이력</a> — 실제로 메일/SMS/카톡이 나갔는지<br>
    <br>
    <span style="color:#5d4037; font-size:12px;">
        ※ 알림이 안 온다면 <strong>알림 규칙</strong> 화면에서 매칭 규칙이 등록돼 있는지, 환경설정의 비상정지 / 일일한도 / 야간 무음 / 디바운싱 쿨다운에 막히지 않았는지 매칭 추적 로그에서 사유를 확인하세요.
    </span>
</div>

<div style="background:#eff6ff; border-left:4px solid #3b82f6; padding:14px 18px; margin-top:18px; font-size:12px; color:#1e3a8a; line-height:1.7;">
    <strong>👨‍💻 개발자에게 — 본인 코드에 경고 발생 위치 추가하기</strong><br>
    운영지킴이는 PHP 표준 핸들러로 모든 위치의 오류를 자동 캡처합니다. 자신의 코드에 알림 받고 싶은 지점이 있다면 다음 한 줄을 넣으세요:<br>
    <pre style="background:#fff; border:1px solid #b6d4ee; border-radius:4px; padding:10px 12px; font-size:12px; margin:8px 0; white-space:pre-wrap;">trigger_error('결제 PG 응답 지연 - 5초 초과', E_USER_WARNING);
trigger_error('재고 임박: SKU=' . $sku, E_USER_NOTICE);
throw new Exception('PG 응답 코드 비정상: ' . $code);</pre>
    더 자세한 패턴은 본 파일(<code>guardian_test_warning.php</code>) 상단의 주석을 참고하세요.
</div>

<?php
include_once(G5_ADMIN_PATH . '/admin.tail.php');
