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
