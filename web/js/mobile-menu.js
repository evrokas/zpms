/**
 * Mobile Sidebar Toggle
 * Phase 7: Layout & Responsive Design
 * Supports both .sidebar (legacy) and .app-sidebar (new) class names
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var hamburger = document.querySelector('.hamburger-toggle');
        var sidebar = document.querySelector('.app-sidebar, .sidebar');
        var backdrop = document.querySelector('.sidebar-backdrop');

        if (!hamburger || !sidebar) return;

        function openSidebar() {
            sidebar.classList.add('is-open');
            hamburger.classList.add('is-open');
            document.body.classList.add('sidebar-open');
            if (backdrop) {
                backdrop.classList.add('is-visible');
            }
        }

        function closeSidebar() {
            sidebar.classList.remove('is-open');
            hamburger.classList.remove('is-open');
            document.body.classList.remove('sidebar-open');
            if (backdrop) {
                backdrop.classList.remove('is-visible');
            }
        }

        hamburger.addEventListener('click', function () {
            if (sidebar.classList.contains('is-open')) {
                closeSidebar();
            } else {
                openSidebar();
            }
        });

        if (backdrop) {
            backdrop.addEventListener('click', closeSidebar);
        }

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && sidebar.classList.contains('is-open')) {
                closeSidebar();
            }
        });
    });
}());
