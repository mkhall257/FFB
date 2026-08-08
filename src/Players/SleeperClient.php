<?php

declare(strict_types=1);

namespace FFB\Players;

/**
 * Fetches the Sleeper players feed — the canonical Player universe. Returns the
 * decoded map of Sleeper player_id => attributes.
 */
final class SleeperClient
{
    public function __construct(
        private readonly string $url = 'https://api.sleeper.app/v1/players/nfl',
    ) {
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function fetchPlayers(): array
    {
        $data = json_decode(RemoteFile::get($this->url), true);
        if (!is_array($data)) {
            throw new \RuntimeException('Unexpected Sleeper players response.');
        }

        /** @var array<string,array<string,mixed>> $data */
        return $data;
    }
}
