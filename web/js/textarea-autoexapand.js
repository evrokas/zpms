/*
 * find here autoexapand functionality for textareas
 * 
 */

// take care of heights when page loads
document.addEventListener('DOMContentLoaded', function() {
    ta=document.querySelectorAll('textarea[autoexpand]');
    // console.log(ta);

    // add event listener when text changes
    ta.forEach((t) =>{
        // console.log('add event listener for ',  t);
        t.addEventListener('keyup', (el) => {
            console.log('pre style.height: ', el.target.style.height, 'target clientHeight: ', el.target.clientHeight, ' target.scrollHeight: ', el.target.scrollHeight);

            if(el.target.clientHeight < el.target.scrollHeight)
                el.target.style.height = el.target.scrollHeight + 'px';
            // console.log('post style.height: ', el.target.style.height, 'target clientHeight: ', el.target.clientHeight, ' target.scrollHeight: ', el.target.scrollHeight);
        });
    });

    // initialize textareas height
    ta.forEach(el =>{
        el.style.height = el.scrollHeight + 'px';
        // console.log('load heights: ', el, el.style.height);
    });
});