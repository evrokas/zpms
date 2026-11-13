/*
 * Notes box on /profile: a Quill editor whose content autosaves exactly
 * like the patient fields do. loader.js owns the timer (1s after the last
 * change; every keystroke restarts it) and the pending/active/success/error
 * spinner. This file only copies the editor's HTML into the form's hidden
 * "notes" field on every change and hands the form to update_form().
 *
 * The server cleans the HTML again (zpms_sanitize_notes_html()) -- the
 * formats list below only keeps the toolbar and pasting tidy, it is not
 * the security boundary.
 */
(function () {
    var container = document.getElementById('profile-notes-editor');
    var form = document.getElementById('profile-notes-form');
    var field = document.getElementById('profile-notes-value');
    if (!container || !form || !field || typeof Quill === 'undefined') return;

    var quill = new Quill(container, {
        theme: 'snow',
        placeholder: container.dataset.placeholder || '',
        modules: {
            toolbar: [
                [{ header: [2, 3, false] }],
                ['bold', 'italic', 'underline', 'strike'],
                [{ color: [] }, { background: [] }],
                [{ list: 'ordered' }, { list: 'bullet' }, { indent: '-1' }, { indent: '+1' }],
                [{ align: [] }],
                ['blockquote', 'link'],
                ['clean']
            ]
        },
        formats: ['header', 'bold', 'italic', 'underline', 'strike', 'color', 'background',
                  'list', 'indent', 'align', 'blockquote', 'link']
    });

    function currentHtml() {
        // An emptied editor still holds <p><br></p>; store it as nothing.
        return quill.getText().trim() === '' ? '' : quill.root.innerHTML;
    }

    var loaderIcon = document.getElementById('notes-loader');

    quill.on('text-change', function (delta, oldDelta, source) {
        if (source !== 'user') return;
        field.value = currentHtml();
        update_form({ target: field }, form);     // loader.js
    });

    // Leaving the page while a save is still waiting would drop the last
    // few keystrokes: send them now instead, without waiting for a reply.
    function flushPending() {
        if (!loaderIcon || !loaderIcon.classList.contains('pending')) return;
        clearTimeout(loaderTimeouts[loaderIcon.id]);   // loader.js
        loaderIcon.classList.remove('pending');
        var data = new FormData(form);
        data.append('use_ajax', '1');
        var url = form.getAttribute('action');  // form.action is the hidden "action" input
        if (navigator.sendBeacon) {
            navigator.sendBeacon(url, data);
        } else {
            fetch(url, { method: 'post', body: data, keepalive: true });
        }
    }
    window.addEventListener('pagehide', flushPending);
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') flushPending();
    });
})();
