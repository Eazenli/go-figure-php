// The name of the chosen file, said in the page's language (the browser's own control says it in its own).
(() => {
    const input = document.getElementById('file');
    const name = document.querySelector('.file-name');
    if (!input || !name) {
        return;
    }
    const say = () => {
        const file = input.files && input.files[0];
        name.textContent = file ? file.name : name.dataset.none;
        name.classList.toggle('is-chosen', Boolean(file));
    };
    input.addEventListener('change', say);
    say();
})();
