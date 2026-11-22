// "Copy with source": one click copies the text the server wrote for a page or a figure, then
// shows it. Without JavaScript, or where the clipboard is refused, the text opens to be selected.
(() => {
    document.addEventListener('click', async (event) => {
        const summary = event.target instanceof Element ? event.target.closest('details.gf-copy > summary') : null;
        if (!summary || !navigator.clipboard) {
            return;
        }
        const box = summary.parentElement;
        const field = box.querySelector('textarea');
        if (!field) {
            return;
        }
        // The text opens under the button the first time, and stays: one sees what was copied.
        if (box.open) {
            event.preventDefault();
        }
        try {
            await navigator.clipboard.writeText(field.value);
        } catch {
            field.focus();
            field.select();

            return;
        }
        const label = summary.dataset.label || summary.textContent;
        summary.dataset.label = label;
        summary.textContent = box.dataset.copied || 'Copied';
        summary.classList.add('is-copied');
        clearTimeout(summary.timer);
        summary.timer = setTimeout(() => {
            summary.textContent = label;
            summary.classList.remove('is-copied');
        }, 2200);
    });
})();
