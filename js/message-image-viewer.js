(function () {
    'use strict';

    const viewer = document.createElement('div');
    viewer.className = 'message-image-viewer';
    viewer.setAttribute('role', 'dialog');
    viewer.setAttribute('aria-modal', 'true');
    viewer.setAttribute('aria-label', 'Image preview');
    viewer.setAttribute('aria-hidden', 'true');
    viewer.hidden = true;
    viewer.innerHTML = `
        <div class="message-image-viewer-panel">
            <div class="message-image-viewer-toolbar">
                <span class="message-image-viewer-name"></span>
                <div class="message-image-viewer-actions">
                    <button type="button" data-action="zoom-out" aria-label="Zoom out" title="Zoom out">−</button>
                    <span class="message-image-viewer-zoom">Fit</span>
                    <button type="button" data-action="zoom-in" aria-label="Zoom in" title="Zoom in">+</button>
                    <button type="button" data-action="fit" aria-label="Reset image to default size" title="Reset to default size">Default</button>
                    <button type="button" data-action="download">Download</button>
                    <button type="button" data-action="close" aria-label="Close image viewer" title="Close">×</button>
                </div>
            </div>
            <div class="message-image-viewer-stage"><img alt=""></div>
        </div>`;
    document.body.appendChild(viewer);

    const image = viewer.querySelector('img');
    const stage = viewer.querySelector('.message-image-viewer-stage');
    const name = viewer.querySelector('.message-image-viewer-name');
    const zoomLabel = viewer.querySelector('.message-image-viewer-zoom');
    let zoom = 1;
    let fitScale = 1;
    let downloadUrl = '';
    let fileName = '';
    let previousFocus = null;

    function resizeImage() {
        if (!image.naturalWidth || !image.naturalHeight) return;
        fitScale = Math.min(
            (stage.clientWidth - 32) / image.naturalWidth,
            (stage.clientHeight - 32) / image.naturalHeight,
            1
        );
        fitScale = Math.max(fitScale, 0.01);
        image.style.width = Math.round(image.naturalWidth * fitScale * zoom) + 'px';
        image.style.height = Math.round(image.naturalHeight * fitScale * zoom) + 'px';
        zoomLabel.textContent = zoom === 1 ? 'Fit' : Math.round(zoom * 100) + '%';
    }

    function closeViewer() {
        viewer.classList.remove('open');
        viewer.setAttribute('aria-hidden', 'true');
        viewer.hidden = true;
        document.body.classList.remove('image-viewer-open');
        image.removeAttribute('src');
        if (previousFocus) previousFocus.focus();
    }

    document.addEventListener('click', function (event) {
        const trigger = event.target.closest('.message-attachment-image[data-image-src]');
        if (!trigger) return;

        event.preventDefault();
        previousFocus = trigger;
        downloadUrl = trigger.href;
        fileName = trigger.dataset.imageName || 'image';
        name.textContent = fileName;
        image.alt = fileName;
        zoom = 1;
        image.onload = resizeImage;
        image.src = trigger.dataset.imageSrc;
        viewer.hidden = false;
        viewer.classList.add('open');
        viewer.setAttribute('aria-hidden', 'false');
        document.body.classList.add('image-viewer-open');
        resizeImage();
        viewer.querySelector('[data-action="close"]').focus();
    });

    viewer.addEventListener('click', function (event) {
        if (event.target === viewer) {
            closeViewer();
            return;
        }
        const button = event.target.closest('button[data-action]');
        if (!button) return;

        switch (button.dataset.action) {
            case 'zoom-in':
                zoom = Math.min(zoom + 0.25, 4);
                resizeImage();
                break;
            case 'zoom-out':
                zoom = Math.max(zoom - 0.25, 0.25);
                resizeImage();
                break;
            case 'fit':
                zoom = 1;
                resizeImage();
                stage.scrollTo({ top: 0, left: 0, behavior: 'smooth' });
                break;
            case 'download': {
                const download = document.createElement('a');
                download.href = downloadUrl;
                download.download = fileName;
                document.body.appendChild(download);
                download.click();
                download.remove();
                break;
            }
            case 'close':
                closeViewer();
                break;
        }
    });

    document.addEventListener('keydown', function (event) {
        if (!viewer.classList.contains('open')) return;
        if (event.key === 'Escape') closeViewer();
        if (event.key === '+' || event.key === '=') {
            zoom = Math.min(zoom + 0.25, 4);
            resizeImage();
        }
        if (event.key === '-') {
            zoom = Math.max(zoom - 0.25, 0.25);
            resizeImage();
        }
    });

    window.addEventListener('resize', resizeImage);
})();