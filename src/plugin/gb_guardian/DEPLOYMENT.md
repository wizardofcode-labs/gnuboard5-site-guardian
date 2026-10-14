# 공통 기능 업데이트 배포 안내

이 업데이트는 구조화 보고 API, 발생별 게시판 참조, 동시 요청 보호, 처리 시간 기록을 추가한다. 기존 배포본의 문구·브랜드·설정 기본값은 유지한다. 특정 서비스의 호출부나 파일명·게시판을 해석하는 코드는 포함하지 않는다.

## 필수 PHP 파일 16개

아래 저장소 경로의 `src/`를 그누보드 루트에 대응시킨다. 두 새 라이브러리를 포함해 모든 파일을 같은 버전으로 교체한다.

```text
src/adm/guardian_config.php
src/adm/guardian_log.php
src/adm/guardian_log_action.php
src/adm/guardian_log_view.php
src/adm/guardian_notify_log.php
src/adm/guardian_notify_log_action.php
src/extend/gb_guardian.extend.php
src/plugin/gb_guardian/install.php
src/plugin/gb_guardian/lib/error_handler.lib.php
src/plugin/gb_guardian/lib/guardian_db.lib.php
src/plugin/gb_guardian/lib/guardian_notifier.lib.php
src/plugin/gb_guardian/lib/guardian_protector.lib.php
src/plugin/gb_guardian/lib/guardian_report.lib.php
src/plugin/gb_guardian/lib/guardian_rule_engine.lib.php
src/plugin/gb_guardian/lib/guardian_summary.lib.php
src/plugin/gb_guardian/lib/guardian_timing.lib.php
```

## 기존 설치 업데이트

1. 파일과 DB를 백업하고 사이트 요청과 요약 cron/방문자 트리거를 잠시 중지한다. 진행 중인 요청이 끝난 뒤 파일을 교체한다.
2. 위 16개 PHP 파일을 업로드한다. `guardian_report.lib.php`와 `guardian_timing.lib.php`를 반드시 함께 포함한다. OPcache를 고정하는 환경은 새 파일이 로드되도록 갱신한다.
3. 관리자 로그인 후 `/plugin/gb_guardian/install.php`를 연다. 페이지를 열면 설치·업그레이드가 실행된다.
4. `guardian_occurrence` 테이블 확인/생성, 발송 조회 인덱스 추가 또는 SKIP, DB 이름 잠금(GET_LOCK / RELEASE_LOCK) OK를 확인한다. FAIL이 있으면 재개 전 원인을 해결한다.
5. 기존 설정·규칙·수신자·cron 토큰이 유지되는지 확인한다. 인덱스는 이름이 달라도 `(channel, sent_dt)`로 시작하는 전체 컬럼 인덱스가 있으면 유지한다. 추가 작업은 DB 크기에 따라 지연·잠금이 생길 수 있다.
6. 설치 테스트와 테스트 발송, 구조화 오류 보고, 발생 상세 조회, 예약 상태와 정리 동작을 확인한 후 요청과 요약 트리거를 재개한다. 이번 업데이트 때문에 `cleanup.php`를 실행할 필요는 없다.

새 테이블은 기존 prefix를 사용한다. 그누보드 코어 컬럼을 추가하지 않으며 기존 설정 기본값을 덮어쓰지 않는다. DB 잠금을 지원하지 않거나 잠금 획득이 실패하면 수집·발송을 보호 없이 실행하지 않는다.

## 확인할 동작

- 동일 키의 동시 오류는 저장 쿨다운 내 그룹 한 개에 횟수와 상세를 누적한다.
- 같은 오류·채널·주소의 진행 중 예약은 재발송을 막는다. 전역 범위는 같은 수신자의 모든 채널을 함께 검사한다.
- 예약/실패/결과 미확인도 일일 한도에 포함한다. 확정 실패는 한도 내 재시도 가능하다.
- 진행 중 예약은 10분 보호하며 만료 후 결과 미확인으로 표시한다. 같은 해시의 다음 예약 시 만료 이력을 갱신한다. 외부 전달 후 DB 기록 실패 등 장애 상황의 정확히 한 번 전달은 보장하지 않는다.
- 관리자의 삭제 작업은 10분 이내 진행 중 예약을 제외한다.
- 비활성/미설치/수집 등급 제외 상태의 명시적 API 호출은 PHP 로그를 남기고 캡처·발송하지 않는다. 정상 요청은 시간 로그를 남기지 않는다.
- 기존 일반 PHP 오류는 메시지 기반 그룹화를 유지한다. 구조화 API로 전환한 오류의 첫 발생은 새 알림이 될 수 있으며 과거 그룹은 합치지 않는다.
- 상세는 50건씩 조회하고 보관기간에 따라 미해결 그룹에서도 만료한다. 게시판 원본은 유지한다.

공용 API는 [REPORT_API.md](REPORT_API.md), 상세 정책은 [OCCURRENCES.md](OCCURRENCES.md), 회귀 테스트는 [tests/README.md](../../../tests/README.md)를 참고한다.
