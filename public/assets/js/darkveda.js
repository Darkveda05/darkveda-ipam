/* DarkVeda IPAM — client behavior */
(function () {
  'use strict';

  // ---------- Theme toggle (dark default) ----------
  const root = document.documentElement;
  const saved = localStorage.getItem('dv-theme');
  if (saved === 'light' || saved === 'dark') {
    root.setAttribute('data-bs-theme', saved);
  }

  const toggle = document.getElementById('themeToggle');
  if (toggle) {
    const icon = toggle.querySelector('i');
    const sync = () => {
      const dark = root.getAttribute('data-bs-theme') === 'dark';
      icon.className = dark ? 'bi bi-sun' : 'bi bi-moon-stars';
    };
    sync();
    toggle.addEventListener('click', () => {
      const next = root.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
      root.setAttribute('data-bs-theme', next);
      localStorage.setItem('dv-theme', next);
      sync();
    });
  }

  // ---------- Delete confirmations ----------
  document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (e) => {
      if (!window.confirm(form.dataset.confirm)) e.preventDefault();
    });
  });
})();
