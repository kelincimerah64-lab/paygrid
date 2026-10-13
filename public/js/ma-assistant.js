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
    const fileInput = document.getElementById('ma-assistant-file-input');
    const attachmentsEl = document.getElementById('ma-assistant-attachments');
    const sendBtn = document.getElementById('ma-assistant-send');

    const MAX_ATTACHMENTS = 3;

    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

    let history = [];
    let pendingAttachments = []; // { file, dataUrl, isImage }
    let busy = false;

    const renderAttachments = () => {
        attachmentsEl.innerHTML = '';
        pendingAttachments.forEach((att, idx) => {
            const chip = document.createElement('div');
            chip.className = 'ma-attachment-chip';
            if (att.isImage) {
                const img = document.createElement('img');
                img.src = att.dataUrl;
                img.alt = att.file.name;
                chip.appendChild(img);
            } else {
                const icon = document.createElement('div');
                icon.className = 'file-icon';
                icon.textContent = '📄';
                icon.title = att.file.name;
                chip.appendChild(icon);
            }
            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'remove-btn';
            removeBtn.textContent = '×';
            removeBtn.setAttribute('aria-label', 'Hapus lampiran');
            removeBtn.addEventListener('click', () => {
                pendingAttachments.splice(idx, 1);
                renderAttachments();
            });
            chip.appendChild(removeBtn);
            attachmentsEl.appendChild(chip);
        });
        attachmentsEl.hidden = pendingAttachments.length === 0;
    };

    const addMessage = (role, text, attachments) => {
        const bubble = document.createElement('div');
        bubble.className = `ma-msg ${role}`;
        bubble.textContent = text;
        (attachments || []).forEach((att) => {
            if (att.isImage) {
                const img = document.createElement('img');
                img.src = att.dataUrl;
                bubble.appendChild(img);
            } else {
                const tag = document.createElement('div');
                tag.className = 'ma-msg-file';
                tag.textContent = `📎 ${att.file.name}`;
                bubble.appendChild(tag);
            }
        });
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

    attachBtn.addEventListener('click', () => fileInput.click());
    fileInput.addEventListener('change', () => {
        const files = Array.from(fileInput.files || []);
        files.forEach((file) => {
            if (pendingAttachments.length >= MAX_ATTACHMENTS) return;
            const reader = new FileReader();
            reader.onload = () => {
                pendingAttachments.push({ file, dataUrl: reader.result, isImage: file.type.startsWith('image/') });
                renderAttachments();
            };
            reader.readAsDataURL(file);
        });
        fileInput.value = '';
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (busy) return;
        const message = input.value.trim();
        if (!message) return;

        addMessage('user', message, pendingAttachments);
        history.push({ role: 'user', content: message });
        const attachmentsToSend = pendingAttachments;
        input.value = '';
        pendingAttachments = [];
        renderAttachments();

        busy = true;
        sendBtn.disabled = true;
        const loadingBubble = addMessage('assistant', 'Mikir...');
        loadingBubble.classList.add('loading');

        try {
            const body = new FormData();
            body.append('message', message);
            body.append('history', JSON.stringify(history.slice(-20)));
            attachmentsToSend.forEach((att) => body.append('attachments[]', att.file));

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
