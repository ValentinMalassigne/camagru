<?php
// Overlays whitelist: maps a stable id to a PNG filename in public/assets/overlays/.
// The client sends only the id; the server resolves it here (never accepts paths,
// which prevents path traversal). A missing or unknown id is rejected.

declare(strict_types=1);

return [
    1 => 'cat.png',
    2 => 'sun.png',
    3 => 'tree.png',
    4 => 'volcano.png',
];
