# Compatibility log — Firefox 41 / Chrome 46

Living log of every function, API, syntax or CSS feature that caused (or is expected to cause) an issue in Firefox 41 or Chrome 46, with its workaround. Read it before writing client code. Never delete an entry: update its status.

Status:
- `expected`: known from documentation, not yet seen in the VM.
- `confirmed`: reproduced in the VM.
- `fixed`: workaround applied and re-tested in the VM.

| # | Feature | Browser(s) | Symptom | Workaround | Status | Files |
|---|---|---|---|---|---|---|
| 1 | `HTMLMediaElement.srcObject` | Firefox 41 (only `mozSrcObject`), Chrome 46 (supported from 52) | Webcam preview stays blank | Feature-detect: `srcObject`, else `mozSrcObject`, else `video.src = URL.createObjectURL(stream)` | expected | editor JS |
| 2 | `navigator.mediaDevices.getUserMedia` | Chrome 46 | `navigator.mediaDevices` is undefined | Use it when present, else prefixed `navigator.getUserMedia` / `webkitGetUserMedia` / `mozGetUserMedia` with callbacks; if none exists, show the upload-only message | expected | editor JS |
| 3 | `canvas.toBlob` | Chrome 46 (supported from 50) | `toBlob` is not a function | Not used: `toDataURL('image/png')`, then convert to a Blob with `atob`, `Uint8Array` and `Blob`, then `FormData` | expected | editor JS |

## Adding an entry

Add a row when the user reports a problem in the VM. Fill every column: what was used, where it fails, what was seen, the workaround now used in the code, its status, and the files that use it. Reuse the workaround for the same feature everywhere.