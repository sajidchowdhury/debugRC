(function () {
    'use strict';

    var root = document.getElementById('warehouseHubStockSearch');
    if (!root) {
        return;
    }

    var searchUrl = root.dataset.searchUrl || '';
    var input = document.getElementById('warehouseHubSearchInput');
    var resultsEl = document.getElementById('warehouseHubSearchResults');
    var hintEl = document.getElementById('warehouseHubSearchHint');
    var paginationEl = document.getElementById('warehouseHubPagination');

    if (!input || !resultsEl || !searchUrl) {
        return;
    }

    var state = {
        q: '',
        page: 1,
        timer: null,
        loading: false
    };

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text == null ? '' : String(text);
        return div.innerHTML;
    }

    function formatQty(qty) {
        var n = parseFloat(qty);
        if (isNaN(n)) {
            return '0';
        }
        return n.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 });
    }

    function setHint(message) {
        if (hintEl) {
            hintEl.textContent = message;
        }
    }

    function renderLoading() {
        resultsEl.innerHTML = '<div class="hub-loading"><i class="fas fa-spinner fa-spin"></i> Searching…</div>';
        if (paginationEl) {
            paginationEl.innerHTML = '';
        }
    }

    function renderEmpty(message) {
        resultsEl.innerHTML = '<div class="hub-empty-state"><i class="fas fa-search d-block"></i><p class="mb-0">' + escapeHtml(message) + '</p></div>';
        if (paginationEl) {
            paginationEl.innerHTML = '';
        }
    }

    function renderRows(rows) {
        if (!rows.length) {
            renderEmpty('No products match your search.');
            return;
        }

        var html = '<div class="table-responsive"><table class="hub-product-table"><thead><tr>'
            + '<th>Product</th><th>Category</th><th>Group</th><th class="text-end">Qty</th>'
            + '</tr></thead><tbody>';

        rows.forEach(function (row) {
            html += '<tr>'
                + '<td><div class="hub-product-name">' + escapeHtml(row.product_name) + '</div>'
                + '<div class="hub-product-code">' + escapeHtml(row.product_code) + '</div></td>'
                + '<td><span class="hub-tag">' + escapeHtml(row.category_name) + '</span></td>'
                + '<td><span class="hub-tag">' + escapeHtml(row.group_name) + '</span></td>'
                + '<td class="text-end"><strong>' + formatQty(row.qty) + '</strong>'
                + ' <span class="text-muted small">' + escapeHtml(row.unit) + '</span></td>'
                + '</tr>';
        });

        html += '</tbody></table></div>';
        resultsEl.innerHTML = html;
    }

    function renderPagination(meta) {
        if (!paginationEl) {
            return;
        }

        if (!meta.total || meta.pages <= 1) {
            paginationEl.innerHTML = meta.total
                ? '<div class="hub-pagination-info">' + meta.total + ' product line(s) found</div>'
                : '';
            return;
        }

        paginationEl.innerHTML = ''
            + '<div class="hub-pagination-info">'
            + meta.total + ' lines · Page ' + meta.page + ' of ' + meta.pages
            + '</div>'
            + '<div class="hub-pagination-btns">'
            + '<button type="button" data-page="' + (meta.page - 1) + '" ' + (meta.page <= 1 ? 'disabled' : '') + '>Prev</button>'
            + '<button type="button" data-page="' + (meta.page + 1) + '" ' + (meta.page >= meta.pages ? 'disabled' : '') + '>Next</button>'
            + '</div>';

        paginationEl.querySelectorAll('button[data-page]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var page = parseInt(btn.getAttribute('data-page'), 10);
                if (page >= 1 && page <= meta.pages) {
                    state.page = page;
                    fetchResults();
                }
            });
        });
    }

    function fetchResults() {
        if (state.q.length < 2) {
            renderEmpty('Type at least 2 characters to search products.');
            setHint('Search by product name, code, category, or group.');
            return;
        }

        if (state.loading) {
            return;
        }

        state.loading = true;
        renderLoading();
        setHint('Searching "' + state.q + '"…');

        var url = searchUrl
            + '?q=' + encodeURIComponent(state.q)
            + '&page=' + encodeURIComponent(state.page);

        fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        })
            .then(function (res) { return res.json(); })
            .then(function (payload) {
                state.loading = false;
                if (!payload || payload.status !== 'success' || !payload.data) {
                    renderEmpty('Search failed. Please try again.');
                    setHint('');
                    return;
                }

                var data = payload.data;
                renderRows(data.rows || []);
                renderPagination(data);
                setHint(data.total
                    ? 'Showing paginated product stock for "' + state.q + '".'
                    : 'No matches for "' + state.q + '".');
            })
            .catch(function () {
                state.loading = false;
                renderEmpty('Search failed. Please try again.');
                setHint('');
            });
    }

    input.addEventListener('input', function () {
        state.q = input.value.trim();
        state.page = 1;

        clearTimeout(state.timer);
        state.timer = setTimeout(fetchResults, 350);
    });

    renderEmpty('Type at least 2 characters to search products.');
    setHint('Search by product name, code, category, or group.');
})();
