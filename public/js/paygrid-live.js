(() => {
    const parse = (html) => new DOMParser().parseFromString(html, 'text/html');
    const escape = (value) => window.CSS?.escape ? CSS.escape(value) : String(value).replace(/"/g, '\\"');
    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

    const shouldPause = (root) => document.hidden
        || root.querySelector('[data-live-modal]:not([hidden])')
        || root.querySelector('.table-wrap:hover')
        || root.contains(document.activeElement) && ['INPUT', 'TEXTAREA', 'SELECT', 'BUTTON'].includes(document.activeElement?.tagName || '');
    const now = () => Date.now();
    const markInteraction = (root) => {
        root.dataset.livePauseUntil = String(now() + 10000);
    };
    const isInteracting = (root) => Number(root.dataset.livePauseUntil || 0) > now();
    const canForceRefresh = (root) => {
        const lastRefresh = Number(root.dataset.liveLastForceRefresh || 0);
        if (now() - lastRefresh < 1000) return false;
        root.dataset.liveLastForceRefresh = String(now());

        return true;
    };
    const keyFor = (field) => field.dataset.preserveKey || field.name || field.id;
    const snapshotFields = (root) => {
        const fields = new Map();
        root.querySelectorAll('input, textarea, select').forEach((field) => {
            const key = keyFor(field);
            if (!key) return;
            fields.set(key, {
                value: field.value,
                checked: field.checked,
                selectionStart: field.selectionStart,
                selectionEnd: field.selectionEnd,
                active: field === document.activeElement,
            });
        });

        return fields;
    };
    const restoreFields = (root, fields) => {
        fields.forEach((state, key) => {
            const selector = `[data-preserve-key="${escape(key)}"], [name="${escape(key)}"], #${escape(key)}`;
            const field = root.querySelector(selector);
            if (!field) return;
            if (field.type === 'checkbox' || field.type === 'radio') {
                field.checked = state.checked;
            } else {
                field.value = state.value;
            }
            if (state.active) {
                field.focus({ preventScroll: true });
                if (typeof field.setSelectionRange === 'function' && state.selectionStart !== null) {
                    field.setSelectionRange(state.selectionStart, state.selectionEnd);
                }
            }
        });
    };

    // .table-wrap is the scroll container on data-table pages; [data-live-scroll]
    // marks any other scrollable region (e.g. a chat thread) that wants the same
    // scroll-position preservation across a region swap.
    const scrollTargetSelector = '.table-wrap, [data-live-scroll]';
    const isNearBottom = (el) => el.scrollHeight - el.scrollTop - el.clientHeight < 40;

    // A region can contain more than one scrollable target (e.g. two chat tabs
    // in the same live-region) - snapshot/restore every match by its position
    // within the region, not just the first one.
    const snapshotScroll = (root) => {
        const regions = new Map();
        root.querySelectorAll('[data-live-region]').forEach((region) => {
            const key = region.dataset.liveRegion;
            if (!key) return;
            const wraps = region.querySelectorAll(scrollTargetSelector);
            if (!wraps.length) return;
            regions.set(key, Array.from(wraps).map((wrap) => (
                { top: wrap.scrollTop, left: wrap.scrollLeft, atBottom: isNearBottom(wrap) }
            )));
        });

        return { windowX: window.scrollX, windowY: window.scrollY, regions };
    };

    const restoreScroll = (root, scroll, stickToBottom) => {
        scroll.regions.forEach((states, key) => {
            const region = root.querySelector(`[data-live-region="${escape(key)}"]`);
            if (!region) return;
            region.querySelectorAll(scrollTargetSelector).forEach((wrap, i) => {
                const state = states[i];
                if (!state) return;
                if (stickToBottom || state.atBottom) {
                    wrap.scrollTop = wrap.scrollHeight;
                } else {
                    wrap.scrollTop = state.top;
                    wrap.scrollLeft = state.left;
                }
            });
        });
        window.scrollTo(scroll.windowX, scroll.windowY);
    };

    const refresh = async (root, force = false, stickToBottom = false) => {
        if (!force && shouldPause(root)) return;
        if (!force && isInteracting(root)) return;
        const fields = snapshotFields(root);
        const scroll = snapshotScroll(root);

        const response = await fetch(window.location.href, {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-PayGrid-Partial': '1' },
        });
        if (!response.ok) return;

        const nextDoc = parse(await response.text());
        root.querySelectorAll('[data-live-region]').forEach((region) => {
            const key = region.dataset.liveRegion;
            const next = nextDoc.querySelector(`[data-live-region="${escape(key)}"]`);
            if (next && region.innerHTML !== next.innerHTML) region.innerHTML = next.innerHTML;
        });
        restoreFields(root, fields);
        restoreScroll(root, scroll, stickToBottom);
        root.dispatchEvent(new CustomEvent('paygrid:refreshed', { bubbles: true }));
    };

    const setupAutoFilters = () => {
        document.querySelectorAll('form[data-auto-filter]:not([data-auto-filter-ready])').forEach((form) => {
            form.dataset.autoFilterReady = 'true';
            let timer;
            const submit = () => {
                window.clearTimeout(timer);
                timer = window.setTimeout(() => form.requestSubmit(), Number(form.dataset.autoFilterDelay || 500));
            };
            form.querySelectorAll('input[name="q"], input[type="date"], select').forEach((field) => {
                field.addEventListener(field.name === 'q' ? 'input' : 'change', submit);
            });
        });
    };

    const ajaxifyForms = (root) => {
        root.querySelectorAll('form[data-live-form]:not([data-live-form-ready])').forEach((form) => {
            form.dataset.liveFormReady = 'true';
            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                const submitter = event.submitter;
                if (submitter) submitter.disabled = true;
                try {
                    const response = await fetch(form.action, {
                        method: 'POST',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        body: new FormData(form),
                    });
                    if (response.ok) {
                        form.reset();
                        // Force past the interaction-pause guard - that guard exists to
                        // avoid yanking content out from under a user mid-scroll/typing,
                        // but clicking submit IS the user asking to see the result now.
                        // data-scroll-to-bottom (composer forms only) also jumps the
                        // thread to the newly-sent message regardless of prior scroll.
                        await refresh(root, true, form.hasAttribute('data-scroll-to-bottom'));
                    }
                } catch (e) {
                    // network hiccup - next periodic refresh will catch up
                } finally {
                    if (submitter) submitter.disabled = false;
                }
            });
        });
    };

    const setupNoteAutosave = () => {
        document.querySelectorAll('[data-cs-note]:not([data-cs-note-ready])').forEach((field) => {
            field.dataset.csNoteReady = 'true';
            let timer;
            field.addEventListener('input', () => {
                window.clearTimeout(timer);
                timer = window.setTimeout(async () => {
                    if (!field.dataset.noteUrl) return;
                    const body = new FormData();
                    body.append('_method', 'PATCH');
                    body.append('cs_note', field.value);
                    await fetch(field.dataset.noteUrl, {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': csrf(),
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body,
                    }).catch(() => {});
                }, 650);
            });
        });
    };

    document.querySelectorAll('[data-live-root]').forEach((root) => {
        const runRefresh = () => refresh(root).catch(() => {}).finally(() => {
            setupAutoFilters();
            setupNoteAutosave();
            ajaxifyForms(root);
        });
        ajaxifyForms(root);
        const refreshWhenVisible = () => {
            if (document.hidden || !canForceRefresh(root)) return;
            runRefresh();
        };

        root.querySelectorAll('[data-live-region]').forEach((region) => {
            region.addEventListener('pointerenter', () => markInteraction(root));
            region.addEventListener('pointermove', () => markInteraction(root));
            region.addEventListener('focusin', () => markInteraction(root));
            region.addEventListener('wheel', () => markInteraction(root), { passive: true });
            region.addEventListener('touchstart', () => markInteraction(root), { passive: true });
        });
        window.setInterval(runRefresh, Number(root.dataset.liveInterval || 15000));
        document.addEventListener('visibilitychange', refreshWhenVisible);
        window.addEventListener('focus', refreshWhenVisible);
        window.addEventListener('pageshow', refreshWhenVisible);
    });
    setupAutoFilters();
    setupNoteAutosave();
})();
