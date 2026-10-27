'use strict';

document.querySelectorAll('img[data-fallback]').forEach((img) => {
  img.addEventListener('error', () => {
    const fallback = img.dataset.fallback;
    if (fallback && img.getAttribute('src') !== fallback) img.src = fallback;
  }, { once: true });
});

const search = document.querySelector('#satellite-search');
document.addEventListener('keydown', (event) => {
  if (event.key === '/' && search && document.activeElement !== search && !/INPUT|TEXTAREA|SELECT/.test(document.activeElement?.tagName || '')) {
    event.preventDefault();
    search.focus();
  }
  if (event.key === 'Escape' && document.activeElement === search && search.value) {
    search.value = '';
  }
});
