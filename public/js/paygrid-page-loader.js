(() => {
    const bar = document.getElementById('pg-topbar');
    if (!bar) return;

    let timeoutId;
    const show = () => {
        // Restart the width transition from 0 each time instead of just
        // extending the previous fill (matters if two navigations happen
        // back-to-back, e.g. a double click).
        bar.classList.remove('active');
        void bar.offsetWidth; // force reflow so the removal above takes effect
        bar.classList.add('active');
        // Failsafe: some links/forms trigger a file download or open in a new
        // tab without ever navigating this page away (e.g. CSV export), which
        // would otherwise leave the bar stuck forever.
        window.clearTimeout(timeoutId);
        timeoutId = window.setTimeout(() => bar.classList.remove('active'), 8000);
    };
    const hide = () => {
        window.clearTimeout(timeoutId);
        bar.classList.remove('active');
    };

    document.addEventListener('click', (event) => {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        const link = event.target.closest('a[href]');
        if (!link || link.hasAttribute('data-no-page-loader') || link.hasAttribute('download') || link.target === '_blank') return;
        const href = link.getAttribute('href');
        if (!href || href.startsWith('#') || href.startsWith('javascript:') || href.startsWith('mailto:') || href.startsWith('tel:')) return;
        show();
    });

    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (form.hasAttribute('data-live-form') || form.hasAttribute('data-no-page-loader')) return;
        show();
    });

    // bfcache restore (browser Back/Forward) can bring back a page with the
    // bar still mid-transition from before the user navigated away.
    window.addEventListener('pageshow', hide);
})();
