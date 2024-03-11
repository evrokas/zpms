
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
                    var list = JSON.parse(this.responseText);
                    // console.log("AJAX response: ");
                    // console.log( $list);
                    box2.innerHTML = '';

                    list.forEach(el => {
                        box2.innerHTML += "<li onclick=\"selectclick(this)\" data-name=\""+el['name']+"\">"+
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
                xhr.open("POST", '/apps/zeus/web/patients/searchajax/term');
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
}

function appointmentsExpandAll() {
    lis = document.querySelectorAll('ul.patient-appointments-list li');
    lis.forEach(el => {
        // console.log(el.querySelectorAll('.appointment-entry input[type="checkbox"]'));
        el.querySelectorAll('.appointment-entry input[type="checkbox"]')[0].checked = true;
    });
    
    // console.log(lis);
}

function appointmentsCollapseAll() {
    lis = document.querySelectorAll('ul.patient-appointments-list li');
    lis.forEach(el => {
        // console.log(el.querySelectorAll('.appointment-entry input[type="checkbox"]'));
        el.querySelectorAll('.appointment-entry input[type="checkbox"]')[0].checked = false;
    });
    
    // console.log(lis);
}