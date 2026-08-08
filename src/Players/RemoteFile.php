<?php

declare(strict_types=1);

namespace FFB\Players;

/**
 * The thin network boundary for player-sync downloads. Kept as its own tiny
 * unit so the import logic can be tested against fixtures without ever
 * touching the network.
 */
final class RemoteFile
{
    public static function get(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_FAILONERROR => true,
            CURLOPT_USERAGENT => 'FFB/1.0',
        ]);

        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("Request failed for {$url}: {$error}");
        }

        curl_close($ch);

        return (string) $body;
    }
}
