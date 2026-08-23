/* Search panel logic — loaded by search/mini.phtml */
(function () {
    var cfg      = window.AhySearch || {};
    var _api     = cfg.api     || '';
    var _token   = cfg.token   || '';
    var _store   = cfg.store   || 0;
    var _media   = cfg.media   || '';
    var _res     = cfg.res     || '';
    var _suggest = cfg.suggest || '';

    var _popularCache = null;
    var _closeTimer   = null;

    var iconBtn  = document.getElementById('ahy-search-icon-btn');
    var overlay  = document.getElementById('ahy-search-overlay');
    var panel    = document.getElementById('ahy-search-panel');
    var input    = document.getElementById('ahy-overlay-input');
    var clearBtn = document.getElementById('ahy-overlay-clear');
    var body     = document.getElementById('ahy-panel-body');
    var timer    = null;

    document.body.appendChild(overlay);
    document.body.appendChild(panel);

    // Match panel bg to header bg
    (function () {
        var candidates = ['.page-header', '.header.content', 'header', '.page-wrapper'];
        for (var i = 0; i < candidates.length; i++) {
            var el = document.querySelector(candidates[i]);
            if (!el) continue;
            var bg = window.getComputedStyle(el).backgroundColor;
            if (bg && bg !== 'rgba(0, 0, 0, 0)' && bg !== 'transparent') {
                document.documentElement.style.setProperty('--ahy-header-bg', bg);
                break;
            }
        }
    })();

    function esc(s) {
        return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function fmt(p) {
        return p ? new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(p) : '';
    }

    function imgUrl(img) {
        if (!img) return '';
        if (/^https?:\/\//.test(img)) return img;
        return _media + (img.charAt(0) === '/' ? img : '/' + img);
    }

    function productCard(p, nameKey) {
        var url   = p.url_key ? '/' + p.url_key + '.html' : '#';
        var name  = p[nameKey] || '';
        var price = fmt(p.special_price && p.special_price < p.price ? p.special_price : p.price);
        var img   = imgUrl(p.image);
        return '<a class="ahy-overlay-product" href="' + esc(url) + '">'
             + '<div class="ahy-overlay-product-img-wrap"><img src="' + esc(img) + '" alt="' + esc(name) + '" loading="lazy"></div>'
             + '<div class="ahy-overlay-product-info">'
             + '<div class="ahy-overlay-product-name">' + esc(name) + '</div>'
             + (price ? '<div class="ahy-overlay-product-price">' + price + '</div>' : '')
             + '</div>'
             + '</a>';
    }

    function renderPopular(data) {
        var terms    = (data.terms    || []).slice(0, 5);
        var products = (data.products || []).slice(0, 6);

        var left = '<p class="ahy-quick-title">Top Searches</p>';
        if (terms.length) {
            left += '<ul class="ahy-quick-links">';
            terms.forEach(function (term) {
                left += '<li><a href="' + esc(_res) + '?q=' + encodeURIComponent(term) + '">'
                      + '<span class="ahy-ql-icon">↗</span>' + esc(term)
                      + '</a></li>';
            });
            left += '</ul>';
        }

        var right = '';
        if (products.length) {
            right += '<p class="ahy-overlay-results-title">Popular Products</p>'
                   + '<div class="ahy-overlay-grid">';
            products.forEach(function (p) { right += productCard(p, 'title'); });
            right += '</div>';
        }

        body.innerHTML = '<div class="ahy-popular-layout">'
                       + '<div class="ahy-popular-left">' + left + '</div>'
                       + (right ? '<div class="ahy-popular-right">' + right + '</div>' : '')
                       + '</div>';
    }

    function highlight(term, q) {
        var idx = term.toLowerCase().indexOf(q.toLowerCase());
        if (idx < 0) return esc(term);
        return esc(term.slice(0, idx))
             + '<strong>' + esc(term.slice(idx, idx + q.length)) + '</strong>'
             + esc(term.slice(idx + q.length));
    }

    function renderResults(data, q) {
        var terms       = (data.terms       || []).slice(0, 6);
        var products    = (data.products    || []).slice(0, 6);
        var categories  = (data.categories  || []).slice(0, 4);
        var spell       = data.spell_correction || '';

        var hasLeft  = terms.length || categories.length || spell;
        var hasRight = products.length;

        if (!hasLeft && !hasRight) {
            body.innerHTML = '<p class="ahy-overlay-status">No suggestions for "' + esc(q) + '".</p>';
            return;
        }

        var left = '';

        if (terms.length) {
            left += '<p class="ahy-quick-title">Suggestions</p>'
                  + '<ul class="ahy-quick-links">';
            terms.forEach(function (term) {
                left += '<li><a href="' + esc(_res) + '?q=' + encodeURIComponent(term) + '">'
                      + '<span class="ahy-ql-icon">↗</span>'
                      + '<span>' + highlight(term, q) + '</span>'
                      + '</a></li>';
            });
            left += '</ul>';
        }

        if (categories.length) {
            left += '<p class="ahy-quick-title" style="margin-top:' + (terms.length ? '18px' : '0') + '">Categories</p>'
                  + '<ul class="ahy-quick-links">';
            categories.forEach(function (cat) {
                var href = cat.url ? '/' + cat.url : esc(_res) + '?q=' + encodeURIComponent(cat.name);
                left += '<li><a href="' + esc(href) + '">'
                      + '<span class="ahy-ql-icon" style="font-size:14px">⊞</span>'
                      + '<span>' + highlight(cat.name, q) + '</span>'
                      + '</a></li>';
            });
            left += '</ul>';
        }

        if (spell) {
            left += '<p style="margin-top:14px;font-size:13px;color:#6e6e73">Did you mean <a href="' + esc(_res) + '?q=' + encodeURIComponent(spell) + '" style="color:#0071e3;font-weight:500">' + esc(spell) + '</a>?</p>';
        }

        var right = '';
        if (products.length) {
            right += '<p class="ahy-overlay-results-title">Products</p>'
                   + '<div class="ahy-overlay-grid">';
            products.forEach(function (p) { right += productCard(p, 'title'); });
            right += '</div>';
        }
        right += '<a class="ahy-overlay-see-all" style="margin-top:8px;display:inline-flex" href="' + esc(_res) + '?q=' + encodeURIComponent(q) + '">See all results for "' + esc(q) + '" →</a>';

        body.innerHTML = '<div class="ahy-popular-layout">'
                       + '<div class="ahy-popular-left">' + left + '</div>'
                       + (hasRight ? '<div class="ahy-popular-right">' + right + '</div>' : '')
                       + '</div>';
    }

    function showQuickLinks() {
        if (_popularCache) { renderPopular(_popularCache); return; }
        body.innerHTML = '<p class="ahy-overlay-status">Loading…</p>';
        fetch(_suggest + '?search_token=' + encodeURIComponent(_token) + '&q=')
            .then(function (r) { return r.json(); })
            .then(function (data) { _popularCache = data; renderPopular(data); })
            .catch(function () { body.innerHTML = ''; });
    }

    function openSearch() {
        clearTimeout(_closeTimer);
        var scrollY = window.scrollY || window.pageYOffset;
        document.body.style.top      = '-' + scrollY + 'px';
        document.body.style.position = 'fixed';
        document.body.style.width    = '100%';
        document.body.classList.add('ahy-search-open');
        overlay.style.display = 'block';
        overlay.classList.add('open');
        requestAnimationFrame(function () {
            panel.classList.add('open');
            overlay.classList.add('visible');
        });
        iconBtn.setAttribute('aria-expanded', 'true');
        showQuickLinks();
        setTimeout(function () { input.focus(); }, 80);
    }

    function closeSearch() {
        clearTimeout(_closeTimer);
        panel.classList.remove('open');
        overlay.classList.remove('visible');
        iconBtn.setAttribute('aria-expanded', 'false');
        var scrollY = Math.abs(parseInt(document.body.style.top || '0', 10));
        document.body.style.position = '';
        document.body.style.top      = '';
        document.body.style.width    = '';
        window.scrollTo(0, scrollY);
        input.value = '';
        clearBtn.classList.remove('visible');
        showQuickLinks();
        _closeTimer = setTimeout(function () {
            overlay.classList.remove('open');
            overlay.style.display = 'none';
            document.body.classList.remove('ahy-search-open');
        }, 350);
    }

    iconBtn.addEventListener('click', function () {
        panel.classList.contains('open') ? closeSearch() : openSearch();
    });
    overlay.addEventListener('click', closeSearch);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && panel.classList.contains('open')) closeSearch();
    });

    // Close search whenever Luma nav opens (hamburger, minicart, etc.)
    new MutationObserver(function () {
        if (document.body.classList.contains('nav-open') ||
            document.body.classList.contains('nav-before-open')) {
            if (panel.classList.contains('open')) closeSearch();
        }
    }).observe(document.body, { attributes: true, attributeFilter: ['class'] });
    clearBtn.addEventListener('click', function () {
        input.value = '';
        clearBtn.classList.remove('visible');
        showQuickLinks();
        input.focus();
    });

    input.addEventListener('input', function () {
        var q = input.value.trim();
        clearBtn.classList.toggle('visible', q.length > 0);
        clearTimeout(timer);
        if (q.length < 2) { showQuickLinks(); return; }
        body.innerHTML = '<p class="ahy-overlay-status">Searching…</p>';
        timer = setTimeout(function () {
            fetch(_suggest + '?search_token=' + encodeURIComponent(_token) + '&q=' + encodeURIComponent(q))
                .then(function (r) { return r.json(); })
                .then(function (d) { renderResults(d, q); })
                .catch(function () { body.innerHTML = ''; });
        }, 280);
    });
})();
