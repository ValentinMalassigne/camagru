/* Camagru editor script (spec sections 4.4 and 11, COMPATIBILITY.md).
 *
 * Responsibilities:
 * - get the webcam through a small compatibility wrapper (never call
 *   getUserMedia directly), and attach the stream by feature detection;
 * - enable the capture and upload buttons only once an overlay is selected;
 * - draw the video frame to a canvas, export it with toDataURL('image/png')
 *   (never canvas.toBlob, see COMPATIBILITY.md entry 3), convert the result
 *   to a Blob and send it with FormData + XMLHttpRequest and the overlay id
 *   (XHR instead of fetch: see COMPATIBILITY.md entry 5);
 * - when there is no webcam, no permission or no getUserMedia, show a
 *   friendly message instead of the preview and leave the upload form
 *   working. Handled cases produce no console noise.
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
    var message = document.getElementById('editor-message');
    var statusLine = document.getElementById('editor-status');
    var captureButton = document.getElementById('editor-capture');
    var uploadButton = document.getElementById('editor-upload-submit');
    var sideList = document.getElementById('editor-side-list');
    var csrfToken = form.querySelector('input[name="_csrf_token"]').value;
    var hasStream = false;

    /* --- Overlay selection gates the two buttons ----------------------- */

    /**
     * The overlay radio currently selected, or null. The name attribute is
     * shared by all the radios of the group.
     */
    function selectedOverlay() {
        return form.querySelector('input[name="overlay"]:checked');
    }

    /**
     * Enable the capture and upload buttons only when an overlay is chosen.
     * With JavaScript disabled the buttons simply stay enabled and the server
     * validates the overlay id (the upload form must keep working without JS).
     */
    function refreshButtons() {
        var hasOverlay = selectedOverlay() !== null;
        captureButton.disabled = !hasOverlay;
        uploadButton.disabled = !hasOverlay;
    }

    // Change events from the radios bubble up to the form.
    form.addEventListener('change', refreshButtons);
    refreshButtons();

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
        if (!isSecureOrigin()) {
            setText(message,
                'The webcam is only available on a secure origin. Open the ' +
                'editor at http://localhost:8080 (or serve the site over ' +
                'HTTPS). You are currently viewing this page from "' +
                location.hostname + '". You can still upload a picture below.');
        }
        message.className = message.className.replace(' is-hidden', '');
    }

    getWebcam(function (stream) {
        attachStream(stream);
        hasStream = true;
    }, function () {
        showNoWebcam();
    });

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
     * Send a picture (the webcam blob) and the overlay id to the server.
     *
     * Transport: XMLHttpRequest, not fetch. In Firefox 41 the capture with
     * fetch + FormData + Blob never completes (COMPATIBILITY.md entry 5);
     * XHR with the same FormData works in every target browser. The
     * X-CSRF-Token header carries the token (Csrf.php reads it), and
     * X-Requested-With tells the server this request expects JSON instead
     * of the no-JavaScript redirect.
     *
     * @param {Blob} blob The captured frame as a PNG blob.
     * @param {string} overlayId The selected overlay id.
     * @param {Function} onSuccess Called with the parsed JSON response.
     * @param {Function} onFailure Called with the server's error message,
     *                             or null when there is none.
     */
    function sendPicture(blob, overlayId, onSuccess, onFailure) {
        var data = new FormData();
        data.append('photo', blob, 'capture.png');
        data.append('overlay', overlayId);

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
     * Handle a click on the capture button: take the frame, send it, and
     * either show the new thumbnail or the server's error message.
     */
    function handleCapture() {
        var overlay = selectedOverlay();
        if (!overlay) {
            return;
        }
        if (!hasStream || !video.videoWidth) {
            setStatus('The webcam is not available. You can still upload a picture.');
            return;
        }

        setStatus('Saving your picture...');
        var blob = dataUrlToBlob(captureFrame());
        sendPicture(blob, overlay.value, function (data) {
            addThumbnail(data.id, data.url);
            setStatus('Picture saved.');
        }, function (errorMessage) {
            setStatus(errorMessage ? errorMessage : 'The picture could not be saved.');
        });
    }

    captureButton.addEventListener('click', handleCapture);
}());
