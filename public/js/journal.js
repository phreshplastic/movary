(() => {
    'use strict';
    let draft;
    let review;
    const media = () => byId('media-type').value;
    const byId = id => document.getElementById(id);
    const panels = ['paste', 'matches', 'review', 'saved'];
    const show = name => {
        panels.forEach(panel => { byId(`${panel}-panel`).hidden = panel !== name; });
        const labels = {paste: ['Paste titles', 'One title per line. Add reactions, dates, and notes after matching.'], matches: ['Review matches', 'Choose the right version and add any details you remember.'], review: ['Review changes', 'Check these changes before saving.'], saved: ['Saved to your library', '']};
        [byId('intake-title').textContent, byId('step-description').textContent] = labels[name];
        byId('journal-status').textContent = '';
    };
    const request = async (action, body) => {
        const response = await fetch(`${window.APPLICATION_URL}/journal/${action}`, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name=csrf-token]').content}, body: JSON.stringify(body)});
        if (response.status === 403) throw new Error('Your session expired. Sign in again, then return to your library.');
        let data;
        try { data = await response.json(); } catch { throw new Error('The server could not finish this request. Your list is still here; please try again.'); }
        if (!response.ok) throw new Error(data.error || 'Unable to save right now. Please try again.');
        return data;
    };
    const run = async (message, action) => {
        const buttons = document.querySelectorAll('.journal-card button');
        buttons.forEach(button => { button.disabled = true; });
        byId('journal-status').textContent = message;
        try { await action(); } catch (error) { byId('journal-status').textContent = error.message; }
        finally { buttons.forEach(button => { button.disabled = false; }); }
    };
    const text = (parent, tag, value) => { const node = document.createElement(tag); node.textContent = value; parent.append(node); return node; };
    byId('find-matches').addEventListener('click', () => run('Finding matches…', async () => {
        if (!byId('watched-confirmation').checked) throw new Error('Please confirm that you watched these titles.');
        draft = await request('preview', {text: byId('recall').value, mediaType: media()});
        byId('matches').replaceChildren();
        draft.items.forEach((item, index) => {
            const row = text(byId('matches'), 'section', ''); row.className = 'journal-match';
            const label = text(row, 'label', `${item.input.title}${item.input.year ? ` (${item.input.year})` : ''}`); label.htmlFor = `match-${index}`; label.className = 'form-label';
            const select = document.createElement('select'); select.id = `match-${index}`; select.dataset.index = index; select.className = 'form-select';
            const empty = document.createElement('option'); empty.value = ''; empty.textContent = 'Choose the correct title…'; select.append(empty);
            item.candidates.forEach(candidate => { const option = document.createElement('option'); option.value = candidate.id; option.textContent = `${candidate.title} (${candidate.year || 'year unknown'}) · ${candidate.language} · TMDB ${candidate.id}`; select.append(option); });
            const skip = document.createElement('option'); skip.value = 'skip'; skip.textContent = 'Skip this title for now'; select.append(skip);
            if (item.selected) select.value = item.selected;
            row.append(select);
            const about = text(row, 'details', ''); about.className = 'small text-body-secondary my-2'; text(about, 'summary', 'About this match');
            const overview = text(about, 'p', '');
            const update = () => { const candidate = item.candidates.find(c => String(c.id) === select.value); overview.textContent = candidate ? candidate.overview : (item.candidates.length ? 'Choose a version before continuing.' : 'No matches found. Edit the title/year, or skip it for now.'); };
            select.addEventListener('change', update); update();
            const fields = text(row, 'div', ''); fields.className = 'match-details';
            [['reaction', 'Reaction (optional)', 'text'], ['date', 'Watch date (optional)', 'date'], ['note', 'Note (optional)', 'text']].forEach(([key, title, type]) => {
                const field = text(fields, 'div', '');
                const label = text(field, 'label', title); label.className = 'form-label small'; label.htmlFor = `detail-${index}-${key}`;
                const input = document.createElement('input'); input.type = type; input.id = label.htmlFor; input.className = 'form-control'; input.value = item.input[key]; input.dataset.row = index; input.dataset.field = key; input.maxLength = 2000;
                if (key === 'reaction') input.placeholder = 'In your own words';
                field.append(input);
            });
        });
        show('matches');
    }));
    byId('review-changes').addEventListener('click', () => run('Preparing your preview…', async () => {
        const choices = {};
        document.querySelectorAll('#matches select').forEach(select => { if (!select.value) throw new Error('Choose a movie or skip each unresolved title.'); choices[select.dataset.index] = select.value; });
        const details = {};
        document.querySelectorAll('#matches input[data-field]').forEach(input => { (details[input.dataset.row] ||= {})[input.dataset.field] = input.value; });
        review = await request('review', {id: draft.id, choices, details});
        byId('changes').replaceChildren();
        review.changes.forEach(change => {
            const row = text(byId('changes'), 'section', ''); row.className = 'journal-change';
            text(row, 'h3', `${change.after.title} (${change.after.year || '?'})`).className = 'h6';
            const after = change.after;
            text(row, 'p', `${change.before ? 'Update existing title' : 'Add to library'} · ${after.reaction || 'No reaction'} · ${after.date || 'Unknown watch date'}`);
            if (change.before && change.before.reaction !== after.reaction) text(row, 'p', `Reaction changes from “${change.before.reaction || 'none'}” to “${after.reaction || 'none'}”.`);
            if (change.before && change.before.status !== after.status) text(row, 'p', `Mark as watched (previously ${change.before.status}), based on your confirmation.`);
            if (after.note && (!change.before || change.before.note !== after.note)) text(row, 'p', `Note: ${after.note}`);
            text(row, 'p', change.play === 'library-only' ? 'TV show: save to library; no season or episode completion is inferred.' : (change.play === 'add' ? 'Record one movie watch.' : 'Already watched; keep existing plays.'));
        });
        show('review');
    }));
    byId('save-journal').addEventListener('click', () => run('Saving changes…', async () => {
        const result = await request('commit', {id: review.id, approval: review.approval});
        byId('save-summary').textContent = `${result.saved} title${result.saved === 1 ? '' : 's'} saved and verified.`;
        show('saved');
    }));
    byId('back-paste').addEventListener('click', () => show('paste'));
    byId('back-matches').addEventListener('click', () => show('matches'));
    byId('add-more').addEventListener('click', () => location.reload());
    const updateType = () => {
        byId('recall').placeholder = media() === 'tv' ? 'Severance (2022)\nThe Bear (2022)' : 'Arrival (2016)\nAlien (1979)';
    };
    if (new URLSearchParams(location.search).get('type') === 'tv') byId('media-type').value = 'tv';
    byId('media-type').addEventListener('change', updateType);
    updateType();
})();
