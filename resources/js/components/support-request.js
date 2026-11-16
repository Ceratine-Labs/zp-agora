/**
 * A support request on a chain, with a screenshot the person marks up
 * (Ryan, 16 Nov 2026: "a screenshot that the user can edit in line with a
 * couple of tools like Circle or FreeDraw").
 *
 * The form arrives in the support modal after load, so everything here is
 * delegated from the document. The image comes from the browser's own screen
 * capture (the person picks this tab), a paste, or a chosen file; it is drawn
 * on a canvas, marked up with a pen, a circle or an arrow, and sent as a PNG
 * with the message. No library: the canvas and getDisplayMedia are enough.
 *
 *   <form data-support-form> … <div data-support-shot> … <canvas data-shot-canvas>
 */
const editors = new WeakMap();
const INK = '#e11d48';
const MAX_WIDTH = 1600;

export default function supportRequest() {
    document.addEventListener('click', onClick);
    document.addEventListener('change', (event) => {
        const input = event.target.closest('[data-shot-file]');
        if (!input || !input.files?.[0]) return;
        loadFile(shotOf(input), input.files[0]);
    });
    document.addEventListener('paste', (event) => {
        const form = document.querySelector('dialog[open] [data-support-form]');
        if (!form) return;
        const item = [...(event.clipboardData?.items || [])].find((i) => i.type.startsWith('image/'));
        if (!item) return;
        event.preventDefault();
        loadFile(form.querySelector('[data-support-shot]'), item.getAsFile());
    });
    document.addEventListener('submit', onSubmit);
}

function shotOf(el) {
    return el.closest('[data-support-shot]');
}

async function onClick(event) {
    const capture = event.target.closest('[data-shot-capture]');
    if (capture) return captureScreen(shotOf(capture));

    const tool = event.target.closest('[data-shot-tool]');
    if (tool) {
        const shot = shotOf(tool);
        const editor = editors.get(shot);
        if (!editor) return;
        editor.tool = tool.dataset.shotTool;
        shot.querySelectorAll('[data-shot-tool]').forEach((b) => {
            const on = b === tool;
            b.classList.toggle('is-on', on);
            b.setAttribute('aria-pressed', String(on));
        });
        return;
    }

    const undo = event.target.closest('[data-shot-undo]');
    if (undo) {
        const editor = editors.get(shotOf(undo));
        if (editor) { editor.shapes.pop(); draw(editor); }
        return;
    }

    const clear = event.target.closest('[data-shot-clear]');
    if (clear) {
        const shot = shotOf(clear);
        editors.delete(shot);
        shot.querySelector('[data-shot-canvas]').hidden = true;
        shot.querySelector('[data-shot-tools]').hidden = true;
        shot.querySelector('[data-shot-hint]').hidden = false;
        const file = shot.querySelector('[data-shot-file]');
        if (file) file.value = '';
    }
}

/**
 * The browser asks which screen or tab to share; the person picks this one.
 * The dialog is closed first so the capture shows the page, not the form.
 */
async function captureScreen(shot) {
    const dialog = shot.closest('dialog');
    const hint = shot.querySelector('[data-shot-hint]');

    if (!navigator.mediaDevices?.getDisplayMedia) {
        hint.textContent = 'Screen capture is not available in this browser. Paste an image (Ctrl+V) or choose one.';
        return;
    }

    dialog?.close();
    await new Promise((r) => setTimeout(r, 250));

    let stream;
    try {
        stream = await navigator.mediaDevices.getDisplayMedia({
            video: { displaySurface: 'browser' },
            audio: false,
            preferCurrentTab: true,
            selfBrowserSurface: 'include',
        });
        const video = document.createElement('video');
        video.srcObject = stream;
        video.muted = true;
        await video.play();
        await new Promise((r) => setTimeout(r, 300));
        const frame = document.createElement('canvas');
        frame.width = video.videoWidth;
        frame.height = video.videoHeight;
        frame.getContext('2d').drawImage(video, 0, 0);
        dialog?.showModal();
        start(shot, frame);
    } catch (error) {
        dialog?.showModal();
        hint.textContent = 'The screen was not captured. Paste an image (Ctrl+V) or choose one instead.';
    } finally {
        stream?.getTracks().forEach((t) => t.stop());
    }
}

function loadFile(shot, file) {
    if (!shot || !file) return;
    const image = new Image();
    image.onload = () => { start(shot, image); URL.revokeObjectURL(image.src); };
    image.src = URL.createObjectURL(file);
}

function start(shot, source) {
    const canvas = shot.querySelector('[data-shot-canvas]');
    const width = source.naturalWidth || source.width;
    const height = source.naturalHeight || source.height;
    const scale = Math.min(1, MAX_WIDTH / width);

    canvas.width = Math.round(width * scale);
    canvas.height = Math.round(height * scale);
    canvas.hidden = false;
    shot.querySelector('[data-shot-tools]').hidden = false;
    shot.querySelector('[data-shot-hint]').hidden = true;

    const editor = {
        canvas,
        base: source,
        shapes: [],
        tool: shot.querySelector('[data-shot-tool].is-on')?.dataset.shotTool || 'pen',
        width: Math.max(3, Math.round(canvas.width / 320)),
    };
    editors.set(shot, editor);
    draw(editor);

    if (!canvas.dataset.wired) {
        canvas.dataset.wired = '1';
        canvas.addEventListener('pointerdown', (e) => down(shot, e));
        canvas.addEventListener('pointermove', (e) => move(shot, e));
        canvas.addEventListener('pointerup', (e) => up(shot, e));
        canvas.addEventListener('pointerleave', (e) => up(shot, e));
    }
}

function point(editor, event) {
    const rect = editor.canvas.getBoundingClientRect();
    return {
        x: (event.clientX - rect.left) * (editor.canvas.width / rect.width),
        y: (event.clientY - rect.top) * (editor.canvas.height / rect.height),
    };
}

function down(shot, event) {
    const editor = editors.get(shot);
    if (!editor) return;
    event.preventDefault();
    editor.canvas.setPointerCapture?.(event.pointerId);
    const p = point(editor, event);
    editor.current = { tool: editor.tool, points: [p], from: p, to: p };
}

function move(shot, event) {
    const editor = editors.get(shot);
    if (!editor?.current) return;
    const p = point(editor, event);
    if (editor.current.tool === 'pen') editor.current.points.push(p);
    editor.current.to = p;
    draw(editor);
}

function up(shot) {
    const editor = editors.get(shot);
    if (!editor?.current) return;
    editor.shapes.push(editor.current);
    editor.current = null;
    draw(editor);
}

function draw(editor) {
    const ctx = editor.canvas.getContext('2d');
    ctx.clearRect(0, 0, editor.canvas.width, editor.canvas.height);
    ctx.drawImage(editor.base, 0, 0, editor.canvas.width, editor.canvas.height);
    ctx.strokeStyle = INK;
    ctx.fillStyle = INK;
    ctx.lineWidth = editor.width;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    [...editor.shapes, editor.current].filter(Boolean).forEach((s) => shape(ctx, s, editor.width));
}

function shape(ctx, s, width) {
    ctx.beginPath();
    if (s.tool === 'pen') {
        s.points.forEach((p, i) => (i ? ctx.lineTo(p.x, p.y) : ctx.moveTo(p.x, p.y)));
        ctx.stroke();
    } else if (s.tool === 'circle') {
        const cx = (s.from.x + s.to.x) / 2;
        const cy = (s.from.y + s.to.y) / 2;
        ctx.ellipse(cx, cy, Math.abs(s.to.x - s.from.x) / 2 || 1, Math.abs(s.to.y - s.from.y) / 2 || 1, 0, 0, Math.PI * 2);
        ctx.stroke();
    } else if (s.tool === 'arrow') {
        const angle = Math.atan2(s.to.y - s.from.y, s.to.x - s.from.x);
        const head = width * 5;
        ctx.moveTo(s.from.x, s.from.y);
        ctx.lineTo(s.to.x, s.to.y);
        ctx.stroke();
        ctx.beginPath();
        ctx.moveTo(s.to.x, s.to.y);
        ctx.lineTo(s.to.x - head * Math.cos(angle - Math.PI / 7), s.to.y - head * Math.sin(angle - Math.PI / 7));
        ctx.lineTo(s.to.x - head * Math.cos(angle + Math.PI / 7), s.to.y - head * Math.sin(angle + Math.PI / 7));
        ctx.closePath();
        ctx.fill();
    }
}

async function onSubmit(event) {
    const form = event.target.closest('[data-support-form]');
    if (!form) return;
    event.preventDefault();

    const status = form.querySelector('[data-support-status]');
    const send = form.querySelector('[data-support-send]');
    const data = new FormData(form);
    const editor = editors.get(form.querySelector('[data-support-shot]'));

    send.disabled = true;
    status.textContent = 'Sending…';

    try {
        if (editor) {
            const blob = await new Promise((resolve) => editor.canvas.toBlob(resolve, 'image/png'));
            if (blob) data.set('screenshot', blob, 'screenshot.png');
        }
        const response = await fetch(form.action, {
            method: 'POST',
            body: data,
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const body = await response.json().catch(() => ({}));

        if (response.status === 422) {
            const first = Object.values(body.errors || {})[0];
            throw new Error(Array.isArray(first) ? first[0] : (body.message || 'Check the message.'));
        }
        if (response.status === 429) throw new Error('Too many requests at once. Wait a minute and try again.');
        if (!response.ok) throw new Error(`The server answered ${response.status}.`);

        form.innerHTML = '';
        const done = document.createElement('p');
        done.className = 'support-done';
        done.textContent = body.message || 'Support request logged.';
        form.append(done);
    } catch (error) {
        status.textContent = error.message || 'The request could not be sent.';
        send.disabled = false;
    }
}
