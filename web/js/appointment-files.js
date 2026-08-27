// APPOINTMENT FILE ATTACHMENTS
//
// Upload (click-to-browse or drag-and-drop), delete (hard, behind a
// confirm() dialog), and preview (hover, or click/tap on touch devices)
// for the "Συνημμένα Αρχεία" section on each appointment. Talks to
// web/appointment_files.php's upload/delete/download routes via
// fetch()+FormData. Replaces file-upload.js + pdf-preview.js, which were
// both purely decorative -- neither ever actually talked to the server.

// Hard ceiling on how long a single upload's XMLHttpRequest is allowed to
// run before it's treated as failed -- see uploadOneFile()'s own comment
// for why this exists (in short: a request can genuinely take a long
// time to even START sending on a phone -- e.g. iOS still finishing an
// iCloud-backed photo's on-device download before the browser can read
// it at all -- and with no timeout, that reads as "nothing happens"
// forever instead of a clear, actionable error).
const APPOINTMENT_FILE_UPLOAD_TIMEOUT_MS = 120000; // 2 minutes

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.file-upload-section').forEach(initAppointmentFileSection);

    // Delegated so it keeps working for existing-file rows appended after
    // a successful upload, which can't be bound to individually up front.
    document.addEventListener('click', handleDelegatedClick);
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeAllPreviews();
    });
});

function initAppointmentFileSection(section) {
    // Collapsed by default -- server-rendered already .is-expanded when
    // the appointment has existing files (see view_appointment.zetem), so
    // this only ever needs to toggle, never set the initial state.
    const heading = section.querySelector('[data-toggle-attachments]');
    if (heading) {
        heading.addEventListener('click', function() {
            section.classList.toggle('is-expanded');
        });
    }

    const form = section.querySelector('.file-upload-form');
    const dropzone = section.querySelector('[data-dropzone]');
    const input = section.querySelector('.file-input');
    const previews = section.querySelector('.file-previews');
    const existingFiles = section.querySelector('.existing-files');

    if (!form || !dropzone || !input) return;

    const uploadUrl = form.getAttribute('data-upload-url');

    // Click-to-browse is native, via the <label for="..."> -- deliberately
    // no manual click handler on the dropzone/label, that would double-fire
    // the file picker.
    input.addEventListener('change', function(e) {
        handleFiles(e.target.files, uploadUrl, previews, existingFiles);
        input.value = ''; // allow re-selecting the same file later
    });

    ['dragover', 'dragenter'].forEach(function(evt) {
        dropzone.addEventListener(evt, function(e) {
            e.preventDefault();
            dropzone.classList.add('dropzone-active');
        });
    });
    dropzone.addEventListener('dragleave', function() {
        dropzone.classList.remove('dropzone-active');
    });
    dropzone.addEventListener('drop', function(e) {
        e.preventDefault();
        dropzone.classList.remove('dropzone-active');
        handleFiles(e.dataTransfer.files, uploadUrl, previews, existingFiles);
    });

    const pasteBtn = section.querySelector('[data-paste-clipboard]');
    const pasteStatus = section.querySelector('[data-paste-status]');
    const pasteDescription = section.querySelector('[data-paste-description]');
    if (pasteBtn) {
        if (!navigator.clipboard || !navigator.clipboard.read) {
            pasteBtn.disabled = true;
            pasteBtn.title = 'Δεν υποστηρίζεται από αυτόν τον browser';
        } else {
            pasteBtn.addEventListener('click', function() {
                pasteImageFromClipboard(pasteBtn, pasteStatus, pasteDescription, uploadUrl, previews, existingFiles);
            });
        }
    }
}

// "Paste from clipboard" button -- reads an image straight off the OS
// clipboard (e.g. a screenshot) via the async Clipboard API, synthesizes a
// filename since a pasted image has none, and feeds it through the exact
// same uploadOneFile() pipeline as a click-selected or dropped file.
// descriptionInput is the optional free-text note field next to the paste
// button (a pasted image, unlike a real file, has no name of its own to
// identify it later, so this is the one place staff are prompted for a
// description at upload time) -- read at click time, not bound once, so
// whatever the user just typed is what gets sent with this specific paste.
function pasteImageFromClipboard(button, statusEl, descriptionInput, uploadUrl, previewsContainer, existingFilesContainer) {
    if (statusEl) statusEl.textContent = '';
    button.disabled = true;

    const description = descriptionInput ? descriptionInput.value.trim() : '';

    navigator.clipboard.read().then(function(items) {
        for (let i = 0; i < items.length; i++) {
            const imageType = items[i].types.find(function(t) { return t.indexOf('image/') === 0; });
            if (imageType) {
                items[i].getType(imageType).then(function(blob) {
                    uploadOneFile(blobToFile(blob, imageType), uploadUrl, previewsContainer, existingFilesContainer, description);
                    if (descriptionInput) descriptionInput.value = '';
                });
                return;
            }
        }
        if (statusEl) statusEl.textContent = 'Δεν βρέθηκε εικόνα στο πρόχειρο';
    }).catch(function() {
        if (statusEl) statusEl.textContent = 'Δεν ήταν δυνατή η πρόσβαση στο πρόχειρο -- ελέγξτε τα δικαιώματα του browser';
    }).finally(function() {
        button.disabled = false;
    });
}

function blobToFile(blob, mimeType) {
    const extensions = { 'image/png': 'png', 'image/jpeg': 'jpg', 'image/webp': 'webp', 'image/gif': 'gif' };
    const ext = extensions[mimeType] || 'png';
    const stamp = new Date().toISOString().replace(/[:.]/g, '-');
    return new File([blob], 'pasted-image-' + stamp + '.' + ext, { type: mimeType });
}

// Shared by both the click-to-browse `change` event and the `drop` event --
// one file list, one upload path, no duplicated validation/preview logic.
function handleFiles(fileList, uploadUrl, previewsContainer, existingFilesContainer) {
    for (let i = 0; i < fileList.length; i++) {
        uploadOneFile(fileList[i], uploadUrl, previewsContainer, existingFilesContainer);
    }
}

function uploadOneFile(file, uploadUrl, previewsContainer, existingFilesContainer, description) {
    const previewItem = createLocalPreview(file, previewsContainer, description);
    const progressFill = previewItem.querySelector('.preview-progress-fill');
    const statusEl = previewItem.querySelector('.preview-status');

    const formData = new FormData();
    formData.append('appointmentFile', file);
    formData.append('csrf_token', csrfToken());
    if (description) formData.append('description', description);

    // XMLHttpRequest, not fetch() -- fetch() has no cross-browser way to
    // observe upload progress, so a large photo over a slow mobile
    // connection looked completely frozen: the local preview thumbnail
    // appears instantly (it's generated client-side, before any network
    // activity starts), then nothing visibly changes again until the
    // response finally comes back, however long that takes. There's no
    // way to tell "still working" from "stuck" in that gap. XHR's
    // upload.onprogress is the one place a real percentage is available,
    // and the timeout below means a genuinely stuck request -- e.g. iOS
    // still finishing an iCloud-backed photo's on-device download before
    // the browser can even read it, or a stalled connection -- surfaces
    // as a clear, actionable error instead of leaving the tile in limbo.
    const xhr = new XMLHttpRequest();
    xhr.open('POST', uploadUrl, true);
    xhr.timeout = APPOINTMENT_FILE_UPLOAD_TIMEOUT_MS;

    xhr.upload.addEventListener('progress', function(e) {
        if (!e.lengthComputable) return;
        const pct = Math.round((e.loaded / e.total) * 100);
        if (progressFill) progressFill.style.width = pct + '%';
        if (statusEl) statusEl.textContent = 'Μεταφόρτωση... ' + pct + '%';
    });

    xhr.addEventListener('load', function() {
        let data = null;
        try { data = JSON.parse(xhr.responseText); } catch (e) { /* handled below as a failure */ }

        if (xhr.status >= 200 && xhr.status < 300 && data && data.success) {
            removePreviewItem(previewItem);
            appendExistingFileRow(existingFilesContainer, data.file);
            updateFileCountBadge(existingFilesContainer.closest('.file-upload-section'));
        } else {
            showPreviewError(previewItem, (data && data.error) || 'Η μεταφόρτωση απέτυχε');
        }
    });

    xhr.addEventListener('error', function() {
        showPreviewError(previewItem, 'Σφάλμα δικτύου κατά τη μεταφόρτωση');
    });

    xhr.addEventListener('timeout', function() {
        showPreviewError(previewItem, 'Η μεταφόρτωση καθυστέρησε πολύ -- ελέγξτε τη σύνδεσή σας και δοκιμάστε ξανά');
    });

    xhr.send(formData);
}

function createLocalPreview(file, container, description) {
    const previewItem = document.createElement('div');
    previewItem.className = 'preview-item';

    const removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'preview-remove';
    removeBtn.innerHTML = '&times;';
    removeBtn.title = 'Ακύρωση';
    previewItem.appendChild(removeBtn);

    if (file.type.startsWith('image/')) {
        const img = document.createElement('img');
        img.className = 'preview-image';
        // Object URL, not FileReader.readAsDataURL() -- a data: URL means
        // holding the entire file re-encoded as a base64 string in memory
        // (~33% bigger than the file itself) for as long as the preview
        // tile exists. Desktop test uploads are usually small enough that
        // this never mattered, but a real phone camera photo can be
        // several MB, and iOS Safari's per-tab memory budget is far
        // tighter than desktop -- large enough uploads (especially a few
        // picked at once, each getting its own data: URL) have been seen
        // to make the tab reload mid-upload, silently aborting the
        // in-flight fetch() before the server ever received it. An object
        // URL just references the existing File's bytes with no copy.
        // Revoked in removePreviewItem() below once the tile is done with
        // it, whether that's a successful upload or a manual cancel.
        const objectUrl = URL.createObjectURL(file);
        previewItem.dataset.objectUrl = objectUrl;
        img.src = objectUrl;
        previewItem.appendChild(img);
    } else {
        const generic = document.createElement('div');
        generic.className = 'preview-pdf';
        generic.innerHTML = "<i class='bx bxs-file-pdf'></i>";
        previewItem.appendChild(generic);
    }

    const info = document.createElement('div');
    info.className = 'preview-info';

    const name = document.createElement('div');
    name.className = 'preview-name';
    name.textContent = file.name;
    info.appendChild(name);

    const size = document.createElement('div');
    size.className = 'preview-size';
    size.textContent = formatFileSize(file.size);
    info.appendChild(size);

    if (description) {
        const desc = document.createElement('div');
        desc.className = 'preview-description';
        desc.textContent = description;
        info.appendChild(desc);
    }

    previewItem.appendChild(info);

    // Thin progress bar, filled in by uploadOneFile()'s XHR
    // upload.onprogress handler -- the visible signal that a slow
    // upload is actually moving rather than stuck (see uploadOneFile()'s
    // own comment for why this exists).
    const progress = document.createElement('div');
    progress.className = 'preview-progress';
    const progressFill = document.createElement('div');
    progressFill.className = 'preview-progress-fill';
    progress.appendChild(progressFill);
    previewItem.appendChild(progress);

    const status = document.createElement('div');
    status.className = 'preview-status';
    status.textContent = 'Μεταφόρτωση...';
    previewItem.appendChild(status);

    if (container) container.appendChild(previewItem);
    return previewItem;
}

// Removes a local preview tile and releases its object URL (see
// createLocalPreview()) -- the one function both the successful-upload
// path and the manual "cancel" button go through, so neither can forget
// the revoke and leak the reference.
function removePreviewItem(previewItem) {
    if (previewItem.dataset.objectUrl) {
        URL.revokeObjectURL(previewItem.dataset.objectUrl);
    }
    previewItem.remove();
}

function showPreviewError(previewItem, message) {
    previewItem.classList.add('preview-error');
    const status = previewItem.querySelector('.preview-status');
    if (!status) return;

    // Built via DOM methods (not innerHTML) so `message` -- server-
    // supplied text (e.g. appointment_files.php's own error strings) --
    // stays safely text-only, same as the plain textContent assignment
    // this replaces.
    status.textContent = '';
    const icon = document.createElement('i');
    icon.className = 'bx bx-error-circle';
    status.appendChild(icon);
    status.appendChild(document.createTextNode(message));
}

function appendExistingFileRow(existingFilesContainer, file) {
    if (!existingFilesContainer) return;

    const noFiles = existingFilesContainer.querySelector('.no-files');
    if (noFiles) noFiles.remove();

    const item = document.createElement('div');
    item.className = 'file-item';
    item.setAttribute('data-file-id', file.id);

    const trigger = document.createElement('div');
    trigger.className = 'file-preview-trigger';
    if (file.is_image) {
        // The small list icon and the hover popup both use the generated
        // thumbnail (server falls back to the full image itself if one
        // wasn't made) -- only the View button and "open in new tab"
        // pull the full-size original.
        const thumbUrl = file.thumbnail_url || file.download_url;
        trigger.innerHTML =
            '<div class="file-preview"><img src="' + thumbUrl + '" alt="' + escapeHtml(file.file_name) + '"></div>' +
            '<div class="file-preview-popup"><img src="' + thumbUrl + '" alt="' + escapeHtml(file.file_name) + '"></div>';
    } else {
        trigger.innerHTML = "<div class=\"file-preview pdf-preview\"><i class='bx bxs-file-pdf'></i></div>";
    }
    item.appendChild(trigger);

    const info = document.createElement('div');
    info.className = 'file-info';
    const nameSpan = document.createElement('span');
    nameSpan.className = 'file-name';
    nameSpan.textContent = file.file_name;
    const sizeSpan = document.createElement('span');
    sizeSpan.className = 'file-size';
    sizeSpan.textContent = formatFileSize(file.size);
    info.appendChild(nameSpan);
    info.appendChild(sizeSpan);
    if (file.description) {
        const descSpan = document.createElement('span');
        descSpan.className = 'file-description';
        descSpan.textContent = file.description;
        info.appendChild(descSpan);
    }
    item.appendChild(info);

    const actions = document.createElement('div');
    actions.className = 'file-actions';

    const viewBtn = document.createElement('button');
    viewBtn.type = 'button';
    viewBtn.className = 'view-btn';
    viewBtn.setAttribute('data-file', file.download_url);
    viewBtn.textContent = 'Προβολή';
    actions.appendChild(viewBtn);

    const deleteBtn = document.createElement('button');
    deleteBtn.type = 'button';
    deleteBtn.className = 'delete-btn';
    deleteBtn.setAttribute('data-delete-url', file.delete_url);
    deleteBtn.textContent = 'Διαγραφή';
    actions.appendChild(deleteBtn);

    item.appendChild(actions);
    existingFilesContainer.appendChild(item);
}

function handleDelegatedClick(e) {
    const removeBtn = e.target.closest('.preview-remove');
    if (removeBtn) {
        const previewItem = removeBtn.closest('.preview-item');
        if (previewItem) removePreviewItem(previewItem);
        return;
    }

    const viewBtn = e.target.closest('.view-btn');
    if (viewBtn) {
        const filePath = viewBtn.getAttribute('data-file');
        if (filePath) window.open(filePath, '_blank');
        return;
    }

    const deleteBtn = e.target.closest('.delete-btn');
    if (deleteBtn) {
        if (!window.confirm('Η διαγραφή του αρχείου είναι οριστική. Θέλετε σίγουρα να συνεχίσετε;')) return;

        const url = deleteBtn.getAttribute('data-delete-url');
        const fileItem = deleteBtn.closest('.file-item');
        deleteBtn.disabled = true;

        fetch(url, { method: 'POST', headers: { 'X-CSRF-Token': csrfToken() } })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data && data.success && fileItem) {
                    const section = fileItem.closest('.file-upload-section');
                    fileItem.remove();
                    updateFileCountBadge(section);
                    if (section) {
                        const existingFiles = section.querySelector('.existing-files');
                        if (existingFiles && !existingFiles.querySelector('.file-item') && !existingFiles.querySelector('.no-files')) {
                            const noFiles = document.createElement('p');
                            noFiles.className = 'no-files';
                            noFiles.textContent = 'Δεν υπάρχουν συνημμένα αρχεία';
                            existingFiles.appendChild(noFiles);
                        }
                    }
                } else {
                    deleteBtn.disabled = false;
                    window.alert('Η διαγραφή απέτυχε');
                }
            })
            .catch(function() {
                deleteBtn.disabled = false;
                window.alert('Σφάλμα δικτύου κατά τη διαγραφή');
            });
        return;
    }

    // File preview trigger: click/tap toggles an "is-active" class -- the
    // hover case is handled by CSS alone, this is the touch/click fallback.
    // A PDF trigger has no popup to toggle, so clicking it opens the file
    // in a new tab instead (same as the View button), rather than faking a
    // preview it can't show.
    const trigger = e.target.closest('.file-preview-trigger');
    if (trigger) {
        const popup = trigger.querySelector('.file-preview-popup');
        if (popup) {
            const wasActive = trigger.classList.contains('is-active');
            closeAllPreviews();
            if (!wasActive) trigger.classList.add('is-active');
        } else {
            const fileItem = trigger.closest('.file-item');
            const fileViewBtn = fileItem && fileItem.querySelector('.view-btn');
            if (fileViewBtn) {
                const filePath = fileViewBtn.getAttribute('data-file');
                if (filePath) window.open(filePath, '_blank');
            }
        }
        return;
    }

    // Any other click closes an open preview popup (outside-click dismiss).
    closeAllPreviews();
}

// Keeps the heading's file-count badge in sync after an upload/delete
// completes without a page reload -- adds it on the first upload into an
// empty section, removes it once the last file is deleted.
function updateFileCountBadge(section) {
    if (!section) return;
    const heading = section.querySelector('.file-upload-section-heading');
    if (!heading) return;

    const count = section.querySelectorAll('.existing-files .file-item').length;
    let badge = heading.querySelector('.file-count-badge');

    if (count > 0) {
        if (!badge) {
            badge = document.createElement('span');
            badge.className = 'file-count-badge';
            heading.appendChild(badge);
        }
        badge.textContent = count;
    } else if (badge) {
        badge.remove();
    }
}

function closeAllPreviews() {
    document.querySelectorAll('.file-preview-trigger.is-active').forEach(function(el) {
        el.classList.remove('is-active');
    });
}

function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}
