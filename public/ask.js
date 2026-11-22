// /ask: shows the page while the model writes it. The server sends server-sent events through
// /ask/stream; each "title" or "component" event is a part of the page, already rendered and with
// every figure resolved by the server. Without JavaScript, /ask renders the whole page at once.
(() => {
    const result = document.getElementById('result');
    if (!result || !window.fetch || !window.TextDecoderStream) {
        return;
    }
    const parts = result.querySelector('.parts');
    const origin = result.querySelector('.origin');
    const tally = result.querySelector('.tally');
    const raw = result.querySelector('#model-stream pre');
    const link = result.querySelector('.link');
    // The count of the page while it is written: figures proven, cells refused, texts not shown,
    // counted on what the server sent so far. The server's own sentence comes at the end.
    const labels = (result.dataset.counts || 'proven|refused|not shown').split('|');
    const count = (server = null) => {
        let live = result.querySelector('.live-tally');
        if (!live) {
            live = document.createElement('ul');
            live.className = 'live-tally';
            live.setAttribute('aria-hidden', 'true'); // the sentence of the server is the status
            for (const [index, name] of ['proven', 'refused', 'hidden'].entries()) {
                const item = document.createElement('li');
                item.className = name;
                item.append(document.createElement('b'), labels[index] || name);
                live.append(item);
            }
            // Above the page, where the eye is while it builds.
            (result.querySelector('.shown .column-title') || tally).after(live);
        }
        // While the page is written, what can be seen so far; at the end, the count of the server
        // (which also counts the points of a line chart).
        const counts = server || [parts.querySelectorAll('a.gf-figure:not(.gf-line a), .gf-line svg a:has(circle)').length, parts.querySelectorAll('.gf-refusal').length, parts.querySelectorAll('.gf-unsourced').length];
        [...live.children].forEach((item, index) => {
            const number = item.firstElementChild;
            if (number.textContent !== String(counts[index])) {
                number.textContent = String(counts[index]);
                item.dataset.count = String(counts[index]);
                number.classList.remove('bump');
                void number.offsetWidth; // restarts the animation
                number.classList.add('bump');
            }
        });
    };
    const say = (text, problem = false) => {
        tally.textContent = text;
        tally.className = problem ? 'tally problem' : 'tally';
    };

    // The steps of the agent are over: the last one is no longer shown as running, and a line says so.
    const finish = (answered = true) => {
        const steps = result.querySelector('.agent-steps');
        if (steps && !steps.classList.contains('is-done')) {
            steps.classList.add('is-done');
            // "The answer is below" only when there is one: not after a problem or a cut stream.
            if (answered) {
                const done = document.createElement('li');
                done.textContent = result.dataset.done || 'Finished.';
                steps.append(done);
            }
        }
    };

    const show = (event, data) => {
        switch (event) {
            case 'origin': origin.textContent = data; break;
            // What the question said that was not understood: in full view, above the answer.
            case 'leftout': {
                const leftOut = result.querySelector('.left-out');
                if (leftOut) {
                    leftOut.textContent = data;
                    leftOut.hidden = false;
                }
                break;
            }
            case 'title':
            case 'component':
                parts.insertAdjacentHTML('beforeend', data);
                count();
                break;
            case 'delta': raw.textContent += data; break;
            // What the agent does when the catalog cannot answer: each step as it happens.
            case 'step': {
                let steps = result.querySelector('.agent-steps');
                if (!steps) {
                    steps = document.createElement('ol');
                    steps.className = 'agent-steps';
                    steps.setAttribute('aria-live', 'polite');
                    result.insertBefore(steps, result.querySelector('.columns'));
                }
                const step = document.createElement('li');
                step.textContent = data;
                steps.append(step);
                break;
            }
            // The question is asked again with the dataset the server just added: a new page.
            case 'reset':
                parts.replaceChildren();
                count();
                raw.textContent = '';
                say(result.dataset.writing || 'The model is writing…');
                break;
            case 'end':
                finish();
                // The model's text, laid out once it is whole: easier to read, still its own words.
                try {
                    raw.textContent = JSON.stringify(JSON.parse(raw.textContent), null, 2);
                } catch {
                    // not JSON as a whole: left as written
                }
                if (data.credits) {
                    parts.insertAdjacentHTML('beforeend', data.credits); // rendered by the server
                }
                say(data.summary); // written by the server, in the page's language
                count([data.proven, data.refused, data.hidden]);
                // Copy with source, the link of the page, and the answer to tamper with: rendered by
                // the server, like the page itself.
                link.innerHTML = data.actions || '';
                break;
            case 'problem': finish(false); say(data, true); break;
        }
    };

    // One question at a time: a new one stops the stream of the previous, whose parts would land
    // under the new question otherwise.
    let current = null;
    const ask = async (url, init, question) => {
        current?.abort();
        const controller = current = new AbortController();
        result.classList.remove('is-idle');
        parts.replaceChildren();
        link.replaceChildren();
        result.querySelector('.agent-steps')?.remove();
        result.querySelector('.live-tally')?.remove();
        raw.textContent = '';
        origin.textContent = '';
        const leftOut = result.querySelector('.left-out');
        if (leftOut) {
            leftOut.textContent = '';
            leftOut.hidden = true;
        }
        let asked = result.querySelector('.asked');
        if (!asked) {
            asked = document.createElement('h2');
            asked.className = 'asked';
            result.insertBefore(asked, result.querySelector('.columns'));
        }
        asked.textContent = question;
        // The question and the answer are in the language they were asked in, which the page may not be.
        const { language = '', ...options } = init;
        asked.lang = language;
        result.querySelector('.gf-page')?.setAttribute('lang', language || document.documentElement.lang);
        say(result.dataset.writing || 'The model is writing…');
        result.scrollIntoView({ behavior: 'smooth' });

        let response;
        try {
            response = await fetch(url, { ...options, signal: controller.signal });
        } catch (error) {
            if (controller.signal.aborted) {
                return;
            }
            throw error;
        }
        if (!response.ok || !response.body) {
            let text = '';
            try {
                // The server explains itself in plain text; an error page is not shown as text.
                text = (response.headers.get('Content-Type') || '').startsWith('text/plain') ? await response.text() : '';
            } catch {
                // the body could not be read: the status says enough
            }
            if (!controller.signal.aborted) {
                say(text || response.statusText, true);
            }
            return;
        }
        const reader = response.body.pipeThrough(new TextDecoderStream()).getReader();
        let ended = false;
        let buffer = '';
        for (;;) {
            let chunk;
            try {
                chunk = await reader.read();
            } catch (error) {
                if (controller.signal.aborted) {
                    return;
                }
                throw error;
            }
            const { value, done } = chunk;
            if (done) {
                // A stream that closes without its last event: said, not left "writing".
                if (!ended && !controller.signal.aborted) {
                    finish(false);
                    say(result.dataset.cut || 'The answer stopped before its end.', true);
                }
                break;
            }
            buffer += value;
            let end;
            while ((end = buffer.indexOf('\n\n')) >= 0) {
                const block = buffer.slice(0, end);
                buffer = buffer.slice(end + 2);
                let event = 'message';
                let data = '';
                for (const line of block.split('\n')) {
                    if (line.startsWith('event: ')) event = line.slice(7);
                    else if (line.startsWith('data: ')) data += line.slice(6);
                }
                if (controller.signal.aborted) {
                    return;
                }
                ended = ended || 'end' === event || 'problem' === event;
                show(event, JSON.parse(data));
            }
        }
    };

    // The EN / FR switch goes back to the page as it is now: with the question shown, so that the
    // other language opens the same question (its twin) rather than an empty page.
    const followTheSwitch = () => {
        for (const a of document.querySelectorAll('header.site .language a')) {
            const url = new URL(a.href);
            url.searchParams.set('back', window.location.pathname + window.location.search);
            a.href = url.href;
        }
    };

    for (const link of document.querySelectorAll('.gf-examples a[data-stream]')) {
        link.addEventListener('click', (e) => {
            e.preventDefault();
            for (const other of document.querySelectorAll('.gf-examples a')) other.removeAttribute('aria-current');
            link.setAttribute('aria-current', 'true');
            history.replaceState(null, '', link.href);
            followTheSwitch();
            ask(link.dataset.stream, { language: link.lang }, link.textContent).catch((error) => say(String(error), true));
        });
    }

    const form = document.getElementById('ask-form');
    const question = document.getElementById('question');
    // A question to click (one that works without AI, another month, another figure): put in the
    // field and asked like a typed one. Without a model nothing is spent.
    document.addEventListener('click', (e) => {
        const chip = e.target instanceof Element ? e.target.closest('a[data-ask]') : null;
        if (!chip || !form || !question || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) {
            return;
        }
        e.preventDefault();
        question.value = chip.dataset.ask;
        // With a key, a question to click is still read by its words: the box says so.
        const words = document.querySelector('input[name="words"]');
        if (words) {
            words.checked = true;
        }
        history.replaceState(null, '', chip.href);
        form.requestSubmit();
    });
    if (form && question) {
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            ask(form.dataset.stream, { method: 'POST', body: new FormData(form) }, question.value).catch((error) => say(String(error), true));
        });
        // A question typed on the home page is asked at once, and only when the visitor comes from
        // this site: a link from elsewhere never spends a call of the key.
        let fromHere = false;
        try {
            fromHere = new URL(document.referrer).origin === window.location.origin;
        } catch {
            // no referrer: the visitor presses the button
        }
        if ('autostart' in form.dataset && question.value && fromHere) {
            history.replaceState(null, '', window.location.pathname);
            form.requestSubmit();
        }
    }
})();
