<?php

/**
 * Ticket 11: regole per liquidazioni IVA, F24 e crediti, versionate per anno della liquidazione.
 * Fonti: docs/FISCAL_RULES.md (R-LIQ-*). Per gli anni senza voce vale «Da verificare» (nessuna regola inventata).
 */
$base = [
    'small_debt_limit' => '100.00',          // art. 1 c. 4 DPR 100/1998 (D.Lgs. 1/2024): non superiore a 100 € → rinvio
    'small_debt_deadline' => '12-16',        // e comunque entro il 16 dicembre dello stesso anno
    'quarterly_interest_pct' => '1.00',      // art. 7 DPR 542/1999 (trimestrali per opzione)
    'tr_threshold' => '2582.28',             // art. 38-bis c. 2 DPR 633/1972: strettamente superiore
    'tr_deadlines' => [1 => '04-30', 2 => '07-31', 3 => '10-31'],
    'compensation_free_limit' => '5000.00',  // art. 17 D.Lgs. 241/1997: oltre, dal 10° giorno successivo alla presentazione
    'compensation_days_after_filing' => 10,
    'visto_limit' => '5000.00',              // art. 10 DL 78/2009: visto di conformità oltre 5.000 €
    'compensation_yearly_cap' => '2000000.00', // limite annuo delle compensazioni (art. 34 L. 388/2000)
    'refund_guarantee_limit' => '30000.00',  // art. 38-bis c. 3: oltre, garanzia o visto e dichiarazione sostitutiva
    'codes' => [
        'monthly' => [1 => '6001', 2 => '6002', 3 => '6003', 4 => '6004', 5 => '6005', 6 => '6006', 7 => '6007', 8 => '6008', 9 => '6009', 10 => '6010', 11 => '6011', 12 => '6012'],
        'quarterly' => [1 => '6031', 2 => '6032', 3 => '6033'],
        'special_q4' => '6034',
        'annual' => '6099',
        'tr_credit' => [1 => '6036', 2 => '6037', 3 => '6038'],
    ],
    // Scadenze (nominali, poi primo giorno lavorativo successivo; 1–20 agosto → 20 agosto, art. 3-quater DL 16/2012)
    'due' => ['monthly_day' => 16, 'quarterly' => [1 => '05-16', 2 => '08-16', 3 => '11-16'], 'q4_option' => '03-16', 'q4_special' => '02-16'],
    'tr_presupposti' => [
        'a' => 'Lett. a) aliquota media degli acquisti superiore a quella delle vendite',
        'b' => 'Lett. b) operazioni non imponibili superiori al 25% del totale',
        'c' => 'Lett. c) acquisti di beni ammortizzabili superiori ai 2/3 degli acquisti',
        'd' => 'Lett. d) soggetto non residente identificato',
        'e' => 'Lett. e) operazioni non soggette (art. 7-bis – 7-septies) superiori al 50%',
    ],
    'annual_presupposti' => [
        'cessazione' => 'Cessazione dell’attività',
        'a' => 'Art. 30 c. 3 lett. a) aliquota media',
        'b' => 'Art. 30 c. 3 lett. b) operazioni non imponibili oltre il 25%',
        'c' => 'Art. 30 c. 3 lett. c) beni ammortizzabili',
        'd' => 'Art. 30 c. 3 lett. d) operazioni non soggette',
        'triennale' => 'Art. 30 c. 4: credito per tre anni consecutivi',
    ],
];

return [
    'years' => [
        2025 => ['version' => 'LIQ-2025.1'] + $base,
        2026 => ['version' => 'LIQ-2026.1'] + $base,
        // 2027 e 2028 (indicazione del committente, 21.11.2026): parametri e scadenze ripresi invariati dal 2026, poiché non è nota alcuna modifica dei presupposti.
        // Verificare ogni anno alla fonte (legge di bilancio, decreti); in caso di modifica creare qui un insieme proprio.
        2027 => ['version' => 'LIQ-2027.1'] + $base,
        2028 => ['version' => 'LIQ-2028.1'] + $base,
    ],
];
