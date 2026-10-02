<?php
// One gallery page worth of cards (the <li> elements of the grid). Shared by
// pages/gallery.php (full page) and the infinite-scroll JSON response
// (GalleryController::index): both render the same markup, so the AJAX-inserted
// cards are byte-identical to the server-rendered ones. Escaping happens in
// partials/gallery_item.php.
/** @var array<int, array<string, mixed>> $images The current page's pictures. */
?>
<?php foreach ($images as $image): ?>
    <?= \App\Core\View::renderPartial('partials/gallery_item.php', ['image' => $image]) ?>
<?php endforeach; ?>
