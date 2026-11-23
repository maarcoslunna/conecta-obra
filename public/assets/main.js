(() => {
  /* El menú móvil (drawer) lo gestiona ui.js */

  document.querySelectorAll('[data-year]').forEach(el => el.textContent = new Date().getFullYear());

  const items = document.querySelectorAll('[data-anim]');
  if ('IntersectionObserver' in window && items.length) {
    const io = new IntersectionObserver(entries => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          entry.target.classList.add('in-view');
          io.unobserve(entry.target);
        }
      });
    }, { threshold: .12 });
    items.forEach(el => io.observe(el));
  } else {
    items.forEach(el => el.classList.add('in-view'));
  }
})();