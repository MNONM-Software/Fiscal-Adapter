<?php

namespace Mnonm\FiscalAdapter\Tests\Support;

use Carbon\CarbonImmutable;
use Mnonm\FiscalAdapter\Arca\Wsaa\AccessTicket;
use Mnonm\FiscalAdapter\Arca\Wsaa\TicketKey;
use Mnonm\FiscalAdapter\Arca\Wsaa\TicketStore;

/**
 * Durable store for the homologation suite: keeps the 12 h ticket between runs on the same machine.
 * The file holds token and sign, so it is created with mode 0600.
 */
final class FileTicketStore implements TicketStore
{
    public function get(TicketKey $key): ?AccessTicket
    {
        $path = $this->path($key);

        if (! is_file($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);

        if (! is_array($data) || ! isset($data['token'], $data['sign'], $data['expires_at'])) {
            return null;
        }

        return new AccessTicket((string) $data['token'], (string) $data['sign'], CarbonImmutable::parse((string) $data['expires_at']));
    }

    public function put(TicketKey $key, AccessTicket $ticket): void
    {
        $path = $this->path($key);
        $temporary = $path.'.'.bin2hex(random_bytes(4)).'.tmp';

        touch($temporary);
        chmod($temporary, 0600);
        file_put_contents($temporary, json_encode([
            'token' => $ticket->token,
            'sign' => $ticket->sign,
            'expires_at' => $ticket->expiresAt->toIso8601String(),
        ], JSON_THROW_ON_ERROR));
        rename($temporary, $path);
    }

    public function forget(TicketKey $key): void
    {
        @unlink($this->path($key));
    }

    private function path(TicketKey $key): string
    {
        return sys_get_temp_dir().'/fiscal-adapter-homo-ticket-'.sha1($key->toString()).'.json';
    }
}
