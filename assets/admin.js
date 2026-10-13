/* Affiliate WPM: scoped, dependency-free settings controls. */
(function () {
    'use strict';
    const config = window.awpmAdmin || {};
    const notices = Array.from(document.querySelectorAll('.awpm-admin [data-awpm-toast]'));
    if (notices.length) {
        const stack = document.createElement('div');
        stack.className = 'awpm-toast-stack';
        document.querySelector('.awpm-admin').appendChild(stack);
        notices.forEach(function (toast) {
            stack.appendChild(toast);
            let timer = 0;
            let remaining = Math.min(18000, Math.max(8000, toast.textContent.length * 45));
            let started = 0;
            let hovered = false;
            let closed = false;
            function pause() {
                if (timer) { window.clearTimeout(timer); timer = 0; remaining = Math.max(0, remaining - (Date.now() - started)); }
            }
            function resume() {
                if (closed || timer || toast.dataset.autodismiss !== 'yes' || hovered || toast.contains(document.activeElement) || document.hidden) return;
                started = Date.now(); timer = window.setTimeout(dismiss, remaining);
            }
            function dismiss() {
                if (closed) return;
                closed = true; pause();
                document.removeEventListener('visibilitychange', visibility);
                toast.classList.add('is-leaving');
                const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                window.setTimeout(function () { toast.remove(); if (!stack.children.length) stack.remove(); }, reduced ? 0 : 180);
            }
            function visibility() { if (document.hidden) pause(); else resume(); }
            toast.querySelector('.awpm-toast-dismiss').addEventListener('click', dismiss);
            toast.addEventListener('mouseenter', function () { hovered = true; pause(); });
            toast.addEventListener('mouseleave', function () { hovered = false; resume(); });
            toast.addEventListener('focusin', pause);
            toast.addEventListener('focusout', function () { window.setTimeout(resume, 0); });
            toast.addEventListener('keydown', function (event) { if (event.key === 'Escape') { event.preventDefault(); dismiss(); } });
            document.addEventListener('visibilitychange', visibility);
            resume();
        });
        // Flash messages should not reappear on refresh or when copying the page URL.
        try {
            const url = new URL(window.location.href);
            ['saved', 'awpm_message', 'awpm_error'].forEach(function (key) { url.searchParams.delete(key); });
            window.history.replaceState(window.history.state, '', url.toString());
        } catch (ignore) { /* Keep notifications usable if history changes are unavailable. */ }
    }
    let dirty = false;
    document.querySelectorAll('form.awpm-settings').forEach(function (form) {
        const bar = form.querySelector('.awpm-save-bar') || (form.id ? document.querySelector('.awpm-save-bar button[form="' + form.id + '"]')?.closest('.awpm-save-bar') : null);
        const state = bar ? bar.querySelector('.awpm-save-state') : null;
        if (bar) {
            const slot = document.createElement('div');
            slot.className = 'awpm-save-bar-slot';
            bar.before(slot);
            slot.appendChild(bar);
            let frame = 0;
            function positionBar() {
                frame = 0;
                const rect = slot.getBoundingClientRect();
                const inset = window.innerWidth <= 600 ? 10 : 16;
                const docked = rect.bottom <= window.innerHeight - inset;
                bar.classList.toggle('awpm-save-bar-fixed', !docked);
                bar.classList.toggle('awpm-save-bar-docked', docked);
                if (docked) {
                    bar.style.removeProperty('left');
                    bar.style.removeProperty('width');
                } else {
                    const left = Math.max(10, rect.left);
                    const right = Math.min(document.documentElement.clientWidth - 10, rect.right);
                    bar.style.left = left + 'px';
                    bar.style.width = Math.max(0, right - left) + 'px';
                }
                slot.style.height = bar.offsetHeight + 'px';
            }
            function queuePosition() {
                if (!frame) frame = window.requestAnimationFrame(positionBar);
            }
            window.addEventListener('resize', queuePosition, {passive: true});
            window.addEventListener('scroll', queuePosition, {passive: true});
            if (window.ResizeObserver) new ResizeObserver(queuePosition).observe(slot);
            positionBar();
        }
        function markDirty() {
            dirty = true;
            if (state) { state.textContent = config.unsaved || 'You have unsaved changes.'; state.classList.add('is-dirty'); }
        }
        form.addEventListener('input', markDirty);
        form.addEventListener('change', markDirty);
        form.addEventListener('click', function (event) {
            if (event.target.closest('#awpm-add-method, .awpm-remove-method')) markDirty();
        });
        form.addEventListener('submit', function () {
            dirty = false;
            if (state) state.textContent = config.saving || 'Saving your changes...';
        });
        const preview = form.querySelector('.awpm-design-preview');
        if (preview) form.querySelectorAll('input[type="color"], #awpm-radius').forEach(function (input) {
            input.addEventListener('input', function () {
                const key = input.id.replace('awpm-design-', '');
                if (input.type === 'color' && /^#[a-f0-9]{6}$/i.test(input.value)) preview.style.setProperty('--awpm-' + key, input.value);
                else if (input.id === 'awpm-radius') preview.style.setProperty('--awpm-radius', Math.min(24, Math.max(0, Number(input.value) || 0)) + 'px');
            });
        });
    });
    window.addEventListener('beforeunload', function (event) {
        if (dirty) { event.preventDefault(); event.returnValue = ''; }
    });
    const exchange = document.getElementById('awpm-exchange_source');
    const manualRates = document.getElementById('awpm-manual-exchange-rates');
    const release = document.getElementById('awpm-release_mode');
    const releaseDays = document.getElementById('awpm-release-days');
    function conditionalFields() {
        if (exchange && manualRates) manualRates.hidden = !['auto', 'manual'].includes(exchange.value);
        if (release && releaseDays) {
            releaseDays.hidden = release.value !== 'delay';
            const input = releaseDays.querySelector('input');
            if (input) input.required = release.value === 'delay';
        }
    }
    if (exchange) exchange.addEventListener('change', conditionalFields);
    if (release) release.addEventListener('change', conditionalFields);
    document.querySelectorAll('[data-awpm-provider]').forEach(function (card) {
        const provider = card.dataset.awpmProvider;
        const environment = card.querySelector('select[name="awpm_gateway[' + provider + '_mode]"]');
        const marker = card.querySelector('[data-awpm-credential-mode]');
        const title = card.querySelector('[data-awpm-credential-title]');
        const fields = Array.from(card.querySelectorAll('[data-awpm-credential]'));
        if (!environment || !marker) return;
        let current = marker.value;
        const drafts = {};
        function refresh() {
            fields.forEach(function (field) {
                const saved = current === 'live' ? field.dataset.savedLive : field.dataset.savedTest;
                field.placeholder = saved === 'yes' ? field.dataset.savedPlaceholder : field.dataset.emptyPlaceholder;
            });
            if (title) title.textContent = current === 'live' ? 'Live credentials' : 'Sandbox / Test credentials';
        }
        environment.addEventListener('change', function () {
            drafts[current] = fields.map(function (field) { return field.value; });
            current = environment.value;
            fields.forEach(function (field, index) { field.value = drafts[current] ? drafts[current][index] : ''; });
            marker.value = current;
            refresh();
        });
        refresh();
    });
    conditionalFields();
})();
