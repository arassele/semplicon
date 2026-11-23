<?php

namespace App\Domain\Import;

use DOMDocument;

/** Verifica rispetto allo schema ufficiale e alle liste di codici (denominazioni) tratte dallo schema. */
final class FatturaPaSchema
{
    public const NAMESPACE = 'http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2';

    private static ?array $codes = null;

    public static function validate(DOMDocument $dom): void
    {
        $root = $dom->documentElement;
        if (! $root || $root->localName !== 'FatturaElettronica' || $root->namespaceURI !== self::NAMESPACE) {
            throw new ImportError('Il file non è una fattura elettronica FatturaPA (versione 1.2).');
        }
        $version = $root->getAttribute('versione');
        if (! in_array($version, ['FPR12', 'FPA12'], true)) {
            throw new ImportError("Formato non supportato: {$version}. Sono supportati FPR12 e FPA12.");
        }

        SafeXml::withRestrictedLoader(function () use ($dom) {
            if (! $dom->schemaValidate(config('semplicon.fatturapa_schema'))) {
                throw new ImportError('Il file non rispetta lo schema ufficiale FatturaPA 1.2.3.', SafeXml::errors());
            }
        });
    }

    /** Denominazione di un codice dallo schema, es. ('TipoDocumentoType', 'TD04') → «Nota di credito». */
    public static function label(string $type, ?string $code): ?string
    {
        return $code === null ? null : (self::codes()[$type][$code] ?? null);
    }

    /** @return array<string, array<string, string>> */
    public static function codes(): array
    {
        if (self::$codes === null) {
            $xsd = file_get_contents(config('semplicon.fatturapa_schema'));
            self::$codes = [];
            foreach (['TipoDocumentoType', 'NaturaType', 'EsigibilitaIVAType', 'ModalitaPagamentoType', 'TipoCassaType', 'TipoRitenutaType'] as $type) {
                preg_match('/<xs:simpleType name="'.$type.'">(.*?)<\/xs:simpleType>/s', $xsd, $m);
                preg_match_all('/<xs:enumeration value="([^"]+)">\s*<xs:annotation>\s*<xs:documentation>(.*?)<\/xs:documentation>/s', $m[1] ?? '', $all, PREG_SET_ORDER);
                foreach ($all as [, $value, $doc]) {
                    self::$codes[$type][$value] = trim(preg_replace('/\s+/', ' ', html_entity_decode($doc, ENT_QUOTES | ENT_XML1)));
                }
            }
        }

        return self::$codes;
    }

    /** Valori ammessi di un tipo di schema senza denominazione (es. CausalePagamentoType). @return list<string> */
    public static function enumValues(string $type): array
    {
        $xsd = file_get_contents(config('semplicon.fatturapa_schema'));
        preg_match('/<xs:simpleType name="'.$type.'">(.*?)<\/xs:simpleType>/s', $xsd, $m);
        preg_match_all('/<xs:enumeration value="([^"]+)"/', $m[1] ?? '', $all);

        return $all[1];
    }
}
