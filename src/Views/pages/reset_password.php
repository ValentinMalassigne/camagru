<?php
// Reset-password page (reached from the emailed single-use link). The token
// travels in a hidden field, escaped with e_attr().
/** @var string $title */
/** @var array<string, string> $errors */
/** @var string $token */
/** @var string $csrfToken */
?>
<section class="page page--auth">
    <h1 class="page__title">Choose a new password</h1>
    <p class="page__lead">Your new password must follow the same rules as at registration.</p>

    <?php if (!empty($errors)): ?>
        <ul class="form-errors">
            <?php foreach ($errors as $message): ?>
                <li class="form-errors__item"><?= e($message) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <form class="auth-form" method="post" action="/reset-password">
        <input type="hidden" name="_csrf_token" value="<?= e($csrfToken) ?>">
        <input type="hidden" name="token" value="<?= e_attr($token) ?>">

        <div class="auth-form__row">
            <label class="auth-form__label" for="password">New password</label>
            <input class="auth-form__input" type="password" id="password" name="password"
                   autocomplete="new-password" minlength="8" required>
            <p class="auth-form__hint">At least 8 characters, with a lowercase letter, an uppercase letter and a digit.</p>
        </div>

        <div class="auth-form__row">
            <button class="auth-form__submit" type="submit">Reset my password</button>
        </div>
    </form>
</section>
