/* One collection query feeds both views; all personal writes use reviewed transactions. */
(() => {
    'use strict';
    const root = document.querySelector('.personal-library');
    if (!root) return;
    const base = window.APPLICATION_URL || '';
    const $ = id => document.getElementById(id);
    const page = root.dataset.screen;
    const params = new URLSearchParams(location.search);
    const statusLabel = value => ({seen: 'Watched', unseen: 'Not watched', unknown: 'Unknown'})[value] || value;
    const languageLabel = code => { try {return new Intl.DisplayNames([navigator.language], {type:'language'}).of(code);} catch {return code;} };
    const reactionWords = ['Loved', 'Liked', 'Okay', 'Disliked', 'Strongly disliked'];
    const kindLabel = value => ({explicit: 'Your preference', approved_insight: 'Approved model interpretation', imported_context: 'Imported background · original wording preserved'})[value] || value;
    const el = (tag, text, className) => {
        const node = document.createElement(tag);
        if (text !== undefined && text !== null) node.textContent = text;
        if (className) node.className = className;
        return node;
    };
    function error(message) {
        $('library-alert').textContent = message;
        $('library-alert').hidden = false;
    }
    async function api(action, data = {}) {
        const response = await fetch(`${base}/library-api/${action}`, {
            method: 'POST', credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content},
            body: JSON.stringify(data)
        });
        const value = await response.json();
        if (!response.ok || value.error) throw new Error(value.error || 'The request could not finish.');
        return value;
    }
    function link(text, path, className) {
        const node = el('a', text, className);
        node.href = base + path;
        return node;
    }
    function poster(url, title, className = 'title-poster') {
        const wrap = el('div', undefined, className);
        if (url) {
            const image = el('img'); image.src = url; image.alt = ''; image.loading = 'lazy';
            image.addEventListener('error', () => { image.remove(); wrap.append(el('span', title)); }, {once: true});
            wrap.append(image);
        } else wrap.append(el('span', title));
        return wrap;
    }
    function run(fn) { return Promise.resolve().then(fn).catch(e => error(e.message)); }
    function stableRequest() {
        return window.crypto.randomUUID ? crypto.randomUUID() : Date.now().toString(36) + Math.random().toString(36).slice(2) + 'request';
    }
    async function save(changes, requestId) {
        const preview = await api('preview', {changes, requestId});
        // The visible form is the human review. This token commits only that exact proposal.
        return api('commit', {id: preview.id, approval: preview.approval});
    }
    function collectNames(items) { return (items || []).map(x => x.name).join(', '); }

    async function collection() {
        const form = $('collection-filters');
        const interest = page === 'watchlist' ? (params.get('interest') === 'not_interested' ? 'not_interested' : 'want') : '';
        if (interest === 'not_interested') {
            document.title = 'Not interested | Movies & TV';
            $('collection-heading').textContent = 'Not interested';
            $('collection-description').textContent = 'Titles you don’t want suggested. This doesn’t change whether you’ve watched them.';
            $('interest-alternate').textContent = 'Back to wishlist'; $('interest-alternate').href = base + '/watchlist';
        }
        let offset = 0, total = 0, sequence = 0;
        let view = localStorage.getItem('personalLibraryView') || 'grid';
        function setView(value) {
            view = value; localStorage.setItem('personalLibraryView', value);
            $('collection-grid').hidden = value !== 'grid'; $('collection-table').hidden = value !== 'table';
            for (const mode of ['grid', 'table']) {
                $(`view-${mode}`).classList.toggle('active', mode === value);
                $(`view-${mode}`).setAttribute('aria-pressed', String(mode === value));
            }
        }
        $('view-grid').onclick = () => setView('grid'); $('view-table').onclick = () => setView('table'); setView(view);
        for (const [name, value] of params) {
            const control = form.elements.namedItem(name);
            if (control) control.value = value;
        }
        form.elements.namedItem('status').value = page === 'library' ? 'seen' : 'all';
        if (params.has('genre') || params.has('person')) { $('collection-extra').hidden = false; $('more-filters').setAttribute('aria-expanded', 'true'); }
        $('more-filters').onclick = () => {
            $('collection-extra').hidden = !$('collection-extra').hidden;
            $('more-filters').setAttribute('aria-expanded', String(!$('collection-extra').hidden));
        };
        const facets = await api('facets');
        for (const kind of ['genre', 'person', 'language']) {
            const select = form.elements.namedItem(kind);
            const seen = new Set();
            for (const item of facets.items.filter(x => x.kind === kind)) {
                if (kind === 'person' && !['Director', 'Creator'].includes(item.role) && item.provider_id !== params.get('person')) continue;
                if (seen.has(item.provider_id)) continue; seen.add(item.provider_id);
                select.append(new Option(kind === 'language' ? languageLabel(item.label) : item.label, item.provider_id));
            }
            if (params.has(kind)) select.value = params.get(kind);
        }
        const reactions = [...new Set([...reactionWords, ...facets.items.filter(x => x.kind === 'reaction').map(x => x.label)])];
        reactions.forEach(word => form.elements.namedItem('reaction').append(new Option(word, word)));
        if (params.has('reaction')) form.elements.namedItem('reaction').value = params.get('reaction');
        async function load() {
            const ticket = ++sequence;
            const filters = Object.fromEntries(new FormData(form));
            filters.status = page === 'library' ? 'seen' : 'all';
            if (interest) filters.interest = interest;
            const result = await api('search', {...filters, offset, limit: 25});
            if (ticket !== sequence) return;
            total = result.total;
            $('collection-grid').replaceChildren(); $('collection-table').querySelector('tbody').replaceChildren();
            for (const title of result.items) {
                const detailPath = `/titles/${title.id}`;
                const card = link('', detailPath, 'poster-card');
                card.append(poster(title.metadata.poster, title.title, 'poster-cover'), el('div', title.title, 'poster-name'), el('div', `${title.release_year || 'Year unknown'} · ${title.media_type === 'tv' ? 'TV' : 'Movie'}`, 'poster-year text-body-secondary'));
                card.append(el('div', title.reaction || (page === 'library' ? 'No reaction yet' : ''), 'poster-reaction'));
                $('collection-grid').append(card);
                const row = el('tr');
                const nameCell = el('td'); nameCell.append(link(title.title, detailPath, 'library-title'), el('span', title.release_year, 'text-body-secondary ms-2'));
                row.append(nameCell, el('td', title.media_type === 'tv' ? 'TV' : 'Movie'), el('td', title.reaction || '—'), el('td', collectNames(title.metadata.genres) || '—', 'text-body-secondary'));
                $('collection-table').querySelector('tbody').append(row);
            }
            $('collection-count').textContent = `${total} ${total === 1 ? 'title' : 'titles'}`;
            $('collection-empty').hidden = total !== 0;
            $('collection-pagination')?.setAttribute('aria-busy', 'false');
            $('page-previous').disabled = offset === 0; $('page-next').disabled = result.nextOffset === null;
            $('page-label').textContent = total ? `${offset + 1}–${Math.min(total, offset + 25)} of ${total}` : '';
            const query = new URLSearchParams();
            for (const [key, value] of Object.entries(filters)) if (value && value !== 'all' && key !== 'status') query.set(key, value);
            history.replaceState(null, '', location.pathname + (query.size ? '?' + query : ''));
        }
        let timer;
        form.addEventListener('submit', e => {e.preventDefault(); offset = 0; run(load);});
        form.addEventListener('input', () => {clearTimeout(timer); timer = setTimeout(() => {offset = 0; run(load);}, 220);});
        form.addEventListener('change', () => {clearTimeout(timer); offset = 0; run(load);});
        form.addEventListener('reset', () => setTimeout(() => {offset = 0; run(load);}, 0));
        $('page-next').onclick = () => {offset += 25; run(load);}; $('page-previous').onclick = () => {offset = Math.max(0, offset - 25); run(load);};
        await load();
    }

    async function quickLog() {
        let selected, local = null, searchPage = 1, query = '', mediaType = 'all';
        let requestId = stableRequest(), frozenChanges = null;
        const today = new Date(); today.setMinutes(today.getMinutes() - today.getTimezoneOffset());
        $('watch-date').value = today.toISOString().slice(0, 10);
        const initialAction = params.get('action') || 'log_watch';
        const resolveId = params.get('resolve');
        if (initialAction === 'set_interest') $('log-heading').textContent = 'Save a title';
        if (resolveId) $('log-heading').textContent = 'Match this title';
        function mode() {
            const action = $('watch-action').value;
            $('watch-details').hidden = action === 'set_interest' || !!resolveId;
            $('interest-field').hidden = action !== 'set_interest' || !!resolveId;
            $('progress-field').hidden = action !== 'set_progress';
            $('date-field').hidden = action === 'edit_assessment';
            $('season-field').hidden = !selected || selected.mediaType !== 'tv';
            const scope = $('watch-season').value ? `season ${$('watch-season').value}` : selected?.mediaType === 'tv' ? 'the overall show' : 'this movie';
            $('watch-review').textContent = resolveId ? 'This only links provider metadata. Your viewing history and reaction stay as they are.' : action === 'set_interest' ? 'This changes your preference without adding a viewing.' : action === 'edit_assessment' ? `Save your assessment of ${scope}. No new viewing will be added.` : action === 'set_progress' ? `Save progress and any reaction for ${scope}. Other seasons and the overall reaction stay separate.` : `Log ${local?.viewing_status === 'seen' ? 'another' : 'a'} viewing of ${scope} · ${$('date-unknown').checked ? 'Unknown date' : $('watch-date').value}.`;
            $('watch-date').disabled = $('date-unknown').checked;
            $('date-caption').textContent = $('date-unknown').checked ? 'No date will be inferred.' : $('watch-date').value === today.toISOString().slice(0, 10) ? 'Today' : 'Selected date';
        }
        async function select(candidate) {
            selected = candidate; local = null; requestId = stableRequest(); frozenChanges = null;
            for (const control of $('watch-form').querySelectorAll('input,textarea,select,button[data-reaction]')) control.disabled = false;
            $('date-unknown').checked = false; $('watch-date').value = today.toISOString().slice(0,10);
            if (candidate.localId) local = (await api('title', {id: candidate.localId})).title;
            else if (candidate.mediaType === 'tv') {
                const details = (await api('catalog-title', {mediaType: candidate.mediaType, tmdbId: candidate.tmdbId})).metadata;
                candidate.seasons = details.seasons;
            }
            $('catalog-results').replaceChildren(); $('catalog-more').hidden = true; $('log-success').hidden = true;
            const match = $('selected-title'); match.replaceChildren(poster(candidate.poster, candidate.title, 'selected-poster'));
            const info = el('div'); info.append(el('h2', candidate.title), el('p', `${candidate.year || 'Year unknown'} · ${candidate.mediaType === 'tv' ? 'TV show' : 'Movie'}`, 'text-body-secondary mb-0')); match.append(info);
            $('existing-context').textContent = local ? `${statusLabel(local.viewing_status)}${local.reaction ? ' · ' + local.reaction : ''}` : 'No viewing record in your library yet.';
            $('watch-reaction').value = initialAction === 'edit_assessment' ? local?.reaction || '' : '';
            $('watch-note').value = initialAction === 'edit_assessment' ? local?.note || '' : '';
            $('watch-interest').value = local?.interest === 'none' ? 'want' : local?.interest || 'want';
            $('watch-action').value = initialAction;
            $('watch-action').querySelector('[value="edit_assessment"]').disabled = local?.viewing_status !== 'seen';
            $('watch-action').querySelector('[value="set_progress"]').disabled = candidate.mediaType !== 'tv';
            $('watch-action').querySelector('[value="log_watch"]').textContent = local?.viewing_status === 'seen' ? 'Log another viewing' : 'Log this viewing';
            $('watch-season').replaceChildren(new Option('Overall show', ''));
            const seasons = local?.metadata?.seasons || candidate.seasons || [];
            for (const season of seasons) $('watch-season').append(new Option(season.name || `Season ${season.season_number}`, season.season_number));
            $('log-mode').hidden = !!resolveId; $('watch-form').hidden = false; mode(); $('watch-reaction').focus();
        }
        async function search(append = false) {
            const result = await api('catalog', {query, mediaType, page: searchPage});
            if (!append) $('catalog-results').replaceChildren();
            if (!result.items.length && !append) $('catalog-results').append(el('p', 'No matches. Try a different title or search type.', 'text-body-secondary'));
            for (const match of result.items) {
                const button = el('button', undefined, 'catalog-match'); button.type = 'button';
                button.append(poster(match.poster, match.title, 'match-poster'));
                const label = el('span'); label.append(el('strong', match.title), el('span', `${match.year || 'Year unknown'} · ${match.mediaType === 'tv' ? 'TV show' : 'Movie'}${match.viewingStatus === 'seen' ? ' · Watched' : ''}`, 'small text-body-secondary')); button.append(label);
                button.onclick = () => run(() => select(match)); $('catalog-results').append(button);
            }
            $('catalog-more').hidden = result.nextPage === null;
        }
        $('catalog-search').onsubmit = e => {e.preventDefault(); query = $('catalog-query').value; mediaType = $('catalog-type').value; searchPage = 1; $('watch-form').hidden = true; run(() => search());};
        $('catalog-more').onclick = () => {searchPage++; run(() => search(true));};
        $('watch-cancel').onclick = () => {$('watch-form').hidden = true; $('catalog-query').focus();};
        $('watch-form').addEventListener('input', () => {if (!frozenChanges) requestId = stableRequest(); mode();});
        $('watch-action').onchange = () => {
            if ($('watch-action').value === 'edit_assessment' && local && !$('watch-season').value) {
                $('watch-reaction').value = local.reaction || ''; $('watch-note').value = local.note || '';
            }
            mode();
        };
        document.querySelectorAll('[data-reaction]').forEach(button => button.onclick = () => {$('watch-reaction').value = button.dataset.reaction; if (!frozenChanges) requestId = stableRequest();});
        $('watch-form').onsubmit = async e => {
            e.preventDefault(); $('library-alert').hidden = true;
            if (!selected) return;
            const button = $('watch-save'); button.disabled = true; button.textContent = 'Saving…';
            const action = resolveId ? 'resolve_identity' : $('watch-action').value;
            const change = {action, ...(resolveId ? {titleId: resolveId, tmdbId: selected.tmdbId} : local ? {titleId: local.id} : {mediaType: selected.mediaType, tmdbId: selected.tmdbId})};
            if (!['set_interest', 'resolve_identity'].includes(action)) Object.assign(change, {reaction: $('watch-reaction').value, note: $('watch-note').value, date: $('date-unknown').checked ? null : $('watch-date').value, season: selected.mediaType === 'tv' && $('watch-season').value !== '' ? Number($('watch-season').value) : null});
            if (action === 'set_interest') change.interest = $('watch-interest').value;
            if (action === 'set_progress') change.progress = $('watch-progress').value;
            frozenChanges ||= [change];
            for (const control of $('watch-form').querySelectorAll('input,textarea,select,button[data-reaction]')) control.disabled = true;
            try {
                const receipt = await save(frozenChanges, requestId);
                $('watch-form').hidden = true; $('log-success').hidden = false;
                $('log-success').replaceChildren(el('span', 'Saved. '), link('Open title', `/titles/${receipt.titleIds[0]}`));
                frozenChanges = null; requestId = stableRequest();
            } catch (failure) {
                error(failure.message + ' Retry Save to recover this exact request. Choose another title to start a fresh request.');
            } finally {button.disabled = false; button.textContent = 'Save';}
        };
        if (params.get('title')) {
            const title = (await api('title', {id: params.get('title')})).title;
            await select({localId: title.id, title: title.title, year: title.release_year, mediaType: title.media_type, tmdbId: title.tmdb_id, poster: title.metadata.poster});
        }
        if (resolveId) {
            const title = (await api('title', {id: resolveId})).title;
            $('catalog-query').value = title.title; query = title.title; mediaType = title.media_type; $('catalog-type').value = mediaType;
            await search();
        }
    }

    async function titlePage() {
        const id = root.dataset.titleId;
        const result = await api('title', {id}); const title = result.title;
        document.title = `${title.title} | Library`;
        const detail = $('title-detail'); const metadata = title.metadata;
        detail.append(poster(metadata.poster, title.title, 'detail-poster'));
        const content = el('div', undefined, 'title-content');
        content.append(el('h1', title.title), el('p', `${title.release_year || 'Year unknown'} · ${title.media_type === 'tv' ? 'TV show' : 'Movie'}${metadata.language ? ' · ' + languageLabel(metadata.language) : ''}${metadata.runtime && !Array.isArray(metadata.runtime) ? ' · ' + metadata.runtime + ' min' : ''}`, 'text-body-secondary'));
        const tags = el('div', undefined, 'metadata-links');
        for (const genre of metadata.genres || []) tags.append(link(genre.name, `/library?genre=${encodeURIComponent(genre.id)}`, 'btn btn-outline-secondary btn-sm'));
        content.append(tags);
        if (metadata.filmmakers?.length) {
            const makers = el('p', undefined, 'filmmaker-links small mt-3');
            makers.append(el('span', title.media_type === 'tv' ? 'Created by ' : 'Directed by ', 'text-body-secondary'));
            metadata.filmmakers.forEach((person, index) => {if (index) makers.append(el('span', ', ')); makers.append(link(person.name, `/library?person=${encodeURIComponent(person.id)}`));});
            content.append(makers);
        }
        if (metadata.overview) content.append(el('p', metadata.overview, 'title-overview'));
        const assessment = el('section', undefined, 'personal-assessment'); assessment.append(el('h2', 'Your context'), el('p', title.reaction || statusLabel(title.viewing_status), 'current-reaction'));
        if (title.note) assessment.append(el('p', title.note, 'preserve-lines'));
        content.append(assessment);
        const actions = el('div', undefined, 'title-actions');
        actions.append(link(title.viewing_status === 'seen' ? 'Add another viewing' : 'Mark as watched', `/log-watch?title=${id}`, 'btn btn-primary'));
        if (title.viewing_status === 'seen') actions.append(link('Edit reaction', `/log-watch?title=${id}&action=edit_assessment`, 'btn btn-outline-secondary'));
        actions.append(link(title.interest === 'want' ? 'On wishlist' : title.interest === 'not_interested' ? 'Not interested' : 'Save to a list', `/log-watch?title=${id}&action=set_interest`, 'btn btn-outline-secondary'));
        content.append(actions);
        if (title.tmdb_id === null) content.append(link('Match title metadata', `/log-watch?resolve=${id}`, 'small d-inline-block mt-3'));
        else {
            const refresh = el('button', 'Refresh metadata', 'btn btn-link btn-sm p-0 mt-3');
            refresh.onclick = () => run(async () => {refresh.disabled = true; await api('refresh', {id}); location.reload();}); content.append(refresh);
            content.append(el('p', title.refreshed_at ? `TMDB · refreshed ${title.refreshed_at.slice(0, 10)}` : 'Metadata is missing; your personal record is preserved.', 'small text-body-secondary mt-2'));
        }
        if (title.progress?.length) {
            content.append(el('h2', 'TV progress', 'mt-4'));
            const seen = new Set();
            for (const entry of title.progress) {
                const scope = entry.season === null ? 'Overall show' : `Season ${entry.season}`;
                if (seen.has(scope)) continue; seen.add(scope);
                content.append(el('p', `${scope} · ${entry.progress.replaceAll('_', ' ')}`));
            }
        }
        if (title.seasonAssessments?.length) {
            content.append(el('h2', 'Season reactions', 'mt-4'));
            for (const entry of title.seasonAssessments) {
                content.append(el('p', `Season ${entry.season} · ${entry.reaction || 'No current reaction'}`));
                if (entry.note) content.append(el('p', entry.note, 'text-body-secondary preserve-lines small'));
            }
        }
        const credits = el('section', undefined, 'title-credits');
        const creditsToggle = el('button', 'Show people & credits', 'btn btn-outline-secondary btn-sm'); creditsToggle.type = 'button'; creditsToggle.setAttribute('aria-expanded', 'false'); credits.append(creditsToggle);
        const people = el('div', undefined, 'credit-list mt-3'); people.hidden = true;
        creditsToggle.onclick = () => {people.hidden = !people.hidden; creditsToggle.textContent = people.hidden ? 'Show people & credits' : 'Hide people & credits'; creditsToggle.setAttribute('aria-expanded', String(!people.hidden));};
        function addPeople(items) {for (const person of items) {const row = el('div'); row.append(link(person.name, `/library?person=${encodeURIComponent(person.id)}`), el('span', `${person.role}${person.scope === 'aggregate' ? ' · across episodes' : ''}`, 'small text-body-secondary')); people.append(row);}}
        addPeople(metadata.people || []); credits.append(people);
        let nextCredits = title.credits.nextOffset;
        if (nextCredits !== null) {
            const more = el('button', 'More credits', 'btn btn-outline-secondary btn-sm mt-3');
            more.onclick = () => run(async () => {const next = (await api('title', {id, creditsOffset: nextCredits})).title; addPeople(next.metadata.people); nextCredits = next.credits.nextOffset; more.hidden = nextCredits === null;}); people.append(more);
        }
        content.append(credits); detail.append(content);
        let nextHistory = 0;
        async function loadHistory() {
            const history = await api('history', {id, offset: nextHistory});
            for (const event of history.items) {
                const row = el('article', undefined, 'history-event');
                const label = {historical_evidence: 'Historical viewing evidence', viewing: 'Logged viewing', assessment: 'Assessment updated', progress: 'Progress updated'}[event.kind] || event.kind;
                row.append(el('h3', `${label}${event.season !== null ? ' · Season ' + event.season : ''}`), el('p', `${['assessment', 'progress'].includes(event.kind) ? 'Recorded ' + event.recorded_at.slice(0,10) : event.watched_on || 'Unknown watch date'}${event.reaction ? ' · ' + event.reaction : ''}`, 'text-body-secondary mb-1'));
                if (event.note) row.append(el('p', event.note, 'preserve-lines mb-0')); $('viewing-history').append(row);
            }
            if (!history.total) $('viewing-history').append(el('p', 'No viewing evidence recorded.', 'text-body-secondary'));
            nextHistory = history.nextOffset; $('history-more').hidden = nextHistory === null;
        }
        $('history-more').onclick = () => run(loadHistory); await loadHistory();
    }

    async function browseHistory() {
        const selected = new Map();
        const dialog = $('backfill-dialog');
        let pageNumber = 1, nextPage = null, ticket = 0, pending = null, requestId = stableRequest();
        function counter() {
            $('backfill-review').disabled = !selected.size || !!pending;
            $('backfill-review').textContent = selected.size ? `Review ${selected.size} selected` : 'Review selected';
        }
        function key(title) {return `${title.mediaType}:${title.tmdbId}`;}
        async function load() {
            const current = ++ticket;
            $('backfill-status').textContent = 'Loading titles…';
            const filters = Object.fromEntries(new FormData($('backfill-filters')));
            const result = await api('browse', {...filters, page:pageNumber});
            if (current !== ticket) return;
            nextPage = result.nextPage;
            $('backfill-grid').replaceChildren();
            for (const title of result.items) {
                const card = el('article', undefined, 'backfill-card');
                card.append(poster(title.poster, title.title, 'poster-cover'), el('h2', title.title, 'poster-name'), el('p', title.year || 'Year unknown', 'poster-year text-body-secondary'));
                if (title.viewingStatus === 'seen') {
                    card.append(link('Already in library', `/titles/${title.localId}`, 'small')); $('backfill-grid').append(card); continue;
                }
                const label = el('label', undefined, 'form-check'); const check = el('input', undefined, 'form-check-input'); check.type = 'checkbox';
                check.checked = selected.has(key(title)); label.append(check, el('span', 'Watched', 'form-check-label'));
                const reaction = el('select', undefined, 'form-select form-select-sm mt-2'); reaction.setAttribute('aria-label', `Reaction to ${title.title}`);
                reaction.append(new Option('No reaction yet', '')); reactionWords.forEach(word => reaction.append(new Option(word, word)));
                if (title.existingReaction && !reactionWords.includes(title.existingReaction)) reaction.append(new Option(title.existingReaction, title.existingReaction));
                reaction.value = selected.get(key(title))?.reaction ?? title.existingReaction ?? ''; reaction.disabled = !check.checked;
                check.onchange = () => {if (check.checked && selected.size >= 100) {check.checked = false; error('Save this batch before selecting more than 100 titles.'); return;} reaction.disabled = !check.checked; if (check.checked) selected.set(key(title), {...title, reaction:reaction.value}); else selected.delete(key(title)); counter();};
                reaction.onchange = () => {if (check.checked) selected.set(key(title), {...title, reaction:reaction.value});};
                card.append(label, reaction); $('backfill-grid').append(card);
            }
            $('backfill-status').textContent = 'TMDB catalog · 1,000+ votes per title. These lists help recall; they aren’t personalized recommendations.';
            $('backfill-page').textContent = `Page ${pageNumber}`;
            $('backfill-previous').disabled = pageNumber === 1; $('backfill-next').disabled = nextPage === null;
        }
        $('backfill-filters').onsubmit = e => e.preventDefault();
        $('backfill-filters').onchange = () => {pageNumber = 1; run(load);};
        $('backfill-previous').onclick = () => {pageNumber = Math.max(1,pageNumber - 1); run(load);};
        $('backfill-next').onclick = () => {pageNumber = nextPage || pageNumber; run(load);};
        $('backfill-review').onclick = () => {
            $('backfill-changes').replaceChildren(); $('backfill-receipt').textContent = '';
            for (const title of selected.values()) $('backfill-changes').append(el('p', `${title.title} (${title.year || 'Year unknown'}) · Watched · ${title.reaction || 'No reaction'} · Unknown date`));
            requestId = stableRequest(); dialog.showModal();
        };
        function closeBatch() {
            if (pending) {selected.clear(); pending = null; location.reload();}
            else dialog.close();
        }
        $('backfill-close').onclick = closeBatch;
        $('backfill-save').onclick = async () => {
            const button = $('backfill-save'); button.disabled = true; button.textContent = 'Saving…';
            pending ||= Array.from(selected.values(), title => ({action:'confirm_seen', mediaType:title.mediaType, tmdbId:title.tmdbId, reaction:title.reaction, note:title.existingNote || '', date:null}));
            // Freeze this reviewed batch for exact retries; closing after an error reloads authoritative state.
            $('backfill-close').disabled = true; dialog.oncancel = e => e.preventDefault();
            for (const control of root.querySelectorAll('#backfill-grid input,#backfill-grid select,#backfill-filters select,#backfill-previous,#backfill-next')) control.disabled = true;
            counter();
            try {
                const receipt = await save(pending, requestId);
                $('backfill-receipt').textContent = `Saved ${receipt.titleIds.length} titles. Dates remain unknown.`;
                selected.clear(); pending = null; button.hidden = true;
                $('backfill-close').disabled = false; dialog.oncancel = null;
                dialog.onclose = () => location.reload();
            } catch (failure) {
                $('backfill-receipt').textContent = failure.message + ' Retry Save selected for this exact batch, or close to reload your saved history.';
                $('backfill-close').disabled = false; dialog.oncancel = event => {event.preventDefault(); closeBatch();};
            } finally {button.disabled = false; button.textContent = 'Save selected';}
        };
        window.addEventListener('beforeunload', event => {if (selected.size || pending) {event.preventDefault(); event.returnValue = '';}});
        await load();
    }

    async function tastePage() {
        let requestId = stableRequest(), pendingTaste = null;
        const [context, titles] = await Promise.all([api('context', {fullStatements:true}), api('search', {status: 'seen', limit: 100})]);
        while (context.tasteNextOffset !== null) {
            const more = await api('context', {fullStatements:true, tasteOffset:context.tasteNextOffset});
            context.taste.push(...more.taste); context.tasteNextOffset = more.tasteNextOffset;
        }
        while (titles.nextOffset !== null) {
            const more = await api('search', {status:'seen', limit:100, offset:titles.nextOffset});
            titles.items.push(...more.items); titles.nextOffset = more.nextOffset;
        }
        for (const title of titles.items) $('taste-evidence').append(new Option(`${title.title} (${title.release_year})`, title.id));
        function edit(statement = null) {
            requestId = stableRequest(); pendingTaste = null; $('taste-edit-dialog').showModal();
            for (const control of $('taste-form').querySelectorAll('input,textarea,select')) control.disabled = false;
            $('taste-id').value = statement?.id || ''; $('taste-statement').value = statement?.statement || '';
            $('taste-kind').value = statement?.kind || 'explicit'; $('taste-kind').querySelector('[value="imported_context"]').hidden = statement?.kind !== 'imported_context';
            for (const option of $('taste-evidence').options) option.selected = (statement?.evidence || []).includes(option.value);
            $('taste-editor-title').textContent = statement ? 'Edit statement' : 'Add a preference'; $('taste-statement').focus();
        }
        const reading = $('taste-read-dialog');
        const readableText = text => text.replace(/\*\*(.*?)\*\*/g, '$1').replace(/`([^`]+)`/g, '$1').replace(/\*([^*\n]+)\*/g, '$1');
        const cleanText = text => readableText(text).replace(/\r/g, '').replace(/\n[ \t]*\n(?:[ \t]*\n)+/g, '\n\n').trim();
        function read(statement, name) {
            $('taste-read-title').textContent = name;
            $('taste-read-source').textContent = kindLabel(statement.kind);
            $('taste-read-body').replaceChildren();
            for (const block of cleanText(statement.statement).replace(/^# [^\n]+\n*/, '').split(/\n\n/)) {
                if (/^#{1,3} /.test(block) && !block.includes('\n')) $('taste-read-body').append(el('h3', block.replace(/^#+ /, '')));
                else $('taste-read-body').append(el('p', block, 'preserve-lines'));
            }
            const evidence = el('div', undefined, 'metadata-links');
            for (const id of statement.evidence) evidence.append(link(titles.items.find(title => title.id === id)?.title || 'Supporting watch', `/titles/${id}`, 'small'));
            $('taste-read-body').append(evidence);
            $('edit-open-taste').onclick = () => {reading.close(); edit(statement);}; reading.showModal();
        }
        context.taste.sort((a, b) => Number((a.provenance?.source || '').includes('WATCH_HISTORY')) - Number((b.provenance?.source || '').includes('WATCH_HISTORY')));
        for (const statement of context.taste) {
            const source = statement.provenance?.source || '';
            const name = statement.kind === 'imported_context' ? (source.includes('TASTE_PROFILE') ? 'Taste profile' : 'History notes') : kindLabel(statement.kind);
            const card = el('button', undefined, 'taste-card'); card.type = 'button';
            const preview = name === 'History notes' ? 'Background notes from the original history import, kept for reference.' : cleanText(statement.statement).split('\n').filter(line => line.trim() && !line.startsWith('#')).join(' ').slice(0, 180);
            card.append(el('h2', name), el('p', preview + (preview.length === 180 ? '…' : ''), 'text-body-secondary'), el('span', statement.kind === 'imported_context' ? 'Saved background · Read & edit' : 'Read & edit', 'small text-body-secondary'));
            card.onclick = () => read(statement, name); $('taste-statements').append(card);
        }
        $('close-taste').onclick = () => reading.close();
        $('add-taste').onclick = () => edit(); $('cancel-taste').onclick = () => {$('taste-edit-dialog').close();};
        $('taste-form').onsubmit = async event => {
            event.preventDefault(); const button = $('taste-form').querySelector('[type="submit"],button:not([type])'); button.disabled = true;
            const change = {action: 'save_taste', kind: $('taste-kind').value, statement: $('taste-statement').value, evidence: Array.from($('taste-evidence').selectedOptions, x => x.value)};
            if ($('taste-id').value) change.tasteId = $('taste-id').value;
            pendingTaste ||= [change];
            for (const control of $('taste-form').querySelectorAll('input,textarea,select')) control.disabled = true;
            try {await save(pendingTaste, requestId); location.reload();} catch (failure) {error(failure.message + ' Retry Save for this exact statement, or cancel and reopen the editor for a fresh review.');} finally {button.disabled = false;}
        };
    }
    run(() => page === 'library' || page === 'watchlist' ? collection() : page === 'log-watch' ? quickLog() : page === 'title' ? titlePage() : page === 'browse-history' ? browseHistory() : tastePage());
})();
