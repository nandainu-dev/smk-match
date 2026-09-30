<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class QuestionImageStorage
{
    private const MAX_BYTES = 5 * 1024 * 1024;
    private const MAX_DIMENSION = 4096;

    /** @var array<string, string> */
    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(private readonly string $publicDirectory)
    {
        if (trim($this->publicDirectory) === '') {
            throw new \InvalidArgumentException('Public directory is required.');
        }
    }

    public function store(string $temporaryPath): string
    {
        if (!is_file($temporaryPath) || !is_readable($temporaryPath)) {
            throw new \InvalidArgumentException('Question image upload is not readable.');
        }

        $size = filesize($temporaryPath);
        if ($size === false || $size < 1 || $size > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Question image exceeds the allowed size.');
        }

        $image = @getimagesize($temporaryPath);
        if (!is_array($image)
            || !isset($image[0], $image[1], $image['mime'])
            || !is_int($image[0])
            || !is_int($image[1])
            || !is_string($image['mime'])
            || $image[0] < 1
            || $image[1] < 1
            || $image[0] > self::MAX_DIMENSION
            || $image[1] > self::MAX_DIMENSION
            || !isset(self::MIME_EXTENSIONS[$image['mime']])) {
            throw new \InvalidArgumentException('Question image type or dimensions are invalid.');
        }

        $directory = $this->questionDirectory();
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Question image directory could not be created.');
        }

        $extension = self::MIME_EXTENSIONS[$image['mime']];
        do {
            $filename = bin2hex(random_bytes(32)) . '.' . $extension;
            $destination = $directory . DIRECTORY_SEPARATOR . $filename;
        } while (file_exists($destination));

        if (!@rename($temporaryPath, $destination)) {
            if (!@copy($temporaryPath, $destination)) {
                throw new RuntimeException('Question image could not be stored.');
            }

            @unlink($temporaryPath);
        }

        return '/uploads/questions/' . $filename;
    }

    public function discardNewlyStored(string $publicPath): void
    {
        if (preg_match('#\A/uploads/questions/[a-f0-9]{64}\.(?:jpg|png|webp)\z#D', $publicPath) !== 1) {
            return;
        }

        $path = $this->questionDirectory() . DIRECTORY_SEPARATOR . basename($publicPath);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function questionDirectory(): string
    {
        return rtrim($this->publicDirectory, "\\/")
            . DIRECTORY_SEPARATOR . 'uploads'
            . DIRECTORY_SEPARATOR . 'questions';
    }
}
