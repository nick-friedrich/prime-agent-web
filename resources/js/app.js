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

const chatRoot = $('[data-chat]');
if (chatRoot) {
    const transcriptNode = $('[data-chat-transcript]', chatRoot);
    const scrollNode = $('[data-chat-scroll]', chatRoot);
    const emptyNode = $('[data-chat-empty]', chatRoot);
    const composer = $('[data-chat-composer]', chatRoot);
    const input = $('[data-chat-input]', chatRoot);
    const feedback = $('[data-chat-feedback]', chatRoot);
    const count = $('[data-chat-count]', chatRoot);
    const currentActivity = $('[data-current-activity]', chatRoot);
    const sendButton = $('[data-composer-send]', chatRoot);
    const stopButton = $('[data-composer-stop]', chatRoot);
    const initialNode = $('[data-chat-initial]');
    let etag = null;
    let polling = false;

    const atBottom = () => scrollNode.scrollHeight - scrollNode.scrollTop - scrollNode.clientHeight < 90;
    const formatTime = value => {
        if (!value) return '';
        const date = new Date(value);
        return Number.isNaN(date.valueOf()) ? '' : new Intl.DateTimeFormat(undefined, { hour: '2-digit', minute: '2-digit' }).format(date);
    };
    const codeBlock = (label, value) => {
        if (!value) return null;
        const block = document.createElement('div');
        const heading = document.createElement('small');
        const pre = document.createElement('pre');
        heading.textContent = label;
        pre.textContent = value;
        block.append(heading, pre);
        return block;
    };
    const formatDuration = milliseconds => {
        if (milliseconds === null || milliseconds === undefined) return '';
        return milliseconds < 1000 ? `${milliseconds}ms` : `${(milliseconds / 1000).toFixed(milliseconds < 10000 ? 1 : 0)}s`;
    };
    const activityDetails = item => {
        const values = [];
        if (item.inputLines) values.push(`↑ ${item.inputLines}`);
        if (item.outputLines) values.push(`↓ ${item.outputLines} ${item.outputLines === 1 ? 'line' : 'lines'}`);
        if (item.durationMs !== null && item.durationMs !== undefined) values.push(formatDuration(item.durationMs));
        if (item.errorName) values.push(item.errorName);
        return values.join(' · ');
    };
    const activityRow = (item, expanded, kind) => {
        const details = document.createElement('details');
        details.className = `timeline-activity ${kind}${item.error ? ' error' : ''}${item.current ? ' current' : ''}`;
        details.dataset.entryId = item.id;
        details.open = expanded.has(item.id);
        const summary = document.createElement('summary');
        const icon = document.createElement('span');
        const label = document.createElement('strong');
        const preview = document.createElement('code');
        const metrics = document.createElement('small');
        icon.className = 'timeline-icon';
        icon.textContent = item.error ? '×' : item.current ? '◆' : kind === 'thinking' ? '◌' : kind === 'agent-message' ? '◆' : '✓';
        label.textContent = kind === 'thinking' ? 'Thinking' : kind === 'agent-message' ? `Agent message · ${item.sender || 'subagent'}` : (item.language || item.name || 'tool');
        preview.textContent = kind === 'thinking' ? item.summary : item.preview;
        metrics.textContent = kind === 'agent-message' ? (item.relationship || 'received') : activityDetails(item);
        summary.append(icon, label, preview, metrics);
        const body = document.createElement('div');
        body.className = 'timeline-body';
        if (kind === 'thinking' || kind === 'agent-message') {
            const content = document.createElement('div');
            content.className = 'message-content';
            content.innerHTML = item.html || '';
            body.append(content);
        } else {
            const argumentsBlock = codeBlock('Input', item.arguments);
            const outputBlock = codeBlock(item.error ? 'Error output' : 'Output', item.output);
            const diffsBlock = codeBlock('Changes', item.diffs);
            if (argumentsBlock) body.append(argumentsBlock);
            if (outputBlock) body.append(outputBlock);
            if (diffsBlock) body.append(diffsBlock);
            if (item.truncated) {
                const notice = document.createElement('p');
                notice.textContent = 'Output truncated to keep this chat responsive.';
                body.append(notice);
            }
        }
        details.append(summary, body);
        return details;
    };
    const renderItem = (item, expanded) => {
        if (item.type === 'tool') {
            return activityRow(item, expanded, 'tool');
        }
        if (item.type === 'thinking') return activityRow(item, expanded, 'thinking');
        if (item.type === 'agent_message') return activityRow(item, expanded, 'agent-message');

        const article = document.createElement('article');
        article.className = `chat-message ${item.role}`;
        article.dataset.entryId = item.id;
        const meta = document.createElement('div');
        const author = document.createElement('strong');
        const time = document.createElement('time');
        author.textContent = item.label || (item.role === 'user' ? 'You' : item.role === 'assistant' ? 'Prime Agent' : 'System');
        time.textContent = formatTime(item.timestamp);
        meta.append(author, time);
        const content = document.createElement('div');
        content.className = 'message-content';
        content.innerHTML = item.html || '';
        article.append(meta, content);
        return article;
    };
    const render = payload => {
        const shouldFollow = atBottom() || transcriptNode.children.length === 0;
        const expanded = new Set($$('details[open][data-entry-id]', transcriptNode).map(node => node.dataset.entryId));
        const items = payload.transcript?.items || [];
        transcriptNode.replaceChildren(...items.map(item => renderItem(item, expanded)));
        emptyNode.hidden = items.length > 0;
        if (payload.transcript?.available === false) {
            emptyNode.hidden = false;
            $('h2', emptyNode).textContent = 'Transcript unavailable';
            $('p', emptyNode).textContent = payload.transcript.error || 'Prime Agent has not written this transcript yet.';
        }
        const agent = payload.agent || {};
        const title = $('[data-chat-title]', chatRoot);
        if (title && agent.firstMessage) {
            const displayTitle = agent.firstMessage.length > 72 ? `${agent.firstMessage.slice(0, 69)}...` : agent.firstMessage;
            title.textContent = displayTitle;
            document.title = `${displayTitle} — Prime Agent`;
        }
        if (count) count.textContent = agent.messageCount ?? items.filter(item => item.type === 'message').length;
        const canStop = agent.activity === 'working' && Boolean(agent.activeSessionId);
        sendButton.hidden = canStop;
        stopButton.hidden = !canStop;
        const status = $('[data-chat-status]', chatRoot);
        if (status) {
            const archived = agent.lifecycle === 'archived';
            const working = agent.activity === 'working';
            status.className = `status-pill ${working ? 'running' : archived ? 'paused' : 'idle'}`;
            status.replaceChildren(document.createElement('i'), document.createTextNode(archived ? 'archived' : working ? 'working' : 'idle'));
        }
        const activity = payload.transcript?.currentActivity || {};
        if (currentActivity) {
            currentActivity.className = `current-activity ${activity.kind || 'idle'}${activity.active ? ' active' : ''}`;
            $('[data-current-activity-label]', currentActivity).textContent = activity.label || 'Ready for input';
            const detail = $('[data-current-activity-detail]', currentActivity);
            detail.textContent = activity.detail || '';
            detail.hidden = !activity.detail;
        }
        if (shouldFollow) requestAnimationFrame(() => { scrollNode.scrollTop = scrollNode.scrollHeight; });
    };
    const poll = async () => {
        if (polling || document.hidden) return;
        polling = true;
        try {
            const headers = { Accept: 'application/json' };
            if (etag) headers['If-None-Match'] = etag;
            const response = await fetch(chatRoot.dataset.transcriptUrl, { headers });
            if (response.status === 304) return;
            const body = await response.json();
            if (!response.ok) throw new Error(body.message || 'Could not refresh the transcript.');
            etag = response.headers.get('ETag');
            render(body);
        } catch (error) {
            feedback.textContent = error.message || 'Could not refresh the transcript.';
            feedback.classList.add('error');
        } finally {
            polling = false;
        }
    };

    try { render(JSON.parse(initialNode.textContent)); } catch { poll(); }
    setInterval(poll, 2000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });

    const resizeInput = () => {
        input.style.height = 'auto';
        input.style.height = `${Math.min(input.scrollHeight, 180)}px`;
    };
    input.addEventListener('input', resizeInput);
    input.addEventListener('keydown', event => {
        if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
            event.preventDefault();
            composer.requestSubmit();
        }
    });
    composer.addEventListener('submit', async event => {
        event.preventDefault();
        const message = input.value.trim();
        if (!message) return;
        sendButton.disabled = true;
        input.disabled = true;
        feedback.classList.remove('error', 'success');
        feedback.textContent = 'Sending…';
        try {
            const response = await fetch(chatRoot.dataset.messageUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify({ message }),
            });
            const body = await response.json();
            if (!response.ok) {
                const validation = body.errors?.message?.[0];
                throw new Error(validation || body.message || 'Prime Agent did not accept the message.');
            }
            input.value = '';
            resizeInput();
            const delivery = body.receipt?.deliveryStatus === 'queued' ? 'Queued for the agent.' : 'Delivered to the agent.';
            feedback.textContent = delivery;
            feedback.classList.add('success');
            etag = null;
            await poll();
        } catch (error) {
            feedback.textContent = error.message || 'Could not send the message.';
            feedback.classList.add('error');
        } finally {
            sendButton.disabled = false;
            input.disabled = false;
            input.focus();
        }
    });

    stopButton.addEventListener('click', async () => {
        if (!window.confirm('Stop this agent? You can resume its saved session later.')) return;
        stopButton.disabled = true;
        feedback.classList.remove('error', 'success');
        feedback.textContent = 'Stopping…';
        try {
            const response = await fetch(chatRoot.dataset.stopUrl, {
                method: 'DELETE',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').content,
                },
            });
            const body = await response.json();
            if (!response.ok) throw new Error(body.message || 'Prime Agent could not stop the agent.');
            window.location.assign(body.redirect);
        } catch (error) {
            feedback.textContent = error.message || 'Could not stop the agent.';
            feedback.classList.add('error');
            stopButton.disabled = false;
        }
    });
}
