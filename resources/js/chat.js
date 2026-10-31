// WiBuy chat in the browser.
//
// One rule keeps this simple: a real-time event (or a poll tick) never carries message content, it
// just means "ask the server for anything newer than the last message I have". The server returns
// ready-made HTML after checking permissions. So WebSockets and polling share one code path, and
// when the WebSocket is down we just poll every few seconds instead.

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

const jsonHeaders = () => ({
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    'X-CSRF-TOKEN': csrf(),
});

let lightbox;

function openImage(src) {
    if (!lightbox) {
        lightbox = document.createElement('dialog');
        lightbox.className = 'm-auto max-h-[92vh] max-w-[96vw] rounded-xl bg-black p-0 backdrop:bg-black/80';
        lightbox.innerHTML = '<img alt="Photo" class="max-h-[92vh] max-w-[96vw] object-contain">';
        lightbox.addEventListener('click', () => lightbox.close());
        document.body.appendChild(lightbox);
    }

    lightbox.querySelector('img').src = src; // the full-size photo loads only now
    lightbox.showModal();
}

function removedNode(id) {
    const el = document.createElement('div');
    el.dataset.messageId = id;
    el.className = 'my-2 text-center text-xs italic text-stone-400';
    el.textContent = 'This message was removed.';

    return el;
}

export function initChat(root) {
    const list = root.querySelector('[data-chat-list]');
    const olderButton = root.querySelector('[data-load-older]');
    const form = root.querySelector('[data-chat-form]');
    const errorBox = root.querySelector('[data-chat-error]');
    const liveLabel = root.querySelector('[data-chat-live]');
    const messagesUrl = root.dataset.messagesUrl;

    let lastId = Number(root.dataset.lastId) || 0;
    let firstId = Number(root.dataset.firstId) || 0;
    let hasMore = root.dataset.hasMore === '1';
    let fetching = false;
    let fetchAgain = false;
    let loadingOlder = false;
    let pollTimer = null;
    let lastReadAt = 0;

    const nearBottom = () => list.scrollHeight - list.scrollTop - list.clientHeight < 80;
    const scrollToBottom = () => { list.scrollTop = list.scrollHeight; };
    const setLive = (text) => { if (liveLabel) liveLabel.textContent = text; };

    function showError(text) {
        if (!errorBox) return;
        errorBox.textContent = text;
        errorBox.classList.toggle('hidden', !text);
    }

    function nodesFrom(html) {
        const template = document.createElement('template');
        template.innerHTML = html.trim();

        return [...template.content.children];
    }

    // Never show the same message twice (a poll and a WebSocket event can arrive together).
    function dropExisting(nodes) {
        return nodes.filter((node) => !list.querySelector(`[data-message-id="${node.dataset.messageId}"]`));
    }

    async function getMessages(query) {
        const response = await fetch(`${messagesUrl}?${query}`, { headers: jsonHeaders(), credentials: 'same-origin' });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);

        return response.json();
    }

    function markRead() {
        // At most every 3 seconds, and only while the person can actually see the chat.
        if (document.hidden || Date.now() - lastReadAt < 3000) return;
        lastReadAt = Date.now();
        fetch(root.dataset.readUrl, { method: 'POST', headers: jsonHeaders(), credentials: 'same-origin' }).catch(() => {});
    }

    async function fetchNew() {
        if (fetching) { fetchAgain = true; return; }
        fetching = true;

        try {
            const stickToBottom = nearBottom();
            const data = await getMessages(`after_id=${lastId}`);

            if (data.count > 0) {
                root.querySelector('[data-chat-empty]')?.remove();
                const fresh = dropExisting(nodesFrom(data.html));
                fresh.forEach((node) => list.appendChild(node));
                lastId = data.last_id;
                if (!firstId) firstId = data.first_id;
                if (stickToBottom) scrollToBottom();
                markRead();
            }
        } catch (e) {
            // Offline or a hiccup: the next tick or event will try again.
        } finally {
            fetching = false;
            if (fetchAgain) { fetchAgain = false; fetchNew(); }
        }
    }

    async function loadOlder() {
        if (!hasMore || loadingOlder || !firstId) return;
        loadingOlder = true;

        try {
            const heightBefore = list.scrollHeight;
            const topBefore = list.scrollTop;
            const data = await getMessages(`before_id=${firstId}`);

            if (data.count > 0) {
                const older = dropExisting(nodesFrom(data.html));
                const anchor = olderButton ? olderButton.nextSibling : list.firstChild;
                older.forEach((node) => list.insertBefore(node, anchor));
                firstId = data.first_id;
            }

            hasMore = Boolean(data.has_more);
            olderButton?.classList.toggle('hidden', !hasMore);
            // Keep the message the person was reading in the same place on screen.
            list.scrollTop = topBefore + (list.scrollHeight - heightBefore);
        } catch (e) {
            // Try again next time they scroll up.
        } finally {
            loadingOlder = false;
        }
    }

    function startPolling() {
        if (pollTimer) return;
        setLive('Updating every few seconds');
        pollTimer = setInterval(() => { if (!document.hidden) fetchNew(); }, Number(root.dataset.poll) || 5000);
    }

    function stopPolling() {
        clearInterval(pollTimer);
        pollTimer = null;
    }

    function markDeleted(id) {
        const el = list.querySelector(`[data-message-id="${id}"]`);
        if (el) el.replaceWith(removedNode(id));
    }

    async function startRealtime() {
        if (root.dataset.realtime !== '1') { startPolling(); return; }

        // If the WebSocket is not up within 4 seconds, poll until it is.
        const fallback = setTimeout(startPolling, 4000);

        try {
            const { default: echo } = await import('./echo');
            const pusher = echo.connector.pusher;

            const onConnected = () => {
                clearTimeout(fallback);
                stopPolling();
                setLive('Live');
                fetchNew(); // catch up on anything missed while disconnected
            };

            pusher.connection.bind('state_change', ({ current }) => {
                if (current === 'connected') onConnected();
                else if (['unavailable', 'failed', 'disconnected'].includes(current)) startPolling();
            });

            echo.private(root.dataset.channel)
                .listen('.message.sent', () => fetchNew())
                .listen('.message.deleted', (e) => markDeleted(e.id))
                .error(() => startPolling());

            if (pusher.connection.state === 'connected') onConnected();
        } catch (e) {
            clearTimeout(fallback);
            startPolling();
        }
    }

    // ---- sending
    if (form) {
        const fileName = form.querySelector('[data-file-name]');

        form.querySelectorAll('[data-file-input]').forEach((input) => {
            input.addEventListener('change', () => {
                if (fileName) fileName.textContent = input.files[0]?.name ?? '';
            });
        });

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const button = form.querySelector('button[type="submit"]');
            button.disabled = true;
            showError('');

            try {
                const response = await fetch(form.action, {
                    method: 'POST', body: new FormData(form), headers: jsonHeaders(), credentials: 'same-origin',
                });

                if (response.ok) {
                    form.reset();
                    if (fileName) fileName.textContent = '';
                    await fetchNew();
                    scrollToBottom();
                } else if (response.status === 429) {
                    showError('You are sending messages too fast. Please wait a moment.');
                } else {
                    const body = await response.json().catch(() => ({}));
                    const first = body.errors ? Object.values(body.errors)[0]?.[0] : null;
                    showError(first || body.message || 'Could not send your message. Please try again.');
                }
            } catch (e) {
                showError('No connection. Your message was not sent.');
            } finally {
                button.disabled = false;
            }
        });

        // Enter sends on computers; on phones Enter is a new line and the Send button sends.
        form.querySelector('textarea')?.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' && !event.shiftKey && window.matchMedia('(pointer: fine)').matches) {
                event.preventDefault();
                form.requestSubmit();
            }
        });
    }

    // ---- report / delete buttons and tapping photos
    root.addEventListener('submit', async (event) => {
        const actionForm = event.target.closest('form[data-ajax-action]');
        if (!actionForm) return;

        event.preventDefault();
        if (actionForm.dataset.confirm && !window.confirm(actionForm.dataset.confirm)) return;

        try {
            const response = await fetch(actionForm.action, {
                method: 'POST', body: new FormData(actionForm), headers: jsonHeaders(), credentials: 'same-origin',
            });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            if (actionForm.hasAttribute('data-remove-message')) {
                const message = actionForm.closest('[data-message-id]');
                if (message) message.replaceWith(removedNode(message.dataset.messageId));
            } else {
                const done = document.createElement('span');
                done.textContent = actionForm.dataset.done || 'Done';
                actionForm.replaceWith(done);
            }
        } catch (e) {
            showError('That did not work. Please try again.');
        }
    });

    root.addEventListener('click', (event) => {
        const link = event.target.closest('a[data-full]');
        if (!link) return;
        event.preventDefault();
        openImage(link.dataset.full);
    });

    olderButton?.addEventListener('click', loadOlder);
    list.addEventListener('scroll', () => { if (list.scrollTop < 30) loadOlder(); }, { passive: true });

    // Open chat = "online": no "new messages" SMS while someone is looking at it.
    setInterval(() => {
        if (!document.hidden) fetch(root.dataset.pingUrl, { method: 'POST', headers: jsonHeaders(), credentials: 'same-origin' }).catch(() => {});
    }, 45000);

    document.addEventListener('visibilitychange', () => { if (!document.hidden) { fetchNew(); markRead(); } });

    scrollToBottom();
    startRealtime();
}
