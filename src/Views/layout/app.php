<?php
/** @var string $content Page HTML. */
/** @var array<int, array{type: string, message: string}> $flashes */
/** @var string $title */
/** @var mixed $currentUser */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'Camagru') ?></title>
    <?php if (!empty($headMeta)) { echo $headMeta; } ?>
    <link rel="icon" type="image/png" href="/favicon.png">
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
    <header class="site-header">
        <div class="site-header__inner">
            <a class="site-header__brand" href="/">Camagru</a>
            <nav class="site-nav">
                <?php if ($currentUser !== null): ?>
                    <a class="site-nav__link" href="/editor">Editor</a>
                    <a class="site-nav__link" href="/account">Account</a>
                    <form class="site-nav__form" method="post" action="/logout">
                        <input type="hidden" name="_csrf_token" value="<?= e($csrfToken ?? '') ?>">
                        <button class="site-nav__link site-nav__link--button" type="submit">Logout</button>
                    </form>
                <?php else: ?>
                    <a class="site-nav__link" href="/login">Login</a>
                    <a class="site-nav__link site-nav__link--button" href="/register">Register</a>
                <?php endif; ?>
            </nav>
        </div>
    </header>

    <main class="site-main">
        <?php if (!empty($flashes)): ?>
            <div class="flash-list">
                <?php foreach ($flashes as $flash): ?>
                    <div class="flash flash--<?= e_attr($flash['type']) ?>">
                        <?= e($flash['message']) ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?= $content ?>
    </main>

    <footer class="site-footer">
        <p>Camagru &mdash; &eacute;cole 42 project</p>
    </footer>
</body>
</html>
