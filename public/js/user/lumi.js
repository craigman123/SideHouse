document.addEventListener('DOMContentLoaded', () => {
    const page = document.getElementById('lumiChat');
    const form = document.getElementById('lumiForm');
    const input = document.getElementById('lumiInput');
    const sendButton = document.getElementById('lumiSend');
    const messages = document.getElementById('lumiMessages');
    const emptyState = document.getElementById('lumiEmptyState');
    const typing = document.getElementById('lumiTyping');
    const error = document.getElementById('lumiError');
    const retryButton = document.getElementById('lumiRetry');
    const clearOpenButton = document.getElementById('lumiClearOpen');
    const clearDialog = document.getElementById('lumiClearDialog');
    const clearConfirmButton = document.getElementById('lumiClearConfirm');

    if (!page || !form || !input || !sendButton || !messages) return;

    let conversation = [];
    let lastFailedMessage = '';
    let isSending = false;

    function scrollToLatest() {
        messages.scrollTop = messages.scrollHeight;
    }

    function renderMessage(role, text, createdAt = new Date()) {
        const message = document.createElement('article');
        message.className = `lumi-message lumi-message-${role}`;
        message.setAttribute('aria-label', role === 'user' ? 'Your message' : 'Lumi reply');

        const bubble = document.createElement('p');
        bubble.className = 'lumi-message-bubble';
        bubble.textContent = text;

        const time = document.createElement('time');
        time.dateTime = createdAt.toISOString();
        time.textContent = createdAt.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });

        message.append(bubble, time);
        messages.append(message);
        scrollToLatest();
    }

    function setSending(sending) {
        isSending = sending;
        sendButton.disabled = sending;
        input.disabled = sending;
        typing.hidden = !sending;
        messages.setAttribute('aria-busy', String(sending));
        if (sending) scrollToLatest();
    }

    function resizeInput() {
        input.style.height = 'auto';
        input.style.height = `${Math.min(input.scrollHeight, 160)}px`;
    }

    async function requestReply(message) {
        lastFailedMessage = message;
        error.hidden = true;
        setSending(true);

        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
            const response = await fetch(page.dataset.replyUrl, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken || '',
                },
                body: JSON.stringify({ message }),
            });
            const payload = await response.json();

            if (!response.ok || typeof payload.reply !== 'string') {
                throw new Error(payload.message || 'Lumi could not respond.');
            }

            conversation.push({ role: 'assistant', text: payload.reply });
            renderMessage('assistant', payload.reply);
            lastFailedMessage = '';
            error.hidden = true;
        } catch (_) {
            error.hidden = false;
        } finally {
            setSending(false);
            input.focus({ preventScroll: true });
        }
    }

    function sendCurrentMessage() {
        if (isSending) return;
        const message = input.value.trim();
        if (!message) return;

        conversation.push({ role: 'user', text: message });
        renderMessage('user', message);
        emptyState.hidden = true;
        input.value = '';
        resizeInput();
        input.focus({ preventScroll: true });
        requestReply(message);
    }

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        sendCurrentMessage();
    });

    input.addEventListener('input', resizeInput);
    input.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
            event.preventDefault();
            form.requestSubmit();
        }
    });

    messages.querySelectorAll('[data-lumi-prompt]').forEach((button) => {
        button.addEventListener('click', () => {
            input.value = button.dataset.lumiPrompt || '';
            resizeInput();
            form.requestSubmit();
        });
    });

    retryButton.addEventListener('click', () => {
        if (lastFailedMessage && !isSending) requestReply(lastFailedMessage);
    });

    clearOpenButton.addEventListener('click', () => clearDialog.showModal());
    clearConfirmButton.addEventListener('click', () => {
        conversation = [];
        lastFailedMessage = '';
        messages.querySelectorAll('.lumi-message').forEach((message) => message.remove());
        emptyState.hidden = false;
        error.hidden = true;
        clearDialog.close();
        input.value = '';
        resizeInput();
        input.focus({ preventScroll: true });
    });
});
