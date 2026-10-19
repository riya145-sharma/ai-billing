(() => {
    'use strict';
    const form = document.getElementById('speechForm');
    if (!form) return;
    const status = document.getElementById('speechStatus');
    const submit = document.getElementById('speechSubmit');
    const input = document.getElementById('speechInput');
    input.addEventListener('input', () => {
        document.getElementById('speechCounter').textContent = `${Array.from(input.value).length.toLocaleString()} / 4,096 characters`;
    });
    let busy = false;
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (busy || !form.reportValidity()) return;
        busy = true;
        submit.disabled = true;
        submit.textContent = 'Generating…';
        status.textContent = 'Generating your audio. Please keep this page open.';
        try {
            const response = await fetch(form.action, {method: 'POST', body: new FormData(form), headers: {'Accept': 'application/json'}});
            const data = await response.json();
            if (data.request_id) form.elements.request_id.value = data.request_id;
            if (!data.ok) throw new Error(data.message || data.record?.message || 'This request is still processing. Reload to check history.');
            const player = document.getElementById('speechPlayer');
            player.pause();
            player.src = data.audio_url;
            player.load();
            document.getElementById('speechDownload').href = data.download_url;
            document.getElementById('speechResult').hidden = false;
            document.getElementById('speechEmpty').hidden = true;
            status.textContent = 'Your audio is ready. Press play to listen.';
        } catch (error) {
            status.textContent = error instanceof SyntaxError || error instanceof TypeError
                ? 'Connection interrupted or session expired. Reload to check history before submitting again.' : error.message;
        } finally {
            busy = false;
            submit.disabled = false;
            submit.textContent = 'Generate speech';
        }
    });
})();
