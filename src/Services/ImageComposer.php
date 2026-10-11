<?php
// ImageComposer: the single server-side image pipeline (spec section 8).
// Both picture sources — the webcam capture and the plain upload form —
// arrive as an HTTP file upload, so they share this code path: validate the
// upload, detect the real type with finfo and getimagesize, decode with GD,
// re-encode through a fresh truecolor canvas (which also strips any metadata
// or payload embedded in the source), composite the whitelisted stickers with
// their alpha channels, and save under a random filename in the uploads volume.
//
// Security notes (why each check exists):
// - is_uploaded_file() refuses anything that did not really come from this
//   request, so a local path can never be read through a forged $_FILES.
// - The type is detected from the content (finfo) and cross-checked with
//   getimagesize: PHP code renamed to ".png" is rejected before GD decodes.
// - The dimension cap runs BEFORE any GD decode: a tiny-but-huge PNG
//   ("decompression bomb") would otherwise exhaust the memory limit.
// - Stickers are chosen by id only and resolved through
//   config/overlays.php; a filename or path never comes from the client, so
//   path traversal is impossible. An unknown or missing id is rejected.
// - The stored filename is random (bin2hex(random_bytes(16))), so nothing
//   user-controlled ever reaches the filesystem path.

declare(strict_types=1);

namespace App\Services;

use GdImage;

final class ImageComposer
{
    /** Maximum accepted upload size: 10 MB (spec section 8). */
    public const MAX_BYTES = 10 * 1024 * 1024;

    /** Maximum accepted width and height, in pixels. */
    public const MAX_EDGE = 5000;

    /**
     * Validate the upload, composite the stickers and store the result.
     * Between 0 stickers (a plain re-encoded photo) and all stickers may be
     * applied; every id must exist in config/overlays.php.
     *
     * @param array<string, mixed> $file       One entry of $_FILES (an uploaded
     *                                         file: webcam blob or form upload).
     * @param array<int, mixed>    $overlayIds Sticker ids as sent by the client
     *                                         (strings or ints, 0 to N items).
     * @return string The generated filename, without any path ("ab12...png").
     * @throws ImageException When the upload or a sticker id is rejected.
     */
    public static function compose(array $file, array $overlayIds): string
    {
        $bytes = self::readUpload($file);
        $base = self::decode($bytes);
        // The raw bytes are no longer needed; free them before compositing.
        unset($bytes);

        // Duplicates are collapsed and the ids sorted, so a given set of
        // stickers always produces the same layout whatever the submission
        // order was (plain form checkboxes or the webcam fetch).
        $overlays = [];
        foreach (self::uniqueIds($overlayIds) as $id) {
            $overlays[] = self::loadOverlay($id);
        }

        $result = self::composite($base, $overlays);
        imagedestroy($base);
        foreach ($overlays as $overlay) {
            imagedestroy($overlay);
        }

        return self::save($result);
    }

    /**
     * Normalize the client's sticker ids to a sorted list of unique ints.
     * Duplicates (possible only in a crafted request) are collapsed rather
     * than rejected: drawn twice, a sticker would cover itself exactly.
     * Unknown ids are not filtered here; loadOverlay rejects them against
     * the whitelist.
     *
     * @param array<int, mixed> $overlayIds
     * @return array<int, int> Sorted, unique, integer ids.
     */
    private static function uniqueIds(array $overlayIds): array
    {
        $ids = [];
        foreach ($overlayIds as $id) {
            $ids[] = (int) $id;
        }
        $ids = array_values(array_unique($ids));
        sort($ids);
        return $ids;
    }

    /**
     * Check that the entry is a genuine, non-empty upload within the size
     * limit, and return its raw bytes. The size is the one measured by PHP,
     * never a value sent by the client.
     *
     * @param array<string, mixed> $file
     * @throws ImageException
     */
    private static function readUpload(array $file): string
    {
        if (!isset($file['tmp_name']) || !is_string($file['tmp_name']) || !isset($file['error'])) {
            throw new ImageException('No picture was received.');
        }

        $error = (int) $file['error'];
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new ImageException('The picture is too large (10 MB maximum).');
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new ImageException('The upload failed. Please try again.');
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            throw new ImageException('Invalid upload.');
        }
        if (!isset($file['size']) || (int) $file['size'] < 1 || (int) $file['size'] > self::MAX_BYTES) {
            throw new ImageException('The picture is too large (10 MB maximum).');
        }

        $bytes = @file_get_contents($file['tmp_name']);
        if ($bytes === false || $bytes === '') {
            throw new ImageException('No picture was received.');
        }
        return $bytes;
    }

    /**
     * Detect the real image type from the content and decode it with GD.
     * Only PNG and JPEG are accepted (spec section 8).
     *
     * @return GdImage The decoded source image.
     * @throws ImageException
     */
    private static function decode(string $bytes): GdImage
    {
        // finfo reads the content, so a lying extension or name is ignored.
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($bytes);
        if ($mime !== 'image/png' && $mime !== 'image/jpeg') {
            throw new ImageException('Only PNG and JPEG pictures are accepted.');
        }

        // Second, type-aware look at the content; also the dimension cap,
        // applied before any GD decode (see class notes).
        // @: an unreadable input must not raise a PHP warning, only our error.
        $info = @getimagesizefromstring($bytes);
        if ($info === false || !isset($info[0], $info[1], $info[2])) {
            throw new ImageException('The picture could not be read.');
        }
        if ($info[2] !== IMAGETYPE_PNG && $info[2] !== IMAGETYPE_JPEG) {
            throw new ImageException('Only PNG and JPEG pictures are accepted.');
        }
        if ($info[0] < 1 || $info[1] < 1 || $info[0] > self::MAX_EDGE || $info[1] > self::MAX_EDGE) {
            throw new ImageException('The picture must be at most ' . self::MAX_EDGE . ' x ' . self::MAX_EDGE . ' pixels.');
        }

        // @: GD warns on data it cannot decode; the false return is our error.
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw new ImageException('The picture could not be read.');
        }
        return $image;
    }

    /**
     * Load one sticker by its id. The whitelist (config/overlays.php) is
     * the only way from an id to a filename, so no client input can ever
     * reach a filesystem path.
     *
     * @return GdImage The decoded sticker, with its alpha channel.
     * @throws ImageException
     */
    private static function loadOverlay(int $overlayId): GdImage
    {
        $overlays = require APP_ROOT . '/config/overlays.php';
        if (!isset($overlays[$overlayId])) {
            throw new ImageException('Please choose a valid sticker.');
        }

        $path = APP_ROOT . '/public/assets/overlays/' . $overlays[$overlayId];
        if (!is_file($path)) {
            throw new ImageException('The sticker could not be loaded.');
        }

        // @: a corrupt sticker file is a server-side problem; the caller only
        // needs the rejection.
        $overlay = @imagecreatefrompng($path);
        if ($overlay === false) {
            throw new ImageException('The sticker could not be loaded.');
        }
        return $overlay;
    }

    /**
     * Re-encode the source onto a fresh truecolor canvas with an alpha
     * channel, then draw the stickers on top. Each sticker is scaled to fit
     * inside the picture and centered on it; the stickers are drawn in sorted
     * id order, so a higher id sits above a lower one — the artwork itself
     * decides how a stack of stickers looks. editor.js draws the same
     * geometry for the live preview.
     *
     * @param GdImage        $base     The decoded source picture.
     * @param array<GdImage> $overlays The decoded sticker PNGs, in draw order.
     * @return GdImage The composited image (not yet saved).
     */
    private static function composite(GdImage $base, array $overlays): GdImage
    {
        $baseW = imagesx($base);
        $baseH = imagesy($base);

        // Fresh canvas: this is the re-encoding step. GD copies no comments
        // or EXIF from the source, so any embedded payload disappears here.
        $out = imagecreatetruecolor($baseW, $baseH);
        imagealphablending($out, false); // plain overwrite while filling
        imagesavealpha($out, true);      // keep the alpha channel in the output
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));

        // Draw the source at 1:1 (it is already within the dimension cap).
        // resampled (not copied) also converts a palette source to truecolor.
        imagecopyresampled($out, $base, 0, 0, 0, 0, $baseW, $baseH, $baseW, $baseH);

        // Blending ON for the stickers: their transparent pixels must let
        // the photo show through instead of overwriting it.
        imagealphablending($out, true);
        foreach ($overlays as $overlay) {
            // Scale each sticker to fit inside the picture (aspect kept)
            // and center it; later stickers are drawn on top.
            $ovW = imagesx($overlay);
            $ovH = imagesy($overlay);
            $scale = min($baseW / $ovW, $baseH / $ovH);
            $newW = max(1, (int) round($ovW * $scale));
            $newH = max(1, (int) round($ovH * $scale));
            $dstX = (int) floor(($baseW - $newW) / 2);
            $dstY = (int) floor(($baseH - $newH) / 2);

            imagecopyresampled($out, $overlay, $dstX, $dstY, 0, 0, $newW, $newH, $ovW, $ovH);
        }

        return $out;
    }

    /**
     * Write the image to the uploads directory under a random filename and
     * free the GD resource.
     *
     * @return string The stored filename, without any path.
     * @throws ImageException
     */
    private static function save(GdImage $image): string
    {
        $filename = bin2hex(random_bytes(16)) . '.png';

        // The uploads volume exists in the stack; the mkdir only covers a
        // missing directory (e.g. a first local run outside docker).
        if (!is_dir(APP_UPLOAD_DIR)) {
            @mkdir(APP_UPLOAD_DIR, 0775, true);
        }

        // @: a failed write must be our error, not a PHP warning.
        if (!@imagepng($image, APP_UPLOAD_DIR . '/' . $filename)) {
            imagedestroy($image);
            throw new ImageException('The picture could not be saved.');
        }
        imagedestroy($image);
        return $filename;
    }
}
