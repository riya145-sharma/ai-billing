(() => {
    'use strict';
    const form = document.getElementById('contentForm');
    if (!form) return;
    const output = document.getElementById('contentOutput');
    const status = document.getElementById('contentStatus');
    const submit = document.getElementById('contentSubmit');
    const copy = document.getElementById('contentCopy');
    const download = document.getElementById('contentDownload');
    let busy = false;
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (busy || !form.reportValidity()) return;
        busy = true;
        submit.disabled = true;
        submit.textContent = 'Writing…';
        status.textContent = 'Generating your content. Please keep this page open.';
        try {
            const response = await fetch(form.action, {method: 'POST', body: new FormData(form), headers: {'Accept': 'application/json'}});
            const data = await response.json();
            if (data.request_id) form.elements.request_id.value = data.request_id;
            if (!data.ok) throw new Error(data.message || data.record?.message || 'This request is still processing. Reload to check history.');
            output.value = data.record.text;
            copy.disabled = download.disabled = false;
            const tokens = data.record.usage?.total_tokens;
            document.getElementById('contentUsage').textContent = tokens == null ? 'Token usage unavailable.' : `${tokens} tokens used.`;
            status.textContent = data.record.truncated ? 'Content reached the output limit and may be incomplete. Request a shorter version if needed.' : 'Content is ready.';
        } catch (error) {
            status.textContent = error instanceof SyntaxError || error instanceof TypeError
                ? 'Connection interrupted or session expired. Reload to check history before submitting again.' : error.message;
        } finally {
            busy = false;
            submit.disabled = false;
            submit.textContent = 'Generate content';
        }
    });
    copy.addEventListener('click', async () => {
        try { await navigator.clipboard.writeText(output.value); status.textContent = 'Copied.'; }
        catch { output.focus(); output.select(); status.textContent = 'Press Ctrl+C to copy the selected text.'; }
    });
    download.addEventListener('click', () => {
        const url = URL.createObjectURL(new Blob([output.value], {type: 'text/plain;charset=utf-8'}));
        const link = document.createElement('a');
        link.href = url; link.download = 'generated-content.txt'; link.click();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    });
})();
