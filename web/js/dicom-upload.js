/**
 * dicom-upload.js — Vanilla JS chunked AJAX uploader with progress bar.
 */
const DicomUploader = {

    chunkSize: 2 * 1024 * 1024, // 2 MB default, confirmed by server
    baseUrl: '/dicom/upload',

    async upload(file, callbacks) {
        callbacks = callbacks || {};
        var onProgress = callbacks.onProgress;
        var onStatus   = callbacks.onStatus;
        var onComplete = callbacks.onComplete;
        var onError    = callbacks.onError;

        try {
            // 1. Init
            if (onStatus) onStatus('Initializing upload…');
            var initResp = await this.postJSON(this.baseUrl + '/init', {
                filename:     file.name,
                filesize:     file.size,
                total_chunks: Math.ceil(file.size / this.chunkSize),
            });
            var upload_token = initResp.upload_token;
            var chunk_size   = initResp.chunk_size;
            if (chunk_size) this.chunkSize = chunk_size;

            // 2. Send chunks
            var totalChunks = Math.ceil(file.size / this.chunkSize);
            if (onStatus) onStatus('Uploading ' + totalChunks + ' chunks…');

            for (var i = 0; i < totalChunks; i++) {
                var start = i * this.chunkSize;
                var end   = Math.min(start + this.chunkSize, file.size);
                var blob  = file.slice(start, end);

                var fd = new FormData();
                fd.append('upload_token', upload_token);
                fd.append('chunk_index', i);
                fd.append('chunk', blob);

                await this.post(this.baseUrl + '/chunk', fd);
                if (onProgress) onProgress(Math.round(((i + 1) / totalChunks) * 90));
            }

            // 3. Finalize — server assembles + processes
            if (onStatus) onStatus('Processing DICOM exam…');
            if (onProgress) onProgress(92);

            var result = await this.postJSON(this.baseUrl + '/finalize', {
                upload_token: upload_token,
            });
            if (onProgress) onProgress(100);
            if (onComplete) onComplete(result);
            return result;

        } catch (err) {
            if (onError) onError(err.message || 'Upload failed');
            throw err;
        }
    },

    async postJSON(url, data) {
        var fd = new FormData();
        for (var k in data) {
            if (data.hasOwnProperty(k)) fd.append(k, data[k]);
        }
        return this.post(url, fd);
    },

    async post(url, formData) {
        var resp = await fetch(url, { method: 'POST', body: formData });
        var json = await resp.json();
        if (!resp.ok) throw new Error(json.error || 'Request failed');
        return json;
    }
};

// ─── UI Bindings ───────────────────────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', function() {
    var dropzone  = document.getElementById('upload-dropzone');
    var fileInput = document.getElementById('upload-file-input');
    var progress  = document.getElementById('upload-progress');
    var pctEl     = document.getElementById('upload-pct');
    var barEl     = document.getElementById('upload-bar');
    var statusEl  = document.getElementById('upload-status');
    var uploadBtn = document.getElementById('upload-btn');
    var fileInfo  = document.getElementById('upload-file-info');

    if (!dropzone) return; // not on upload page

    var selectedFile = null;

    // Drag-drop
    dropzone.addEventListener('dragover', function(e) {
        e.preventDefault();
        dropzone.classList.add('drag-over');
    });
    dropzone.addEventListener('dragleave', function() {
        dropzone.classList.remove('drag-over');
    });
    dropzone.addEventListener('drop', function(e) {
        e.preventDefault();
        dropzone.classList.remove('drag-over');
        if (e.dataTransfer.files.length > 0) selectFile(e.dataTransfer.files[0]);
    });

    // File input
    fileInput.addEventListener('change', function() {
        if (fileInput.files.length > 0) selectFile(fileInput.files[0]);
    });

    function selectFile(file) {
        selectedFile = file;
        fileInfo.textContent = file.name + ' (' + formatBytes(file.size) + ')';
        uploadBtn.disabled = false;
    }

    // Upload button
    uploadBtn.addEventListener('click', function() {
        if (!selectedFile) return;
        uploadBtn.disabled = true;
        progress.style.display = 'block';

        DicomUploader.upload(selectedFile, {
            onProgress: function(pct) {
                barEl.style.width = pct + '%';
                pctEl.textContent = pct + '%';
            },
            onStatus: function(msg) {
                statusEl.textContent = msg;
            },
            onComplete: function(result) {
                statusEl.textContent = 'Complete! Redirecting…';
                window.location.href = result.redirect;
            },
            onError: function(msg) {
                statusEl.textContent = 'Error: ' + msg;
                uploadBtn.disabled = false;
            }
        });
    });

    function formatBytes(bytes) {
        if (bytes < 1024)    return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / 1048576).toFixed(1) + ' MB';
    }
});
