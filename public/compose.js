// /compare and /compose: the page is redrawn as soon as a choice changes, in place, without
// leaving the form. The server renders it, as for the button "Show the page", which stays for
// visitors without JavaScript; here it only says what is already happening.
(() => {
    const form = document.querySelector('form.gf-compose');
    const result = document.getElementById('gf-result');
    if (!form || !result || !window.fetch || !window.DOMParser) {
        return;
    }
    let timer = null;
    let latest = 0; // the choice asked for last: an answer to an earlier one is dropped
    // When the page cannot be redrawn here, the button comes back: the form is sent the usual way.
    const giveUp = () => form.classList.remove('is-live');
    const redraw = async (changed = null) => {
        const request = ++latest;
        const url = new URL(form.action || window.location.href, window.location.href);
        url.search = new URLSearchParams(new FormData(form)).toString();
        result.setAttribute('aria-busy', 'true');
        try {
            const response = await fetch(url, { headers: { Accept: 'text/html' } });
            const page = new DOMParser().parseFromString(await response.text(), 'text/html');
            const fresh = page.getElementById('gf-result');
            if (request !== latest) {
                return;
            }
            if (!fresh) {
                giveUp(); // an error page: nothing to show here
                return;
            }
            result.replaceChildren(...fresh.childNodes);
            // Another indicator has other periods: its own "from" and "to".
            if (changed && 'dataset' === changed.name) {
                for (const id of ['from', 'to']) {
                    const mine = form.querySelector('#' + id);
                    const theirs = page.getElementById(id);
                    if (mine && theirs) {
                        const value = theirs.value; // read before its options move here
                        mine.replaceChildren(...theirs.childNodes);
                        mine.value = value;
                    }
                }
            }
            // What the server says about the choices themselves (too few places, a period before
            // another): shown where the page would be.
            const refused = page.querySelector('form.gf-compose ul li');
            if (!response.ok && !result.textContent.trim()) {
                const problem = document.createElement('p');
                problem.className = 'problem';
                problem.textContent = refused ? refused.textContent.trim() : (result.dataset.failed || 'This choice cannot be drawn.');
                result.replaceChildren(problem);
            }
            if (response.ok) {
                history.replaceState(null, '', url);
            }
        } catch {
            giveUp();
        } finally {
            if (request === latest) {
                result.removeAttribute('aria-busy');
            }
        }
    };
    // The choices: only the ones ticked are shown, and a button opens the whole list with a field
    // to filter it. Without this script every choice is shown, as native checkboxes.
    const words = result.dataset;
    const collapse = (group, label) => {
        const chips = [...group.querySelectorAll('.gf-chip')];
        if (chips.length < 14) {
            return;
        }
        group.classList.add('is-collapsed');
        const filter = document.createElement('input');
        filter.type = 'search';
        filter.className = 'gf-chip-filter';
        filter.placeholder = words.filter || 'Type to filter';
        filter.setAttribute('aria-label', (words.filter || 'Type to filter') + (label ? ': ' + label : ''));
        filter.hidden = true;
        const toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'gf-chip-add';
        const say = () => { toggle.textContent = group.classList.contains('is-collapsed') ? '+ ' + (words.add || 'Add') : (words.done || 'Done'); };
        const narrow = () => {
            const wanted = filter.value.trim().toLowerCase();
            for (const chip of chips) {
                chip.classList.toggle('is-filtered', '' !== wanted && !chip.textContent.toLowerCase().includes(wanted));
            }
        };
        toggle.addEventListener('click', () => {
            const open = group.classList.toggle('is-collapsed') === false;
            toggle.setAttribute('aria-expanded', String(open));
            filter.hidden = !open;
            filter.value = '';
            narrow();
            say();
            if (open) {
                filter.focus();
            }
        });
        filter.addEventListener('input', narrow);
        // Enter in the filter ticks the only choice left, rather than sending the form.
        filter.addEventListener('keydown', (event) => {
            if ('Enter' !== event.key) {
                return;
            }
            event.preventDefault();
            const left = chips.filter((chip) => !chip.classList.contains('is-filtered') && !chip.querySelector('input').checked);
            if (1 === left.length) {
                left[0].querySelector('input').click();
                filter.value = '';
                narrow();
            }
        });
        toggle.setAttribute('aria-expanded', 'false');
        say();
        const tools = document.createElement('span');
        tools.className = 'gf-chip-tools';
        tools.append(toggle, filter);
        (group.querySelector('.gf-chip-group-label') || group.querySelector('.gf-chips')).after(tools);
    };
    const groups = [...form.querySelectorAll('.gf-chip-group')];
    for (const group of groups) {
        collapse(group, group.querySelector('.gf-chip-group-label')?.textContent.trim());
    }
    // A long list that has no groups (places of one kind only).
    for (const list of form.querySelectorAll('.gf-chips')) {
        if (!list.closest('.gf-chip-group')) {
            const wrapper = list.parentElement;
            wrapper.classList.add('gf-chip-group');
            collapse(wrapper, '');
            if (!wrapper.classList.contains('is-collapsed')) {
                wrapper.classList.remove('gf-chip-group');
            }
        }
    }
    // /compose: only the places the chosen indicator covers are offered. Nights are counted by
    // country, the weather is by city: the two lists are never shown together for one indicator.
    let coverage = null;
    try {
        coverage = result.dataset.coverage ? JSON.parse(result.dataset.coverage) : null;
    } catch {
        // every place stays offered, the server refuses those the indicator does not cover
    }
    const indicator = form.querySelector('#dataset');
    const cover = (fill = false) => {
        const places = coverage && indicator ? coverage[indicator.value] : null;
        if (!Array.isArray(places)) {
            return;
        }
        for (const chip of form.querySelectorAll('.gf-chip')) {
            const input = chip.querySelector('input[name="places[]"]');
            if (!input) {
                continue;
            }
            const covered = places.includes(input.value);
            chip.classList.toggle('is-uncovered', !covered);
            if (!covered) {
                input.checked = false;
            }
        }
        for (const group of form.querySelectorAll('.gf-chip-group')) {
            group.classList.toggle('is-empty', !group.querySelector('.gf-chip:not(.is-uncovered)'));
        }
        // An indicator that covers none of the ticked places starts with its first three: a page,
        // not "choose a place".
        if (fill && !form.querySelector('input[name="places[]"]:checked')) {
            const offered = [...form.querySelectorAll('.gf-chip:not(.is-uncovered) input[name="places[]"]')];
            for (const input of offered.slice(0, 3)) {
                input.checked = true;
            }
        }
    };
    cover();
    form.classList.add('is-live');

    form.addEventListener('change', (event) => {
        const changed = event.target;
        // The field that filters a list is not a choice.
        if (changed.classList?.contains('gf-chip-filter')) {
            return;
        }
        // A place unticked in a closed list disappears: the focus goes to the button of its list.
        if ('checkbox' === changed.type && !changed.checked) {
            const group = changed.closest('.gf-chip-group.is-collapsed');
            group?.querySelector('.gf-chip-add')?.focus();
        }
        if (changed === indicator) {
            cover(true);
        }
        clearTimeout(timer);
        timer = setTimeout(() => redraw(changed), 250);
    });
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        clearTimeout(timer);
        redraw().then(() => result.scrollIntoView({ behavior: 'smooth', block: 'start' }));
    });
})();
