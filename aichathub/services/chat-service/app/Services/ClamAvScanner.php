<?php

namespace App\Services;

class ClamAvScanner
{
    /**
     * Streams $bytes to clamd over its INSTREAM protocol and returns 'clean',
     * 'infected', or 'error' (daemon unreachable / timed out / bad response —
     * callers should fail closed on 'error', not treat it as clean).
     */
    public function scan(string $bytes): string
    {
        $host = config('services.clamav.host');
        $port = (int) config('services.clamav.port');

        $socket = @fsockopen($host, $port, $errno, $errstr, 5);
        if ($socket === false) {
            return 'error';
        }

        try {
            stream_set_timeout($socket, 15);
            fwrite($socket, "zINSTREAM\0");

            // clamd's INSTREAM protocol: each chunk is a 4-byte big-endian length
            // prefix followed by the chunk bytes; a zero-length chunk terminates
            // the stream and triggers the scan.
            foreach (str_split($bytes, 262144) ?: [''] as $chunk) {
                fwrite($socket, pack('N', strlen($chunk)).$chunk);
            }
            fwrite($socket, pack('N', 0));

            $response = '';
            while (! feof($socket)) {
                $chunk = fread($socket, 4096);
                if ($chunk === false) {
                    break;
                }
                $response .= $chunk;

                $meta = stream_get_meta_data($socket);
                if ($meta['timed_out'] || str_contains($response, "\0") || str_contains($response, "\n")) {
                    break;
                }
            }

            if (str_contains($response, 'FOUND')) {
                return 'infected';
            }
            if (str_contains($response, 'OK')) {
                return 'clean';
            }

            return 'error';
        } catch (\Throwable) {
            return 'error';
        } finally {
            fclose($socket);
        }
    }
}
