/* Theme + sidebar bootstrapping for the upgraded Laptop Tracking System */
(function () {
    var STORAGE_KEY = 'lts.theme';
    var SIDEBAR_KEY = 'lts.sidebar';

    function applyTheme(t) {
        document.documentElement.setAttribute('data-theme', t);
        var icon = document.getElementById('themeToggleIcon');
        if (icon) icon.className = t === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
    }

    function getInitial() {
        try {
            var s = localStorage.getItem(STORAGE_KEY);
            if (s) return s;
        } catch (e) {}
        return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }

    // Apply ASAP (before paint) — script is included in <head>
    applyTheme(getInitial());

    document.addEventListener('DOMContentLoaded', function () {
        applyTheme(getInitial());

        // Theme toggle
        var btn = document.getElementById('themeToggleBtn');
        if (btn) {
            btn.addEventListener('click', function () {
                var current = document.documentElement.getAttribute('data-theme') || 'light';
                var next = current === 'dark' ? 'light' : 'dark';
                applyTheme(next);
                try { localStorage.setItem(STORAGE_KEY, next); } catch (e) {}
            });
        }

        // Sidebar toggle
        var shell = document.querySelector('.app-shell');
        var sidebar = document.querySelector('.app-sidebar');
        var backdrop = document.querySelector('.sidebar-backdrop');
        var menuBtn = document.getElementById('menuToggleBtn');

        try {
            if (localStorage.getItem(SIDEBAR_KEY) === 'mini' && shell && window.innerWidth > 992) {
                shell.classList.add('sidebar-mini');
            }
        } catch (e) {}

        if (menuBtn && shell) {
            menuBtn.addEventListener('click', function () {
                if (window.innerWidth <= 992) {
                    if (sidebar) sidebar.classList.toggle('show');
                    if (backdrop) backdrop.classList.toggle('show');
                } else {
                    shell.classList.toggle('sidebar-mini');
                    try {
                        localStorage.setItem(SIDEBAR_KEY, shell.classList.contains('sidebar-mini') ? 'mini' : 'full');
                    } catch (e) {}
                }
            });
        }
        if (backdrop && sidebar) {
            backdrop.addEventListener('click', function () {
                sidebar.classList.remove('show');
                backdrop.classList.remove('show');
            });
        }
    });
})();
