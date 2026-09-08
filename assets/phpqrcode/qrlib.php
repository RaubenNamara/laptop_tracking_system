<?php
/**
 * qrlib.php — Drop-in stub for the phpqrcode library.
 *
 * The original phpqrcode library is not bundled. This stub provides the same
 * QRcode::png() interface by downloading a QR image from the free
 * api.qrserver.com service and saving it locally, so the rest of the
 * application works without modification.
 *
 * api.qrserver.com is free, requires no API key, and works on any PHP
 * installation with allow_url_fopen enabled (XAMPP default) or cURL.
 */

if (!class_exists('QRcode')) {

    class QRcode {

        /**
         * Generate a QR code PNG and save it to $outfile.
         *
         * @param string      $data     The content to encode in the QR code.
         * @param string|bool $outfile  File path to save the PNG, or false to output to browser.
         * @param int         $level    Error correction level constant (ignored — API uses M).
         * @param int         $size     Module size hint (1-10). Multiplied to get pixel size.
         * @param int         $margin   Quiet-zone modules (passed to API as margin).
         * @return void
         */
        public static function png(
            string $data,
            $outfile   = false,
            int $level  = 0,
            int $size   = 4,
            int $margin = 2
        ): void {
            // Map $size to pixel dimension (each module is ~$size*10 px, capped sensibly)
            $px = max(100, min(600, $size * 40));

            $url = 'https://api.qrserver.com/v1/create-qr-code/?'
                 . http_build_query([
                     'data'   => $data,
                     'size'   => $px . 'x' . $px,
                     'ecc'    => 'M',
                     'margin' => $margin,
                     'format' => 'png',
                 ]);

            $imgData = self::fetch($url);

            if ($imgData === false || strlen($imgData) < 100) {
                // Fallback: create a tiny placeholder PNG so the app doesn't crash
                $imgData = self::placeholderPng($px);
            }

            if ($outfile === false) {
                // Output directly to browser
                header('Content-Type: image/png');
                echo $imgData;
            } else {
                // Ensure directory exists
                $dir = dirname($outfile);
                if (!is_dir($dir)) {
                    mkdir($dir, 0777, true);
                }
                file_put_contents($outfile, $imgData);
            }
        }

        // ── Private helpers ──────────────────────────────────────────────────

        private static function fetch(string $url): string|false {
            // Try cURL first (more reliable on Windows/XAMPP)
            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_TIMEOUT        => 10,
                    CURLOPT_SSL_VERIFYPEER => false,   // tolerate self-signed certs on localhost
                    CURLOPT_USERAGENT      => 'LaptopTrackingSystem/1.0',
                ]);
                $body = curl_exec($ch);
                $err  = curl_errno($ch);
                curl_close($ch);
                if ($err === 0 && $body !== false) return $body;
            }

            // Fallback: file_get_contents with stream context
            $ctx = stream_context_create([
                'http' => [
                    'timeout'        => 10,
                    'ignore_errors'  => false,
                    'user_agent'     => 'LaptopTrackingSystem/1.0',
                ],
                'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
            ]);

            $body = @file_get_contents($url, false, $ctx);
            return $body;
        }

        /**
         * Return a minimal valid 1×1 transparent PNG as a placeholder.
         * Used when the QR API is unreachable (e.g. no internet on server).
         */
        private static function placeholderPng(int $size = 200): string {
            if (function_exists('imagecreatetruecolor')) {
                $im  = imagecreatetruecolor($size, $size);
                $bg  = imagecolorallocate($im, 220, 220, 220);
                $fg  = imagecolorallocate($im, 80,  80,  80);
                imagefill($im, 0, 0, $bg);
                $lbl = 'QR N/A';
                imagestring($im, 3, (int)(($size - 42) / 2), (int)(($size - 14) / 2), $lbl, $fg);
                ob_start();
                imagepng($im);
                $data = ob_get_clean();
                imagedestroy($im);
                return $data;
            }
            // Minimal 1×1 white PNG (hard-coded bytes) as last resort
            return base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwADhQGAWjR9awAAAABJRU5ErkJggg=='
            );
        }
    }
}

// Error-correction level constants expected by some callers
if (!defined('QR_ECLEVEL_L')) define('QR_ECLEVEL_L', 0);
if (!defined('QR_ECLEVEL_M')) define('QR_ECLEVEL_M', 1);
if (!defined('QR_ECLEVEL_Q')) define('QR_ECLEVEL_Q', 2);
if (!defined('QR_ECLEVEL_H')) define('QR_ECLEVEL_H', 3);
