// WiBuy keeps JavaScript tiny: a live countdown, a copy-link button and the chat.
// Everything else works without it. Chat code is a separate file that only downloads on pages with a chat.

function formatLeft(ms) {
    const totalSeconds = Math.floor(ms / 1000);
    const days = Math.floor(totalSeconds / 86400);
    const hours = Math.floor((totalSeconds % 86400) / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = totalSeconds % 60;

    if (days > 0) return `${days}d ${hours}h ${minutes}m left`;
    if (hours > 0) return `${hours}h ${minutes}m ${seconds}s left`;

    return `${minutes}m ${seconds}s left`;
}

function tickCountdowns() {
    document.querySelectorAll('[data-deadline]').forEach((el) => {
        const ms = new Date(el.dataset.deadline).getTime() - Date.now();
        el.textContent = ms <= 0 ? el.dataset.endedText || 'Ended' : formatLeft(ms);
    });
}

function setupCopyButtons() {
    document.querySelectorAll('[data-copy]').forEach((button) => {
        button.addEventListener('click', async () => {
            const text = button.dataset.copy;
            const label = button.textContent;

            try {
                await navigator.clipboard.writeText(text);
            } catch (e) {
                // Old browsers: fall back to a temporary text box.
                const box = document.createElement('textarea');
                box.value = text;
                document.body.appendChild(box);
                box.select();
                document.execCommand('copy');
                box.remove();
            }

            button.textContent = 'Link copied!';
            setTimeout(() => (button.textContent = label), 2000);
        });
    });
}

// The unread badge in the header: a tiny JSON poll every 30 seconds.
function setupUnreadBadge() {
    const badges = document.querySelectorAll('[data-unread-badge]');
    if (badges.length === 0) return;

    const refresh = async () => {
        if (document.hidden) return;

        try {
            const response = await fetch(badges[0].dataset.url, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            if (!response.ok) return;
            const { count } = await response.json();

            badges.forEach((badge) => {
                badge.textContent = count > 99 ? '99+' : String(count);
                badge.classList.toggle('hidden', count < 1);
            });
        } catch (e) {
            // Offline: keep the old number.
        }
    };

    setInterval(refresh, 30000);
}

// Product gallery: tapping a thumbnail shows that picture (without JS the thumbnail opens the picture).
function setupGallery() {
    const main = document.querySelector('[data-gallery-main]');
    if (!main) return;

    document.querySelectorAll('[data-gallery-thumb]').forEach((link) => {
        link.addEventListener('click', (event) => {
            event.preventDefault();
            main.src = link.dataset.galleryThumb;
            document.querySelectorAll('[data-gallery-thumb]').forEach((l) => l.classList.replace('ring-brand-600', 'ring-transparent'));
            link.classList.replace('ring-transparent', 'ring-brand-600');
        });
    });
}

// Checkout: switching a seller between delivery and pickup reloads the page with the new totals.
// Without JavaScript the server still recalculates the total when the buyer pays.
function setupCheckoutTotals() {
    const form = document.querySelector('[data-checkout]');
    if (!form) return;

    form.querySelectorAll('input[name^="methods["]').forEach((radio) => {
        radio.addEventListener('change', () => {
            const params = new URLSearchParams();
            ['source', 'product', 'qty', 'district', 'coupon_id'].forEach((name) => {
                const field = form.querySelector(`[name="${name}"]`);
                if (field && field.value && name !== 'source') params.set(name, field.value);
            });
            form.querySelectorAll('input[name^="methods["]:checked').forEach((checked) => params.set(checked.name, checked.value));
            window.location = `${form.dataset.recalcUrl}?${params.toString()}`;
        });
    });
}

// Lets phones install WiBuy as an app (Add to Home screen). Needs https, or localhost.
if ('serviceWorker' in navigator && (location.protocol === 'https:' || location.hostname === 'localhost')) {
    navigator.serviceWorker.register('/sw.js').catch(() => {});
}

document.addEventListener('DOMContentLoaded', () => {
    setupGallery();
    setupCheckoutTotals();

    if (document.querySelector('[data-deadline]')) {
        tickCountdowns();
        setInterval(tickCountdowns, 1000);
    }

    setupCopyButtons();
    setupUnreadBadge();

    const chats = document.querySelectorAll('[data-chat]');
    if (chats.length > 0) {
        import('./chat').then(({ initChat }) => chats.forEach(initChat));
    }
});
