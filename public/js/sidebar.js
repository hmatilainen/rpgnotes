(function () {
    var STORAGE_KEY = 'sidebar-state';

    function loadState() {
        try {
            var raw = localStorage.getItem(STORAGE_KEY);
            return raw ? JSON.parse(raw) : {};
        } catch (e) {
            return {};
        }
    }

    function saveState(state) {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
        } catch (e) {
            // localStorage unavailable (private browsing, quota, etc.) — degrade silently.
        }
    }

    var state = loadState();
    var detailsElements = document.querySelectorAll('.sidebar details[data-path]');

    detailsElements.forEach(function (details) {
        var path = details.getAttribute('data-path');

        if (Object.prototype.hasOwnProperty.call(state, path)) {
            details.open = state[path];
        }

        details.addEventListener('toggle', function () {
            state[path] = details.open;
            saveState(state);
        });
    });
})();
