/* Affiliate WPM: scoped, dependency-free settings controls. */
(function () {
    'use strict';
    const config = window.awpmAdmin || {};
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
    const list = document.getElementById('awpm-method-list');
    const mode = document.getElementById('awpm-payout_mode');
    const selection = document.getElementById('awpm-method_selection');
    const add = document.getElementById('awpm-add-method');
    let index = list ? list.children.length : 0;
    function sync() {
        const manual = document.getElementById('awpm-manual-methods');
        if (manual && mode) manual.hidden = mode.value !== 'manual';
        if (add && list) add.disabled = list.children.length >= 20;
    }
    function single(changed) {
        if (!list || !selection || selection.value !== 'single') return;
        const checked = Array.from(list.querySelectorAll('input[type="checkbox"]:checked'));
        const keep = changed && changed.checked ? changed : checked[0];
        checked.forEach(function (box) { box.checked = box === keep; });
    }
    if (add) add.addEventListener('click', function () {
        if (list.children.length >= 20) return;
        const template = document.getElementById('awpm-method-template');
        const fragment = template.content.cloneNode(true);
        fragment.querySelectorAll('[name]').forEach(function (el) { el.name = el.name.replace('__INDEX__', String(index)); });
        index += 1;
        list.appendChild(fragment);
        single(); sync();
        list.lastElementChild.querySelector('input[type="text"]').focus();
    });
    if (list) {
        list.addEventListener('click', function (event) {
            if (event.target.closest('.awpm-remove-method')) {
                event.target.closest('.awpm-method-row').remove(); sync();
            }
        });
        list.addEventListener('change', function (event) {
            if (event.target.type === 'checkbox') single(event.target);
        });
    }
    if (mode) mode.addEventListener('change', sync);
    if (selection) selection.addEventListener('change', function () { single(); });
    document.addEventListener('click', function (event) {
        const button = event.target.closest('[data-awpm-confirm]');
        if (button && !window.confirm(button.dataset.awpmConfirm)) event.preventDefault();
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
    conditionalFields();
    sync();
})();
