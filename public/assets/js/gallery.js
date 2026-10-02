/* Camagru gallery script (bonus: infinite pagination).
 *
 * Progressive enhancement only: the Previous/Next links remain the way to
 * page through the gallery without JavaScript. When this script runs it
 * loads the next page through XMLHttpRequest (never fetch, see
 * COMPATIBILITY.md entry 5) as soon as the viewport comes close to the
 * bottom, appends the server-rendered cards to the grid and hides the
 * pagination nav (the links come back if a request ever fails, so the
 * fallback is never lost).
 *
 * Handled errors are silent apart from restoring the nav; never the console.
 * Written in ES5 style: var and function only, no arrow functions, no
 * template literals, no async/await.
 */
(function () {
    'use strict';

    var grid = document.getElementById('gallery-grid');
    if (!grid) {
        // Empty gallery or not on the gallery page.
        return;
    }

    var page = parseInt(grid.getAttribute('data-page'), 10) || 1;
    var totalPages = parseInt(grid.getAttribute('data-total-pages'), 10) || 1;
    var nav = document.getElementById('gallery-pagination');
    var loading = false;

    // Nothing to load dynamically: leave the plain links in place.
    if (totalPages <= 1) {
        return;
    }

    /** Hide the fallback nav while the dynamic loading works. */
    function hideNav() {
        if (nav) {
            nav.className = nav.className + ' is-hidden';
        }
    }

    /** Bring the fallback nav back (used when a dynamic load fails). */
    function showNav() {
        if (nav) {
            nav.className = nav.className.replace(' is-hidden', '');
        }
    }

    /**
     * Load the next page as JSON and append its cards to the grid. The
     * server renders the cards with the same partial as the initial page,
     * so the markup (and its escaping) is identical.
     */
    function loadNextPage() {
        if (loading || page >= totalPages) {
            return;
        }
        loading = true;

        var xhr = new XMLHttpRequest();
        xhr.open('GET', '/?page=' + (page + 1), true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) {
                return;
            }
            loading = false;
            var answer = null;
            try {
                answer = JSON.parse(xhr.responseText);
            } catch (parseError) {
                answer = null;
            }
            if (xhr.status === 200 && answer && answer.html && answer.page > page) {
                grid.insertAdjacentHTML('beforeend', answer.html);
                page = answer.page;
                totalPages = answer.totalPages || totalPages;
                hideNav();
                // A short page (or a small window) may not raise a scroll
                // event at all: keep loading until the viewport is full.
                if (nearBottom()) {
                    loadNextPage();
                }
            } else {
                // Offline or expired: give the plain links back.
                showNav();
            }
        };
        xhr.send(null);
    }

    /** True when the viewport is close enough to the bottom to load more. */
    function nearBottom() {
        var scrollHeight = Math.max(
            document.documentElement.scrollHeight,
            document.body.scrollHeight
        );
        return (window.pageYOffset + window.innerHeight) >= (scrollHeight - 700);
    }

    window.addEventListener('scroll', function () {
        if (nearBottom()) {
            loadNextPage();
        }
    });

    hideNav();
    // First page may already fit in the viewport: no scroll event would come.
    if (nearBottom()) {
        loadNextPage();
    }
}());
