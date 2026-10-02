<?php
// Image detail page (spec sections 4.3 and 5: GET /images/{id}): the
// picture, its author and date, the like toggle with the count, and the
// comments with their authors. Visible to everyone; visitors get links to
// the login page instead of the like button and the comment form. Markup
// only; every value is escaped with e().
/** @var string $title */
/** @var array<string, mixed> $image The picture row (author username and counts included). */
/** @var bool $liked Has the current user already liked this picture? */
/** @var string $date Formatted creation date of the picture. */
/** @var array<int, array<string, mixed>> $comments Oldest first, each with a formatted date. */
/** @var string $csrfToken */
/** @var mixed $currentUser */
?>
<section class="page page--image">
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
            <form class="image-view__like-form" method="post"
                  action="/images/<?= (int) $image['id'] ?>/like">
                <input type="hidden" name="_csrf_token" value="<?= e($csrfToken) ?>">
                <button class="image-view__like-button<?= $liked ? ' image-view__like-button--on' : '' ?>"
                        type="submit"><?= $liked ? 'Unlike' : 'Like' ?></button>
            </form>
        <?php else: ?>
            <a class="image-view__like-button image-view__like-button--link" href="/login">Like</a>
        <?php endif; ?>
        <span class="image-view__like-count">
            <?= (int) $image['like_count'] ?> like<?= ((int) $image['like_count'] === 1) ? '' : 's' ?>
        </span>
    </div>

    <section class="comments">
        <h2 class="comments__title">
            Comments (<?= (int) $image['comment_count'] ?>)
        </h2>

        <?php if (empty($comments)): ?>
            <p class="comments__empty">No comments yet.</p>
        <?php else: ?>
            <ul class="comments__list">
                <?php foreach ($comments as $comment): ?>
                    <li class="comments__item">
                        <p class="comments__meta">
                            <span class="comments__author"><?= e((string) $comment['username']) ?></span>
                            <span class="comments__date"><?= e((string) $comment['date']) ?></span>
                        </p>
                        <p class="comments__body"><?= e((string) $comment['body']) ?></p>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($currentUser !== null): ?>
            <form class="comments__form auth-form" method="post"
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
        <?php else: ?>
            <p class="comments__login-hint">
                <a href="/login">Log in</a> or <a href="/register">register</a> to like and comment.
            </p>
        <?php endif; ?>
    </section>
</section>
