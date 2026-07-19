# P3-7: Final Cutover Sign-Off

> **Status:** Checklist ready — operational process (not code)
> **Prerequisite:** ALL prior phases (P0-P2) complete + ALL P3 checks pass

---

## Pre-Cutover Checklist

### Code Readiness
- [ ] All Phase 0 tasks complete (P0-1 through P0-8) — committed + pushed
- [ ] All Phase 1 tasks complete (P1-1 through P1-7) — committed + pushed
- [ ] All Phase 2 tasks complete (P2-1 through P2-7) — committed + pushed

### Database Readiness
- [ ] `php artisan migrate` — all migrations applied successfully
- [ ] `post_load_fixes.sql` — ETL conversions run (status enums, branch_id backfill, etc.)
- [ ] `sync_sequences.sql` — IDENTITY sequences set to MAX(id)
- [ ] `etl_verify.sql` — all integrity checks show 0

### Verification Commands (all must pass)
- [ ] `php artisan stock:replay-verify` — zero drift + sales-specific checks pass (P3-1)
- [ ] `php artisan journal:replay-verify` — all GL checks pass + sales-specific checks pass (P3-2)
- [ ] `php artisan subledger:reconcile` — all 6 sections green (P3-3)
- [ ] `php artisan reversal:verify` — all reversals net to zero + sales-specific checks pass (P3-5)
- [ ] `php artisan sales:pen-test` — all 5 security tests pass (P3-6)

### Functional Testing (accountant exercises 20 workflows)
- [ ] Create invoice → verify GL posted + customer_ledger updated
- [ ] Edit draft invoice → verify GL re-posted
- [ ] Cancel draft invoice → verify GL reversed
- [ ] Godown prep → verify status change
- [ ] Issue challan → verify stock OUT + COGS GL + sales_challan_items populated
- [ ] Cancel challan → verify stock restored + invoice back to draft
- [ ] Receive payment → verify GL Dr Bank / Cr AR + allocation
- [ ] Cancel payment → verify GL reversed + allocation deleted
- [ ] Create return → verify original_cost snapshotted
- [ ] Confirm return → verify stock IN at original_cost + GL revenue + COGS reversal
- [ ] Reverse return → verify stock + GL + linked damage all reversed
- [ ] Return with Damage item → verify linked damage_invoice created + stock OUT + GL
- [ ] Print invoice → verify paginated output
- [ ] Print challan → verify delivery note
- [ ] Print return slip → verify return details
- [ ] Print payment receipt → verify allocations shown
- [ ] View sales audit trail → verify 13 event types logged
- [ ] Stale draft cleanup (`php artisan sales:cancel-stale-drafts --dry-run`) → verify correct count
- [ ] Credit limit override → verify audit log + notification
- [ ] Cross-branch access attempt → verify 403

### Security Testing
- [ ] Login as `user` role → verify 403 on all sales write routes
- [ ] Login as `salesman` → verify can finalize but NOT issue challan
- [ ] Login as `accountant` → verify can confirm returns but NOT finalize invoices
- [ ] Forge `branch_id` in POST → verify 403 (branch.isolation)
- [ ] Admin cross-branch access → verify allowed + `branch_override` audit logged

### Performance
- [ ] Cart add-to-cart response < 500ms with 50 open invoices
- [ ] Invoice index page loads < 2s with 1000+ invoices
- [ ] P&L report renders < 3s on 1 year of data

## Cutover Steps

1. **Flip Nginx routing**: sales routes → Laravel only (legacy read-only)
2. **Observe 24h** with rollback ready
3. **Verify**: no incidents, no errors in `dev.log`
4. **Sign off**: lead developer + accountant + project owner

## Sign-Off

| Role | Name | Signature | Date |
|------|------|-----------|------|
| Lead Developer | _______________ | _______________ | _______________ |
| Accountant | _______________ | _______________ | _______________ |
| Project Owner | _______________ | _______________ | _______________ |

---

## 🎉 COMPLETION SUMMARY

Upon sign-off, the Sales Module migration is COMPLETE:

| Phase | Tasks | Commits | Status |
|-------|-------|---------|--------|
| Phase 0 (Critical Blockers) | 8 | 5 | ✅ Complete |
| Phase 1 (Operational Features) | 7 | 7 | ✅ Complete |
| Phase 2 (Refinements) | 7 | 6 | ✅ Complete |
| Phase 3 (Verification & QA) | 7 | 5 | ✅ Code Ready |
| **Total** | **29** | **23** | **✅ Ready for Production** |

### What Was Built (Phases 0-2):
- 9 migrations (schema fixes + new tables/columns)
- 4 new Eloquent models (SalesChallanItem, InvoicePaymentAllocation, SalesAccess, SalesAuditLogger)
- 3 new middleware (EnforceBranchIsolation, BranchScope, EnsureRole enhancements)
- 5 new Blade views (edit invoice + 5 print templates)
- 1 new Artisan command (stale draft cleanup + pen-test)
- 13 audit event types across 4 services
- 10 notification event types
- 19 RBAC role middleware assignments
- 5 branch.isolation middleware assignments
- Idempotency token on finalize
- Redis pipeline cache with proactive invalidation
- Transport snapshot workflow
- ETL data conversion scripts (6 fixes + 10 verification queries)
