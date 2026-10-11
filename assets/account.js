(function () {
    'use strict';
    const method = document.getElementById('awpm-method');
    if (method) method.addEventListener('change', function () {
        document.getElementById('awpm-instructions').textContent = method.selectedOptions[0].dataset.instructions || '';
    });
    const copy = document.querySelector('[data-awpm-copy]');
    if (copy) copy.addEventListener('click', async function () {
        const input = document.getElementById('awpm-ref-link');
        input.focus(); input.select();
        try {
            if (navigator.clipboard && window.isSecureContext) await navigator.clipboard.writeText(input.value);
            else if (!document.execCommand('copy')) return;
            const label = copy.textContent;
            copy.textContent = copy.dataset.copied;
            window.setTimeout(function () { copy.textContent = label; }, 2000);
        } catch (error) { /* Leave the link selected for manual copying. */ }
    });
})();
