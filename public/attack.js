// "Attack this answer": the zone of /lie?page=… opens under the answer, in place. Each attack and
// each layout edited by hand is sent to the server, which checks it and renders the result; nothing
// is decided here. Without JavaScript, the button opens the page itself.
(() => {
    if (!window.fetch || !window.DOMParser) {
        return;
    }
    const words = document.body.dataset;

    // The zone of the page the server answered with; null when it holds none (an error page).
    const zoneOf = (html) => {
        const zone = new DOMParser().parseFromString(html, 'text/html').querySelector('#attack');
        if (!zone) {
            return null;
        }
        const here = document.importNode(zone, true);
        // The page around may already have an element with these names.
        here.removeAttribute('id');
        here.querySelector('#result')?.removeAttribute('id');
        const field = here.querySelector('#layout');
        if (field) {
            field.id = 'attack-layout';
            here.querySelector('label[for="layout"]')?.setAttribute('for', 'attack-layout');
        }

        return here;
    };

    const load = async (place, url, options) => {
        place.setAttribute('aria-busy', 'true');
        try {
            // A layout the schema refuses comes back as 422, with the page that says why.
            const response = await fetch(url, options);
            const zone = zoneOf(await response.text());
            if (!zone) {
                throw new Error('No zone in the answer.');
            }
            place.replaceChildren(...place.querySelectorAll(':scope > .gf-attack-close, :scope > .gf-attack-lead'), zone);
        } catch {
            window.location.assign(typeof url === 'string' ? url : url.href);
        } finally {
            place.removeAttribute('aria-busy');
        }
    };

    document.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target : null;
        if (!target || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }
        const opener = target.closest('a.gf-attack-open');
        if (opener) {
            event.preventDefault();
            const holder = opener.closest('p') || opener;
            let place = holder.nextElementSibling?.classList.contains('gf-attack-here') ? holder.nextElementSibling : null;
            if (place) {
                place.remove();
                opener.setAttribute('aria-expanded', 'false');

                return;
            }
            place = document.createElement('section');
            place.className = 'gf-attack-here';
            const close = document.createElement('button');
            close.type = 'button';
            close.className = 'gf-attack-close';
            close.textContent = words.attackClose || 'Close';
            close.addEventListener('click', () => {
                place.remove();
                opener.setAttribute('aria-expanded', 'false');
                opener.focus();
            });
            const lead = document.createElement('p');
            lead.className = 'gf-attack-lead';
            lead.textContent = words.attackLead || 'Press an attack: the server checks the changed answer again, without any AI.';
            place.append(close, lead);
            holder.after(place);
            opener.setAttribute('aria-expanded', 'true');
            load(place, opener.href).then(() => place.scrollIntoView({ block: 'nearest', behavior: 'smooth' }));

            return;
        }
        // An attack pressed inside a zone that is open under an answer.
        const attack = target.closest('.gf-attack-here .gf-attacks a');
        if (attack) {
            event.preventDefault();
            load(attack.closest('.gf-attack-here'), attack.href);
        }
    });

    document.addEventListener('submit', (event) => {
        const form = event.target instanceof HTMLFormElement ? event.target : null;
        const place = form?.closest('.gf-attack-here');
        if (!form || !place) {
            return;
        }
        event.preventDefault();
        load(place, form.action, { method: 'POST', body: new FormData(form) });
    });
})();
