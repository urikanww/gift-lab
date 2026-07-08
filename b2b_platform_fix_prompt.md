# B2B Gifting Platform - Fix Prompt (companion to the audit prompt)

> Usage: run AFTER an audit pass. Paste this prompt plus a batch of findings
> from the audit results table. One batch = 3–5 related findings, blockers
> first. Never paste the entire findings table into one run.
> SPEC-GAP findings are excluded - route those to the business team.

---

## PASS 3 - Fix Execution

You are fixing defects in a B2B custom gifting platform. The defects come
from a checklist audit; each has a check ID (e.g. C8-mobile, D5). The audit
checklist is the single source of truth for done-ness.

### Input for this run

FINDINGS BATCH (paste rows from the audit table, verbatim):

```
ID | check | status | evidence | severity
<paste 3–5 rows here>
```

### Non-negotiable execution rules

- Fix ONLY the findings in this batch. No refactors, no "while I'm here"
  improvements, no touching code paths unrelated to these IDs. If you
  discover a new defect while fixing, log it under "New findings" - do not
  fix it in this run.
- If any pasted row has status SPEC-GAP, stop and return it: spec gaps are
  business decisions, not code fixes. Do not invent the missing rule.
- Every fix must preserve the spec's locked decisions: browse-first with
  quote-gated accounts, freeze-on-quote snapshots, CC0/CC-BY licence gate,
  superadmin-configurable pricing (never hard-code a price, threshold, or
  margin - read it from configuration).
- Root-cause each finding before coding. A symptom patch that makes the
  check pass while leaving the cause (e.g. suppressing an error instead of
  handling the file format) is a failed fix.
- Desktop AND mobile: if the finding ID is platform-suffixed, fix and verify
  on that platform; then confirm the fix did not regress the other platform's
  row for the same base ID.

### Per-finding workflow (repeat for each ID in the batch)

1. **Diagnose.** State the root cause in one or two sentences, with the file
   or component path.
2. **Plan.** State the minimal change that resolves the root cause.
3. **Implement.** Make the change.
4. **Verify.** Re-execute the EXACT audit check for this ID, following the
   original check wording and evidence rules (PASS requires evidence:
   repro steps, screenshot ref, or code path).
5. **Regression sweep.** Re-run every audit check that shares a code path
   with this fix (e.g. a designer fix touching C4 requires re-checking
   C5–C10 and D5–D6). List which IDs you re-checked and their status.

### Output format (in this order, nothing omitted)

1. Fix table: `ID | root cause | change made (files touched) | verification
   result | regression IDs re-checked`
2. Coverage line: "X of X batch findings addressed" - numbers must match.
   Any finding not fixed appears with status BLOCKED and the reason.
3. New findings discovered during fixing, formatted as audit rows with a
   suggested new checklist ID (feeds back into Pass 1).
4. Returned items: any SPEC-GAP rows pasted by mistake, with the business
   decision required.
5. One-paragraph risk note: what this batch touched that a human should
   eyeball before the next audit run.

### Batch ordering guidance (for the human, not the model)

- Batch 1: all BLOCKER severity rows, grouped by area (A before C before D).
- Batch 2+: MAJOR rows grouped by shared code path (all designer rows
  together, all pricing rows together).
- MINOR rows last, or bundled into a related batch if trivially adjacent.
- After all batches: re-run Pass 1 in full. The audit table, not the fix
  reports, is the acceptance record.
