# Future Scope — Explicitly Deferred, Unscheduled

These items are described in master-plan.md but are not included in any milestone's charter and have no assigned delivery milestone or date. Listing them here prevents them from being silently dropped or silently reintroduced into a milestone's scope without proper approval.

- Chat attachments (images, documents, screenshots) — master-plan.md section 6.4.
- Draggable chat launcher (a one-time drag-to-place; the master plan explicitly rules out continuous free movement) — master-plan.md section 7.2.
- Generic webhook rule action (as opposed to the Telegram-specific actions already in scope) — master-plan.md section 4.2.
- Administrative bot write commands (add order note, change conversation state, assign conversation, pause/resume automation rule, change operator availability, modify order state) — master-plan.md section 8.2. M08's charter includes only the read-only command set.
- Write-capable AI tools (retrieve verified order, add internal order note, change conversation status, cancel an eligible order, create a restricted coupon) — master-plan.md section 9.5. M09/M10 charters include only read-only AI tools.
- Nested OR rule condition groups — master-plan.md section 4.2 lists nested AND/OR groups generally, but M02's charter commits only to AND groups. This entry remains deferred until formally moved.

## How an item leaves this list

No item here is removed merely because an implementation plan chooses to implement it. Moving any item out of this file and into a milestone's scope requires, in every case:

1. Master Architect review.
2. Product Owner approval.
3. The corresponding milestone charter update and this file's update, both applied together.
4. A new ADR, if the change alters a milestone boundary (per docs/adr/README.md's "when an ADR is required" rule).

No milestone number is reserved for any item on this list, and no delivery date is implied, until that process completes for that specific item.
