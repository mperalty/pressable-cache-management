window.pcmPost = window.pcmPost || function(bodyObj) {
        var timeoutMs = 15000;
        var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
        var timeoutId = null;
        var params = new URLSearchParams();
        Object.keys(bodyObj || {}).forEach(function(key){
            var value = bodyObj[key];
            if (Array.isArray(value)) {
                value.forEach(function(item){ params.append(key + '[]', item); });
                return;
            }
            params.append(key, value);
        });
        if (controller) {
            timeoutId = window.setTimeout(function(){
                controller.abort();
            }, timeoutMs);
        }

        return fetch(ajaxurl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: params.toString(),
            signal: controller ? controller.signal : undefined
        }).then(function(response){
            if (timeoutId) window.clearTimeout(timeoutId);
            if (!response.ok) {
                var error = new Error('http_' + response.status);
                error.status = response.status;
                throw error;
            }
            return response.json();
        }).catch(function(error){
            if (timeoutId) window.clearTimeout(timeoutId);
            if (error && (error.name === 'AbortError' || error.message === 'timeout')) {
                var timeoutError = new Error('timeout');
                timeoutError.isTimeout = true;
                throw timeoutError;
            }
            throw error;
        });
    };

    window.pcmHandleError = window.pcmHandleError || function(context, error, targetEl) {
        function esc(value) {
            return String(value == null ? '' : value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        var message = 'Unable to reach the server. Check your connection.';
        if (error && (error.isTimeout || error.message === 'timeout')) {
            message = 'This is taking longer than expected...';
        } else if (error && (error.status === 403 || /nonce|forbidden|permission|rest_forbidden/i.test(error.message || ''))) {
            message = "You don't have permission to perform this action.";
        }
        if (targetEl) {
            targetEl.innerHTML = '<div class="pcm-inline-error"><strong>' + esc(context) + ':</strong> ' + esc(message) + '</div>';
        }
        return message;
    };
