// PDF PREVIEW FUNCTIONALITY (for existing PDF files)

document.addEventListener('DOMContentLoaded', function() {
    // This would typically be populated from server data
    // For demonstration, we'll simulate existing files
    
    const existingFilesContainers = document.querySelectorAll('.existing-files');
    
    existingFilesContainers.forEach(container => {
        // In a real application, you would fetch this data from the server
        // For now, we'll just set up the structure for when data is available
        
        // Example of how to add an existing file (commented out as it's just a template)
        /*
        const fileItem = document.createElement('div');
        fileItem.className = 'file-item';
        
        fileItem.innerHTML = `
            <div class="file-preview">
                <img src="path/to/thumbnail.jpg" alt="File preview">
            </div>
            <div class="file-info">
                <span class="file-name">medical_report.pdf</span>
                <span class="file-size">2.4 MB</span>
            </div>
            <div class="file-actions">
                <button class="view-btn" data-file="path/to/file.pdf">Προβολή</button>
                <button class="delete-btn">Διαγραφή</button>
            </div>
        `;
        
        container.appendChild(fileItem);
        */
    });
    
    // Handle view button clicks
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('view-btn')) {
            const filePath = e.target.getAttribute('data-file');
            // In a real application, this would open the file in a viewer/modal
            window.open(filePath, '_blank');
        }
    });
});