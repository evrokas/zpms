
// Shared by every fetch()/XHR call in this app that mutates state -- reads
// the per-session token the page shell renders into <meta name="csrf-token">
// (web/templates/page/main.zetem). A <form method="post"> instead carries
// its own hidden csrf_token field (csrf_field(), server-rendered) and needs
// no JS at all; this helper is only for calls that build their own
// FormData/JSON body by hand, so the hidden field never gets included
// automatically.
function csrfToken() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.content : '';
}

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

var xhr = new XMLHttpRequest(),
    box2 = document.getElementById('select-box');
var timeout;
var basepath = '';

if(search.length > 0) {
    var el = search[0];
    el.addEventListener('keyup', (ev) => {
        if(ev.key == "Escape") {
            box2.style.display = 'none';
            return;
        }
        if(el.value.length>0) {
            box2.style.display = 'block';

            xhr.onreadystatechange = function() {
                if((this.readyState == 4) && (this.status == 200)) {
                    var response = JSON.parse(this.responseText);
                    var list = response['list'];

                    console.log("AJAX response: ");
                    console.log( response );
                    box2.innerHTML = '';
                    basepath = response['referer'];

                    list.forEach(el => {
                        box2.innerHTML += "<li onclick=\"selectclick(this)\" data-url=\""+el['link']+"\"data-id=\""+el['id']+"\" data-name=\""+el['name']+"\">"+
                        "<span class=\"name\">"+el['name']+"</span>"+
                        "<span class=\"tel\">"+"{Τηλ:"+el['tel']+"}"+"</span>"+
                        "<span class=\"amka\">"+"ΑΜΚΑ: "+el['amka']+"</span>"+
                        "</li>";
                    })
                }
            }

            clearTimeout(timeout);
            timeout = setTimeout(function () {
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
    search[0].value = e.dataset.name;
    box2.style.display = 'none';

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

/* same confirmation, for a delete action that's now a real POST form
   (needs a CSRF token, so it can't be a bare <a href> anymore) instead
   of a link -- intercepts submit instead of click. */
let trashforms = document.querySelectorAll('.patients-list form[confirmation], .admin-list form[confirmation]');
if(trashforms.length>0) {
    trashforms.forEach(el => {
        el.addEventListener('submit', (e) => {
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
            return 1;
        } else {
            return 0;
        }
    }}
];

function validatorUpdateClass(target, result) {
    target.classList.remove('valid', 'not-valid');

    switch( result ) {
        case 0: // not-valid
            target.classList.add('not-valid');
        break;
        case 1: // valid
            target.classList.add('valid');
        break;
        case 2: // special do not add valid/not-valid class
        break;
    }
}

// initialize validator element event and setup initial appearance of elements
validatorList.forEach(vi => {
    validatorArray.push(vi);

    vi.addEventListener('input', (ev) => {
        handler = validatorHandlers.find(h => h.name === ev.target.attributes.validator.value);
        res = handler.cb(ev.target.value);
        validatorUpdateClass(ev.target, res);
    })

    handler = validatorHandlers.find(h => h.name === vi.attributes.validator.value);
    res = handler.cb(vi.value);
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
        diffDate.getMonth() + "m ");
}

function isDateValid(dateStr) {
  return !isNaN(new Date(dateStr));
}

function dobChange(e) {
    target=document.getElementById(e.attributes['agefield'].nodeValue);

    ymd = e.value.split('-');
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


/* live duplicate-name check on the "new patient" form -- see
 * patient_new_check_name() in index.php. Only ever finds a
 * [data-check-duplicate] input on the /patient/new page, so this whole
 * block is a no-op everywhere else. */
function escapeHtml(str) {
    if(str === null || str === undefined) return '';
    return String(str).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

let duplicateCheckInput = document.querySelector('[data-check-duplicate]');
let duplicateCheckBox = document.getElementById('duplicate-warning');

if(duplicateCheckInput && duplicateCheckBox) {
    let duplicateCheckTimeout;

    function renderDuplicateWarning(matches) {
        if(!matches || matches.length === 0) {
            duplicateCheckBox.style.display = 'none';
            duplicateCheckBox.innerHTML = '';
            return;
        }

        let html = '<p>' + (matches.length > 1
            ? 'Βρέθηκαν ήδη ασθενείς με αυτό το όνομα:'
            : 'Υπάρχει ήδη ασθενής με αυτό το όνομα:') + '</p><ul>';

        matches.forEach(m => {
            html += '<li>'
                + '<span class="name">' + escapeHtml(m.name) + '</span>'
                + '<span class="amka">ΑΜΚΑ: ' + escapeHtml(m.amka || '—') + '</span>'
                + '<a class="load-existing" href="' + escapeHtml(m.link) + '">Φόρτωση φακέλου</a>'
                + '</li>';
        });

        html += '</ul>';

        duplicateCheckBox.innerHTML = html;
        duplicateCheckBox.style.display = 'block';
    }

    duplicateCheckInput.addEventListener('input', (ev) => {
        clearTimeout(duplicateCheckTimeout);

        let term = ev.target.value.trim();
        if(term.length < 2) {
            renderDuplicateWarning([]);
            return;
        }

        duplicateCheckTimeout = setTimeout(() => {
            let url = window.location.pathname.replace(/\/?$/, '/') + 'namecheck';

            fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'pname=' + encodeURIComponent(term)
            })
            .then(res => res.json())
            .then(data => renderDuplicateWarning(data.matches))
            .catch(err => console.log('duplicate name check failed', err));
        }, 300);
    });

    /* clicking a match navigates away and abandons whatever else was
     * typed into the new-patient form so far -- confirm first */
    duplicateCheckBox.addEventListener('click', (ev) => {
        let link = ev.target.closest('a.load-existing');
        if(!link) return;

        if(!confirm('Υπάρχει ήδη ασθενής με αυτό το όνομα. Θέλετε να φορτώσετε τον υπάρχοντα φάκελο ασθενή; Τα στοιχεία που καταχωρήσατε δεν θα αποθηκευτούν.')) {
            ev.preventDefault();
        }
    });
}

// Appointment type select (view_appointment.zetem / edit_appointment.zetem)
// -- shows the operation-details textarea only when 'Επέμβαση' (operation)
// is selected. Delegated at the document level since each appointment
// card on a patient's page is its own independent form; server-rendered
// with the correct .is-hidden state already applied (see
// .input-operation-notes in appointment-improvements.css), so this only
// needs to react to further changes, not set up the initial state.
document.addEventListener('change', (ev) => {
    if(!ev.target.matches('[data-appointment-type]')) return;

    let container = ev.target.closest('.appointment-entry');
    if(!container) return;

    let notes = container.querySelector('[data-operation-notes]');
    if(!notes) return;

    notes.classList.toggle('is-hidden', ev.target.value !== 'operation');
});

