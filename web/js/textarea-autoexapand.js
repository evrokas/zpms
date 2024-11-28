ta=document.querySelectorAll('textarea[autoexpand]');

// console.log(ta);


// add event listener when text changes
ta.forEach((t) =>{
    // console.log('add event listener for ',  t);
    t.addEventListener('keyup', (el) => {
        while(el.target.clientHeight<el.target.scrollHeight) {
            el.target.style.height = el.target.clientHeight + 10 + 'px';
        }
            // console.log(el.target.clientHeight, el.target.scrollHeight);
            
        });
});


// take care of heights when page loads
ta.forEach(el =>{

    while(el.clientHeight<el.scrollHeight) {
        el.style.height = el.clientHeight + 16 + 'px';
    }
});