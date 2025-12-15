// ATTACHMENTS SIDEBAR FUNCTIONALITY - Draggable separator and collapsible sidebar

document.addEventListener('DOMContentLoaded', function() {
    // Initialize all appointment split containers
    const splitContainers = document.querySelectorAll('.appointment-split-container');
    
    splitContainers.forEach(container => {
        const separator = container.querySelector('.split-separator');
        const formSection = container.querySelector('.appointment-form-section');
        const attachmentsSidebar = container.querySelector('.attachments-sidebar');
        const toggleButton = container.querySelector('.toggle-attachments');
        
        // Set initial widths (60% form, 40% attachments)
        formSection.style.flex = '3'; // 60%
        attachmentsSidebar.style.flex = '2'; // 40%
        
        // Initialize drag functionality for separator
        initSeparatorDrag(separator, formSection, attachmentsSidebar);
        
        // Initialize toggle functionality for sidebar
        initSidebarToggle(toggleButton, attachmentsSidebar);
    });
    
    // Initialize file upload functionality for all appointment file inputs
    const fileInputs = document.querySelectorAll('.file-input');
    
    fileInputs.forEach(input => {
        const appointmentId = input.id.split('-')[2]; // Extract appointment ID
        const previewContainer = document.getElementById(`file-previews-${appointmentId}`);
        const uploadLabel = input.previousElementSibling;
        
        // Handle file selection
        input.addEventListener('change', function(e) {
            handleFiles(e.target.files, previewContainer);
            // Reset the input to allow selecting the same file again
            input.value = '';
        });
        
        // Drag and drop functionality
        uploadLabel.addEventListener('dragover', function(e) {
            e.preventDefault();
            uploadLabel.classList.add('dragover');
        });
        
        uploadLabel.addEventListener('dragleave', function() {
            uploadLabel.classList.remove('dragover');
        });
        
        uploadLabel.addEventListener('drop', function(e) {
            e.preventDefault();
            uploadLabel.classList.remove('dragover');
            handleFiles(e.dataTransfer.files, previewContainer);
        });
    });
    
    // Handle file removal
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('preview-remove')) {
            const previewItem = e.target.closest('.preview-item');
            previewItem.remove();
        }
        
        if (e.target.classList.contains('delete-btn')) {
            const fileItem = e.target.closest('.file-item');
            // In a real application, you would send a request to delete the file from the server
            fileItem.remove();
        }
    });
});

// Initialize draggable separator functionality
function initSeparatorDrag(separator, formSection, attachmentsSidebar) {
    let isResizing = false;
    
    separator.addEventListener('mousedown', function(e) {
        isResizing = true;
        document.body.style.cursor = 'col-resize';
        document.body.style.userSelect = 'none';
        
        // For vertical layout on mobile
        if (window.innerWidth <= 992) {
            document.body.style.cursor = 'row-resize';
        }
    });
    
    document.addEventListener('mousemove', function(e) {
        if (!isResizing) return;
        
        const container = separator.parentElement;
        const containerRect = container.getBoundingClientRect();
        
        if (window.innerWidth > 992) {
            // Horizontal layout - resize width
            const newFormWidth = ((e.clientX - containerRect.left) / containerRect.width) * 100;
            const newAttachmentsWidth = 100 - newFormWidth;
            
            // Apply constraints (min 30% for each section)
            if (newFormWidth >= 30 && newAttachmentsWidth >= 30) {
                formSection.style.flex = `0 0 ${newFormWidth}%`;
                attachmentsSidebar.style.flex = `0 0 ${newAttachmentsWidth}%`;
            }
        } else {
            // Vertical layout - resize height
            const newFormHeight = ((e.clientY - containerRect.top) / containerRect.height) * 100;
            const newAttachmentsHeight = 100 - newFormHeight;
            
            // Apply constraints (min 30% for each section)
            if (newFormHeight >= 30 && newAttachmentsHeight >= 30) {
                formSection.style.flex = `0 0 ${newFormHeight}%`;
                attachmentsSidebar.style.flex = `0 0 ${newAttachmentsHeight}%`;
            }
        }
    });
    
    document.addEventListener('mouseup', function() {
        isResizing = false;
        document.body.style.cursor = '';
        document.body.style.userSelect = '';
    });
    
    // Handle touch events for mobile devices
    separator.addEventListener('touchstart', function(e) {
        isResizing = true;
        e.preventDefault();
    });
    
    document.addEventListener('touchmove', function(e) {
        if (!isResizing) return;
        
        const container = separator.parentElement;
        const containerRect = container.getBoundingClientRect();
        const touch = e.touches[0];
        
        if (window.innerWidth > 992) {
            // Horizontal layout - resize width
            const newFormWidth = ((touch.clientX - containerRect.left) / containerRect.width) * 100;
            const newAttachmentsWidth = 100 - newFormWidth;
            
            // Apply constraints (min 30% for each section)
            if (newFormWidth >= 30 && newAttachmentsWidth >= 30) {
                formSection.style.flex = `0 0 ${newFormWidth}%`;
                attachmentsSidebar.style.flex = `0 0 ${newAttachmentsWidth}%`;
            }
        } else {
            // Vertical layout - resize height
            const newFormHeight = ((touch.clientY - containerRect.top) / containerRect.height) * 100;
            const newAttachmentsHeight = 100 - newFormHeight;
            
            // Apply constraints (min 30% for each section)
            if (newFormHeight >= 30 && newAttachmentsHeight >= 30) {
                formSection.style.flex = `0 0 ${newFormHeight}%`;
                attachmentsSidebar.style.flex = `0 0 ${newAttachmentsHeight}%`;
            }
        }
    });
    
    document.addEventListener('touchend', function() {
        isResizing = false;
    });
}

// Initialize sidebar toggle functionality
function initSidebarToggle(toggleButton, attachmentsSidebar) {
    toggleButton.addEventListener('click', function() {
        attachmentsSidebar.classList.toggle('collapsed');
        
        // Update toggle button icon
        const icon = toggleButton.querySelector('i');
        if (attachmentsSidebar.classList.contains('collapsed')) {
            icon.className = 'bx bx-chevron-left';
        } else {
            icon.className = 'bx bx-chevron-right';
        }
    });
}

// File handling functions (copied from file-upload.js for completeness)
function handleFiles(files, previewContainer) {
    for (let i = 0; i < files.length; i++) {
        const file = files[i];
        createPreview(file, previewContainer);
    }
}

function createPreview(file, container) {
    const previewItem = document.createElement('div');
    previewItem.className = 'preview-item';
    
    const removeBtn = document.createElement('button');
    removeBtn.className = 'preview-remove';
    removeBtn.innerHTML = '×';
    removeBtn.title = 'Remove file';
    
    const previewInfo = document.createElement('div');
    previewInfo.className = 'preview-info';
    
    const fileName = document.createElement('div');
    fileName.className = 'preview-name';
    fileName.textContent = file.name;
    
    const fileSize = document.createElement('div');
    fileSize.className = 'preview-size';
    fileSize.textContent = formatFileSize(file.size);
    
    previewInfo.appendChild(fileName);
    previewInfo.appendChild(fileSize);
    
    previewItem.appendChild(removeBtn);
    
    // Create different preview based on file type
    if (file.type.startsWith('image/')) {
        const img = document.createElement('img');
        img.className = 'preview-image';
        
        const reader = new FileReader();
        reader.onload = function(e) {
            img.src = e.target.result;
        };
        reader.readAsDataURL(file);
        
        previewItem.appendChild(img);
    } else if (file.type === 'application/pdf') {
        const pdfPreview = document.createElement('div');
        pdfPreview.className = 'preview-pdf';
        pdfPreview.innerHTML = '<i class="bx bxs-file-pdf"></i><span>PDF Document</span>';
        previewItem.appendChild(pdfPreview);
    } else {
        const genericPreview = document.createElement('div');
        genericPreview.className = 'preview-pdf';
        genericPreview.innerHTML = '<i class="bx bxs-file"></i><span>Document</span>';
        previewItem.appendChild(genericPreview);
    }
    
    previewItem.appendChild(previewInfo);
    container.appendChild(previewItem);
}

function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}