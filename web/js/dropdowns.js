// dropdowns.js — chained book/chapter/verse dropdowns.
// Also keeps the end-verse range dropdown in sync with the current chapter.
// Repopulates the edition dropdown when the user selects a different book
// (OT Hebrew books get BHS+LXX; NT/LXX books get NA28+TR+LXX).
// Auto-submits the form when the user changes the Edition selection.
(function () {
    const selBook    = document.getElementById('sel-book');
    const selChapter = document.getElementById('sel-chapter');
    const selVerse   = document.getElementById('sel-verse');
    const selRangeEnd = document.getElementById('sel-range-end');
    const selCount   = document.getElementById('sel-count'); // legacy count=N support
    const selEdition = document.getElementById('sel-edition');
    if (!selBook || !selChapter || !selVerse) return;

    const OT_EDITIONS = [
        { code: 'BHS',        name: 'Biblia Hebraica Stuttgartensia' },
        { code: 'LXX-Rahlfs', label: 'LXX', name: 'Rahlfs LXX 1935' }
    ];
    const NT_EDITIONS = [
        { code: 'NA28',       name: 'Nestle-Aland 28th edition' },
        { code: 'TR',         name: 'Scrivener Textus Receptus 1894' },
        { code: 'LXX-Rahlfs', label: 'LXX', name: 'Rahlfs LXX 1935' }
    ];

    function savedNtEdition() {
        try {
            const saved = localStorage.getItem('mispar-nt-version') || localStorage.getItem('mispar-nt-edition') || '';
            if (saved.toLowerCase() === 'tr') return 'TR';
            if (saved.toLowerCase() === 'na27' || saved.toUpperCase() === 'NA28') return 'NA28';
        } catch (e) {}
        return '';
    }

    function rememberNtEdition(code) {
        if (!['NA28', 'TR'].includes(code)) return;
        try {
            localStorage.setItem('mispar-nt-edition', code);
            localStorage.setItem('mispar-nt-version', code === 'TR' ? 'tr' : 'na27');
        } catch (e) {}
    }

    function populate(sel, items, resetTo) {
        sel.innerHTML = '';
        for (const v of items) {
            const opt = document.createElement('option');
            opt.value = v;
            opt.textContent = v;
            sel.appendChild(opt);
        }
        sel.value = resetTo && items.includes(resetTo) ? resetTo : items[0];
    }

    function populateRangeEnd(verses, resetTo) {
        if (!selRangeEnd) {
            if (selCount) populateCount(verses.length || 1);
            return;
        }
        const start = parseInt(selVerse.value || '1', 10) || 1;
        const allowed = verses.map(v => parseInt(v, 10)).filter(v => v >= start);
        selRangeEnd.innerHTML = '';
        for (const v of allowed.length ? allowed : [start]) {
            const opt = document.createElement('option');
            opt.value = String(v);
            opt.textContent = String(v);
            selRangeEnd.appendChild(opt);
        }
        const target = parseInt(resetTo || start, 10) || start;
        selRangeEnd.value = allowed.includes(target) ? String(target) : String(start);
        selRangeEnd.dataset.max = String(allowed.length ? allowed[allowed.length - 1] : start);
    }

    function populateCount(maxN) {
        if (!selCount) return;
        selCount.innerHTML = '';
        for (let i = 1; i <= maxN; i++) {
            const opt = document.createElement('option');
            opt.value = i;
            opt.textContent = i === 1 ? '1 verse' : i + ' verses';
            selCount.appendChild(opt);
        }
        selCount.value = '1';
        selCount.dataset.max = String(maxN);
    }

    // Repopulate the edition dropdown to match the currently-selected book's
    // tradition: Hebrew OT books get BHS + LXX-Rahlfs; everything else gets
    // NA28 + TR + LXX-Rahlfs. Preserves the current edition if it's valid
    // in the new set; otherwise resets to the first option.
    function syncEditionOptions() {
        if (!selEdition) return;
        const opt    = selBook.selectedOptions[0];
        const lang   = opt ? opt.dataset.lang : '';
        const isLxx  = opt ? opt.value.startsWith('Lxx') : false;
        // OT editions for Hebrew MT books and all LXX books (which are OT Greek).
        const isNt = lang === 'Greek' && !isLxx;
        const editions = (lang === 'Hebrew' || isLxx) ? OT_EDITIONS : NT_EDITIONS;
        const current  = selEdition.value;
        selEdition.innerHTML = '';
        for (const ed of editions) {
            const o = document.createElement('option');
            o.value = ed.code;
            o.textContent = ed.label || ed.code;
            o.title = ed.name;
            selEdition.appendChild(o);
        }
        const codes = editions.map(e => e.code);
        const saved = isNt ? savedNtEdition() : '';
        selEdition.value = codes.includes(current) ? current : (codes.includes(saved) ? saved : editions[0].code);
        selEdition.disabled = false;
    }

    selBook.addEventListener('change', function () {
        // Only sync edition options on pages that use dynamic edition lists
        // (i.e. not pages with data-static on the edition select).
        if (!selEdition || !selEdition.dataset.static) {
            syncEditionOptions();
        }
        // Navigate directly so chapter/verse always reset to 1:1.
        // Preserve any extra form fields (width, letters, etc.) from the
        // current form so page-specific params survive book changes.
        const form = this.closest('form');
        const params = new URLSearchParams();
        params.set('book',    this.value);
        params.set('chapter', '1');
        params.set('verse',   '1');
        if (selEdition) params.set('edition', selEdition.value);
        if (form) {
            const skip = new Set(['book', 'chapter', 'verse', 'edition', 'count', 'end_verse']);
            for (const el of form.elements) {
                if (el.name && !skip.has(el.name)) params.set(el.name, el.value);
            }
        }
        window.location.href = '?' + params.toString();
    });

    selChapter.addEventListener('change', async function () {
        const book    = selBook.value;
        const chapter = this.value;
        // Relative URL — works whether the page is served at /bible/ or root.
        const verses  = await fetch(`api.php?api=verses&book=${encodeURIComponent(book)}&chapter=${chapter}`)
            .then(r => r.json()).catch(() => []);
        populate(selVerse, verses, null);
        populateRangeEnd(verses, null);
        const form = selChapter.closest('form');
        if (form) form.submit();
    });

    selVerse.addEventListener('change', function () {
        const options = Array.from(selVerse.options).map(opt => opt.value);
        populateRangeEnd(options, null);
        if (selCount) selCount.value = '1';
        const form = selVerse.closest('form');
        if (form) form.submit();
    });

    if (selRangeEnd) {
        selRangeEnd.addEventListener('change', function () {
            const form = selRangeEnd.closest('form');
            if (form) form.submit();
        });
    }

    if (selCount) {
        selCount.addEventListener('change', function () {
            const form = selCount.closest('form');
            if (form) form.submit();
        });
    }

    // Auto-submit when the user picks a new edition — no need to click Go.
    if (selEdition) {
        selEdition.addEventListener('change', function () {
            rememberNtEdition(this.value);
            const form = this.closest('form');
            if (form) form.submit();
        });
    }

    if (selEdition && !new URLSearchParams(window.location.search).has('edition')) {
        const opt = selBook.selectedOptions[0];
        const saved = savedNtEdition();
        if (opt && opt.dataset.lang === 'Greek' && !opt.value.startsWith('Lxx') && saved && selEdition.value !== saved) {
            selEdition.value = saved;
            const form = selEdition.closest('form');
            if (form) form.submit();
        }
    }
})();
