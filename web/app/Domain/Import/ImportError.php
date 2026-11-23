<?php

namespace App\Domain\Import;

use RuntimeException;

/** Errore in un file di importazione. I messaggi sono destinati al cliente (in italiano). */
final class ImportError extends RuntimeException
{
    /** @param list<string> $details */
    public function __construct(string $message, public readonly array $details = [])
    {
        parent::__construct($message);
    }
}
