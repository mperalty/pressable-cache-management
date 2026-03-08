/*PCM_PLAYBOOK_META
{"playbook_id":"pb_purge_storm","version":"1.1.0","severity":"critical","title":"Repeated Global Purges","rule_ids":["repeated-global-purges","purge_storm"],"audiences":["site_owner","developer","host_support"]}
PCM_PLAYBOOK_META*/
# Repeated Global Purges

## Problem summary
Frequent global purges are preventing cache warmup, causing repeated cold-cache performance.

## Step-by-step remediation
1. **Validate pattern**
   - Identify purge frequency and timing.
   - Confirm global purges are happening repeatedly.

2. **Find purge sources**
   - Audit plugins, hooks, cron jobs, and deployment scripts calling full purge.

3. **Apply immediate protection**
   - Enable cooldown/debounce if available.
   - Temporarily disable non-critical automated purge triggers.

4. **Implement durable fix**
   - Replace global purges with URL-level or tag-level purges.
   - Batch repetitive events into one queued purge job.

5. **Re-check performance**
   - Confirm purge frequency drops.
   - Watch hit-rate and response time recovery.

## Verify success
- Global purge count is significantly lower.
- Cache hit-rate recovers and stays stable.
- Users stop seeing repeated cold-cache slowdowns.
