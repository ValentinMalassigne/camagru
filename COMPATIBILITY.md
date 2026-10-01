# Compatibility log — Firefox 41 / Chrome 46

Living log of every function, API, syntax or CSS feature that caused (or is expected to cause) an issue in Firefox 41 or Chrome 46, with its workaround. Read it before writing client code. Never delete an entry: update its status.

Status:
- `expected`: known from documentation, not yet seen in the VM.
- `confirmed`: reproduced in the VM.
- `fixed`: workaround applied and re-tested in the VM.

| # | Feature | Browser(s) | Symptom | Workaround | Status | Files |
|---|---|---|---|---|---|---|
| 1 | `HTMLMediaElement.srcObject` | Firefox 41 (only `mozSrcObject`), Chrome 46 (supported from 52) | Webcam preview stays blank | Feature-detect: `srcObject`, else `mozSrcObject`, else `video.src = URL.createObjectURL(stream)` | fixed | `public/assets/js/editor.js` |
| 2 | `navigator.mediaDevices.getUserMedia` | Chrome 46 | `navigator.mediaDevices` is undefined | Use it when present, else prefixed `navigator.getUserMedia` / `webkitGetUserMedia` / `mozGetUserMedia` with callbacks; if none exists, show the upload-only message | fixed | `public/assets/js/editor.js` |
| 3 | `canvas.toBlob` | Chrome 46 (supported from 50) | `toBlob` is not a function | Not used: `toDataURL('image/png')`, then convert to a Blob with `atob`, `Uint8Array` and `Blob`, then `FormData` | fixed | `public/assets/js/editor.js` |
| 4 | `<input type="password">` on an insecure page | Firefox 41 | Console warning: "Password fields present on an insecure (http://) page" and "Password fields present in a form with an insecure (http://) form action" | None possible in code: the spec serves the site on `http://localhost:8080` (no TLS in scope), and Firefox emits this warning itself for every password field over HTTP. Accepted as browser output, listed in NOTES.md; not app noise. | confirmed | register (later: login, reset, account) |
| 5 | `fetch()` with a `FormData` body containing a `Blob` (webcam capture) | Firefox 41 | The capture request never completes: the promise rejects with no console error, the server never receives anything (no image row, no log entry), and the editor shows "The picture could not be saved". The plain upload form (a normal form POST of the same fields) works. | Do not use `fetch` for the capture: send the exact same pipeline (`toDataURL('image/png')` → `atob`/`Uint8Array`/`Blob` → `FormData` with the overlay id) through `XMLHttpRequest` (`setRequestHeader` for `X-CSRF-Token` and `X-Requested-With`). Server code unchanged. | fixed | `public/assets/js/editor.js` |
| 6 | `getUserMedia` on a non-localhost HTTP origin | Chrome 46 (Firefox 41 allows it) | The camera is never requested: no permission prompt, the prefixed/unprefixed wrapper's failure callback runs immediately, and the editor shows "the webcam is not available". | Chrome only allows the webcam on a secure origin: serve/test the editor as `http://localhost:8080` in the VM (port forward) or over HTTPS. When the page origin is not secure, editor.js now detects it and shows an explicit message naming the requirement and the current host, instead of the generic "not available" text. | fixed | `public/assets/js/editor.js` |

## Adding an entry

Add a row when the user reports a problem in the VM. Fill every column: what was used, where it fails, what was seen, the workaround now used in the code, its status, and the files that use it. Reuse the workaround for the same feature everywhere.