// APPOINTMENT FILE ATTACHMENTS
//
// Upload (click-to-browse or drag-and-drop), delete (hard, behind a
// confirm() dialog), and preview (hover, or click/tap on touch devices)
// for the "Συνημμένα Αρχεία" section on each appointment. Talks to
// web/appointment_files.php's upload/delete/download routes via
// fetch()+FormData. Replaces file-upload.js + pdf-preview.js, which were
// both purely decorative -- neither ever actually talked to the server.

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
}

// Shared by both the click-to-browse `change` event and the `drop` event --
// one file list, one upload path, no duplicated validation/preview logic.
function handleFiles(fileList, uploadUrl, previewsContainer, existingFilesContainer) {
    for (let i = 0; i < fileList.length; i++) {
        uploadOneFile(fileList[i], uploadUrl, previewsContainer, existingFilesContainer);
    }
}

function uploadOneFile(file, uploadUrl, previewsContainer, existingFilesContainer) {
    const previewItem = createLocalPreview(file, previewsContainer);

    const formData = new FormData();
    formData.append('appointmentFile', file);

    fetch(uploadUrl, { method: 'POST', body: formData })
        .then(function(response) {
            return response.json().then(function(data) {
                return { ok: response.ok, data: data };
            });
        })
        .then(function(result) {
            if (result.ok && result.data && result.data.success) {
                previewItem.remove();
                appendExistingFileRow(existingFilesContainer, result.data.file);
            } else {
                showPreviewError(previewItem, (result.data && result.data.error) || 'Η μεταφόρτωση απέτυχε');
            }
        })
        .catch(function() {
            showPreviewError(previewItem, 'Σφάλμα δικτύου κατά τη μεταφόρτωση');
        });
}

function createLocalPreview(file, container) {
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
        const reader = new FileReader();
        reader.onload = function(e) { img.src = e.target.result; };
        reader.readAsDataURL(file);
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

    previewItem.appendChild(info);

    const status = document.createElement('div');
    status.className = 'preview-status';
    previewItem.appendChild(status);

    if (container) container.appendChild(previewItem);
    return previewItem;
}

function showPreviewError(previewItem, message) {
    previewItem.classList.add('preview-error');
    const status = previewItem.querySelector('.preview-status');
    if (status) status.textContent = message;
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
        trigger.innerHTML =
            '<div class="file-preview"><img src="' + file.download_url + '" alt="' + escapeHtml(file.file_name) + '"></div>' +
            '<div class="file-preview-popup"><img src="' + file.download_url + '" alt="' + escapeHtml(file.file_name) + '"></div>';
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
        if (previewItem) previewItem.remove();
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

        fetch(url, { method: 'POST' })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data && data.success && fileItem) {
                    fileItem.remove();
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
