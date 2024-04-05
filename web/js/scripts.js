
// var field = document.getElementById('datepicker');
//     if(field != null) {

//         var picker = new Pikaday({
//         onSelect: function(date) {
//                 field.value = picker.toString();
//             }
//         });
//         field.parentNode.insertBefore(picker.el, field.nextSibling);
//     }

console.log('scripts.js is loaded!');

search = document.querySelectorAll('.select2');
console.log( search );

var xhr = new XMLHttpRequest(),
    box2 = document.getElementById('select-box');
var timeout;
var basepath = '';

if(search.length > 0) {
    var el = search[0];
    // console.log( 'attach event for select2 node change');
    el.addEventListener('keyup', (ev) => {
        if(ev.key == "Escape") {
            box2.style.display = 'none';
            return;
        }
        if(el.value.length>0) {
            box2.style.display = 'block';
            // console.log(el.value);

            xhr.onreadystatechange = function() {
                if((this.readyState == 4) && (this.status == 200)) {
                    var response = JSON.parse(this.responseText);
                    var list = response['list'];

                    console.log("AJAX response: ");
                    // console.log( $list);
                    console.log( response );
                    box2.innerHTML = '';
                    basepath = response['referer'];

                    list.forEach(el => {
                        box2.innerHTML += "<li onclick=\"selectclick(this)\" data-url=\""+el['link']+"\"data-id=\""+el['id']+"\" data-name=\""+el['name']+"\">"+
                        "<span class=\"name\">"+el['name']+"</span>"+
                        // "<span class=\"age\">"+"["+el['age']+" έτη]"+"</span>"+
                        "<span class=\"tel\">"+"{Τηλ:"+el['tel']+"}"+"</span>"+
                        "<span class=\"amka\">"+"ΑΜΚΑ: "+el['amka']+"</span>"+
                        "</li>";
                    })
                    
                    // $list.forEach(el => {
                    //     box.value += el['name'] + "\n";
                    // });
                }
            }

            clearTimeout(timeout);
            timeout = setTimeout(function () {
                // xhr.open("POST", '/apps/zeus/web/patients/searchajax/term');
                xhr.open("POST", 'patients/searchajax/term');
                xhr.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
                xhr.send("sterm=" + el.value);
            }, (el.value.length<2)?0:200);  /* if length is less than 2 send request immediately */
        } else {
            box2.style.display = 'none';
        }
    });
}

function selectclick(e){ 
    // console.log( "Clicked "  + e.innerHTML);
    search[0].value = e.dataset.name;    //innerHTML;
    // document.getElementById();
    box2.style.display = 'none';

    // console.log( window.location.href);
    // console.log( basepath );
    // console.log( basepath + e.dataset.url);
    window.location.replace( basepath + e.dataset.url );
}

function appointmentsExpandAll() {
    lis = document.querySelectorAll('ul.patient-appointments-list > li');
    lis.forEach(el => {
        // console.log(el.querySelectorAll('.appointment-entry input[type="checkbox"]'));
        el.querySelectorAll('.appointment-entry input[type="checkbox"]')[0].checked = true;
    });
    
    // console.log(lis);
}

function appointmentsCollapseAll() {
    lis = document.querySelectorAll('ul.patient-appointments-list > li');
    lis.forEach(el => {
        // console.log(el.querySelectorAll('.appointment-entry input[type="checkbox"]'));
        el.querySelectorAll('.appointment-entry input[type="checkbox"]')[0].checked = false;
    });
    
    // console.log(lis);
}

var elementTimeout;

function saveTimeout(element) {
    console.log( element );
}

function startTimeout() {
    clearTimeout(elementTimeout);
    elementTimeout = timeout(document.querySelector('.appointment-entry')[0], 2000);
}

textAreas = document.querySelectorAll('textarea');
console.log(textAreas);

var loaderTimeouts = [];

function enableLoader(loader) {
    console.log('enabling loader', loader);
    
    clearTimeout(loaderTimeouts[loader.id]);
    loaderTimeouts[loader.id] = setTimeout( function () {
        console.log('loader timeout');
        loader.classList.toggle('active');
    }, 1000);


}

function field_update(e) {
    // e.preventDefault();
    console.log('clicked ', e);
    // console.log('target: ', e.attributes.loader);
    // console.log('parent: ', e.parentElement);
    loader = document.getElementById(e.attributes.loader.value);
    console.log('loader: ', loader);
    loader.classList.toggle('active');
    // console.log(loaderTimeouts[loader.id]);

    enableLoader(loader);
}

window.addEventListener('load', (e) => {
    console.log('page loaded');

    // loaders = document.querySelectorAll('button[loader]');
    loaders = document.querySelectorAll('.loader0');
    console.log( loaders );
  
    loaders.forEach(l => {
        console.log( l );
        loaderTimeouts[l.id] = 0;

        // loaderTimeouts.push({[l.attributes.loader.value]:0});
    })

    console.log(loaderTimeouts);
})