/* Private canonical-person photos. Images, drafts and review proofs stay out of browser storage. */
'use strict';
window.createPersonPhotos = ({ node, request, labels: L }) => {
    let sequence = 0;
    function mount(host, base, { staff = false, compact = false, open = null } = {}) {
        const root = node('section', { class: 'card border bg-white shadow-sm mb-3', 'aria-label': L.title });
        host.append(root);
        let model, currentSummary, draft = null, proof = null, revision = 0, cropper = null, cropResize = null, resetCrop = null, localUrl = null, camera = null, source = 'upload';
        let dead = false, working = false, pageLeaving = false, started = false;
        const live = () => !dead && root.isConnected;
        const status = node('p', { role: 'status', 'aria-live': 'polite' });
        const preview = node('div', { 'aria-live': 'polite' });
        const editor = node('div', { class: 'border rounded overflow-hidden mb-3', style: 'height:300px;max-width:100%;' });
        const image = (url, alt) => node('img', { src: url, alt, width: '160', height: '160',
            class: 'rounded border object-fit-cover mb-3', referrerpolicy: 'no-referrer',
            onerror: (event) => { if (live() && event.currentTarget.isConnected) {
                event.currentTarget.remove(); status.textContent = L.image_error;
            } } });
        const stop = () => { camera?.getTracks().forEach(track => track.stop()); camera = null; };
        const releaseImage = () => { cropResize?.disconnect(); cropResize = null; resetCrop = null;
            cropper?.destroy(); cropper = null; editor.replaceChildren();
            if (localUrl) URL.revokeObjectURL(localUrl); localUrl = null; };
        const discard = (id = draft) => { if (id && !pageLeaving) void request(base + 'discard/', 'POST', { draft_id: id }).catch(() => {});
            if (id === draft) { draft = null; proof = null; } };
        const invalidate = () => { revision++; discard(); preview.replaceChildren(); };
        async function run(action, control = null) {
            if (working || !live()) return;
            working = true;
            root.setAttribute('aria-busy', 'true');
            const buttons = [...root.querySelectorAll('button')].filter(button => !button.disabled);
            buttons.forEach(button => { button.disabled = true; });
            if (control) control.disabled = true;
            status.textContent = '';
            try { await action(); }
            catch (error) {
                if (!live()) return;
                if ([401, 403].includes(error.status)) {
                    stop(); releaseImage(); discard(); root.replaceChildren(node('p', { role: 'status', class: 'alert alert-warning' }, L.denied));
                } else { proof = null; preview.replaceChildren(); status.textContent = error.photoMessage || L.error; }
            } finally { working = false; root.removeAttribute('aria-busy');
                buttons.forEach(button => { if (button.isConnected) button.disabled = false; });
                if (control?.isConnected) control.disabled = false;
            }
        }
        const button = (text, icon, action, primary = false) => node('button', { type: 'button',
            class: `btn btn-${primary ? 'primary' : 'outline-secondary'}`, onclick: event => run(action, event.currentTarget) },
            node('i', { class: `bi bi-${icon} me-2`, 'aria-hidden': 'true' }), text);
        function dispose(event = null) {
            if (dead) return;
            pageLeaving = event?.type === 'pagehide';
            dead = true; revision++; stop(); releaseImage(); discard(); observer.disconnect();
            document.removeEventListener('visibilitychange', hidden); window.removeEventListener('pagehide', dispose);
            root.replaceChildren();
        }
        const observer = new MutationObserver(() => {
            if (!started && root.isConnected) { started = true; void run(render); }
            else if (started && !root.isConnected) dispose();
        });
        observer.observe(document.body, { subtree: true, childList: true });
        const hidden = () => { if (document.hidden) stop(); };
        document.addEventListener('visibilitychange', hidden); window.addEventListener('pagehide', dispose);
        async function choose(file, kind = 'upload') {
            invalidate(); stop(); releaseImage();
            if (!file || file.size > 5242880 || !/\.(png|jpg)$/i.test(file.name)) throw { photoMessage: L.file_error };
            const bytes = new Uint8Array(await file.slice(0, 12).arrayBuffer());
            if (!(bytes[0] === 255 && bytes[1] === 216 && bytes[2] === 255) &&
                ![137,80,78,71,13,10,26,10].every((v, i) => bytes[i] === v)) throw { photoMessage: L.file_error };
            const requested = revision;
            localUrl = URL.createObjectURL(file);
            const original = node('img', { src: localUrl, alt: L.crop, style: 'max-width:100%;' });
            await original.decode();
            if (!live() || requested !== revision) return;
            if (original.naturalWidth * original.naturalHeight > 4000000 ||
                Math.min(original.naturalWidth, original.naturalHeight) < 32) throw { photoMessage: L.file_error };
            source = kind; editor.hidden = false; editor.append(original);
            cropper = new window.CampusPhotoCropper(original, { container: editor });
            const activeCropper = cropper;
            const canvas = cropper.getCropperCanvas(); canvas.style.height = '300px'; canvas.style.width = '100%';
            const selection = cropper.getCropperSelection(); selection.aspectRatio = 1; selection.keyboard = true;
            const cropImage = cropper.getCropperImage();
            // Geometry must be synchronous. A global reduced-motion duration otherwise starts implicit "all" transitions.
            for (const part of [canvas, cropImage, selection]) part.style.transitionProperty = 'none';
            // The library can calculate its first selection before fitting the image. Initialize after readiness.
            selection.initialCoverage = NaN;
            await cropImage.$ready();
            await new Promise(resolve => requestAnimationFrame(resolve));
            if (!live() || cropper !== activeCropper) return;
            cropImage.$center('contain');
            await cropImage.$nextTick();
            resetCrop = () => {
                const frame = canvas.getBoundingClientRect(), fitted = cropImage.getBoundingClientRect();
                const side = Math.min(frame.width, frame.height, fitted.width, fitted.height) * 0.8;
                selection.$change((frame.width - side) / 2, (frame.height - side) / 2, side, side);
            };
            resetCrop();
            selection.addEventListener('change', invalidate);
            let lastWidth = canvas.getBoundingClientRect().width;
            cropResize = new ResizeObserver(() => {
                if (!live() || cropper !== activeCropper) return;
                const frame = canvas.getBoundingClientRect(), before = cropImage.getBoundingClientRect();
                if (Math.abs(frame.width - lastWidth) < 1) return;
                lastWidth = frame.width;
                const relative = { x: (selection.x - before.left + frame.left) / before.width,
                    y: (selection.y - before.top + frame.top) / before.height, size: selection.width / before.width };
                cropImage.$center('contain');
                const after = cropImage.getBoundingClientRect(), side = relative.size * after.width;
                selection.$change(after.left - frame.left + relative.x * after.width,
                    after.top - frame.top + relative.y * after.height, side, side);
            });
            cropResize.observe(canvas);
        }
        function currentPhoto(target, saved) {
            target.replaceChildren(saved.has_photo ? image(base + 'content/?revision=' + saved.version, L.current) : node('p', {}, L.none));
            if (saved.institutional) target.append(node('p', { class: 'badge text-bg-secondary' }, L.institution));
        }
        async function reload() {
            const saved = await request(base);
            if (!live()) return;
            if (!model?.can_edit || !saved.can_edit || compact) return render(saved);
            model = saved; invalidate(); currentPhoto(currentSummary, saved); status.textContent = L.reload_help;
        }
        async function render(saved = null) {
            saved ??= await request(base);
            if (!live()) return;
            model = saved; stop(); releaseImage(); discard();
            const body = node('div', { class: 'card-body' }, node('h3', { class: 'h6' }, L.title), status);
            currentSummary = node('div'); currentPhoto(currentSummary, saved); body.append(currentSummary);
            root.replaceChildren(body);
            if (compact) { if (open) body.append(button(L.edit, 'person-bounding-box', open)); return; }
            body.append(button(L.refresh, 'arrow-clockwise', reload));
            if (!saved.can_edit) {
                body.append(node('p', { class: 'form-text' }, !saved.enabled ? L.disabled : saved.institutional && !staff ? L.locked : L.readonly));
                return;
            }
            body.append(node('p', { class: 'form-text' }, staff ? L.staff_help : L.drop));
            const form = node('fieldset', { class: 'mt-3' });
            const id = `person-photo-${++sequence}`;
            const file = node('input', { id: id + '-file', type: 'file', accept: '.png,.jpg,image/png,image/jpeg', class: 'form-control' });
            const drop = node('div', { class: 'border border-2 rounded bg-light p-3 mb-3' },
                node('label', { for: id + '-file', class: 'form-label fw-semibold' }, L.upload), file, node('p', { class: 'form-text' }, L.drop));
            file.addEventListener('change', () => run(() => choose(file.files[0])));
            drop.addEventListener('dragover', event => event.preventDefault());
            drop.addEventListener('drop', event => { event.preventDefault(); if (event.dataTransfer.files.length === 1)
                void run(() => choose(event.dataTransfer.files[0])); });
            const reason = node('textarea', { id: id + '-reason', class: 'form-control mb-3', required: true, maxlength: '500', rows: '2' });
            if (!staff) reason.value = L.reason_default;
            reason.addEventListener('input', invalidate);
            const cropButtons = node('div', { class: 'd-flex flex-wrap gap-2 mb-3' });
            for (const [label, icon, dx, dy] of [[L.left,'arrow-left',-10,0],[L.right,'arrow-right',10,0],
                [L.up,'arrow-up',0,-10],[L.down,'arrow-down',0,10]]) cropButtons.append(button(label, icon, () => cropper?.getCropperSelection().$move(dx,dy)));
            cropButtons.append(button(L.zoom_in, 'zoom-in', () => cropper?.getCropperSelection().$zoom(-0.1)),
                button(L.zoom_out, 'zoom-out', () => cropper?.getCropperSelection().$zoom(0.1)),
                button(L.reset, 'arrow-counterclockwise', () => resetCrop?.()));
            const videoHost = node('div');
            const cameraButton = button(L.camera, 'camera-video', async () => {
                stop(); invalidate();
                let stream;
                try { stream = await navigator.mediaDevices.getUserMedia({ video: { width: { ideal: 1280 }, height: { ideal: 720 } }, audio: false }); }
                catch { throw { photoMessage: L.camera_error }; }
                if (!live()) { stream.getTracks().forEach(track => track.stop()); return; }
                camera = stream;
                const video = node('video', { autoplay: true, muted: true, playsinline: true, class: 'w-100 rounded border',
                    style: 'max-height:300px;', 'aria-label': L.camera_view });
                video.srcObject = stream;
                const capture = button(L.capture, 'camera', async () => {
                    if (!video.videoWidth) throw { photoMessage: L.camera_error };
                    const canvas = document.createElement('canvas');
                    const scale = Math.min(1, 1280 / video.videoWidth, 720 / video.videoHeight);
                    canvas.width = Math.round(video.videoWidth * scale); canvas.height = Math.round(video.videoHeight * scale);
                    canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
                    const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.9));
                    stop(); videoHost.replaceChildren();
                    if (live() && blob) await choose(new File([blob], 'camera.jpg', { type: 'image/jpeg' }), 'camera');
                });
                videoHost.replaceChildren(node('p', { class: 'form-text' }, L.camera_help), video,
                    node('div', { class: 'd-flex flex-wrap gap-2 my-2' }, capture,
                        button(L.stop, 'stop-circle', () => { stop(); videoHost.replaceChildren(); })));
            });
            editor.hidden = true;
            form.append(drop, cameraButton, videoHost, node('h4', { class: 'h6 mt-3' }, L.crop),
                node('p', { class: 'form-text' }, L.crop_help), editor, cropButtons,
                node('label', { for: id + '-reason', class: 'form-label' }, L.reason), reason);
            async function review(kind, consent = false) {
                if (!reason.reportValidity()) return;
                invalidate(); const requested = revision;
                const input = new FormData();
                input.append('_workspace_json', JSON.stringify({ version: model.version, source: kind, reason: reason.value, consent }));
                if (['upload', 'camera'].includes(kind)) {
                    if (!cropper) throw { photoMessage: L.file_error };
                    const canvas = await cropper.getCropperSelection().$toCanvas({ width: 512, height: 512 });
                    const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.9));
                    if (!blob) throw { photoMessage: L.file_error };
                    input.append('photo', blob, 'photo.jpg');
                }
                const reviewed = await request(base + 'review/', 'POST', input);
                if (!live() || requested !== revision) { discard(reviewed.draft_id); return; }
                draft = reviewed.draft_id; proof = reviewed.review_token;
                const confirm = button(L.confirm, 'check2-circle', async () => {
                    if (!proof) return;
                    form.disabled = true; editor.inert = true;
                    try {
                        await request(base + 'confirm/', 'POST', { draft_id: draft, review_token: proof });
                        draft = null; proof = null;
                        if (live()) { await render(); status.textContent = L.saved; }
                    } finally { form.disabled = false; editor.inert = false; }
                }, true);
                preview.replaceChildren(node('section', { class: 'card border bg-white mt-3' }, node('div', { class: 'card-body' },
                    node('h4', { class: 'h6' }, L.review_title), node('p', { class: 'form-text' }, L.review_help),
                    reviewed.removed ? node('p', {}, L.removed) : image(base + 'preview/' + draft + '/', L.review_title),
                    node('p', {}, reason.value), confirm)));
                confirm.focus();
            }
            const actions = node('div', { class: 'd-flex flex-wrap gap-2' },
                button(L.review, 'eye', () => review(source), true),
                button(L.cancel, 'x-circle', () => { invalidate(); releaseImage(); stop(); videoHost.replaceChildren(); }));
            if (saved.has_photo) actions.append(button(L.clear, 'trash', () => review('removed')));
            form.append(actions);
            if (!staff && saved.gravatar_enabled) {
                const consent = node('input', { id: id + '-consent', type: 'checkbox', class: 'form-check-input' });
                consent.addEventListener('change', invalidate);
                form.append(node('section', { class: 'card border bg-white mt-3' }, node('div', { class: 'card-body' },
                    node('p', { class: 'form-text' }, L.gravatar_help), node('div', { class: 'form-check mb-3' }, consent,
                        node('label', { for: id + '-consent', class: 'form-check-label' }, L.consent)),
                    button(L.gravatar, 'person-circle', () => { if (!consent.checked) throw { photoMessage: L.consent_required };
                        return review('gravatar', true); }))));
            }
            body.append(form, preview);
        }
        if (root.isConnected) { started = true; void run(render); }
        return { dispose, reload: () => run(reload) };
    }
    return { mount };
};
