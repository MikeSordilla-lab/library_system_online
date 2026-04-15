# Phase 1 Receipts/Tickets Runbook

## Scope

Phase 1 provides browser-printable receipt/ticket flows for checkout, fine payment,
reservation, and visitor pass issuance. This runbook does not include POS middleware
or ESC/POS hardware integration.

## Migrations

Run base migration:

```bash
php admin/migrations/runner.php receipts-phase1
```

If upgrading an existing environment with prior receipt tables/columns, also run safe extension:

```bash
php admin/migrations/runner.php receipts-phase1-safe
```

Expected schema additions:

- `Receipt_Tickets`: `status`, `format`, `locale`, `timezone`, `payload_hash`, `payload_signature`, `branch_id`, `channel`
- `Receipt_Ticket_Logs`: `reason`, `job_target`, `target_status`, `error_message`, `channel`
- `Receipt_Print_Jobs` table

## Feature Toggle

API endpoints read `Settings.key = receipt_phase1_enabled`:

- enabled values: `1`, `true`, `on` (default fallback)
- disabled values: `0`, `false`, `off`, `disabled`, `no`

When disabled, receipt API endpoints return `503` with toggle metadata.

## Manual Test Checklist

1. **Checkout receipt**
   - Librarian performs checkout.
   - Confirm receipt link appears.
   - Open receipt view and kiosk view.

2. **Fine payment receipt**
   - Librarian marks borrower fines as paid.
   - Confirm receipt generated with amount/currency.

3. **Reservation receipt**
   - Borrower reserves available book.
   - Confirm receipt link appears and opens.

4. **Visitor pass**
   - Librarian opens `librarian/visitor-pass.php`.
   - Submit visitor details and print format.
   - Confirm visitor pass receipt renders.

5. **Reprint audit**
   - Open receipt page, submit reprint reason.
   - Confirm success flash.
   - Verify `Receipt_Ticket_Logs` has `event_type=reprint` with reason.

6. **API checks**
   - `POST api/receipts/create.php`
   - `GET api/receipts/get.php?no=...`
   - `POST api/receipts/reprint.php` with reason
   - `GET api/receipts/print-meta.php?no=...`
   - `GET api/receipts/qr.php?no=...`
   - `GET api/receipts/pdf.php?no=...`

7. **Access denial**
   - Borrower attempts to access another borrower receipt by number.
   - Confirm 403 for page and API.

## Deployment Plan

1. Deploy code.
2. Run `receipts-phase1` migration.
3. Run `receipts-phase1-safe` migration.
4. Verify `receipt_phase1_enabled` setting (default enabled).
5. Execute manual test checklist.

## Rollback Plan

Fast rollback (recommended):

1. Set `receipt_phase1_enabled=0` in `Settings` to disable receipt API endpoints.
2. Revert app files to previous release.

Schema rollback (optional, use with caution):

- Keep new columns/tables in place if possible (backward-compatible).
- If strict rollback is required, drop only newly added table `Receipt_Print_Jobs` and ignore extra columns unless absolutely necessary.

## Limitations

- PDF output is fallback-only (HTML/downloadable HTML), no PDF engine dependency added.
- QR payload is signed text payload only; image QR generation is not included.
- Visitor pass uses receipt payload storage; dedicated visitor entity/table is not introduced in Phase 1.
