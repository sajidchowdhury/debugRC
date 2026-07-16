<?php
// core/QrRenderer.php — self-hosted QR images (no external API).

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

class QrRenderer
{
    public static function pngDataUri(string $data, int $size = 200): string
    {
        $binary = self::pngBinary($data, $size);

        return 'data:image/png;base64,' . base64_encode($binary);
    }

    public static function pngBinary(string $data, int $size = 200): string
    {
        self::boot();

        $options = new QROptions([
            'outputType'   => QRCode::OUTPUT_IMAGE_PNG,
            'scale'        => max(4, (int)round($size / 25)),
            'imageBase64'  => false,
            'addQuietzone' => true,
        ]);

        return (new QRCode($options))->render($data);
    }

    public static function emitPng(string $data, int $size = 200): void
    {
        header('Content-Type: image/png');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        echo self::pngBinary($data, $size);
        exit;
    }

    private static function boot(): void
    {
        static $loaded = false;
        if ($loaded) {
            return;
        }

        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        if (!is_readable($autoload)) {
            throw new RuntimeException('Composer autoload not found. Run composer install.');
        }

        require_once $autoload;
        $loaded = true;
    }
}
