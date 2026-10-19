(() => {
    'use strict';
    const byId = id => document.getElementById(id);
    const rows = Array.from(document.querySelectorAll('#library-rows tr'));
    const filter = () => {
        const query = byId('library-search').value.trim().toLocaleLowerCase();
        const type = byId('library-type').value;
        const status = byId('library-status').value;
        let count = 0;
        rows.forEach(row => {
            row.hidden = !((type === 'all' || row.dataset.type === type) && (status === 'all' || row.dataset.status === status) && row.dataset.search.toLocaleLowerCase().includes(query));
            if (!row.hidden) count++;
        });
        byId('library-empty').hidden = count !== 0;
        byId('library-count').textContent = `${count} title${count === 1 ? '' : 's'}`;
        byId('library-empty-add').href = `${window.APPLICATION_URL}/add-watched${type === 'tv' ? '?type=tv' : ''}`;
    };
    ['library-search', 'library-type', 'library-status'].forEach(id => byId(id).addEventListener('input', filter));
    filter();
})();
