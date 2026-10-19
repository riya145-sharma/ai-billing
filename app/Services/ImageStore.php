<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Ids;
use App\Core\Settings;
use App\Storage\FileStore;
use App\Storage\StorageException;

/**
 * Generated image files: storage/images/YYYY/MM/DD/<img id>.<ext>
 * (outside the web root; served only through an authorised download route).
 * Every image is validated by content, not by name: real MIME sniffing,
 * decodable dimensions, allowed type, size cap.
 */
final class ImageStore
{
    private const EXT = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

    private string $root;

    public function __construct(
        private FileStore $fs,
        ?string $root = null,
        private ?int $maxBytes = null,
        private ?array $allowedMime = null,
    ) {
        $this->root = rtrim(str_replace('\\', '/', $root ?? (string)Config::get('paths.images')), '/');
        $this->fs->ensureDir($this->root);
    }

    /** Decode a base64 payload from Azure (b64_json) strictly. */
    public static function decodeBase64(string $b64): string
    {
        $bin = base64_decode(preg_replace('/\s+/', '', $b64) ?? '', true);
        if ($bin === false || $bin === '') {
            throw new StorageException('Image payload is not valid base64');
        }
        return $bin;
    }

    /**
     * Validate and save an image for a generation id.
     * @return array{storage_path: string, mime_type: string, file_size_bytes: int, sha256: string, width: int, height: int}
     */
    public function save(string $generationId, string $bytes): array
    {
        $date = Ids::dateOf($generationId, 'img');
        $max = $this->maxBytes ?? Settings::int('image.max_file_bytes', 10 * 1024 * 1024);
        $allowed = $this->allowedMime ?? (array)Settings::get('image.allowed_mime', array_keys(self::EXT));

        $size = strlen($bytes);
        if ($size === 0) {
            throw new StorageException('Image is empty');
        }
        if ($size > $max) {
            throw new StorageException('Image exceeds the maximum size of ' . round($max / 1048576, 1) . ' MB');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: '';
        if (!isset(self::EXT[$mime]) || !in_array($mime, $allowed, true)) {
            throw new StorageException("Image type not allowed: {$mime}");
        }
        $info = @getimagesizefromstring($bytes);
        if ($info === false || ($info['mime'] ?? '') !== $mime || $info[0] < 1 || $info[1] < 1 || $info[0] > 8192 || $info[1] > 8192) {
            throw new StorageException('Image data is not a valid ' . $mime);
        }

        $rel = str_replace('-', '/', $date) . '/' . $generationId . '.' . self::EXT[$mime];
        $this->fs->atomicPut($this->root . '/' . $rel, $bytes);

        return [
            'storage_path'    => $rel,
            'mime_type'       => $mime,
            'file_size_bytes' => $size,
            'sha256'          => hash('sha256', $bytes),
            'width'           => (int)$info[0],
            'height'          => (int)$info[1],
        ];
    }

    /** Absolute path for a stored image, refusing anything outside storage/images. */
    public function resolve(string $rel): string
    {
        if (!preg_match('#^\d{4}/\d{2}/\d{2}/img_\d{8}_[0-9a-f]{16}\.(png|jpg|webp)$#', $rel)) {
            throw new StorageException('Invalid image path');
        }
        $root = realpath($this->root);
        $path = realpath($this->root . '/' . $rel);
        if ($root === false || $path === false
            || !str_starts_with(str_replace('\\', '/', $path), str_replace('\\', '/', $root) . '/')) {
            throw new StorageException('Image not found');
        }
        return $path;
    }
}
