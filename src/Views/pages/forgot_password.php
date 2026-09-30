<?php
// Forgot-password page. The response to the POST never reveals whether the
// email belongs to an account, so the page copy matches that behaviour.
/** @var string $title */
/** @var array<string, string> $errors */
/** @var string $csrfToken */
?>
<section class="page page--auth">
    <h1 class="page__title">Forgot your password?</h1>
    <p class="page__lead">
        Enter your account's email address. If it exists, we will send you a
        link to choose a new password. The link is valid for one hour and can
        only be used once.
    </p>

    <?php if (!empty($errors)): ?>
        <ul class="form-errors">
            <?php foreach ($errors as $message): ?>
                <li class="form-errors__item"><?= e($message) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <form class="auth-form" method="post" action="/forgot-password">
        <input type="hidden" name="_csrf_token" value="<?= e($csrfToken) ?>">

        <div class="auth-form__row">
            <label class="auth-form__label" for="email">Email</label>
            <input class="auth-form__input" type="email" id="email" name="email"
                   autocomplete="email" required>
        </div>

        <div class="auth-form__row">
            <button class="auth-form__submit" type="submit">Send reset link</button>
        </div>
    </form>

    <p class="page__alt">Remembered it? <a href="/login">Log in</a>.</p>
</section>
