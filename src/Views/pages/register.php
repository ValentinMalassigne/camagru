<?php
// Registration page (spec section 4.2). Markup only; every value is escaped
// with e(). Field errors come from the server-side Validator.
/** @var string $title */
/** @var array<string, string> $errors */
/** @var array<string, string> $old */
/** @var string $csrfToken */
?>
<section class="page page--auth">
    <h1 class="page__title">Create your account</h1>
    <p class="page__lead">Fill in the form below. We will send you an email to confirm your address.</p>

    <?php if (!empty($errors)): ?>
        <ul class="form-errors">
            <?php foreach ($errors as $message): ?>
                <li class="form-errors__item"><?= e($message) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <form class="auth-form" method="post" action="/register">
        <input type="hidden" name="_csrf_token" value="<?= e($csrfToken) ?>">

        <div class="auth-form__row">
            <label class="auth-form__label" for="username">Username</label>
            <input class="auth-form__input" type="text" id="username" name="username"
                   value="<?= e($old['username'] ?? '') ?>" autocomplete="username"
                   minlength="3" maxlength="20" pattern="[A-Za-z0-9_]+" required>
            <p class="auth-form__hint">3 to 20 characters: letters, numbers and underscores.</p>
        </div>

        <div class="auth-form__row">
            <label class="auth-form__label" for="email">Email</label>
            <input class="auth-form__input" type="email" id="email" name="email"
                   value="<?= e($old['email'] ?? '') ?>" autocomplete="email" required>
        </div>

        <div class="auth-form__row">
            <label class="auth-form__label" for="password">Password</label>
            <input class="auth-form__input" type="password" id="password" name="password"
                   autocomplete="new-password" minlength="8" required>
            <p class="auth-form__hint">At least 8 characters, with a lowercase letter, an uppercase letter and a digit.</p>
        </div>

        <div class="auth-form__row">
            <button class="auth-form__submit" type="submit">Register</button>
        </div>
    </form>

    <p class="page__alt">Already have an account? <a href="/login">Log in</a>.</p>
</section>
