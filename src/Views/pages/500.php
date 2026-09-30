<?php
// Minimal standalone 500 page. Rendered without the layout so a failure in the
// view system itself can never recurse. No internal detail is exposed.
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Server error</title>
</head>
<body>
    <h1>Something went wrong</h1>
    <p>Please try again later.</p>
    <p><a href="/">Back to the home page</a></p>
</body>
</html>
