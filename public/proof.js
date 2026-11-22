// On the site, a click on a proven figure opens its proof in a panel beside the page, without
// leaving it. The panel shows the proof page itself, read from the server. Without JavaScript, with
// a modifier key (new tab) or if the proof cannot be read, the link opens the proof page as before.
(() => {
    if (!window.fetch || !window.HTMLDialogElement || !window.DOMParser) {
        return;
    }
    let dialog = null;
    let request = 0; // the proof asked for last: an answer to an earlier one is dropped
    // The words of the panel, in the page's language (templates/base.html.twig).
    const words = document.body.dataset;
    const panel = () => {
        if (dialog) {
            return dialog;
        }
        dialog = document.createElement('dialog');
        dialog.className = 'proof-panel';
        dialog.setAttribute('aria-label', words.proofLabel || 'Proof of a figure');
        // The inner box fills the panel: a click that reaches the dialog itself is on the shade.
        const inner = document.createElement('div');
        inner.className = 'proof-panel-inner';
        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'close';
        close.setAttribute('aria-label', words.proofClose || 'Close the proof');
        close.textContent = '×';
        close.addEventListener('click', () => dialog.close());
        const body = document.createElement('div');
        body.className = 'body';
        const open = document.createElement('p');
        open.className = 'open';
        const link = document.createElement('a');
        link.textContent = words.proofOpen || 'Open the proof page';
        open.append(link);
        inner.append(close, body, open);
        dialog.append(inner);
        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) {
                dialog.close();
            }
        });
        dialog.addEventListener('close', () => {
            request++;
        });
        document.body.append(dialog);

        return dialog;
    };

    document.addEventListener('click', async (event) => {
        // A figure in the text, or a point or a scale label of a line chart (an SVG link).
        const figure = event.target instanceof Element ? event.target.closest('a.gf-figure, svg a') : null;
        if (!figure || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }
        const href = figure.getAttribute('href') ?? figure.getAttribute('xlink:href');
        if (!href) {
            return;
        }
        const url = new URL(href, window.location.href);
        if (url.origin !== window.location.origin || !url.pathname.startsWith('/proof/')) {
            return;
        }
        event.preventDefault();
        const box = panel();
        const body = box.querySelector('.body');
        body.textContent = words.proofReading || 'Reading the proof…';
        box.querySelector('.open a').href = url.href;
        if (!box.open) {
            box.showModal();
        }
        const current = ++request;
        try {
            const response = await fetch(url, { headers: { Accept: 'text/html' } });
            const main = new DOMParser().parseFromString(await response.text(), 'text/html').querySelector('main');
            if (!main) {
                throw new Error('No proof in the answer.');
            }
            if (current === request) {
                body.replaceChildren(...Array.from(main.childNodes, (node) => document.importNode(node, true)));
            }
        } catch {
            // Only for the proof still asked for, in a panel still open.
            if (current === request && box.open) {
                window.location.assign(url.href);
            }
        }
    });
})();
