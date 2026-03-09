/*PCM_PLAYBOOK_META
{"playbook_id":"pb_opcache_high_waste","version":"1.1.0","severity":"warning","title":"High OPcache Wasted Memory","rule_ids":["high_wasted_memory","opcache_wasted_memory"],"audiences":["site_owner","developer","host_support"]}
PCM_PLAYBOOK_META*/
# High OPcache Wasted Memory

## Problem summary
OPcache wasted memory is high, which can cause restarts, degraded performance, and inconsistent response times.

## Step-by-step remediation
1. **Confirm current OPcache state**
   - Record wasted memory %, restart count, and memory usage.

2. **Review deploy/invalidation behavior**
   - Check how often code deployments invalidate large portions of OPcache.
   - Reduce unnecessary file churn during releases.

3. **Tune OPcache settings**
   - Increase OPcache memory if utilization is persistently high.
   - Review timestamp validation settings for your deployment model.

4. **Deploy during low traffic window**
   - Apply setting changes carefully and monitor immediately after.

5. **Track results**
   - Confirm wasted memory trend drops over time.
   - Ensure restart frequency is reduced.

## Verify success
- Lower wasted memory percentage.
- Fewer OPcache restarts.
- More stable request performance.
