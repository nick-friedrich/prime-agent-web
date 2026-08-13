const $ = (selector, root = document) => root.querySelector(selector);
const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];

$$('[data-open-modal]').forEach(button => button.addEventListener('click', () => {
    document.getElementById(button.dataset.openModal)?.showModal();
}));
$$('[data-close-modal]').forEach(button => button.addEventListener('click', () => button.closest('dialog')?.close()));
$$('dialog').forEach(dialog => dialog.addEventListener('click', event => {
    if (event.target === dialog) dialog.close();
}));

const directoryPicker = $('[data-directory-picker]');
if (directoryPicker) {
    const projectName = $('[data-project-name]');
    const projectPath = $('[data-project-path]');
    const searchInput = $('[data-directory-search]', directoryPicker);
    const searchResults = $('[data-directory-results]', directoryPicker);
    const browseResults = $('[data-directory-browse-results]', directoryPicker);
    const currentPath = $('[data-directory-current]', directoryPicker);
    const upButton = $('[data-directory-up]', directoryPicker);
    const useButton = $('[data-directory-use]', directoryPicker);
    let searchTimer;
    let searchRequest = 0;
    let searchLoaded = false;
    let activeSearchResult = -1;
    let browseLoaded = false;
    let browsePath = null;
    let browseParent = null;

    const pickerMessage = (container, message, error = false) => {
        container.replaceChildren();
        const paragraph = document.createElement('p');
        paragraph.textContent = message;
        if (error) paragraph.className = 'directory-error';
        container.append(paragraph);
    };
    const humanize = name => name.replace(/[-_]+/g, ' ').replace(/\b\w/g, character => character.toUpperCase());
    const chooseDirectory = (path, name) => {
        projectPath.value = path;
        projectPath.dispatchEvent(new Event('change', { bubbles: true }));
        if (!projectName.value.trim()) projectName.value = humanize(name);
    };
    const responseJson = async response => {
        const body = await response.json();
        if (!response.ok) throw new Error(body.message || 'The directory request failed.');
        return body;
    };

    const renderSearchResults = repositories => {
        searchResults.replaceChildren();
        activeSearchResult = -1;
        if (!repositories.length) return pickerMessage(searchResults, 'No matching Git repositories found.');
        repositories.forEach((repository, index) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.setAttribute('role', 'option');
            button.dataset.resultIndex = index;
            const name = document.createElement('strong');
            const path = document.createElement('code');
            name.textContent = repository.name;
            path.textContent = repository.path;
            button.append(name, path);
            button.addEventListener('click', () => chooseDirectory(repository.path, repository.name));
            searchResults.append(button);
        });
    };
    const searchRepositories = async () => {
        const request = ++searchRequest;
        pickerMessage(searchResults, 'Searching local repositories…');
        try {
            const url = new URL(directoryPicker.dataset.searchUrl, window.location.origin);
            if (searchInput.value.trim()) url.searchParams.set('q', searchInput.value.trim());
            const body = await responseJson(await fetch(url, { headers: { Accept: 'application/json' } }));
            if (request === searchRequest) renderSearchResults(body.repositories || []);
        } catch (error) {
            if (request === searchRequest) pickerMessage(searchResults, error.message || 'Could not search local repositories.', true);
        }
    };
    const setActiveSearchResult = index => {
        const results = $$('[role="option"]', searchResults);
        if (!results.length) return;
        activeSearchResult = (index + results.length) % results.length;
        results.forEach((result, resultIndex) => {
            result.classList.toggle('active', resultIndex === activeSearchResult);
            result.setAttribute('aria-selected', resultIndex === activeSearchResult ? 'true' : 'false');
        });
        results[activeSearchResult].scrollIntoView({ block: 'nearest' });
    };

    searchInput.addEventListener('input', () => {
        searchLoaded = true;
        clearTimeout(searchTimer);
        searchTimer = setTimeout(searchRepositories, 180);
    });
    searchInput.addEventListener('keydown', event => {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            setActiveSearchResult(activeSearchResult + (event.key === 'ArrowDown' ? 1 : -1));
        } else if (event.key === 'Enter' && activeSearchResult >= 0) {
            event.preventDefault();
            $$('[role="option"]', searchResults)[activeSearchResult]?.click();
        }
    });

    const browse = async path => {
        pickerMessage(browseResults, 'Loading folders…');
        try {
            const url = new URL(directoryPicker.dataset.browseUrl, window.location.origin);
            if (path) url.searchParams.set('path', path);
            const body = await responseJson(await fetch(url, { headers: { Accept: 'application/json' } }));
            browseLoaded = true;
            browsePath = body.current;
            browseParent = body.parent;
            currentPath.textContent = body.current;
            upButton.disabled = !body.parent;
            useButton.hidden = !body.is_git;
            browseResults.replaceChildren();
            if (!body.directories?.length) pickerMessage(browseResults, 'This folder has no visible subdirectories.');
            (body.directories || []).forEach(directory => {
                const button = document.createElement('button');
                button.type = 'button';
                const icon = document.createElement('span');
                const details = document.createElement('span');
                const name = document.createElement('strong');
                const hint = document.createElement('small');
                icon.textContent = directory.is_git ? '⌘' : '›';
                name.textContent = directory.name;
                hint.textContent = directory.is_git ? 'Git repository' : 'Folder';
                details.append(name, hint);
                button.append(icon, details);
                button.addEventListener('click', () => browse(directory.path));
                browseResults.append(button);
            });
        } catch (error) {
            pickerMessage(browseResults, error.message || 'Could not browse this directory.', true);
        }
    };

    upButton.addEventListener('click', () => { if (browseParent) browse(browseParent); });
    useButton.addEventListener('click', () => { if (browsePath) chooseDirectory(browsePath, browsePath.split('/').filter(Boolean).pop() || 'Project'); });
    $$('[data-directory-mode]', directoryPicker).forEach(tab => tab.addEventListener('click', () => {
        $$('[data-directory-mode]', directoryPicker).forEach(item => {
            const selected = item === tab;
            item.classList.toggle('active', selected);
            item.setAttribute('aria-selected', selected ? 'true' : 'false');
        });
        $$('[data-directory-panel]', directoryPicker).forEach(panel => { panel.hidden = panel.dataset.directoryPanel !== tab.dataset.directoryMode; });
        if (tab.dataset.directoryMode === 'browse' && !browseLoaded) browse();
        if (tab.dataset.directoryMode === 'search') {
            searchInput.focus();
            if (!searchLoaded) {
                searchLoaded = true;
                searchRepositories();
            }
        }
    }));

    $$('[data-open-modal="project-modal"]').forEach(button => button.addEventListener('click', () => {
        if (!searchLoaded) {
            searchLoaded = true;
            searchRepositories();
        }
    }));
}

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
