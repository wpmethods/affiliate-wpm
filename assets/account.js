(function () {
    'use strict';
    const method = document.getElementById('awpm-method');
    if (method) {
        function payoutMethod() {
            const option = method.selectedOptions[0];
            document.getElementById('awpm-instructions').textContent = option.dataset.instructions || '';
            const details = document.getElementById('awpm-details');
            const label = document.querySelector('label[for="awpm-details"]');
            const stripe = option.dataset.provider === 'stripe';
            if (details) { details.disabled = stripe; details.required = !stripe; details.hidden = stripe; }
            if (label) { label.hidden = stripe; label.textContent = option.dataset.provider === 'paypal' ? 'PayPal email' : 'Payment destination details'; }
            if (details) { details.rows = option.dataset.provider === 'paypal' ? 1 : 3; details.placeholder = option.dataset.provider === 'paypal' ? 'Your PayPal account email' : ''; }
        }
        method.addEventListener('change', payoutMethod); payoutMethod();
    }
    document.querySelectorAll('[data-awpm-copy]').forEach(function (copy) {
        const originalLabel = copy.textContent;
        let copyTimer;
        copy.addEventListener('click', async function () {
            const input = document.getElementById('awpm-ref-link');
            const url = copy.dataset.awpmUrl || (input && input.value);
            if (!url) return;
            const scope = copy.closest('.awpm-best-sellers') || copy.closest('.awpm-link-card');
            const feedback = scope && scope.querySelector('[role="status"]');
            copy.disabled = true;
            let temporary;
            try {
                if (navigator.clipboard && window.isSecureContext) await navigator.clipboard.writeText(url);
                else {
                    temporary = document.createElement('textarea');
                    temporary.value = url;
                    temporary.readOnly = true;
                    temporary.style.position = 'fixed';
                    temporary.style.opacity = '0';
                    document.body.appendChild(temporary);
                    temporary.focus(); temporary.select();
                    if (!document.execCommand('copy')) throw new Error('Clipboard unavailable');
                }
                copy.textContent = copy.dataset.copied || 'Copied!';
                if (feedback) feedback.textContent = copy.textContent;
                window.clearTimeout(copyTimer);
                copyTimer = window.setTimeout(function () { copy.textContent = originalLabel; }, 2000);
            } catch (error) {
                if (feedback) feedback.textContent = copy.dataset.copyFailed || 'Could not copy. Please try again.';
                if (!copy.dataset.awpmUrl && input) { input.focus(); input.select(); }
            } finally {
                if (temporary) { temporary.remove(); copy.focus(); }
                copy.disabled = false;
            }
        });
    });
})();
