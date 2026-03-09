/*PCM_PLAYBOOK_META
{"playbook_id":"pb_vary_cookie","version":"1.1.0","severity":"warning","title":"High-Cardinality Vary: Cookie","rule_ids":["vary_cookie","vary_high_cardinality_cookie","volatile_vary"],"audiences":["site_owner","developer","host_support"]}
PCM_PLAYBOOK_META*/
# High-Cardinality Vary: Cookie

## Problem summary
`Vary: Cookie` on public responses creates too many cache variants and lowers hit rates.

## Step-by-step remediation
1. **Confirm current header behavior**
   - Inspect public page responses in private browsing.
   - Verify `Vary` includes `Cookie`.

2. **Find the source**
   - Audit plugins/theme code that modify `Vary` headers.
   - Check personalization and consent/cookie-management logic.

3. **Apply safe immediate fix**
   - Remove blanket `Vary: Cookie` on anonymous/public pages.
   - Keep variation only where true cookie-based personalization exists.

4. **Refine variation strategy**
   - Prefer explicit narrow variation keys over all cookies.
   - Limit per-cookie variation to required endpoints.

5. **Measure impact**
   - Compare cache variant count and hit ratio before/after.

## Verify success
- Public pages no longer vary on all cookies.
- Hit ratio improves and remains stable.
- Warning clears on follow-up scan.
