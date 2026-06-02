/* global MPA */
(function ($) {
    'use strict';

    if (typeof MPA === 'undefined') {
        return;
    }

    function setBusy(button, busy) {
        if (!button) return;
        if (busy) {
            button.dataset.originalText = button.textContent;
            button.disabled = true;
            button.textContent = MPA.i18n.loading;
        } else {
            button.disabled = false;
            if (button.dataset.originalText) {
                button.textContent = button.dataset.originalText;
            }
        }
    }

    function showInline(panel, type, message) {
        if (!panel) return;
        const div = document.createElement('div');
        div.className = 'mpa-alert mpa-alert--' + type;
        div.textContent = message;
        panel.prepend(div);
        setTimeout(function () { div.remove(); }, 4000);
    }

    function getMetaBox(btn) {
        return btn.closest('.mpa-metabox') || btn.closest('.mpa-card') || btn.closest('.mpa-detail') || document;
    }

    function call(action, data) {
        return $.post(MPA.ajaxUrl, $.extend({ action: action, mpa_action: action, nonce: MPA.nonce }, data || {}));
    }

    $(document).on('click', '.mpa-action', function (e) {
        e.preventDefault();
        const btn = this;
        const action = btn.dataset.action;
        const box = getMetaBox(btn);
        const orderId = box.dataset.order || btn.dataset.order || (btn.closest('[data-order]') && btn.closest('[data-order]').dataset.order);
        const simItem = btn.closest('.mpa-sim');
        const iccid = btn.dataset.iccid || (simItem ? simItem.dataset.iccid : '');

        if (!action) return;

        const needsOrder = action === 'mpa_sync_order';
        if (needsOrder && !orderId) {
            showInline(box, 'error', MPA.i18n.error);
            return;
        }

        if (action === 'mpa_topup' && !window.confirm(MPA.i18n.confirmTopup)) return;

        const payload = { order_id: orderId, iccid: iccid };

        if (action === 'mpa_topup' && !btn.dataset.package) {
            const pkg = window.prompt('package_id del top-up (p.ej. meraki-mobile-7days-1gb-topup)');
            if (!pkg) return;
            payload.package_id = pkg;
        } else if (action === 'mpa_topup') {
            payload.package_id = btn.dataset.package;
        }

        if (action === 'mpa_refund_esim') {
            const form = btn.closest('.mpa-refund-form');
            if (!form) { showInline(box, 'error', MPA.i18n.error); return; }
            payload.reason = form.querySelector('.mpa-refund-reason').value;
            payload.notes = form.querySelector('.mpa-refund-notes').value;
            if (!payload.reason) { showInline(box, 'error', MPA.i18n.error); return; }
        }

        if (action === 'mpa_share_esim') {
            const form = btn.closest('.mpa-share-form');
            if (!form) { showInline(box, 'error', MPA.i18n.error); return; }
            const emailEl = form.querySelector('.mpa-share-email');
            const optEl = form.querySelector('.mpa-share-option');
            payload.email = emailEl ? emailEl.value : '';
            payload.sharing_option = optEl ? optEl.value : 'link';
            if (!payload.email) { showInline(box, 'error', MPA.i18n.error); return; }
        }

        if (action === 'mpa_assign_esim_user') {
            const form = btn.closest('.mpa-assign-form');
            if (!form) { showInline(box, 'error', MPA.i18n.error); return; }
            const nameEl  = form.querySelector('.mpa-assign-name');
            const emailEl = form.querySelector('.mpa-assign-email');
            payload.name  = nameEl  ? nameEl.value  : '';
            payload.email = emailEl ? emailEl.value : '';
            if (!payload.email) { showInline(box, 'error', MPA.i18n.error); return; }
        }

        if (action === 'mpa_refund_esim_by_iccid') {
            payload.reason = (btn.dataset.reason || 'OTHERS');
            payload.notes  = (btn.dataset.notes || '');
        }

        setBusy(btn, true);

        call(action, payload)
            .done(function (response) {
                if (response && response.success) {
                    const msg = (response.data && response.data.message) || 'OK';
                    if (action === 'mpa_topup' || action === 'mpa_refund_esim' || action === 'mpa_refund_esim_by_iccid' || action === 'mpa_share_esim' || action === 'mpa_assign_esim_user') {
                        window.location.href = MPA.ajaxUrl.replace('admin-ajax.php', 'admin.php') + '?page=mpa-esim-detail&iccid=' + encodeURIComponent(iccid) + '&mpa_action_done=' + encodeURIComponent(msg);
                        return;
                    }
                    showInline(box, 'success', msg);
                    if (action === 'mpa_get_usage' && response.data && response.data.data) {
                        console.log('[MPA] usage', response.data.data);
                        setTimeout(function () { window.location.reload(); }, 600);
                    }
                    if (action === 'mpa_get_qr' && response.data && response.data.data) {
                        console.log('[MPA] instructions', response.data.data);
                    }
                    if (action === 'mpa_sync_order') {
                        setTimeout(function () { window.location.reload(); }, 600);
                    }
                } else {
                    const msg = (response && response.data && response.data.message) || MPA.i18n.error;
                    showInline(box, 'error', msg);
                }
            })
            .fail(function (xhr) {
                let msg = MPA.i18n.error;
                try {
                    const r = JSON.parse(xhr.responseText);
                    if (r && r.data && r.data.message) msg = r.data.message;
                } catch (err) {}
                showInline(box, 'error', msg);
            })
            .always(function () {
                setBusy(btn, false);
            });
    });

    // Toggle refund form
    $(document).on('click', '.mpa-refund-toggle', function (e) {
        e.preventDefault();
        var form = this.nextElementSibling;
        if (form && form.classList.contains('mpa-refund-form')) {
            var isHidden = form.style.display === 'none' || form.style.display === '';
            form.style.display = isHidden ? 'block' : 'none';
            if (isHidden) { form.querySelector('.mpa-refund-reason').focus(); }
        }
    });

    // Toggle share eSIM form
    $(document).on('click', '.mpa-share-toggle', function (e) {
        e.preventDefault();
        var form = this.nextElementSibling;
        if (form && form.classList.contains('mpa-share-form')) {
            var isHidden = form.style.display === 'none' || form.style.display === '';
            form.style.display = isHidden ? 'block' : 'none';
            if (isHidden) { var i = form.querySelector('.mpa-share-email'); if (i) i.focus(); }
        }
    });

    // Toggle assign eSIM user form
    $(document).on('click', '.mpa-assign-toggle', function (e) {
        e.preventDefault();
        var form = this.nextElementSibling;
        if (form && form.classList.contains('mpa-assign-form')) {
            var isHidden = form.style.display === 'none' || form.style.display === '';
            form.style.display = isHidden ? 'block' : 'none';
            if (isHidden) { var i = form.querySelector('.mpa-assign-name'); if (i) i.focus(); }
        }
    });

    // Tabs
    $(document).on('click', '.mpa-tabs__tab', function () {
        const tab = this;
        const root = tab.closest('.mpa-tabs');
        if (!root) return;
        const name = tab.dataset.tab;
        root.querySelectorAll('.mpa-tabs__tab').forEach(function (t) { t.classList.toggle('is-active', t === tab); t.setAttribute('aria-selected', t === tab ? 'true' : 'false'); });
        root.querySelectorAll('.mpa-tabs__panel').forEach(function (p) { p.classList.toggle('is-active', p.dataset.panel === name); });
    });

    // Copy buttons
    $(document).on('click', '[data-copy]', function () {
        const target = document.querySelector(this.dataset.copy);
        if (!target) return;
        const text = target.textContent || target.value || '';
        const fallback = function () {
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); } catch (e) {}
            document.body.removeChild(ta);
        };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).catch(fallback);
        } else {
            fallback();
        }
        const original = this.textContent;
        this.textContent = MPA.i18n.copied;
        const self = this;
        setTimeout(function () { self.textContent = original; }, 1200);
    });
})(jQuery);
