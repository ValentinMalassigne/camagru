<?php
// Image detail page (spec sections 4.3 and 5: GET /images/{id}): the
// picture, its author and date, the like toggle with the count, the social
// share links (bonus) and the comments with their authors. With JavaScript,
// image.js submits the like and comment forms via XHR and updates the page in
// place; without it, both forms POST and redirect as before. Visible to
// everyone; visitors get links to the login page instead of the buttons.
// Markup only; every value is escaped with e().
/** @var string $title */
/** @var array<string, mixed> $image The picture row (author username and counts included). */
/** @var bool $liked Has the current user already liked this picture? */
/** @var string $date Formatted creation date of the picture. */
/** @var array<int, array<string, mixed>> $comments Oldest first, each with a formatted date. */
/** @var array<int, array<string, string>> $shares Social share links (label + URL), built by the controller. */
/** @var string $csrfToken */
/** @var mixed $currentUser */
?>
<section class="page page--image" data-image-id="<?= (int) $image['id'] ?>">
    <h1 class="page__title">Picture by <?= e((string) $image['username']) ?></h1>
    <p class="image-view__back"><a href="/">Back to the gallery</a></p>

    <figure class="image-view">
        <img class="image-view__img" src="/uploads/<?= e_attr($image['filename']) ?>"
             alt="Picture by <?= e((string) $image['username']) ?>">
        <figcaption class="image-view__caption">
            by <?= e((string) $image['username']) ?> &mdash; <?= e($date) ?>
        </figcaption>
    </figure>

    <div class="image-view__actions">
        <?php if ($currentUser !== null): ?>
            <form id="like-form" class="image-view__like-form" method="post"
                  action="/images/<?= (int) $image['id'] ?>/like">
                <input type="hidden" name="_csrf_token" value="<?= e($csrfToken) ?>">
                <button id="like-button" class="image-view__like-button<?= $liked ? ' image-view__like-button--on' : '' ?>"
                        type="submit"><?= $liked ? 'Unlike' : 'Like' ?></button>
            </form>
        <?php else: ?>
            <a class="image-view__like-button image-view__like-button--link" href="/login">Like</a>
        <?php endif; ?>
        <span id="like-count" class="image-view__like-count">
            <?= (int) $image['like_count'] ?> like<?= ((int) $image['like_count'] === 1) ? '' : 's' ?>
        </span>
        <span id="like-status" class="image-view__status"></span>
    </div>

    <div class="image-share">
        <span class="image-share__label">Share:</span>
        <?php foreach ($shares as $share): ?>
            <a class="image-share__link" href="<?= e_attr($share['url']) ?>"
               target="_blank" rel="noreferrer noopener"><?= e($share['label']) ?></a>
        <?php endforeach; ?>
    </div>

    <section class="comments">
        <h2 class="comments__title">
            Comments (<span id="comment-count"><?= (int) $image['comment_count'] ?></span>)
        </h2>

        <?php if (empty($comments)): ?>
            <p id="comments-empty" class="comments__empty">No comments yet.</p>
        <?php else: ?>
            <ul id="comments-list" class="comments__list">
                <?php foreach ($comments as $comment): ?>
                    <?= \App\Core\View::renderPartial('partials/comment.php', ['comment' => $comment]) ?>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($currentUser !== null): ?>
            <form id="comment-form" class="comments__form auth-form" method="post"
                  action="/images/<?= (int) $image['id'] ?>/comments">
                <input type="hidden" name="_csrf_token" value="<?= e($csrfToken) ?>">
                <div class="auth-form__row">
                    <label class="auth-form__label" for="comment-body">Add a comment</label>
                    <textarea class="auth-form__input" id="comment-body" name="body" rows="3"
                              maxlength="1000" required></textarea>
                </div>
                <div class="auth-form__row">
                    <button class="auth-form__submit" type="submit">Post comment</button>
                </div>
            </form>
            <p id="comment-status" class="image-view__status"></p>
        <?php else: ?>
            <p class="comments__login-hint">
                <a href="/login">Log in</a> or <a href="/register">register</a> to like and comment.
            </p>
        <?php endif; ?>
    </section>

    <!-- Versioned include: /assets/ is cached for 1 hour, so every change to
         image.js MUST bump this version (same rule as editor.js). -->
    <script src="/assets/js/image.js?v=1"></script>
</section>
