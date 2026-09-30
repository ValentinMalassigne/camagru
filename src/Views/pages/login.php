<?php
// Login page (spec section 4.2). Markup only; values escaped with e().
// A failed attempt shows one generic message for a wrong username and a
// wrong password alike.
/** @var string $title */
/** @var array<string, string> $errors */
/** @var string $csrfToken */
?>
<section class="page page--auth">
    <h1 class="page__title">Log in</h1>

    <?php if (!empty($errors)): ?>
        <ul class="form-errors">
            <?php foreach ($errors as $message): ?>
                <li class="form-errors__item"><?= e($message) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <form class="auth-form" method="post" action="/login">
        <input type="hidden" name="_csrf_token" value="<?= e($csrfToken) ?>">

        <div class="auth-form__row">
            <label class="auth-form__label" for="username">Username</label>
            <input class="auth-form__input" type="text" id="username" name="username"
                   autocomplete="username" required>
        </div>

        <div class="auth-form__row">
            <label class="auth-form__label" for="password">Password</label>
            <input class="auth-form__input" type="password" id="password" name="password"
                   autocomplete="current-password" required>
        </div>

        <div class="auth-form__row">
            <button class="auth-form__submit" type="submit">Log in</button>
        </div>
    </form>

    <p class="page__alt">No account yet? <a href="/register">Register</a>.</p>
    <p class="page__alt"><a href="/forgot-password">Forgot your password?</a></p>
</section>
