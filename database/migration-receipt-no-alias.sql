-- Migration: Add receipt_no_alias column to Receipt_Tickets for backwards compatibility
-- This allows old sequential receipt numbers to still be looked up
-- New receipts use UUID format (bin2hex(random_bytes(16))) in receipt_no column

ALTER TABLE Receipt_Tickets
ADD COLUMN receipt_no_alias VARCHAR(32) NULL AFTER receipt_no;