/* Camagru editor script (spec sections 4.4 and 11, COMPATIBILITY.md).
 *
 * Responsibilities:
 * - get the webcam through a small compatibility wrapper (never call
 *   getUserMedia directly), and attach the stream by feature detection;
 * - stickers are optional checkboxes: the user may pick 0 to all of them.
 *   The capture button needs at least one sticker; the upload submit is
 *   always available (the server accepts a picture without stickers);
 * - draw the video frame to a canvas, export it with toDataURL('image/png')
 *   (never canvas.toBlob, see COMPATIBILITY.md entry 3), convert the result
 *   to a Blob and send it with FormData + XMLHttpRequest and every selected
 *   sticker id (XHR instead of fetch: see COMPATIBILITY.md entry 5);
 * - when there is no webcam, no permission or no getUserMedia, show a
 *   friendly message instead of the preview and leave the upload form
 *   working. Handled cases produce no console noise.
 * - live sticker preview (bonus): once at least one sticker is selected
 *   and the webcam runs, draw the video and the selected stickers on a
 *   canvas that sits on top of the video, mirroring the server's compositing.
 *   A picture chosen in the upload input takes the webcam's place as the
 *   preview frame until the page is (re)loaded. The server still produces
 *   the final saved image; this is only a preview.
 *
 * Written in ES5 style: var and function only, no arrow functions, no
 * template literals, no async/await.
 */
(function () {
    'use strict';

    var form = document.getElementById('editor-form');
    if (!form) {
        // Not on the editor page.
        return;
    }

    var video = document.getElementById('editor-video');
    var fileInput = document.getElementById('editor-file');
    var message = document.getElementById('editor-message');
    var statusLine = document.getElementById('editor-status');
    var captureButton = document.getElementById('editor-capture');
    var sideList = document.getElementById('editor-side-list');
    var csrfToken = form.querySelector('input[name="_csrf_token"]').value;
    var hasStream = false;
    var overlayCanvas = document.getElementById('editor-overlay-canvas');
    var overlayContext = overlayCanvas ? overlayCanvas.getContext('2d') : null;
    /* Sticker PNGs already loaded, keyed by sticker id, so selecting a
       sticker again never reloads it. */
    var overlayImages = {};
    var previewTimer = null;
    /* The picture chosen in the upload input, once decoded for the preview.
       While set, it replaces the webcam as the preview frame. */
    var fileImage = null;

    /* --- Sticker selection (0 to all, optional) ------------------------- */

    /**
     * Every checkbox of the sticker group. The name carries brackets
     * ("overlay[]") so a plain form submission sends a list.
     */
    function stickerInputs() {
        return form.querySelectorAll('input[name="overlay[]"]');
    }

    /**
     * The ids of the checked stickers, sorted numerically. The server draws
     * the stickers in this same sorted order, so the picture matches the
     * live preview whatever the click order was.
     *
     * @returns {string[]}
     */
    function selectedIds() {
        var ids = [];
        var inputs = stickerInputs();
        for (var i = 0; i < inputs.length; i = i + 1) {
            if (inputs[i].checked) {
                ids.push(inputs[i].value);
            }
        }
        ids.sort(function (a, b) {
            return Number(a) - Number(b);
        });
        return ids;
    }

    /**
     * True once every currently selected sticker has its PNG cached, so
     * the preview can draw the same set the server will composite.
     *
     * @param {string[]} ids
     */
    function allStickersLoaded(ids) {
        for (var i = 0; i < ids.length; i = i + 1) {
            if (!overlayImages[ids[i]]) {
                return false;
            }
        }
        return true;
    }

    /**
     * Keep the capture button in sync with the current mode. In camera
     * mode it reads "Take a photo" and is enabled only when at least one
     * sticker is checked. While a local picture is chosen it becomes the
     * always-enabled "Back to camera" switch (the sticker rule does not
     * apply to it: it never captures). The upload submit is never gated:
     * the server accepts a picture without stickers, so the no-JavaScript
     * upload path keeps working.
     */
    function refreshCaptureButton() {
        if (fileImage !== null) {
            setText(captureButton, 'Back to camera');
            captureButton.disabled = false;
            return;
        }
        setText(captureButton, 'Take a photo');
        captureButton.disabled = selectedIds().length === 0;
    }

    // One change listener covers the whole sticker selection.
    form.addEventListener('change', onSelectionChange);
    // Initial state: no sticker is checked, so the capture button is off.
    refreshCaptureButton();

    /* --- Live sticker preview (bonus) ----------------------------------- */

    /**
     * React to any checkbox change: load the PNG of every newly selected
     * sticker, then show or hide the preview. The sources come from the
     * data-overlay-src attributes rendered by the server (the same
     * whitelist the capture uses), never from free-form client input.
     */
    function onSelectionChange() {
        var inputs = stickerInputs();
        for (var i = 0; i < inputs.length; i = i + 1) {
            if (!inputs[i].checked || overlayImages[inputs[i].value]) {
                continue;
            }
            loadSticker(inputs[i]);
        }
        if (selectedIds().length === 0 && fileImage === null) {
            stopOverlayPreview();
        } else {
            refreshPreview();
        }
        refreshCaptureButton();
    }

    /**
     * Load the PNG of one sticker checkbox and cache it by id, then try to
     * start the preview (the last loaded sticker triggers it).
     *
     * @param {HTMLInputElement} checkbox
     */
    function loadSticker(checkbox) {
        var image = new Image();
        image.onload = function () {
            overlayImages[checkbox.value] = image;
            refreshPreview();
        };
        image.src = checkbox.getAttribute('data-overlay-src');
    }

    /**
     * Start or keep the preview loop running when there is something to
     * show: a chosen upload (with or without stickers) or at least one
     * sticker over the webcam stream. Called whenever the selection, the
     * webcam or the chosen file changes. A running loop picks every change
     * up on its next tick; the immediate draw avoids a flash of stale
     * content.
     */
    function refreshPreview() {
        var ids = selectedIds();
        if (ids.length === 0 && fileImage === null) {
            stopOverlayPreview();
            return;
        }
        // No frame yet: nothing can be drawn (the webcam is still starting,
        // or it failed and no file is chosen).
        if (fileImage === null && !hasStream) {
            return;
        }
        if (!allStickersLoaded(ids) || !overlayContext) {
            return;
        }
        if (overlayCanvas.className.indexOf('is-hidden') !== -1) {
            overlayCanvas.className = overlayCanvas.className.replace(' is-hidden', '');
        }
        drawOverlayPreview();
        if (previewTimer === null) {
            previewTimer = setInterval(drawOverlayPreview, 100);
        }
    }

    /**
     * Stop the preview loop and hide the canvas (called when the last
     * sticker is unchecked). The canvas bitmap is also cleared, so the
     * canvas can never briefly show a stale frame again.
     */
    function stopOverlayPreview() {
        if (previewTimer !== null) {
            clearInterval(previewTimer);
            previewTimer = null;
        }
        if (overlayCanvas) {
            if (overlayCanvas.className.indexOf('is-hidden') === -1) {
                overlayCanvas.className = overlayCanvas.className + ' is-hidden';
            }
            if (overlayContext) {
                overlayContext.clearRect(0, 0, overlayCanvas.width, overlayCanvas.height);
            }
        }
    }

    /**
     * One preview frame: the current frame source, then every selected
     * sticker on top, with the same geometry as ImageComposer: each
     * sticker is scaled to fit inside the frame and centered on it,
     * drawn in sorted id order (higher ids above lower ones).
     *
     * The frame source is the chosen upload when there is one (the user
     * wants to see the stickers appear on the picture they picked), else
     * the webcam video.
     */
    function drawOverlayPreview() {
        var source;
        var width;
        var height;
        if (fileImage !== null) {
            source = fileImage;
            width = fileImage.naturalWidth;
            height = fileImage.naturalHeight;
        } else {
            if (!video.videoWidth) {
                return;
            }
            source = video;
            width = video.videoWidth;
            height = video.videoHeight;
        }
        if (overlayCanvas.width !== width || overlayCanvas.height !== height) {
            overlayCanvas.width = width;
            overlayCanvas.height = height;
        }
        overlayContext.drawImage(source, 0, 0, width, height);

        var ids = selectedIds();
        for (var i = 0; i < ids.length; i = i + 1) {
            var image = overlayImages[ids[i]];
            if (!image) {
                continue;
            }
            var scale = Math.min(
                width / image.naturalWidth,
                height / image.naturalHeight
            );
            var newWidth = Math.max(1, Math.round(image.naturalWidth * scale));
            var newHeight = Math.max(1, Math.round(image.naturalHeight * scale));
            overlayContext.drawImage(image,
                Math.floor((width - newWidth) / 2),
                Math.floor((height - newHeight) / 2),
                newWidth, newHeight);
        }
    }

    /* --- Webcam wrapper and stream attachment --------------------------- */

    /**
     * Ask for the webcam through the best API available (COMPATIBILITY.md
     * entry 2): unprefixed promise-based getUserMedia when present, else the
     * old prefixed callback variants, else the failure callback directly.
     *
     * @param {Function} onSuccess Called with the media stream.
     * @param {Function} onFailure Called with no argument when there is no
     *                             API, no webcam or no permission.
     */
    function getWebcam(onSuccess, onFailure) {
        var constraints = { video: true, audio: false };
        if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
            navigator.mediaDevices.getUserMedia(constraints).then(onSuccess, onFailure);
        } else if (navigator.getUserMedia) {
            navigator.getUserMedia(constraints, onSuccess, onFailure);
        } else if (navigator.webkitGetUserMedia) {
            navigator.webkitGetUserMedia(constraints, onSuccess, onFailure);
        } else if (navigator.mozGetUserMedia) {
            navigator.mozGetUserMedia(constraints, onSuccess, onFailure);
        } else {
            onFailure();
        }
    }

    /**
     * Attach the stream to the <video> by feature detection
     * (COMPATIBILITY.md entry 1): srcObject, else mozSrcObject, else an
     * object URL.
     *
     * @param {MediaStream} stream
     */
    function attachStream(stream) {
        if (typeof video.srcObject !== 'undefined') {
            video.srcObject = stream;
        } else if (typeof video.mozSrcObject !== 'undefined') {
            video.mozSrcObject = stream;
        } else {
            video.src = URL.createObjectURL(stream);
        }
    }

    /**
     * True when the current origin may use the webcam: HTTPS, or localhost /
     * 127.x. Chrome 46 (like current Chrome) silently refuses getUserMedia
     * on any other HTTP origin: no prompt, just an error
     * (COMPATIBILITY.md entry 6).
     */
    function isSecureOrigin() {
        return location.protocol === 'https:' ||
            location.hostname === 'localhost' ||
            location.hostname.indexOf('127.') === 0 ||
            location.hostname === '::1';
    }

    /**
     * Handled failure: replace the preview with a friendly message. The
     * upload form keeps working; nothing is logged to the console. When the
     * origin cannot have a webcam at all (non-localhost HTTP), the message
     * says so instead of a generic "not available".
     */
    function showNoWebcam() {
        video.className = video.className + ' is-hidden';
        // Without a camera the capture button can never work: hide it too.
        captureButton.className = captureButton.className + ' is-hidden';
        if (!isSecureOrigin()) {
            setText(message,
                'The webcam is only available on a secure origin. Open the ' +
                'editor at http://localhost (or serve the site over ' +
                'HTTPS). You are currently viewing this page from "' +
                location.hostname + '". You can still upload a picture below.');
        }
        message.className = message.className.replace(' is-hidden', '');
    }

    getWebcam(function (stream) {
        attachStream(stream);
        hasStream = true;
        // Stickers or a chosen file may already be there: the preview can
        // start now.
        refreshPreview();
    }, function () {
        showNoWebcam();
    });

    /* --- Chosen upload file ---------------------------------------------- */

    /**
     * React to a picture being chosen in the upload input: decode it with a
     * FileReader so the preview can show it (with the selected stickers)
     * in place of the webcam frame. The server still validates the real
     * upload; this is only a preview.
     */
    function onFileChange() {
        fileImage = null;
        refreshCaptureButton();
        if (!fileInput.files || fileInput.files.length === 0) {
            refreshPreview();
            return;
        }

        var reader = new FileReader();
        reader.onload = function () {
            var image = new Image();
            image.onload = function () {
                fileImage = image;
                // Without a webcam the video box is hidden; it must be
                // visible again so the preview canvas has a frame to sit in.
                video.className = video.className.replace(' is-hidden', '');
                refreshPreview();
                refreshCaptureButton();
            };
            image.onerror = function () {
                setStatus('This file cannot be previewed, but it will still be sent to the server.');
                refreshPreview();
            };
            image.src = reader.result;
        };
        reader.readAsDataURL(fileInput.files[0]);
    }

    fileInput.addEventListener('change', onFileChange);

    /* --- Capture and upload --------------------------------------------- */

    /**
     * Draw the current video frame to a canvas and return it as a PNG
     * data URL. The video size is used so the capture is not scaled.
     */
    function captureFrame() {
        var canvas = document.createElement('canvas');
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
        return canvas.toDataURL('image/png');
    }

    /**
     * Convert a PNG data URL to a Blob, without canvas.toBlob
     * (COMPATIBILITY.md entry 3): decode base64 with atob, rebuild the bytes
     * with Uint8Array, wrap them in a Blob.
     *
     * @param {string} dataUrl
     * @returns {Blob}
     */
    function dataUrlToBlob(dataUrl) {
        var byteString = window.atob(dataUrl.split(',')[1]);
        var bytes = new Uint8Array(byteString.length);
        for (var i = 0; i < byteString.length; i = i + 1) {
            bytes[i] = byteString.charCodeAt(i);
        }
        return new Blob([bytes], { type: 'image/png' });
    }

    /**
     * Send a picture (the webcam blob) and every selected sticker id to the
     * server. An empty list is valid: the picture is saved without stickers.
     *
     * Transport: XMLHttpRequest, not fetch. In Firefox 41 the capture with
     * fetch + FormData + Blob never completes (COMPATIBILITY.md entry 5);
     * XHR with the same FormData works in every target browser. The
     * X-CSRF-Token header carries the token (Csrf.php reads it), and
     * X-Requested-With tells the server this request expects JSON instead
     * of the no-JavaScript redirect.
     *
     * @param {Blob} blob The captured frame as a PNG blob.
     * @param {string[]} overlayIds The selected sticker ids.
     * @param {Function} onSuccess Called with the parsed JSON response.
     * @param {Function} onFailure Called with the server's error message,
     *                             or null when there is none.
     */
    function sendPicture(blob, overlayIds, onSuccess, onFailure) {
        var data = new FormData();
        data.append('photo', blob, 'capture.png');
        for (var i = 0; i < overlayIds.length; i = i + 1) {
            data.append('overlay[]', overlayIds[i]);
        }

        var xhr = new XMLHttpRequest();
        xhr.open('POST', form.getAttribute('action'), true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.setRequestHeader('X-CSRF-Token', csrfToken);
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) {
                return;
            }
            var parsed = null;
            try {
                parsed = JSON.parse(xhr.responseText);
            } catch (parseError) {
                parsed = null;
            }
            if (xhr.status >= 200 && xhr.status < 300 && parsed && parsed.url) {
                onSuccess(parsed);
            } else {
                onFailure(parsed && parsed.error ? parsed.error : null);
            }
        };
        xhr.send(data);
    }

    /**
     * Prepend a new thumbnail (image + delete form) to the side section, so
     * the capture is visible immediately, newest first.
     *
     * @param {number} id The new image id (from the JSON response).
     * @param {string} url The new image URL (from the JSON response).
     */
    function addThumbnail(id, url) {
        if (!sideList) {
            return;
        }
        var empty = document.getElementById('editor-side-empty');
        if (empty && empty.parentNode) {
            empty.parentNode.removeChild(empty);
        }

        var item = document.createElement('li');
        item.className = 'editor-side__item';

        var img = document.createElement('img');
        img.className = 'editor-side__img';
        img.src = url;
        img.alt = 'One of my pictures';
        item.appendChild(img);

        var deleteForm = document.createElement('form');
        deleteForm.className = 'editor-side__delete';
        deleteForm.method = 'post';
        deleteForm.action = '/images/' + id + '/delete';

        var csrf = document.createElement('input');
        csrf.type = 'hidden';
        csrf.name = '_csrf_token';
        csrf.value = csrfToken;
        deleteForm.appendChild(csrf);

        var button = document.createElement('button');
        button.className = 'editor-side__delete-button';
        button.type = 'submit';
        button.appendChild(document.createTextNode('Delete'));
        deleteForm.appendChild(button);

        item.appendChild(deleteForm);
        sideList.insertBefore(item, sideList.firstChild);
    }

    /**
     * Replace the text content of an element (no innerHTML, so no injection
     * surface). Used for the status line and the no-webcam message.
     *
     * @param {HTMLElement} element
     * @param {string} text
     */
    function setText(element, text) {
        while (element.firstChild) {
            element.removeChild(element.firstChild);
        }
        element.appendChild(document.createTextNode(text));
    }

    /**
     * Show a short status line for the user. Never the console.
     *
     * @param {string} text
     */
    function setStatus(text) {
        setText(statusLine, text);
    }

    /**
     * Handle a click on the capture button. While a local picture is
     * chosen, the button is the "Back to camera" switch: drop the local
     * picture and return to the webcam preview. In camera mode it takes
     * the frame, sends it with the selected stickers (possibly none), and
     * either shows the new thumbnail or the server's error message.
     */
    function handleCapture() {
        if (fileImage !== null) {
            fileImage = null;
            // Clear the input, otherwise picking the same file again would
            // not fire a change event (the value would not change).
            fileInput.value = '';
            refreshPreview();
            refreshCaptureButton();
            return;
        }
        if (!hasStream || !video.videoWidth) {
            setStatus('The webcam is not available. You can still upload a picture.');
            return;
        }

        setStatus('Saving your picture...');
        var blob = dataUrlToBlob(captureFrame());
        sendPicture(blob, selectedIds(), function (data) {
            addThumbnail(data.id, data.url);
            setStatus('Picture saved.');
        }, function (errorMessage) {
            setStatus(errorMessage ? errorMessage : 'The picture could not be saved.');
        });
    }

    captureButton.addEventListener('click', handleCapture);
}());
