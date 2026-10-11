/* comment-composer.js — slice 6 (comments-notifications.md divergence 1): a comment is plain text with @mentions. Typing "@" at the start of a word opens the
   people-and-agents picker (the same record picker every form uses); picking a name inserts "@Name " into the text; a second picker offers departments. The server
   turns "@Name" into the mention (the longest name it knows); nothing here decides who is mentioned. Delegated, so it works in HTMX-swapped panels. */
(function () {
    'use strict';
    function insert(ta, text) {
        var v = ta.value, s = ta.selectionStart == null ? v.length : ta.selectionStart, e = ta.selectionEnd == null ? s : ta.selectionEnd;
        if (ta.dataset.atPos !== undefined) {                       // the "@" that opened the picker is replaced by the name
            var at = parseInt(ta.dataset.atPos, 10);
            if (v.charAt(at) === '@') { s = at; e = at + 1; }
            delete ta.dataset.atPos;
        }
        ta.value = v.slice(0, s) + text + v.slice(e);
        var c = s + text.length;
        try { ta.setSelectionRange(c, c); } catch (x) { /* not focusable yet */ }
        ta.dispatchEvent(new Event('input', { bubbles: true }));
    }
    document.addEventListener('change', function (ev) {
        var h = ev.target;
        if (!h || !h.matches || !h.matches('input[data-picker-value][data-mention-for]') || !h.value) { return; }
        var field = h.closest('.record-picker-field');
        var labelEl = field.querySelector('.record-picker-label');
        var name = labelEl.textContent.trim();
        var ta = document.getElementById(h.dataset.mentionFor);
        h.value = '';                                               // the field is a chooser, not a value: ready for the next mention
        labelEl.textContent = field.dataset.pickerPlaceholder || 'Choose…';
        labelEl.classList.add('text-muted');
        var clear = field.querySelector('.record-picker-clear');
        if (clear) { clear.classList.add('d-none'); }
        if (!ta) { return; }
        insert(ta, '@' + name + ' ');
        var modal = document.getElementById('record-picker');
        if (modal) { modal.addEventListener('hidden.bs.modal', function () { ta.focus(); }, { once: true }); }
    });
    document.addEventListener('input', function (ev) {
        var ta = ev.target;
        if (!ta || !ta.matches || !ta.matches('textarea[data-mention-box]') || ev.inputType !== 'insertText' || ev.data !== '@') { return; }
        var pos = ta.selectionStart - 1;
        var prev = pos > 0 ? ta.value.charAt(pos - 1) : ' ';
        if (/[\p{L}\p{N}_]/u.test(prev)) { return; }                // "a@b" is an address, not a mention
        var btn = document.getElementById(ta.dataset.mentionPicker + '-open');
        if (!btn || btn.disabled) { return; }
        ta.dataset.atPos = String(pos);
        btn.click();
    });
})();
