/*PCM_PLAYBOOK_META
{"playbook_id":"pb_vary_user_agent","version":"1.1.0","severity":"warning","title":"Vary on User-Agent","rule_ids":["vary_user_agent","vary_high_cardinality_user_agent"],"audiences":["site_owner","developer","host_support"]}
PCM_PLAYBOOK_META*/
# Vary on User-Agent

## Problem summary
`Vary: User-Agent` creates excessive variants (often one per browser/device), fragmenting cache effectiveness.

## Step-by-step remediation
1. **Validate the current behavior**
   - Inspect response headers on key public pages.
   - Confirm `Vary` includes `User-Agent`.

2. **Assess if UA variance is truly needed**
   - Check whether output actually differs by browser/device.
   - If output is functionally identical, remove UA variation.

3. **Implement narrower logic**
   - Use targeted device class splits only when required.
   - Prefer responsive frontend techniques over UA-based rendering.

4. **Retest across device/browser samples**
   - Confirm content still renders correctly.
   - Ensure cache variant count is reduced.

5. **Monitor**
   - Track hit-rate consistency and response-time improvements.

## Verify success
- Public pages stop varying by full `User-Agent`.
- Required device-specific behavior still works.
- Scan clears the UA variation warning.
