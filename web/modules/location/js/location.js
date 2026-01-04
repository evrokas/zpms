/* location places javascript */

ls = document.getElementById('location-selector');

//console.log( ls );

ls.addEventListener('change', (e) => {
    // console.log('selection changed', ls.value);
    // console.log('location selector: ', ls);
    // console.log('location selector: ', ls.dataset.ajaxCallback);
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
