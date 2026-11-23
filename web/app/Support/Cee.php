<?php

namespace App\Support;

/** Voci del conto economico, schema art. 2425 c.c. */
final class Cee
{
    public const LABELS = [
        'A1' => 'Ricavi delle vendite e delle prestazioni',
        'A2' => 'Variazioni delle rimanenze di prodotti in corso di lavorazione, semilavorati e finiti',
        'A3' => 'Variazioni dei lavori in corso su ordinazione',
        'A4' => 'Incrementi di immobilizzazioni per lavori interni',
        'A5' => 'Altri ricavi e proventi',
        'B6' => 'Per materie prime, sussidiarie, di consumo e di merci',
        'B7' => 'Per servizi',
        'B8' => 'Per godimento di beni di terzi',
        'B9a' => 'Personale: salari e stipendi',
        'B9b' => 'Personale: oneri sociali',
        'B9c' => 'Personale: trattamento di fine rapporto',
        'B9d' => 'Personale: trattamento di quiescenza e simili',
        'B9e' => 'Personale: altri costi',
        'B10a' => 'Ammortamento delle immobilizzazioni immateriali',
        'B10b' => 'Ammortamento delle immobilizzazioni materiali',
        'B10c' => 'Altre svalutazioni delle immobilizzazioni',
        'B10d' => 'Svalutazioni dei crediti e delle disponibilità liquide',
        'B11' => 'Variazioni delle rimanenze di materie prime, sussidiarie, di consumo e merci',
        'B12' => 'Accantonamenti per rischi',
        'B13' => 'Altri accantonamenti',
        'B14' => 'Oneri diversi di gestione',
        'C15' => 'Proventi da partecipazioni',
        'C16' => 'Altri proventi finanziari',
        'C17' => 'Interessi e altri oneri finanziari',
        'C17-bis' => 'Utili e perdite su cambi',
        'D18' => 'Rivalutazioni',
        'D19' => 'Svalutazioni',
        '20' => 'Imposte sul reddito dell’esercizio, correnti, differite e anticipate',
        '21' => 'Utile (perdita) dell’esercizio',
    ];
}
