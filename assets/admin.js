/* AffMint: scoped, dependency-free settings controls. */
(function () {
    'use strict';
    const config = window.affmAdmin || {};
    const notices = Array.from(document.querySelectorAll('.affm-admin [data-affm-toast]'));
    if (notices.length) {
        const stack = document.createElement('div');
        stack.className = 'affm-toast-stack';
        document.querySelector('.affm-admin').appendChild(stack);
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
            toast.querySelector('.affm-toast-dismiss').addEventListener('click', dismiss);
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
            ['saved', 'affm_message', 'affm_error'].forEach(function (key) { url.searchParams.delete(key); });
            window.history.replaceState(window.history.state, '', url.toString());
        } catch (ignore) { /* Keep notifications usable if history changes are unavailable. */ }
    }
    let dirty = false;
    document.querySelectorAll('form.affm-settings').forEach(function (form) {
        const bar = form.querySelector('.affm-save-bar') || (form.id ? document.querySelector('.affm-save-bar button[form="' + form.id + '"]')?.closest('.affm-save-bar') : null);
        const state = bar ? bar.querySelector('.affm-save-state') : null;
        if (bar) {
            const slot = document.createElement('div');
            slot.className = 'affm-save-bar-slot';
            bar.before(slot);
            slot.appendChild(bar);
            let frame = 0;
            function positionBar() {
                frame = 0;
                const rect = slot.getBoundingClientRect();
                const inset = window.innerWidth <= 600 ? 10 : 16;
                const docked = rect.bottom <= window.innerHeight - inset;
                bar.classList.toggle('affm-save-bar-fixed', !docked);
                bar.classList.toggle('affm-save-bar-docked', docked);
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
            if (event.target.closest('#affm-add-method, .affm-remove-method')) markDirty();
        });
        form.addEventListener('submit', function () {
            dirty = false;
            if (state) state.textContent = config.saving || 'Saving your changes...';
        });
        const preview = form.querySelector('.affm-design-preview');
        if (preview) form.querySelectorAll('input[type="color"], #affm-radius').forEach(function (input) {
            input.addEventListener('input', function () {
                const key = input.id.replace('affm-design-', '');
                if (input.type === 'color' && /^#[a-f0-9]{6}$/i.test(input.value)) preview.style.setProperty('--affm-' + key, input.value);
                else if (input.id === 'affm-radius') preview.style.setProperty('--affm-radius', Math.min(24, Math.max(0, Number(input.value) || 0)) + 'px');
            });
        });
    });
    window.addEventListener('beforeunload', function (event) {
        if (dirty) { event.preventDefault(); event.returnValue = ''; }
    });
    const exchange = document.getElementById('affm-exchange_source');
    const manualRates = document.getElementById('affm-manual-exchange-rates');
    const release = document.getElementById('affm-release_mode');
    const releaseDays = document.getElementById('affm-release-days');
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
    document.querySelectorAll('[data-affm-provider]').forEach(function (card) {
        const provider = card.dataset.affmProvider;
        const environment = card.querySelector('select[name="affm_gateway[' + provider + '_mode]"]');
        const marker = card.querySelector('[data-affm-credential-mode]');
        const title = card.querySelector('[data-affm-credential-title]');
        const fields = Array.from(card.querySelectorAll('[data-affm-credential]'));
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
