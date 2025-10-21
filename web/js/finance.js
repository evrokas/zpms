// invoice scripts
document.addEventListener("DOMContentLoaded", () => {
    const uploadForm = document.getElementById("uploadForm");
    const pdfFileInput = document.getElementById("pdfFile");
    const dropZone = document.getElementById("dropZone");
    const resultDiv = document.getElementById("result");

    // Function to upload file
    const uploadFile = (file) => {
        const formData = new FormData();
        formData.append("pdfFile", file);

        resultDiv.textContent = "Uploading file... Please wait.";

        fetch(uploadForm.action, {
            method: "POST",
            body: formData,
        })
            .then((response) => response.text())
            .then((data) => {
                if(data) {
                    console.log("response:" , data);
                    try {
                        resp = JSON.parse(data);
                        resultDiv.innerHTML = data;

                        // console.log("response:" , resp);
                        // pname = document.querySelector('.webform .form_elements input[name="patient_id"');
                        // pname.value = data.
                        console.log(resp.document_type);
        
                    } catch(e) {
                        resultDiv.innerHTML = e;
                        console.error(e);
                    }
                }



                // location.reload();
            })
            .catch((error) => {
                resultDiv.textContent = "An error occurred during upload.";
                console.error(error);
            });
    };

    // Automatically upload file when selected via dialog
    pdfFileInput.addEventListener("change", (event) => {
        if (pdfFileInput.files.length > 0) {
            uploadFile(pdfFileInput.files[0]);
        }
    });

    // Drag-and-drop functionality
    dropZone.addEventListener("dragover", (event) => {
        event.preventDefault();
        dropZone.classList.add("highlight");
    });

    dropZone.addEventListener("dragleave", () => {
        dropZone.classList.remove("highlight");
    });

    dropZone.addEventListener("drop", (event) => {
        event.preventDefault();
        dropZone.classList.remove("highlight");

        if (event.dataTransfer.files.length > 0) {
            const file = event.dataTransfer.files[0];
            if (file.type === "application/pdf") {
                uploadFile(file);
            } else {
                resultDiv.textContent = "Please upload a valid PDF file.";
            }
        }
    });
});
