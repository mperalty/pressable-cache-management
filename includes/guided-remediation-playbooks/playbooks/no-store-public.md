/*PCM_PLAYBOOK_META
{"playbook_id":"pb_no_store_public","version":"1.1.0","severity":"critical","title":"No-Store on Public Pages","rule_ids":["no_store_public","cache_control_no_store","cache_control_not_public"],"audiences":["site_owner","developer","host_support"]}
PCM_PLAYBOOK_META*/
# No-Store on Public Pages

## Problem summary
Public pages are returning restrictive cache directives like `no-store`, `private`, or `max-age=0`, which block edge caching.

## Step-by-step remediation
1. **Validate on a public URL**
   - Open the URL in an incognito/private window.
   - Inspect `Cache-Control` response headers.
   - **Expected:** you can see `no-store`, `private`, or equivalent blocking directives.

2. **Isolate recent changes**
   - Review recently activated plugins and theme changes.
   - Focus on security, membership, and performance plugins that modify headers.

3. **Apply immediate fix**
   - Disable the setting/plugin rule that adds restrictive directives globally.
   - Keep restrictive headers only on login/account/checkout/personalized pages.

4. **Developer implementation cleanup**
   - Audit `send_headers` hooks and middleware.
   - Scope restrictive directives by route or auth state, not entire site.

5. **Re-test and monitor**
   - Confirm public pages now send cache-friendly headers.
   - Track cache hit-rate trend over the next few hours.

## Verify success
- Public anonymous pages no longer send `no-store/private`.
- Sensitive/private routes still keep strict headers.
- Follow-up scan clears this finding.

## Escalation
If directives persist, provide host support with one affected URL and full response headers.
