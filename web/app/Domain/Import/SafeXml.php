<?php

namespace App\Domain\Import;

use DOMDocument;

/**
 * Leggere XML in modo sicuro: nessuna dichiarazione DOCTYPE, nessuna entità esterna, nessuna rete.
 * libxml carica risorse esterne solo dalla cartella locale degli schemi (per la verifica XSD).
 */
final class SafeXml
{
    public static function parse(string $xml): DOMDocument
    {
        if (strlen($xml) > config('semplicon.import.max_xml_bytes')) {
            throw new ImportError('Il file XML supera la dimensione massima consentita.');
        }
        // Rimuovere il BOM UTF-8.
        if (str_starts_with($xml, "\xEF\xBB\xBF")) {
            $xml = substr($xml, 3);
        }
        if (preg_match('/<!DOCTYPE/i', $xml)) {
            throw new ImportError('Il file contiene una dichiarazione DOCTYPE: non è accettato per motivi di sicurezza.');
        }

        return self::withRestrictedLoader(function () use ($xml) {
            $dom = new DOMDocument;
            $ok = $dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
            if (! $ok) {
                throw new ImportError('Il file non è un XML valido.', self::errors());
            }
            if ($dom->doctype !== null) {
                throw new ImportError('Il file contiene una dichiarazione DOCTYPE: non è accettato per motivi di sicurezza.');
            }

            return $dom;
        });
    }

    /**
     * Esegue $fn con un entity loader che ammette solo file della cartella degli schemi.
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    public static function withRestrictedLoader(callable $fn): mixed
    {
        $schemaDir = realpath(resource_path('schemas/fatturapa'));
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        libxml_set_external_entity_loader(function ($public, $system) use ($schemaDir) {
            $path = $system ? realpath(str_replace('file://', '', $system)) : false;

            return ($path && $schemaDir && str_starts_with($path, $schemaDir.DIRECTORY_SEPARATOR)) ? $path : null;
        });
        try {
            return $fn();
        } finally {
            libxml_set_external_entity_loader(null);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** @return list<string> */
    public static function errors(int $max = 5): array
    {
        $out = [];
        foreach (array_slice(libxml_get_errors(), 0, $max) as $e) {
            $out[] = 'Riga '.$e->line.': '.trim($e->message);
        }

        return $out;
    }
}
