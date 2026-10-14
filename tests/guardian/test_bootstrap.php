<?php
// DB 테스트는 명시적으로 지정한 임시 소켓만 사용한다. 운영 설정은 로드하지 않는다.
function guardian_test_socket()
{
    $socket = getenv('GUARDIAN_TEST_SOCKET');
    if (!$socket || strpos($socket, '/private/tmp/guardian-test-') !== 0 || !file_exists($socket)) {
        throw new RuntimeException('직접 시작한 임시 DB의 GUARDIAN_TEST_SOCKET을 지정하세요. tests/README.md를 참고하세요.');
    }
    return $socket;
}
