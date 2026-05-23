<?php
/**
 * 그누보드 운영지킴이 - 메일 어댑터
 *
 * 단일 진입점 guardian_send_email() 만 노출한다. SMTP 설정·자동 감지·폴백
 * 등은 모두 그누보드의 mailer() 함수에 위임한다.
 *
 * mailer() 동작 (lib/mailer.lib.php):
 *   1. PHPMailer 인스턴스 생성 (기본 PHP mail() 모드)
 *   2. run_replace('mail_options', $mail, ...) 훅 호출
 *      → SMTP Manager 가 설치되어 있고 활성 상태면
 *        Host/Port/Auth/Secure 를 동적으로 채우고 IsSMTP() 호출
 *      → 미설치/비활성이면 그대로 통과 → PHP mail() 폴백
 *   3. $mail->send()
 *
 * 즉 운영지킴이는 mailer() 한 줄만 호출하면 SMTP/PHP-mail 분기, 다른 mailer
 * 후킹 플러그인과의 통합, 메일 발송 로그 기록까지 그누보드 생태계에 자동
 * 합류한다. 별도 mailer_mode 설정·SMTP Manager 감지 코드는 불필요하다.
 *
 * (c) 2026 K3SOFT / WizardOfCode
 *
 * @package gb_guardian
 * @version 1.0.0
 */
if (!defined('_GNUBOARD_')) exit;

/**
 * 메일 발송 진입점.
 *
 * 모든 SMTP / PHP mail 분기는 그누보드 mailer() 가 처리한다.
 * 사이트를 죽이지 않는 것이 최우선 — die / throw 사용 금지, 어떤 예외도 흡수.
 *
 * @param  string $to      수신자 이메일
 * @param  string $subject 제목 (UTF-8)
 * @param  string $body    HTML 본문
 * @return array           array('success' => bool, 'reason' => string)
 */
function guardian_send_email($to, $subject, $body)
{
    global $config;

    // ---- 수신자 이메일 검증 ----
    if (!filter_var((string)$to, FILTER_VALIDATE_EMAIL)) {
        return array('success' => false, 'reason' => '잘못된 수신자 이메일');
    }

    // ---- 그누보드 메일 발송 정책 ----
    // cf_email_use=0 이면 mailer() 가 즉시 return 해서 NULL 반환 → 발송 실패로 분류.
    // 사전에 명시 차단해 reason 을 더 정확히 표시한다.
    if (empty($config['cf_email_use'])) {
        return array(
            'success' => false,
            'reason'  => '그누보드 메일 발송이 비활성화 (관리자 → 환경설정 → 메일발송 사용)',
        );
    }

    // ---- mailer() 자동 로드 ----
    if (!function_exists('mailer') && defined('G5_LIB_PATH')) {
        $f = G5_LIB_PATH . '/mailer.lib.php';
        if (@is_readable($f)) {
            @include_once($f);
        }
    }
    if (!function_exists('mailer')) {
        return array('success' => false, 'reason' => '그누보드 mailer() 함수 사용 불가');
    }

    // ---- 발신 정보 결정 ----
    $from_name = !empty($config['cf_admin_email_name'])
        ? (string)$config['cf_admin_email_name']
        : '운영지킴이';
    $from_email = !empty($config['cf_admin_email'])
        ? (string)$config['cf_admin_email']
        : '';

    if ($from_email === '' || !filter_var($from_email, FILTER_VALIDATE_EMAIL)) {
        // cf_admin_email 미설정 환경 폴백
        $host = isset($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : 'localhost';
        $host = preg_replace('/[^a-zA-Z0-9.\-]/', '', $host);
        $from_email = 'noreply@' . $host;
    }

    // 헤더 인젝션 방지 (\r\n 차단). 그누보드 mailer 가 PHPMailer 를 쓰므로
    // 추가 인코딩은 PHPMailer 가 책임지지만 발신자 이름의 이상 문자는 차단.
    $from_name = str_replace(array("\r", "\n"), '', $from_name);

    // ---- 실제 발송 ----
    // mailer() 시그니처: ($name, $from, $to, $subject, $content, $type, $file, $cc, $bcc)
    // type=1 = HTML 메일.
    $ok = null;
    try {
        $ok = @mailer($from_name, $from_email, $to, $subject, $body, 1);
    } catch (Exception $e) {
        return array('success' => false, 'reason' => 'mailer() Exception: ' . $e->getMessage());
    }

    if ($ok) {
        return array('success' => true, 'reason' => '');
    }

    // mailer() 가 false / null 반환 — SMTP 인증 실패, 메일 서버 미동작 등
    return array(
        'success' => false,
        'reason'  => 'mailer() 발송 실패 (SMTP 설정 또는 메일 서버 상태 확인)',
    );
}
