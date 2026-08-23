/*
 * loader specific js functions
 */

var loaderTimeouts = [];

function enableLoader(loader, form) {
    clearTimeout(loaderTimeouts[loader.id]);
    loader.classList.remove('success');
    loader.classList.remove('error');
    loader.classList.remove('active');

    // add pending status until timeout
    loader.classList.add('pending');
    loaderTimeouts[loader.id] = setTimeout( function () {
        var fdata = new FormData(form);
        fdata.append('use_ajax', '1');

        // pending form update is finished...
        loader.classList.remove('pending');
    
    
        // send form with fetch()...
        loader.classList.add('active');

        // set X-Requested-With header
        // test for header with, HTTP_X_REQUESTED_WITH
        // value XMLHttpRequest
        fetch(form.action, {
            'method': 'post',
            body: new URLSearchParams( fdata ),
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
        }).then((response) => {
            console.log('ajax response ', response);
            return (response)
        }).then((res) => {
            if(res.status === 200) {
                console.log('ajax request succesful!');
                loader.classList.remove('active');
                loader.classList.add('success');
            }
        }).catch((error) => {
            console.log('ajax request ERROR', error);
            loader.classList.remove('active');
            loader.classList.add('error');
        })
    }, 1000);
}

function update_form(e, form) {
    console.log( e, "has changed, new value: ", e.target.value);

    loader = form.querySelector('button[loader]');
    loadericon = document.getElementById(loader.attributes.loader.value);
    enableLoader(loadericon, form);
}



// on load of page setup input change handlers
window.addEventListener('load', (e) => {
    console.log('page loaded');

    loaders = document.querySelectorAll('.loader0');

    loaders.forEach(l => {
        loaderTimeouts[l.id] = 0;
    })

    loaders.forEach(l => {
        console.log( l.id );

        // add event handler for datetime and select inputs
        Inputs = document.querySelectorAll('input[loader=\"'+l.id+'\"],select[loader=\"'+l.id+'\"]');
        console.log( 'inputs: ', Inputs );
        Inputs.forEach(inp => {
            inp.addEventListener('input', (e) => {
                formnode = e.target.parentNode;
                while(formnode && (formnode.nodeName != "FORM")) {
                    formnode = formnode.parentNode;
                }

                update_form( e, formnode );
            });
        })


        // add event handler for textarea input
        Inputs = document.querySelectorAll('textarea[loader=\"'+l.id+'\"]');

        Inputs.forEach(inp => {
            inp.addEventListener('input', (e) => {
                formnode = e.target.parentNode;
                while(formnode && (formnode.nodeName != "FORM")) {
                    formnode = formnode.parentNode;
                }

                update_form( e, formnode );
            });
        })
    })
})