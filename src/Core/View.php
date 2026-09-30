<?php
// Renders PHP view templates inside a shared layout. Templates contain markup
// only and escape all output with e(). Data is extracted into local variables.

declare(strict_types=1);

namespace App\Core;

class View
{
    /** @var array<string, mixed> Shared data passed to every view (e.g. current user). */
    private static array $shared = [];

    /**
     * Shared data available in every template (set once per request).
     *
     * @param array<string, mixed> $data
     */
    public static function share(array $data): void
    {
        self::$shared = array_merge(self::$shared, $data);
    }

    /**
     * Render a template and return the resulting HTML string.
     *
     * @param string                $template Path relative to src/Views (e.g. "pages/home.php").
     * @param array<string, mixed>  $data    Variables extracted into the template scope.
     */
    public static function render(string $template, array $data = []): string
    {
        return self::renderPartial($template, $data);
    }

    /**
     * Render a page template wrapped in the layout. The page populates
     * $content for the layout via output buffering.
     *
     * @param array<string, mixed> $data
     */
    public static function renderPage(string $template, array $data = []): string
    {
        $data = array_merge(self::$shared, $data);

        // Render the page content first.
        $content = self::renderPartial($template, $data);

        // Flash messages for the layout (escaped on output in the partial).
        $flashes = Session::pullFlashes();

        // Render the layout, exposing $content and $flashes.
        $layoutData = array_merge($data, [
            'content' => $content,
            'flashes' => $flashes,
        ]);

        return self::renderPartial('layout/app.php', $layoutData);
    }

    /**
     * Render a partial template (no layout). Extracts data + shared into scope.
     *
     * @param array<string, mixed> $data
     */
    public static function renderPartial(string $template, array $data = []): string
    {
        $file = __DIR__ . '/../Views/' . $template;
        if (!is_file($file)) {
            throw new \RuntimeException("View not found: $template");
        }

        $data = array_merge(self::$shared, $data);
        // Make shared helpers and data available in the template scope.
        extract($data, EXTR_SKIP);

        ob_start();
        require $file;
        return (string) ob_get_clean();
    }
}
