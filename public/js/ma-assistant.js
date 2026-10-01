(() => {
    const root = document.getElementById('ma-assistant-root');
    if (!root) return;

    const script = document.currentScript;
    const askUrl = script.dataset.askUrl;
    const toggle = document.getElementById('ma-assistant-toggle');
    const panel = document.getElementById('ma-assistant-panel');
    const closeBtn = document.getElementById('ma-assistant-close');
    const messagesEl = document.getElementById('ma-assistant-messages');
    const form = document.getElementById('ma-assistant-form');
    const input = document.getElementById('ma-assistant-input');
    const attachBtn = document.getElementById('ma-assistant-attach');
    const imageInput = document.getElementById('ma-assistant-image-input');
    const imagePreview = document.getElementById('ma-assistant-image-preview');
    const imagePreviewImg = imagePreview.querySelector('img');
    const imageRemoveBtn = document.getElementById('ma-assistant-image-remove');
    const sendBtn = document.getElementById('ma-assistant-send');

    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

    let history = [];
    let pendingImage = null;
    let busy = false;

    const addMessage = (role, text, imageDataUrl) => {
        const bubble = document.createElement('div');
        bubble.className = `ma-msg ${role}`;
        bubble.textContent = text;
        if (imageDataUrl) {
            const img = document.createElement('img');
            img.src = imageDataUrl;
            bubble.appendChild(img);
        }
        messagesEl.appendChild(bubble);
        messagesEl.scrollTop = messagesEl.scrollHeight;
        return bubble;
    };

    toggle.addEventListener('click', () => {
        panel.hidden = !panel.hidden;
        if (!panel.hidden) {
            input.focus();
            if (!messagesEl.children.length) {
                addMessage('assistant', 'Halo! Tanya apa aja soal dashboard atau data toko kamu di sini.');
            }
        }
    });
    closeBtn.addEventListener('click', () => { panel.hidden = true; });

    attachBtn.addEventListener('click', () => imageInput.click());
    imageInput.addEventListener('change', () => {
        const file = imageInput.files[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = () => {
            pendingImage = { file, dataUrl: reader.result };
            imagePreviewImg.src = reader.result;
            imagePreview.hidden = false;
        };
        reader.readAsDataURL(file);
    });
    imageRemoveBtn.addEventListener('click', () => {
        pendingImage = null;
        imageInput.value = '';
        imagePreview.hidden = true;
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (busy) return;
        const message = input.value.trim();
        if (!message) return;

        addMessage('user', message, pendingImage?.dataUrl);
        history.push({ role: 'user', content: message });
        const imageToSend = pendingImage;
        input.value = '';
        pendingImage = null;
        imageInput.value = '';
        imagePreview.hidden = true;

        busy = true;
        sendBtn.disabled = true;
        const loadingBubble = addMessage('assistant', 'Mikir...');
        loadingBubble.classList.add('loading');

        try {
            const body = new FormData();
            body.append('message', message);
            body.append('history', JSON.stringify(history.slice(-20)));
            if (imageToSend) body.append('image', imageToSend.file);

            const res = await fetch(askUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
                body,
            });

            loadingBubble.remove();

            if (!res.ok) {
                addMessage('assistant', 'Maaf, ada gangguan. Coba lagi sebentar lagi ya.');
                return;
            }

            const data = await res.json();
            addMessage('assistant', data.reply);
            history.push({ role: 'assistant', content: data.reply });
        } catch (err) {
            loadingBubble.remove();
            addMessage('assistant', 'Maaf, koneksi gagal. Coba lagi ya.');
        } finally {
            busy = false;
            sendBtn.disabled = false;
        }
    });
})();
