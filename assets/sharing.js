(function () {
    'use strict';
    const config = window.awpmSharing || {};
    document.querySelectorAll('[data-awpm-generator]').forEach(function (form) {
        const section = form.closest('.awpm-generator');
        const status = section.querySelector('[role="status"]');
        const result = section.querySelector('.awpm-generator-result');
        const input = section.querySelector('#awpm-generated-link');
        let busy = false;
        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            if (busy || !form.reportValidity()) return;
            busy = true;
            const button = form.querySelector('button[type="submit"]');
            button.disabled = true;
            status.textContent = '';
            result.hidden = true;
            input.value = '';
            const body = new URLSearchParams({action: 'awpm_generate_link', nonce: config.nonce || '', url: form.elements.url.value.trim()});
            try {
                const response = await fetch(config.ajax, {method: 'POST', credentials: 'same-origin', body: body});
                const data = await response.json();
                if (!response.ok || !data.success) throw new Error(data.data && data.data.message || config.error);
                input.value = data.data.url;
                result.hidden = false;
                input.focus();
            } catch (error) {
                status.textContent = error.message || config.error;
            } finally {
                busy = false;
                button.disabled = false;
            }
        });
    });
    document.querySelectorAll('[data-awpm-share]').forEach(function (button) {
        const icon = button.querySelector('svg path');
        const originalPath = icon && icon.getAttribute('d');
        const originalTitle = button.title;
        const originalLabel = button.getAttribute('aria-label');
        let resetTimer;
        button.addEventListener('click', async function () {
            const section = button.closest('.awpm-product-share, .awpm-generator');
            const input = section.querySelector('.awpm-share-url, #awpm-generated-link');
            const status = section.querySelector('[role="status"]');
            const link = button.dataset.awpmLink || (input && input.value);
            if (!link) return;
            if (input) { input.focus(); input.select(); input.setSelectionRange(0, input.value.length); }
            try {
                if (navigator.clipboard && window.isSecureContext) await navigator.clipboard.writeText(link);
                else {
                    const field = input || document.createElement('textarea');
                    if (!input) {
                        field.value = link;
                        field.setAttribute('readonly', '');
                        field.style.cssText = 'position:fixed;left:-9999px;top:0;';
                        document.body.appendChild(field);
                    }
                    try {
                        field.focus(); field.select();
                        if (!document.execCommand('copy')) throw new Error('copy');
                    } finally {
                        if (!input) { field.remove(); button.focus(); }
                    }
                }
                status.textContent = config.copied;
                button.title = config.copied;
                button.setAttribute('aria-label', config.copied);
                if (icon) icon.setAttribute('d', 'M5 12l4 4L19 6');
                window.clearTimeout(resetTimer);
                resetTimer = window.setTimeout(function () {
                    button.title = originalTitle;
                    if (originalLabel === null) button.removeAttribute('aria-label');
                    else button.setAttribute('aria-label', originalLabel);
                    if (icon) icon.setAttribute('d', originalPath);
                }, 2000);
            } catch (error) {
                if (input) status.textContent = config.manual;
                else { window.prompt(config.manual, link); button.focus(); }
            }
        });
    });
})();
