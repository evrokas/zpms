
// var field = document.getElementById('datepicker');
//     if(field != null) {

//         var picker = new Pikaday({
//         onSelect: function(date) {
//                 field.value = picker.toString();
//             }
//         });
//         field.parentNode.insertBefore(picker.el, field.nextSibling);
//     }

// console.log('scripts.js is loaded!');


function adjustSubmenuJustification() {
    const menuItems = document.querySelectorAll('.submenu-item');

    menuItems.forEach((el) => {
        const submenu = el.querySelector('.drop-menu');
        if(submenu) {
            submenu.classList.remove('right-flush');

            const rect = el.getBoundingClientRect();
            const subRect = submenu.getBoundingClientRect();
                    
            if(rect.right + subRect.width > window.innerWidth) {
                submenu.classList.add('right-flush');
            }
        }
    })
}

let resizeTimeout;
function handleResize() {
    clearTimeout(resizeTimeout);
    resizeTimeout = setTimeout(adjustSubmenuJustification, 100); // Run after 100ms delay
}

document.addEventListener("DOMContentLoaded", adjustSubmenuJustification);

document.addEventListener("resize", handleResize);

if (window.visualViewport) {
    window.visualViewport.addEventListener("resize", handleResize);
}



search = document.querySelectorAll('.select2');
// console.log( search );

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


/* add confirmation dialog to every trash icon */
let trashelements = document.querySelectorAll('.patients-list a[confirmation]');
if(trashelements.length>0) {
    trashelements.forEach(el => {
        el.addEventListener('click', (e) => {
            if(!confirm('Are you sure you want to delete the record?'))
                e.preventDefault();
        })
    })
}


var elementTimeout;

function saveTimeout(element) {
    console.log( element );
}

function startTimeout() {
    clearTimeout(elementTimeout);
    elementTimeout = timeout(document.querySelector('.appointment-entry')[0], 2000);
}


// copy string to clipoard
function copyStr(astr) {
    console.log( astr );
    navigator.clipboard.writeText( astr );
}

function copyStr0(astr) {
    console.log( astr );
}


// input validation code
let validatorArray = [];

// find all elements that have validator attribute
validatorList = document.querySelectorAll('input[validator]');
// console.log( validatorList );

function validateEmail(email) {
    return email.length>3;
}

const validatorHandlers = [
    {name: "amka", cb: (value) => {
        if(value.length == 11) {
            return 1; 
        } else {
            return 0;
        }
    }},
    {name: "telephone", cb: (value) => {
        if(value.length == 10) {
            return 1; 
        } else {
            return 0;
        }
    }},
    {name: "email", cb: (value) => {
        if(value.length==0) {
            return 2;
        } else
        if(validateEmail(value)) {
            // vinp.classList.add('valid');
            return 1; 
        } else {
            // vinp.classList.add('not-valid');
            return 0;
        }
    }}
];

function validatorUpdateClass(target, result) {
    target.classList.remove('valid', 'not-valid');

    switch( result ) {
        case 0: // not-valid
            // console.log('not-valid: ', target);
            target.classList.add('not-valid');
        break;
        case 1: // valid
            // console.log('valid: ', target);
            target.classList.add('valid');
        break;
        case 2: // special do not add valid/not-valid class
            // console.log('special: ', target);
        break;
    }
}

// initialize validator element event and setup initial appearance of elements
validatorList.forEach(vi => {
    // console.log(vi);
    validatorArray.push(vi);

    vi.addEventListener('input', (ev) => {
        // console.log(ev);
        // console.log('validator: ' + ev.target.attributes.validator.value)

        handler = validatorHandlers.find(h => h.name === ev.target.attributes.validator.value);
        // console.log('found validator handler: ', handler);

        res = handler.cb(ev.target.value);
        // console.log( 'testing for validator handler result', res)
        validatorUpdateClass(ev.target, res);
    })

    handler = validatorHandlers.find(h => h.name === vi.attributes.validator.value);
    // console.log('initial found validator handler: ', handler);

    res = handler.cb(vi.value);
    // console.log( 'initial testing for validator handler result', res)
    validatorUpdateClass(vi, res);
});


async function call_totp_action(action) {
    console.log('totp action ', action);
    let response = await fetch('totp/'+action);
    const result = await response.json();

    return result;
}

async function totp_action(action) {
    console.log('action: ', action );
    switch(action) {
        case 'show_qrcode_modal':
            modal = document.getElementById("modal-qr");
            console.log( modal );
            modal.classList.add('open');
            break;
        case 'close_qrcode_modal':
            modal = document.getElementById("modal-qr");
            console.log( modal );
            modal.classList.remove('open');
            break;
        case 'activate':
            call_totp_action(action).then(result => { 
                console.log( result );
                // return result;
                // const imgElement = document.createElement('img');
                // imgElement.src = result;
                // imgElement.alt = 'Fetched Image';
                document.getElementById('qrimg').src = "data:image/png;base64,"+result.qrdata;
            });
        
            break;

        case 'deactivate':
            break;
    }
}



function copyText(text, iconElement) {
    if (navigator.clipboard && window.isSecureContext) {
        // Modern clipboard API
        navigator.clipboard.writeText(text)
            .then(() => showCopiedFeedback(iconElement))
            .catch(err => console.error("Clipboard copy failed:", err));
    } else {
        // Fallback for older browsers
        let textarea = document.createElement("textarea");
        textarea.value = text;
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand("copy");
        document.body.removeChild(textarea);
        showCopiedFeedback(iconElement);
    }
}

function showCopiedFeedback(iconElement) {
    // Store original icon class
    let originalClass = iconElement.className;

    // Change icon to 'bx-check' (✔️) and color to green
    iconElement.className = "bx bx-check";
    iconElement.style.color = "green";

    // Create tooltip element
    let tooltip = document.createElement("span");
    tooltip.className = "tooltip";
    tooltip.innerText = "Copied!";
    document.body.appendChild(tooltip);

    // Position the tooltip near the icon
    let rect = iconElement.getBoundingClientRect();
    tooltip.style.left = `${rect.left + window.scrollX}px`;
    tooltip.style.top = `${rect.top + window.scrollY - 30}px`; // Position above the icon

    // Start fade-out after 1.5s, remove after 2s
    setTimeout(() => {
        tooltip.style.opacity = "0"; // Start fade-out effect
    }, 1500);
    
    setTimeout(() => {
        iconElement.className = originalClass;
        iconElement.style.color = ""; // Reset color
        tooltip.remove(); // Remove tooltip from DOM
    }, 2000);
}

document.addEventListener("click", function(event) {
    let element = event.target;
    if (element.classList.contains("bx-copy")) {
        let textToCopy = element.getAttribute("data-copy-text") || "No text set";
        copyText(textToCopy, element);
    }
});


// startDate must be a date string
function dateAgo(date) {
    var startDate = new Date(date);
    var diffDate = new Date(new Date() - startDate);
    return ((diffDate.toISOString().slice(0, 4) - 1970) + "y " +
        diffDate.getMonth() + "m "
        //  + (diffDate.getDate()-1) + "D"
        );
}

function isDateValid(dateStr) {
  return !isNaN(new Date(dateStr));
}

function dobChange(e) {
    // console.log('dob change: ' , e.attributes['agefield']);
    target=document.getElementById(e.attributes['agefield'].nodeValue);
    // console.log(target);

    ymd = e.value.split('-');
    // console.log('ymd', ymd);
    dobDate = new Date(ymd[2],ymd[1],ymd[0]);

    if(isDateValid(dobDate)
        && (ymd[2] > 1900)
        && (ymd[1] > 0) && (ymd[1] < 13)
        && (ymd[0] >= 0) && (ymd[0] < 32)
        ) {
        d = dateAgo(dobDate);
        target.innerHTML = d;
        e.classList.add('valid');
        e.classList.remove('not-valid');
    } else {
        target.innerHTML = '';
        e.classList.add('not-valid');
        e.classList.remove('valid');
    }

}

