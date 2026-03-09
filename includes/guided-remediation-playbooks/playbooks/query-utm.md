/*PCM_PLAYBOOK_META
{"playbook_id":"pb_query_utm","version":"1.1.0","severity":"warning","title":"UTM Parameter Normalization","rule_ids":["query_noise_utm","query_noise_utm_campaign"],"audiences":["site_owner","developer","host_support"]}
PCM_PLAYBOOK_META*/
# UTM Parameter Normalization

## Problem summary
UTM parameters generate many URL variants with identical content, reducing cache efficiency.

## Step-by-step remediation
1. **Collect sample URLs**
   - Gather common URLs with `utm_source`, `utm_medium`, `utm_campaign`, etc.

2. **Define canonical behavior**
   - Decide the clean destination URL for each affected route.

3. **Implement normalization redirects**
   - Strip UTM parameters for caching/canonicalization.
   - Keep campaign attribution in analytics tools, not response variance.

4. **Validate for marketing teams**
   - Confirm analytics still records campaign/source data as expected.

5. **Re-test caching**
   - Check fewer variant cache entries and better hit-rate stability.

## Verify success
- UTM variants resolve to canonical URLs.
- Marketing attribution remains intact.
- Cache fragmentation warning is resolved.
