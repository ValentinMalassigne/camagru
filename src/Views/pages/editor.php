<?php
// Editor page (spec section 4.4): main section (webcam preview, sticker
// list, capture button and upload form — the webcam and the upload are both
// always available) and side section (thumbnails of all the current user's
// previous pictures, newest first, each with a delete button). Markup only;
// every value is escaped with e(). The capture button needs JavaScript, the
// upload form works without it. Stickers are optional checkboxes: the user
// may pick 0 to all of them; the capture button needs at least one, the
// upload always works (the server accepts a picture without stickers).
/** @var string $title */
/** @var array<int, string> $overlays Overlay id => PNG filename. */
/** @var array<int, array<string, mixed>> $images The user's pictures, newest first. */
/** @var string $csrfToken */
?>
<section class="page page--editor">
    <h1 class="page__title">Editor</h1>

    <div class="editor">
        <section class="editor__main">
            <div class="editor__preview">
                <video id="editor-video" class="editor__video" autoplay muted></video>
                <!-- Live sticker preview (bonus): editor.js draws the selected
                     stickers over the video frames here, mirroring the
                     server's compositing. Hidden until a sticker is selected. -->
                <canvas id="editor-overlay-canvas" class="editor__overlay-canvas is-hidden" width="640" height="480"></canvas>
                <p id="editor-message" class="editor__message is-hidden">
                    The webcam is not available (or permission was refused).
                    You can still upload a picture below.
                </p>
            </div>

            <form id="editor-form" class="editor-form" method="post" action="/editor/capture" enctype="multipart/form-data">
                <input type="hidden" name="_csrf_token" value="<?= e($csrfToken) ?>">

                <fieldset class="editor-overlays">
                    <legend class="editor-overlays__legend">Add stickers (optional)</legend>
                    <ul class="editor-overlays__list">
                        <?php foreach ($overlays as $id => $filename): ?>
                            <li class="editor-overlays__item">
                                <label class="editor-overlays__label">
                                    <input class="editor-overlays__choice" type="checkbox" name="overlay[]"
                                           value="<?= (int) $id ?>" data-overlay-src="/assets/overlays/<?= e($filename) ?>">
                                    <img class="editor-overlays__img" src="/assets/overlays/<?= e($filename) ?>"
                                         alt="Sticker: <?= e(basename((string) $filename, '.png')) ?>">
                                </label>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="editor-overlays__hint">Pick none, some or all. Each sticker is centered on the picture; taking a photo needs at least one, an upload works without.</p>
                </fieldset>

                <div class="editor-upload">
                    <label class="editor-upload__label" for="editor-file">Or upload a picture (PNG or JPEG, 10 MB max)</label>
                    <input class="editor-upload__input" type="file" id="editor-file" name="photo"
                           accept="image/png,image/jpeg" required>
                    <button id="editor-upload-submit" class="editor-submit" type="submit">Save editing</button>
                </div>
            </form>

            <button id="editor-capture" class="editor-submit" type="button" disabled>Take a photo</button>
            <p id="editor-status" class="editor__status"></p>
        </section>

        <aside class="editor__side">
            <h2 class="editor__side-title">My pictures</h2>
            <ul id="editor-side-list" class="editor-side__list">
                <?php if (empty($images)): ?>
                    <li class="editor-side__empty" id="editor-side-empty">No pictures yet.</li>
                <?php else: ?>
                    <?php foreach ($images as $image): ?>
                        <li class="editor-side__item">
                            <img class="editor-side__img" src="/uploads/<?= e($image['filename']) ?>"
                                 alt="One of my pictures">
                            <form class="editor-side__delete" method="post"
                                  action="/images/<?= (int) $image['id'] ?>/delete">
                                <input type="hidden" name="_csrf_token" value="<?= e($csrfToken) ?>">
                                <button class="editor-side__delete-button" type="submit">Delete</button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </aside>
    </div>

    <!-- Versioned include: /assets/ is cached for 1 hour, so every change to
         editor.js MUST bump this version or the browsers keep the old file
         (COMPATIBILITY.md entry 5 was masked this way during testing). -->
    <script src="/assets/js/editor.js?v=10"></script>
</section>
