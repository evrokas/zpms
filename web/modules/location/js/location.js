/* location places javascript */

ls = document.getElementById('location-selector');

ls.addEventListener('change', (e) => {
    url = ls.dataset.ajaxCallback;

    var fdata = new FormData();
    fdata.append('location', ls.value);

    if(url)
    fetch( url, {
        'method': 'post',
        body: new URLSearchParams( fdata ),
        'headers': {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
    }).then((response) => {
        console.log( response );
        return(response);
    }).then((res) => {
        if(res.status === 200) {
            console.log('ajax location request successful');

        }

    }).catch((error) => {
        console.log('ajax request location error ', error);

    })
});
