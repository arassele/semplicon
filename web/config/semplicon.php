<?php

return [
    // Versione del registro delle regole (docs/FISCAL_RULES.md), salvata sul regime al momento della conferma.
    'rule_version' => 'IVA-I01-2026.6',

    // Sempre supportato, nessuna scelta necessaria (indicazione del committente, 18.11.2026).
    'supported_operations' => ['Corrispettivi', 'Reverse charge (anche operazioni con l’estero, intra-UE ed extra-UE)', 'Split payment'],

    // Non supportato. Mostrato solo come avviso, nessun campo di selezione (indicazione del committente, 18.11.2026).
    'unsupported' => ['Regime del margine', 'Altri regimi speciali IVA', 'IVA per cassa (art. 32-bis)'],

    // I02: importazione FatturaPA. I limiti proteggono da file sovradimensionati e ZIP bomb.
    'fatturapa_schema' => resource_path('schemas/fatturapa/Schema_VFPR12_v1.2.3.xsd'),
    'import' => [
        'max_files_per_upload' => 50,
        'max_file_kb' => 10240,          // 10 MB per file (XML, P7M)
        'max_zip_kb' => 51200,           // 50 MB per ZIP
        'max_zip_entries' => 1000,
        'max_zip_uncompressed_bytes' => 200 * 1024 * 1024,
        'max_xml_bytes' => 10 * 1024 * 1024,
    ],

    // Verifica delle partite IVA UE nello scambio di informazioni IVA dell'UE (VIES, REST). Vengono inviati solo paese + numero + propria P.IVA.
    'vies' => [
        'url' => env('VIES_URL', 'https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number'),
        'timeout' => 8,
    ],
];
