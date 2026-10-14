# GB Guardian 회귀 테스트

테스트는 PHP 7.4 이상 CLI를 사용한다. 배포 PHP는 기존 PHP 5.6 호환 문법을 유지한다. 이식 기준은 WizardOfCode 커밋 `d50f473643f712ad9ab966cd04d98e960403432f`이며 서비스 전용 호출부·문자열 분석은 제외했다.

## DB 없는 테스트

```sh
php tests/run.php
```

구조화 보고 API의 활성·비활성·미설치·수집 등급 제외, 인자 검증과 마스킹, 호출 위치, DB/엔진 오류 반환, 시간 집계, 설치 접근 가드를 확인한다. SQL과 발송 어댑터는 대체하며 실제 발송하지 않는다.

## 임시 MariaDB 테스트

운영 설정 파일과 운영 DB를 로드하지 않는다. 직접 시작한 `/private/tmp/guardian-test-*` 경로의 임시 소켓만 허용한다. 테스트는 fixture DB의 테이블을 생성·삭제하므로 공유 DB에 연결하지 않는다. MariaDB, PHP PDO MySQL 확장이 필요하다.

```sh
guardian_test_dir=$(mktemp -d /private/tmp/guardian-test-XXXXXX)
mariadb-install-db --no-defaults --datadir="$guardian_test_dir/data" --auth-root-authentication-method=normal --skip-test-db
mariadbd --no-defaults --datadir="$guardian_test_dir/data" --socket="$guardian_test_dir/mysql.sock" --pid-file="$guardian_test_dir/mysql.pid" --log-error="$guardian_test_dir/server.log" --skip-networking &
```

소켓이 생성되고 서버가 준비된 것을 확인한 뒤 실행한다.

```sh
GUARDIAN_TEST_SOCKET="$guardian_test_dir/mysql.sock" php tests/run.php
mariadb-admin --no-defaults --socket="$guardian_test_dir/mysql.sock" -u root shutdown
```

- 발생 상세: 그룹 키, 횟수, 커서 조회, 게시판 링크, 미설치 폴백, 상세 저장 실패와 복구, 보관기간 및 고아 정리, 게시판 원본 유지.
- 동시성: 실제 PHP 다중 프로세스의 그룹 생성·횟수·발송 예약, 채널·수신자·전역 범위, 일일 한도, DB 잠금 실패·대기 만료·복구, 발송 훅 예외, 예약/결과 저장 실패, 관리자 삭제·상태 필터.
- 설치·성능: 신규 인덱스, 기존 설치 추가, 재실행, 다른 이름의 동등 인덱스, 이름 충돌, 2만 건 발송 이력의 날짜 범위 EXPLAIN, 고아 정리의 배치 진행과 실패 복구.

실제 그누보드 서버의 설치 페이지와 브라우저 관리자 화면, 외부 메일·문자·알림톡 전달은 별도 배포 확인이 필요하다. 임시 서버를 종료한 뒤 임시 디렉터리를 삭제할 수 있다.
