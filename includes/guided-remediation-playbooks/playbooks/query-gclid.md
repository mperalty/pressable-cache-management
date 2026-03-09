/*PCM_PLAYBOOK_META
{"playbook_id":"pb_query_gclid","version":"1.1.0","severity":"warning","title":"Tracking Query Fragmentation (gclid/fbclid)","rule_ids":["query_noise_gclid","query_noise_fbclid"],"audiences":["site_owner","developer","host_support"]}
PCM_PLAYBOOK_META*/
# Tracking Query Fragmentation (gclid/fbclid)

## Problem summary
Tracking parameters like `gclid` and `fbclid` create duplicate URL variants, fragmenting cache keys.

## Step-by-step remediation
1. **Confirm affected URLs**
   - Test a clean URL and a variant with `gclid`/`fbclid`.
   - Verify they render the same content.

2. **Add normalization rule**
   - Configure redirects to canonical URL without tracking params.
   - Preserve analytics attribution separately from cache key behavior.

3. **Test redirect behavior**
   - Ensure tracking URL resolves to canonical destination in one hop.

4. **Protect key pages**
   - Apply rules to major landing pages and campaign destinations first.

5. **Measure impact**
   - Confirm cache key count decreases for affected paths.
   - Monitor improved hit consistency.

## Verify success
- Tracking-param URLs canonicalize correctly.
- Cache fragmentation decreases.
- Scan no longer flags excessive query noise.
