{{--
    The confirmation names what will change in PumpIT (Ryan, 12 Nov 2026:
    "agora is replacing pumpit, always write back to pumpit with confirmation").

    Every People form that writes back carries `data-confirm` (so
    confirm-form.js puts the SweetAlert2 dialog in front of the submit) and
    `data-diff="<table> · <whose>"`. Its controls say what they are:

      data-diff-field="IsActive"  data-diff-old="1"   a column — on the control
          or on an <x-field>'s wrapper, whose control is then used; a checkbox
          reads its data-diff-on / data-diff-off words, a select the text of
          its options, anything else its value
      data-diff-area="Fuel bay"   data-diff-old="1"   one counting area, ticked
          or not

    Before each submit this writes the dialog's text — "BRN_Employee ·
    TPW01 Zulu Thandi: PostNo Fuel Cashier → Site Manager" — into
    data-confirm-text, which confirm-form.js reads at the moment it asks. It
    runs in the capture phase so it is written before that handler reads it.
    Nothing changed reads as such, and the server still decides (STALE,
    NOTHING_CHANGED). Without JavaScript the form's own static text stands.
--}}
<script>
    document.addEventListener('DOMContentLoaded', function () {
        var control = function (el) {
            return el.matches('input, select, textarea') ? el
                : el.querySelector('input[type=checkbox], select, textarea, input:not([type=hidden])');
        };
        var show = function (el, c, raw) {
            if (c.type === 'checkbox') return raw === '1' ? (el.dataset.diffOn || 'Yes') : (el.dataset.diffOff || 'No');
            if (c.tagName === 'SELECT') {
                var o = Array.prototype.find.call(c.options, function (x) { return x.value === raw; });
                return o ? o.text : (raw || 'blank');
            }
            return raw === '' || raw == null ? 'blank' : raw;
        };
        var now = function (c) { return c.type === 'checkbox' ? (c.checked ? '1' : '0') : (c.value || '').trim(); };
        document.querySelectorAll('form[data-diff]').forEach(function (form) {
            form.addEventListener('submit', function () {
                var lines = [];
                form.querySelectorAll('[data-diff-field]').forEach(function (el) {
                    var c = control(el);
                    if (!c) return;
                    var was = el.dataset.diffOld || '', is = now(c);
                    if (was !== is) lines.push(el.dataset.diffField + ': ' + show(el, c, was) + ' → ' + show(el, c, is));
                });
                var added = [], removed = [];
                form.querySelectorAll('[data-diff-area]').forEach(function (el) {
                    var was = el.dataset.diffOld === '1';
                    if (el.checked && !was) added.push(el.dataset.diffArea);
                    if (!el.checked && was) removed.push(el.dataset.diffArea);
                });
                if (added.length) lines.push('Area added: ' + added.join(', '));
                if (removed.length) lines.push('Area removed: ' + removed.join(', '));
                form.dataset.confirmText = form.dataset.diff + '\n'
                    + (lines.length ? lines.join('\n') : 'Nothing will change.')
                    + (form.dataset.diffNote ? '\n\n' + form.dataset.diffNote : '');
            }, true);
        });
    });
</script>
