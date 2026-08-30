# Backup — CM Comercial — Final Readiness

Date: 2026-08-30

## Restore point

Main branch commit at backup creation:

`85de93cfbe302533a45637422e1c294117ceec73`

This point includes the latest application changes and the production-readiness documentation.

## Included state

- 32/32 technical development phases completed.
- Payment Core/Service and Mercado Pago adapter hardened.
- Webhook HMAC, freshness, idempotency and lifecycle protections present.
- Payment/order atomicity protections present.
- Stock payment idempotency and reconciliation protections present.
- Configuration surface validation present.
- Deterministic validation runner and release gate synchronized.
- Production activation procedure documented in `PRODUCTION-ACTIVATION.md`.
- Final readiness document aligned with external activation requirements.

## External activation still required

This Git restore point does not contain production credentials or a production database backup. Before real use, configure the server, HTTPS, database, Mercado Pago credentials/webhook, run E2E in sandbox/homologation, and perform an external database backup/restore validation.

## Secret policy

No production credentials, API tokens or card data are intentionally stored in this backup file.
