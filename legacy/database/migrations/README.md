# Accounting Core Migration

## Important Note
Since there is **no real production data** in the database, we have full freedom to redesign the accounting foundation cleanly.

## Files

| File | Purpose |
|------|---------|
| `001_create_accounting_core_tables.sql` | Creates improved `ledgers` structure + new `journal_entries` and `journal_lines` tables |
| `default_chart_of_accounts.sql` | Seeds a professional default Chart of Accounts suitable for a trading/distribution business |

## How to Apply (Recommended Order)

1. **Backup your current database** (even if test data).
2. Run `001_create_accounting_core_tables.sql`
3. Run `default_chart_of_accounts.sql` (this will clear existing ledger heads and insert a clean structure)
4. Test the new structure

## Next Steps After Running These

After applying the migration, we will:

- Update `LedgerModel.php` to support the new columns
- Create `JournalEntryModel.php`
- Build a `JournalPostingService` (the heart of the new system)
- Start integrating with low-risk transactions (Other Income / Other Expense)

Would you like me to proceed with updating the models and creating the Journal service next?