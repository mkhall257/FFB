<?php
/**
 * Site layout. Expects: string $title, string $content (already-rendered HTML).
 *
 * @var string $title
 * @var string $content
 */
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> · FFB</title>
</head>
<body>
    <main>
<?= $content ?>
    </main>
</body>
</html>
