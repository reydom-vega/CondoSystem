(() => {
  'use strict';
  document.querySelectorAll('[data-history-search]').forEach(history => {
    const input = history.querySelector('[data-history-search-input]');
    if (!input) return;
    const records = [...history.querySelectorAll('[data-history-search-record]')].map(record => ({
      element: record,
      text: record.dataset.historySearchRecord.normalize('NFKC').toLowerCase()
    }));
    const count = history.querySelector('[data-history-search-count]');
    const empty = history.querySelector('[data-history-search-empty]');
    const filter = () => {
      const words = input.value.normalize('NFKC').trim().toLowerCase().split(/\s+/).filter(Boolean);
      let matches = 0;
      records.forEach(record => {
        record.element.hidden = !words.every(word => record.text.includes(word));
        if (!record.element.hidden) matches++;
      });
      if (count) count.textContent = String(matches);
      if (empty) empty.hidden = records.length === 0 || matches > 0;
    };
    input.addEventListener('input', filter);
    input.addEventListener('search', filter);
    filter();
  });
})();
