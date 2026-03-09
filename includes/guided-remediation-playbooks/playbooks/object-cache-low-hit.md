/*PCM_PLAYBOOK_META
{"playbook_id":"pb_object_cache_low_hit","version":"1.1.0","severity":"warning","title":"Low Object Cache Hit Ratio","rule_ids":["low_hit_ratio","object_cache_low_hit_ratio"],"audiences":["site_owner","developer","host_support"]}
PCM_PLAYBOOK_META*/
# Low Object Cache Hit Ratio

## Problem summary
Your object cache is getting too many misses, which increases database load and slows requests.

## Step-by-step remediation
1. **Confirm baseline**
   - Record current hit ratio and request volume.
   - Capture a 24h baseline before making major changes.

2. **Reduce unnecessary flushes**
   - Audit scheduled jobs/hooks that call full object cache flushes.
   - Replace broad flushes with scoped invalidation where possible.

3. **Inspect key churn**
   - Check for short TTLs or highly variable keys.
   - Remove volatile elements from key names when safe.

4. **Tune cache strategy**
   - Use appropriate cache groups and expiration values.
   - Keep hot data cached longer if freshness requirements allow.

5. **Measure again**
   - Compare 24h/7d hit ratio after changes.
   - Watch DB load and slow query trends.

## Verify success
- Hit ratio improves and stabilizes.
- Database pressure decreases during peak traffic.
- Scan no longer reports low-hit concern.
