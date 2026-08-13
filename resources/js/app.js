const $ = (selector, root = document) => root.querySelector(selector);
const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];

$$('[data-open-modal]').forEach(button => button.addEventListener('click', () => {
    document.getElementById(button.dataset.openModal)?.showModal();
}));
$$('[data-close-modal]').forEach(button => button.addEventListener('click', () => button.closest('dialog')?.close()));
$$('dialog').forEach(dialog => dialog.addEventListener('click', event => {
    if (event.target === dialog) dialog.close();
}));

$$('.card-menu').forEach(button => button.addEventListener('click', event => {
    event.stopPropagation();
    const wrap = button.closest('.agent-menu-wrap');
    $$('.agent-menu-wrap.open').filter(item => item !== wrap).forEach(item => item.classList.remove('open'));
    wrap.classList.toggle('open');
}));
document.addEventListener('click', () => $$('.agent-menu-wrap.open').forEach(item => item.classList.remove('open')));

const filters = $('.filters');
$('[data-filter-toggle]')?.addEventListener('click', () => { filters.hidden = !filters.hidden; });
$$('[data-status-filter]').forEach(button => button.addEventListener('click', () => {
    $$('[data-status-filter]').forEach(item => item.classList.remove('active'));
    button.classList.add('active');
    $$('.agent-card').forEach(card => card.hidden = button.dataset.statusFilter !== 'all' && card.dataset.status !== button.dataset.statusFilter);
}));
$('[data-view-toggle]')?.addEventListener('click', () => $('#agent-grid')?.classList.toggle('list'));

const palette = $('.command-palette');
const togglePalette = open => {
    palette.hidden = !open;
    if (open) setTimeout(() => $('input', palette)?.focus(), 20);
};
$('[data-search]')?.addEventListener('click', () => togglePalette(true));
palette?.addEventListener('click', event => { if (event.target === palette) togglePalette(false); });
document.addEventListener('keydown', event => {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') { event.preventDefault(); togglePalette(true); }
    if (event.key === 'Escape' && palette && !palette.hidden) togglePalette(false);
});

const sidebar = $('#sidebar');
$('[data-open-nav]')?.addEventListener('click', () => {
    sidebar.classList.add('open');
    const overlay = document.createElement('div'); overlay.className = 'sidebar-overlay'; document.body.append(overlay);
    overlay.addEventListener('click', closeNav);
});
function closeNav(){ sidebar?.classList.remove('open'); $('.sidebar-overlay')?.remove(); }
$('[data-close-nav]')?.addEventListener('click', closeNav);

$$('.toast').forEach(toast => {
    $('button', toast)?.addEventListener('click', () => toast.remove());
    setTimeout(() => toast.remove(), 5000);
});
