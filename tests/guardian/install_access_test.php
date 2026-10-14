<?php
// 실제 설치 파일의 접근 가드를 별도 PHP 프로세스에서 실행한다. DB/외부 API는 사용하지 않는다.
if (PHP_SAPI !== 'cli') exit;
$root = getenv('GUARDIAN_TEST_ROOT') ?: dirname(__DIR__, 2);
$source = file_get_contents($root . '/src/plugin/gb_guardian/install.php');
$start = strpos($source, "if (!defined('_GNUBOARD_')) exit;");
$end = strpos($source, '$msg = array();', $start);
if ($start === false || $end === false) throw new RuntimeException('설치 접근 가드를 찾을 수 없습니다.');
$guard = substr($source, $start, $end - $start);
$checks = 0;
function check($ok, $message) {
    global $checks;
    $checks++;
    if (!$ok) throw new RuntimeException($message);
}
foreach (array('guest' => '', 'member' => '', 'admin' => 'super') as $case => $admin) {
    $code = 'define("_GNUBOARD_", true);'
        . 'define("G5_BBS_URL", "https://fixture.invalid/wg/bbs");'
        . 'define("G5_PLUGIN_URL", "https://fixture.invalid/wg/plugin");'
        . '$is_admin = ' . var_export($admin, true) . ';'
        . '$warnings = array(); $alerts = 0;'
        . 'set_error_handler(function ($level, $message) { global $warnings; $warnings[] = $message; return true; });'
        . 'function alert($message) { global $alerts; $alerts++; echo "UNSAFE_ALERT"; exit; }'
        . 'ob_start(); register_shutdown_function(function () { global $warnings, $alerts;'
        . '$body = ob_get_clean(); echo json_encode(array("body"=>$body, "status"=>http_response_code(), "warnings"=>$warnings, "alerts"=>$alerts)); });'
        . 'eval(base64_decode(' . var_export(base64_encode($guard), true) . '));'
        . 'echo "INSTALL_ALLOWED";';
    $process = proc_open(array(PHP_BINARY, '-r', $code),
        array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    if (!is_resource($process)) throw new RuntimeException('PHP 하위 프로세스 실행 실패');
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exitCode = proc_close($process);
    $result = json_decode($out, true);
    check($exitCode === 0 && $err === '' && is_array($result), $case . ': 접근 가드 실행 정상');
    check($result['warnings'] === array() && $result['alerts'] === 0, $case . ': 경고 및 alert() 상대 경로 오류 없음');
    if ($admin !== '') {
        check($result['body'] === 'INSTALL_ALLOWED' && $result['status'] !== 403, '관리자는 설치 처리로 진행');
    } else {
        check($result['status'] === 403 && strpos($result['body'], 'INSTALL_ALLOWED') === false, $case . ': 설치 처리 전에 403으로 종료');
        check(strpos($result['body'], '관리자만 접근 가능합니다.') !== false, $case . ': 권한 안내 유지');
        preg_match('/href="([^"]+)"/', $result['body'], $match);
        $url = isset($match[1]) ? htmlspecialchars_decode($match[1], ENT_QUOTES) : '';
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        check(strpos($url, 'https://fixture.invalid/wg/bbs/login.php?') === 0
            && isset($query['url']) && $query['url'] === 'https://fixture.invalid/wg/plugin/gb_guardian/install.php', $case . ': 로그인 후 설치 페이지 복귀');
    }
}
check(strpos($source, "include_once(__DIR__ . '/../../common.php');") !== false, '공통 초기화는 파일 기준 절대 경로 사용');
echo "PASS install access: {$checks}\n";
