(function () {
    const search = document.getElementById('salesGuideSearch');
    const cards = document.querySelectorAll('.sales-guide-card[data-keywords]');
    const empty = document.getElementById('salesGuideNoResults');
    const navLinks = document.querySelectorAll('.sales-guide-nav a');

    function normalize(s) {
        return (s || '').toLowerCase().trim();
    }

    function filterCards() {
        const q = normalize(search?.value || '');
        let visible = 0;
        cards.forEach((card) => {
            const keys = normalize(card.getAttribute('data-keywords'));
            const text = normalize(card.textContent);
            const match = !q || keys.includes(q) || text.includes(q);
            card.classList.toggle('is-hidden', !match);
            if (match) visible++;
        });
        if (empty) {
            empty.classList.toggle('show', visible === 0 && q.length > 0);
        }
    }

    if (search) {
        search.addEventListener('input', filterCards);
    }

    const observer = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) return;
                const id = entry.target.id;
                navLinks.forEach((a) => {
                    a.classList.toggle('active', a.getAttribute('href') === '#' + id);
                });
            });
        },
        { rootMargin: '-20% 0px -60% 0px', threshold: 0 }
    );

    cards.forEach((card) => {
        if (card.id) observer.observe(card);
    });
})();