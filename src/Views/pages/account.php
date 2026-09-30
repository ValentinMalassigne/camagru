<?php
// Account page (spec section 4.2): three small forms — profile (username,
// email), password (current + new) and notification preferences. Markup only;
// every value is escaped with e().
/** @var string $title */
/** @var array<string, mixed>|null $user */
/** @var array<string, string> $errors */
/** @var array<string, string> $old */
/** @var string $csrfToken */
?>
<section class="page page--account">
    <h1 class="page__title">My account</h1>

    <?php if (!empty($errors)): ?>
        <ul class="form-errors">
            <?php foreach ($errors as $message): ?>
                <li class="form-errors__item"><?= e($message) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <section class="account-section">
        <h2 class="account-section__title">Profile</h2>
        <form class="auth-form" method="post" action="/account">
            <input type="hidden" name="_csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="profile">

            <div class="auth-form__row">
                <label class="auth-form__label" for="username">Username</label>
                <input class="auth-form__input" type="text" id="username" name="username"
                       value="<?= e($old['username'] ?? ($user['username'] ?? '')) ?>"
                       autocomplete="username" minlength="3" maxlength="20" pattern="[A-Za-z0-9_]+" required>
                <p class="auth-form__hint">3 to 20 characters: letters, numbers and underscores.</p>
            </div>

            <div class="auth-form__row">
                <label class="auth-form__label" for="email">Email</label>
                <input class="auth-form__input" type="email" id="email" name="email"
                       value="<?= e($old['email'] ?? ($user['email'] ?? '')) ?>"
                       autocomplete="email" required>
                <p class="auth-form__hint">Changing the email takes effect immediately, with no re-verification.</p>
            </div>

            <div class="auth-form__row">
                <button class="auth-form__submit" type="submit">Save profile</button>
            </div>
        </form>
    </section>

    <section class="account-section">
        <h2 class="account-section__title">Password</h2>
        <form class="auth-form" method="post" action="/account">
            <input type="hidden" name="_csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="password">

            <div class="auth-form__row">
                <label class="auth-form__label" for="current_password">Current password</label>
                <input class="auth-form__input" type="password" id="current_password"
                       name="current_password" autocomplete="current-password" required>
            </div>

            <div class="auth-form__row">
                <label class="auth-form__label" for="new_password">New password</label>
                <input class="auth-form__input" type="password" id="new_password"
                       name="new_password" autocomplete="new-password" minlength="8" required>
                <p class="auth-form__hint">At least 8 characters, with a lowercase letter, an uppercase letter and a digit.</p>
            </div>

            <div class="auth-form__row">
                <button class="auth-form__submit" type="submit">Change password</button>
            </div>
        </form>
    </section>

    <section class="account-section">
        <h2 class="account-section__title">Notifications</h2>
        <form class="auth-form" method="post" action="/account">
            <input type="hidden" name="_csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="notifications">

            <div class="auth-form__row auth-form__row--check">
                <input type="checkbox" id="notify_on_comment" name="notify_on_comment" value="1"
                    <?= !empty($user['notify_on_comment']) ? 'checked' : '' ?>>
                <label class="auth-form__label auth-form__label--inline" for="notify_on_comment">
                    Notify me on new comments
                </label>
            </div>

            <div class="auth-form__row auth-form__row--check">
                <input type="checkbox" id="notify_on_own_comment" name="notify_on_own_comment" value="1"
                    <?= !empty($user['notify_on_own_comment']) ? 'checked' : '' ?>>
                <label class="auth-form__label auth-form__label--inline" for="notify_on_own_comment">
                    Also notify me when I comment on my own images
                </label>
            </div>
            <p class="auth-form__hint">The second option has no effect while the first one is off.</p>

            <div class="auth-form__row">
                <button class="auth-form__submit" type="submit">Save notifications</button>
            </div>
        </form>
    </section>

    <section class="account-section account-section--danger">
        <h2 class="account-section__title">Delete account</h2>
        <p class="auth-form__hint">
            Deleting your account is immediate and permanent: your images,
            likes and comments are removed too. Enter your password to confirm.
        </p>
        <form class="auth-form" method="post" action="/account">
            <input type="hidden" name="_csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="delete">

            <div class="auth-form__row">
                <label class="auth-form__label" for="delete_password">Password</label>
                <input class="auth-form__input" type="password" id="delete_password"
                       name="delete_password" autocomplete="current-password" required>
            </div>

            <div class="auth-form__row">
                <button class="auth-form__submit auth-form__submit--danger" type="submit">Delete my account</button>
            </div>
        </form>
    </section>
</section>
