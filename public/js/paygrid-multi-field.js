/**
 * Shared "+ add another" input widget: a .multi-field wrap holding one or
 * more .multi-field-row (input + toggle button) rows. Click handling is
 * delegated globally so this works for rows built at page load (static
 * markup, e.g. Create Toko) and rows built later via build() (e.g. a
 * dynamically-rendered ticket form) without re-wiring anything per row.
 *
 * collect(form) must run right before the real submit - it turns each
 * wrap's current row values into what the server expects: `mode:"array"`
 * emits `name[]` hidden inputs (one per row), the default "comma" mode
 * joins them into the wrap's own `name` hidden input.
 */
window.PayGridMultiField = (function () {
    function refresh(wrap) {
        var rows = wrap.querySelectorAll('.multi-field-row');
        var max = parseInt(wrap.dataset.multiFieldMax || '0', 10);
        rows.forEach(function (row, idx) {
            var btn = row.querySelector('.multi-field-toggle');
            if (!btn) return;
            var isLast = idx === rows.length - 1;
            var atMax = max > 0 && rows.length >= max;
            btn.textContent = isLast ? '+' : '×';
            btn.classList.toggle('remove', !isLast);
            btn.dataset.action = isLast ? 'add' : 'remove';
            btn.hidden = isLast && atMax;
        });
    }

    function addRow(wrap) {
        var template = wrap.querySelector('.multi-field-input');
        var row = document.createElement('div');
        row.className = 'multi-field-row';
        var input = document.createElement('input');
        input.type = template.type;
        input.className = 'multi-field-input';
        if (template.placeholder) input.placeholder = template.placeholder;
        var toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'multi-field-toggle';
        row.appendChild(input);
        row.appendChild(toggle);
        wrap.appendChild(row);
        refresh(wrap);
        input.focus();
    }

    document.addEventListener('click', function (e) {
        if (!(e.target.matches && e.target.matches('.multi-field-toggle'))) return;
        var wrap = e.target.closest('.multi-field');
        if (!wrap) return;
        if (e.target.dataset.action === 'add') {
            addRow(wrap);
        } else {
            e.target.closest('.multi-field-row').remove();
            refresh(wrap);
        }
    });

    function collect(form) {
        form.querySelectorAll('[data-multi-field]').forEach(function (wrap) {
            var name = wrap.dataset.multiField;
            var mode = wrap.dataset.mode || 'comma';
            var values = Array.from(wrap.querySelectorAll('.multi-field-input')).map(function (i) { return i.value.trim(); }).filter(Boolean);
            wrap.querySelectorAll('input[type="hidden"][data-generated]').forEach(function (h) { h.remove(); });
            if (mode === 'array') {
                values.forEach(function (v) {
                    var hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = name + '[]';
                    hidden.value = v;
                    hidden.dataset.generated = '1';
                    wrap.appendChild(hidden);
                });

                return;
            }
            var hidden = form.querySelector('input[type="hidden"][name="' + name + '"]');
            if (hidden) hidden.value = values.join(',');
        });
    }

    /**
     * Builds a fresh .multi-field wrap for a field that doesn't already
     * exist in the page's static markup (e.g. a form whose fields render
     * dynamically per category). `opts.mode` defaults to "comma"; pass
     * "array" for name[] submission. `opts.max` caps how many rows can be
     * added (the last row's + button hides once reached).
     */
    function build(name, opts) {
        opts = opts || {};
        var wrap = document.createElement('div');
        wrap.className = 'multi-field';
        wrap.dataset.multiField = name;
        wrap.dataset.mode = opts.mode || 'comma';
        if (opts.max) wrap.dataset.multiFieldMax = String(opts.max);

        var row = document.createElement('div');
        row.className = 'multi-field-row';
        var input = document.createElement('input');
        input.type = opts.type || 'text';
        input.className = 'multi-field-input';
        if (opts.placeholder) input.placeholder = opts.placeholder;
        var toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'multi-field-toggle';
        toggle.textContent = '+';
        toggle.dataset.action = 'add';
        row.appendChild(input);
        row.appendChild(toggle);
        wrap.appendChild(row);

        if ((opts.mode || 'comma') !== 'array') {
            var hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = name;
            wrap.appendChild(hidden);
        }

        return wrap;
    }

    return { refresh: refresh, collect: collect, build: build };
})();
