window.pcmPost = window.pcmPost || function(bodyObj) {
        var params = new URLSearchParams();
        Object.keys(bodyObj || {}).forEach(function(key){
            var value = bodyObj[key];
            if (Array.isArray(value)) {
                value.forEach(function(item){ params.append(key + '[]', item); });
                return;
            }
            params.append(key, value);
        });
        return fetch(ajaxurl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: params.toString()
        }).then(function(response){
            if (!response.ok) {
                var error = new Error('http_' + response.status);
                error.status = response.status;
                throw error;
            }
            return response.json();
        });
    };

    window.pcmHandleError = window.pcmHandleError || function(context, error, targetEl) {
        var message = 'Unable to reach the server. Check your connection.';
        if (error && (error.status === 403 || /nonce|forbidden|permission/i.test(error.message || ''))) {
            message = "You don't have permission to perform this action. Your session may have expired. Please reload the page.";
        }
        if (targetEl) {
            targetEl.innerHTML = '<div class="pcm-inline-error"><strong>' + context + ':</strong> ' + message + '</div>';
        }
    };
