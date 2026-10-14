<?php
// 테스트 실행기: PHP 7.4+ CLI. 배포 PHP의 5.6 호환성과 별개로 테스트는 현대 PHP를 사용한다.
if (PHP_SAPI !== 'cli') exit;
$cases = array(
    array('report_error_test.php'),
    array('report_error_test.php', 'disabled'),
    array('report_error_test.php', 'uninstalled'),
    array('report_error_test.php', 'filtered'),
    array('timing_test.php'),
    array('install_access_test.php'),
);
if (getenv('GUARDIAN_TEST_SOCKET')) {
    $cases[] = array('occurrence_test.php');
    $cases[] = array('occurrence_test.php', '--missing');
    $cases[] = array('concurrency_test.php');
    $cases[] = array('performance_test.php');
}
foreach ($cases as $case) {
    $command = array(PHP_BINARY, __DIR__ . '/guardian/' . array_shift($case));
    $process = proc_open(array_merge($command, $case), array(0 => STDIN, 1 => STDOUT, 2 => STDERR), $pipes);
    if (!is_resource($process) || proc_close($process) !== 0) exit(1);
}
echo 'PASS suite: ', count($cases), " cases\n";
