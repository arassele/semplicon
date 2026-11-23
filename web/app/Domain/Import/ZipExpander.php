<?php

namespace App\Domain\Import;

use ZipArchive;

/**
 * Legge file XML e P7M da uno ZIP, senza estrarre nulla su disco.
 * Protezione: numero di voci, dimensione per voce e totale, lunghezza effettiva anziché quella indicata nella directory,
 * solo nomi di file senza percorso, nessun archivio annidato, nessuna voce cifrata.
 */
final class ZipExpander
{
    /** @return array{files: list<array{name: string, bytes: string}>, messages: list<string>} */
    public static function expand(string $path): array
    {
        $cfg = config('semplicon.import');
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new ImportError('L’archivio ZIP non è leggibile.');
        }
        try {
            if ($zip->numFiles > $cfg['max_zip_entries']) {
                throw new ImportError("L’archivio contiene troppi file ({$zip->numFiles}, massimo {$cfg['max_zip_entries']}).");
            }
            $files = [];
            $messages = [];
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $raw = (string) $stat['name'];
                if (str_ends_with($raw, '/') || str_starts_with($raw, '__MACOSX/') || str_contains($raw, '/._')) {
                    continue;
                }
                $name = basename(str_replace('\\', '/', $raw));
                if ($name === '' || str_starts_with($name, '.')) {
                    continue;
                }
                if (($stat['encryption_method'] ?? 0) !== 0) {
                    $messages[] = "{$name}: file cifrato, ignorato.";

                    continue;
                }
                $lower = strtolower($name);
                if (str_ends_with($lower, '.zip')) {
                    $messages[] = "{$name}: archivi annidati non sono supportati, ignorato.";

                    continue;
                }
                if (! str_ends_with($lower, '.xml') && ! str_ends_with($lower, '.p7m')) {
                    $messages[] = "{$name}: tipo di file non supportato, ignorato.";

                    continue;
                }
                if ($stat['size'] > $cfg['max_xml_bytes']) {
                    $messages[] = "{$name}: file troppo grande, ignorato.";

                    continue;
                }
                // Leggere al massimo la dimensione indicata + 1: così un'indicazione errata nella directory emerge.
                $bytes = $zip->getFromIndex($i, $stat['size'] + 1);
                if ($bytes === false || strlen($bytes) !== $stat['size']) {
                    $messages[] = "{$name}: contenuto non corrispondente alla dimensione dichiarata, ignorato.";

                    continue;
                }
                $total += strlen($bytes);
                if ($total > $cfg['max_zip_uncompressed_bytes']) {
                    throw new ImportError('Il contenuto dell’archivio supera la dimensione massima consentita.');
                }
                $files[] = ['name' => $name, 'bytes' => $bytes];
            }

            return ['files' => $files, 'messages' => $messages];
        } finally {
            $zip->close();
        }
    }
}
