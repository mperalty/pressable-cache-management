/*PCM_PLAYBOOK_META
{"playbook_id":"pb_redirect_loop","version":"1.1.0","severity":"critical","title":"Redirect Loop Detected","rule_ids":["redirect_loop_detected","loop_conflict"],"audiences":["site_owner","developer","host_support"]}
PCM_PLAYBOOK_META*/
# Redirect Loop Detected

## Problem summary
Two or more redirect rules are sending users in a circular path, causing failed page loads and poor cache behavior.

## Step-by-step remediation
1. **Confirm the loop**
   - Test the failing URL with redirect tracing enabled.
   - Record the redirect chain/hops.

2. **Disable the newest suspect rule**
   - Temporarily turn off the most recently added redirect.
   - Retest to confirm loop stops.

3. **Identify overlap type**
   - Check for exact-match rules conflicting with wildcard/prefix rules.
   - Ensure there is one clear winner per URL pattern.

4. **Set deterministic priority**
   - Reorder rules so specific routes evaluate before broad patterns.
   - Prevent redirects from pointing to paths matched by upstream rules.

5. **Regression test**
   - Validate affected URL, source path, and destination path.
   - Ensure only one redirect (or expected finite chain) remains.

## Verify success
- Loop is eliminated.
- Redirect chain is stable and intentional.
- Scan no longer reports loop conflict.
