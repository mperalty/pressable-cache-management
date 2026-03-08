/*PCM_PLAYBOOK_META
{"playbook_id":"pb_cookie_anonymous","version":"1.1.0","severity":"critical","title":"Anonymous Set-Cookie Blocks Caching","rule_ids":["cookie_on_anonymous","set_cookie_anonymous","anonymous_set_cookie"],"audiences":["site_owner","developer","host_support"]}
PCM_PLAYBOOK_META*/
# Anonymous Set-Cookie Blocks Caching

## Problem summary
Anonymous visitors are receiving a `Set-Cookie` response header. Most edge/page caches treat that response as private, so those pages stop being cached.

## Who should use this
- **Site owner:** follow Steps 1-4, then ask a developer for Step 5 if needed.
- **Developer/host support:** complete all steps including root-cause cleanup.

## Step-by-step remediation
1. **Confirm the issue is real**
   - Open an incognito/private browser window.
   - Load an affected public URL.
   - In DevTools Network, inspect the HTML response headers.
   - **Expected:** `Set-Cookie` appears on an anonymous request.

2. **Find what introduced the cookie**
   - Check recently installed/updated plugins (marketing, personalization, A/B testing, popup tools).
   - Temporarily disable the most likely plugin and retest.
   - **Expected:** when the culprit is disabled, `Set-Cookie` disappears.

3. **Apply the safest immediate fix**
   - Keep the feature disabled on anonymous/public pages.
   - If needed, keep it enabled only for logged-in users or specific routes.
   - **Expected:** public pages stop setting cookies.

4. **Re-check cacheability**
   - Retest headers in private browsing.
   - Confirm cache headers are now cache-friendly.
   - **Expected:** cache HIT ratio starts improving after warmup.

5. **Developer hardening (recommended)**
   - Wrap cookie writes with `is_user_logged_in()` or route guards.
   - Avoid session identifiers for anonymous users unless absolutely required.
   - Ensure cookie writes only occur on endpoints that truly need personalization.

## Verify success
- Anonymous public responses no longer include `Set-Cookie`.
- Affected pages are cacheable again.
- Follow-up scan no longer flags this issue.

## If it still fails
- Check for multiple cookie writers (more than one plugin/theme component).
- Inspect must-use plugins and custom theme `functions.php` logic.
- Escalate to host support with a sample URL and response headers.
