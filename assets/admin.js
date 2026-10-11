/* Affiliate WPM: scoped, dependency-free settings controls. */
(function () {
    'use strict';
    const config = window.awpmAdmin || {};
    let dirty = false;
    document.querySelectorAll('form.awpm-settings').forEach(function (form) {
        const state = form.querySelector('.awpm-save-state');
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
    sync();
})();
