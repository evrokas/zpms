/*
 * loader specific js functions
 */

var loaderTimeouts = [];

function enableLoader(loader, form) {
    // console.log('enabling loader', loader);

    // loaderTimeouts[loader.id]['form'] = form;

    clearTimeout(loaderTimeouts[loader.id]);
    loader.classList.remove('success');
    loader.classList.remove('error');
    loader.classList.remove('active');

    // add pending status until timeout
    loader.classList.add('pending');
    loaderTimeouts[loader.id] = setTimeout( function () {
        // console.log('loader timeout', loader);
        // console.log('loader form', form, loaderTimeouts[loader.id]['form']);
        // loader.classList.remove('active');

        var fdata = new FormData(form);
        fdata.append('use_ajax', '1');
        
        // console.log(form.action);
        
        // console.log(fdata);
        // pending form update is finished...
        loader.classList.remove('pending');
    
    
        // send form with fetch()...
        loader.classList.add('active');

        fetch(form.action, {
            'method': 'post',
            body: new URLSearchParams( fdata ),
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
        }).then((response) => {
            console.log(response);
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

// function field_update(e) {
//     // e.preventDefault();
//     console.log('clicked ', e);
//     // console.log('target: ', e.attributes.loader);
//     // console.log('parent: ', e.parentElement);
//     loader = document.getElementById(e.attributes.loader.value);
//     console.log('loader: ', loader);
//     loader.classList.toggle('active');
//     // console.log(loaderTimeouts[loader.id]);

//     enableLoader(loader);
// }

function update_form(e, form) {
    // console.log( e, "has changed, new value: ", e.target.value);
    // console.log( 'Form node: ', form);

    loader = form.querySelector('button[loader]');
    // console.log('loader element: ', loader);
    loadericon = document.getElementById(loader.attributes.loader.value);
    // console.log('loader icon: ', loadericon);
    enableLoader(loadericon, form);
}



// on load of page setup input change handlers
window.addEventListener('load', (e) => {
    console.log('page loaded');

    // loaders = document.querySelectorAll('button[loader]');
    loaders = document.querySelectorAll('.loader0');
    // console.log( loaders );
  
    loaders.forEach(l => {
        // console.log( l );
        loaderTimeouts[l.id] = 0;

        // loaderTimeouts.push({[l.attributes.loader.value]:0});
    })

    // console.log(loaderTimeouts);

    loaders.forEach(l => {
        // console.log( l.id );

        // add event handler for datetime and select inputs
        Inputs = document.querySelectorAll('input[loader=\"'+l.id+'\"],select[loader=\"'+l.id+'\"]');
        // console.log( 'inputs: ', Inputs );
        Inputs.forEach(inp => {
            inp.addEventListener('input', (e) => {
                // console.log( e, "has changed, new value: ", e.target.value);

                formnode = e.target.parentNode;
                while(formnode && (formnode.nodeName != "FORM")) {
                    // console.log(formnode);    
                    formnode = formnode.parentNode;
                }

                update_form( e, formnode );
            });
        })


        // add event handler for textarea input
        Inputs = document.querySelectorAll('textarea[loader=\"'+l.id+'\"]');
        // console.log( 'inputs: ', Inputs );

        Inputs.forEach(inp => {
            inp.addEventListener('input', (e) => {
                // console.log( e, "has changed, new value: ", e.target.value);
                formnode = e.target.parentNode;
                while(formnode && (formnode.nodeName != "FORM")) {
                    // console.log(formnode);    
                    formnode = formnode.parentNode;
                }

                update_form( e, formnode );
            });
        })
    })
})