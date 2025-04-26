function copyInfo(btn) {
    const name = btn.dataset.name;
    const street = btn.dataset.street;
    const town = btn.dataset.town;
    const postal = btn.dataset.postal;
    const amount = btn.dataset.amount;

    const text = `${name}\n${street}\n${town}\n${postal}\n${amount}`;
    navigator.clipboard.writeText(text).then(() => {
        alert("Copied to clipboard!");
    }).catch(err => {
        alert("Failed to copy.");
    });
}


function copyData0(el) {
    let data = el.dataset.info;
    const text = `${data}`;
    console.log('data: ' , text);
    navigator.clipboard.writeText(text).then(() => {
        console.log("Copy succesfull!");
    }).catch(err => {
        console.log("Copy failed!");
    });
}


function copyData(el) {
    const text = el.dataset.info;

    // Modern async clipboard API
    if (false && navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(() => {
        console.log('text copied successfully!');
        alert("Text copied to clipboard!");
    }).catch(err => {
        console.log('text copy failed!' + err);
        alert("Failed to copy: " + err);
      });
    } else {
      // Fallback method for older browsers
      const textArea = document.createElement("textarea");
      textArea.value = text;

      // Avoid scrolling to bottom
      textArea.style.position = "fixed";
      textArea.style.top = 0;
      textArea.style.left = 0;
      textArea.style.width = "2em";
      textArea.style.height = "2em";
      textArea.style.padding = 0;
      textArea.style.border = "none";
      textArea.style.outline = "none";
      textArea.style.boxShadow = "none";
      textArea.style.background = "transparent";

      document.body.appendChild(textArea);
      textArea.focus();
      textArea.select();
      textArea.setSelectionRange(0, 99999); /* For mobile devices */
  

      try {
        const successful = document.execCommand('copy');
        // console.log('text (textarea) copied successfully!');
        console.log(successful ? "Text copied to clipboard!" : "Failed to copy text.");
        // alert(successful ? "Text copied to clipboard!" : "Failed to copy text.");
      } catch (err) {
        // console.log('text (textarea) copy failed!');

        console.log("Fallback copy failed: " + err);
        // alert("Fallback copy failed: " + err);
      }

      document.body.removeChild(textArea);
    }
  }