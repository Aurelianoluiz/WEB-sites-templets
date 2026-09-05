# CM-Comercial tests

## MySQL 8 / InnoDB concurrency

The integration suites below require a dedicated MySQL 8/InnoDB fixture and the `CM_*` environment variables documented in each test.

```bash
php webhook_mysql_concurrency_test.php
php webhook_mysql_deadlock_test.php
php webhook_pessimistic_locking_test.php
php security_audit.php
php validation_runner.php
php release_gate.php
```

`webhook_mysql_deadlock_test.php` deterministically exercises ER_LOCK_WAIT_TIMEOUT (1205/HY000) and ER_LOCK_DEADLOCK (1213/40001) using independent PDO sessions/processes, checks rollback invariants, verifies webhook containment without HTTP 500, and verifies safe replay/idempotency.

These MySQL integration tests must run against a disposable/dedicated integration database. They do not reset application business data.
