(function () {
    const DEBOUNCE_MS = 250;
    const MIN_LENGTH = 2;

    document.querySelectorAll('[data-search-root]').forEach(function (root) {
        const input = root.querySelector('[data-search-input]');
        const dropdown = root.querySelector('[data-search-dropdown]');
        if (!input || !dropdown) {
            return;
        }

        let timer = null;
        let abort = null;
        let activeIndex = -1;
        let results = [];

        function hideDropdown() {
            dropdown.hidden = true;
            dropdown.replaceChildren();
            activeIndex = -1;
            results = [];
        }

        function render() {
            dropdown.replaceChildren();

            if (results.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'navbar-search-empty';
                empty.textContent = 'No matches';
                dropdown.appendChild(empty);
                dropdown.hidden = false;
                activeIndex = -1;
                return;
            }

            results.forEach(function (hit, index) {
                const link = document.createElement('a');
                link.href = hit.url || '#';
                link.className = 'navbar-search-hit' + (index === activeIndex ? ' is-active' : '');
                link.dataset.index = String(index);

                const title = document.createElement('span');
                title.className = 'navbar-search-hit-title';
                title.textContent = hit.title || '';

                const path = document.createElement('span');
                path.className = 'navbar-search-hit-path';
                path.textContent = hit.path || '';

                link.append(title, path);
                dropdown.appendChild(link);
            });

            dropdown.hidden = false;
        }

        function fetchResults(query) {
            if (abort) {
                abort.abort();
            }
            abort = new AbortController();

            fetch('/search.json?q=' + encodeURIComponent(query), {
                headers: { Accept: 'application/json' },
                signal: abort.signal,
            })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('search failed');
                    }
                    return response.json();
                })
                .then(function (payload) {
                    results = Array.isArray(payload.results) ? payload.results : [];
                    activeIndex = results.length > 0 ? 0 : -1;
                    render();
                })
                .catch(function (err) {
                    if (err && err.name === 'AbortError') {
                        return;
                    }
                    hideDropdown();
                });
        }

        input.addEventListener('input', function () {
            const query = input.value.trim();
            window.clearTimeout(timer);
            if (query.length < MIN_LENGTH) {
                hideDropdown();
                return;
            }
            timer = window.setTimeout(function () {
                fetchResults(query);
            }, DEBOUNCE_MS);
        });

        input.addEventListener('keydown', function (event) {
            if (dropdown.hidden || results.length === 0) {
                if (event.key === 'Escape') {
                    hideDropdown();
                }
                return;
            }

            if (event.key === 'ArrowDown') {
                event.preventDefault();
                activeIndex = (activeIndex + 1) % results.length;
                render();
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                activeIndex = (activeIndex - 1 + results.length) % results.length;
                render();
            } else if (event.key === 'Enter' && activeIndex >= 0 && results[activeIndex]) {
                event.preventDefault();
                window.location.href = results[activeIndex].url;
            } else if (event.key === 'Escape') {
                hideDropdown();
            }
        });

        dropdown.addEventListener('mousedown', function (event) {
            const hit = event.target.closest('.navbar-search-hit');
            if (hit) {
                event.preventDefault();
                window.location.href = hit.getAttribute('href');
            }
        });

        input.addEventListener('blur', function () {
            window.setTimeout(hideDropdown, 120);
        });

        document.addEventListener('click', function (event) {
            if (!root.contains(event.target)) {
                hideDropdown();
            }
        });
    });
})();
