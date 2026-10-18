(() => {
    'use strict';
    let draft;
    let review;
    const byId = id => document.getElementById(id);
    const panels = ['paste', 'matches', 'review', 'saved'];
    const show = name => {
        panels.forEach(panel => { byId(`${panel}-panel`).hidden = panel !== name; });
        const labels = {paste: ['Add watched movies', "Paste one title per line. You'll review the matches before saving."], matches: ['Review movie matches', 'Choose the correct movie for each title, or skip it.'], review: ['Review changes', 'Check these changes before saving.'], saved: ['Changes saved', '']};
        [byId('intake-title').textContent, byId('step-description').textContent] = labels[name];
        byId('journal-status').textContent = '';
    };
    const request = async (action, body) => {
        const response = await fetch(`${window.APPLICATION_URL}/journal/${action}`, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name=csrf-token]').content}, body: JSON.stringify(body)});
        if (response.status === 403) throw new Error('Your session expired. Sign in again, then return to your journal.');
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
        if (!byId('watched-confirmation').checked) throw new Error('Please confirm that these are movies you have watched.');
        draft = await request('preview', {text: byId('recall').value});
        byId('matches').replaceChildren();
        draft.items.forEach((item, index) => {
            const row = text(byId('matches'), 'section', ''); row.className = 'journal-match';
            const label = text(row, 'label', `${item.input.title}${item.input.year ? ` (${item.input.year})` : ''}`); label.htmlFor = `match-${index}`; label.className = 'form-label';
            const select = document.createElement('select'); select.id = `match-${index}`; select.dataset.index = index; select.className = 'form-select';
            const empty = document.createElement('option'); empty.value = ''; empty.textContent = 'Choose the correct movie…'; select.append(empty);
            item.candidates.forEach(candidate => { const option = document.createElement('option'); option.value = candidate.id; option.textContent = `${candidate.title} (${candidate.year || 'year unknown'}) · ${candidate.language} · TMDB ${candidate.id}`; select.append(option); });
            const skip = document.createElement('option'); skip.value = 'skip'; skip.textContent = 'Skip this title for now'; select.append(skip);
            if (item.selected) select.value = item.selected;
            row.append(select);
            const overview = text(row, 'p', ''); overview.className = 'text-body-secondary';
            const update = () => { const candidate = item.candidates.find(c => String(c.id) === select.value); overview.textContent = candidate ? candidate.overview : (item.candidates.length ? 'Choose a version before continuing.' : 'No matches found. Edit the title/year, or skip it for now.'); };
            select.addEventListener('change', update); update();
            text(row, 'p', `${item.input.reaction || 'No reaction supplied'} · ${item.input.date || 'Watch date unknown'}`);
        });
        show('matches');
    }));
    byId('review-changes').addEventListener('click', () => run('Preparing your preview…', async () => {
        const choices = {};
        document.querySelectorAll('#matches select').forEach(select => { if (!select.value) throw new Error('Choose a movie or skip each unresolved title.'); choices[select.dataset.index] = select.value; });
        review = await request('review', {id: draft.id, choices});
        byId('changes').replaceChildren();
        review.changes.forEach(change => {
            const row = text(byId('changes'), 'section', ''); row.className = 'journal-change';
            text(row, 'h3', `${change.after.title} (${change.after.year || '?'})`).className = 'h6';
            const after = change.after;
            text(row, 'p', `${change.before ? 'Update journal entry' : 'Add to journal'} · ${after.reaction || 'No reaction'} · ${after.date || 'Unknown watch date'}`);
            if (change.before && change.before.reaction !== after.reaction) text(row, 'p', `Reaction changes from “${change.before.reaction || 'none'}” to “${after.reaction || 'none'}”.`);
            if (change.before && change.before.status !== after.status) text(row, 'p', `Viewing status changes from ${change.before.status} to Seen, based on your confirmation.`);
            if (after.note && (!change.before || change.before.note !== after.note)) text(row, 'p', `Note: ${after.note}`);
            text(row, 'p', change.play === 'add' ? 'Movary: add one play.' : 'Movary: already watched; keep existing plays.');
        });
        show('review');
    }));
    byId('save-journal').addEventListener('click', () => run('Creating a recovery checkpoint and saving…', async () => {
        const result = await request('commit', {id: review.id, approval: review.approval});
        byId('save-summary').textContent = `${result.saved} movie${result.saved === 1 ? '' : 's'} saved and verified. Your original reactions and unknown dates are preserved.`;
        show('saved');
    }));
    byId('back-paste').addEventListener('click', () => show('paste'));
    byId('back-matches').addEventListener('click', () => show('matches'));
    byId('add-more').addEventListener('click', () => location.reload());
})();
