<?php

namespace App\Support;

/** Sezioni del conto economico nel piano dei conti per la contabilità semplificata. */
final class Sezione
{
    public const LABELS = [
        'ricavi' => 'Ricavi',
        'altri_proventi' => 'Altri proventi e componenti positivi',
        'proventi_finanziari' => 'Proventi finanziari',
        'acquisti' => 'Acquisti di merci, materie e materiali',
        'servizi' => 'Servizi',
        'godimento' => 'Godimento di beni di terzi',
        'personale' => 'Personale',
        'ammortamenti' => 'Ammortamenti',
        'oneri_diversi' => 'Oneri diversi di gestione',
        'oneri_finanziari' => 'Oneri finanziari',
        'imposte' => 'Imposte',
        // Contabilità professionisti (quadro RE, art. 54 TUIR); l'attribuzione alle righe seguirà con i dati della dichiarazione dei redditi.
        'compensi' => 'Compensi (concorrono al reddito)',
        'altri_proventi_re' => 'Altri proventi e plusvalenze',
        'ammortamenti_re' => 'Beni strumentali: ammortamenti, leasing e noleggi',
        'immobili' => 'Studio e immobili',
        'lavoro_dipendente' => 'Lavoro dipendente e collaboratori',
        'compensi_terzi' => 'Compensi a terzi',
        'consumi' => 'Consumi e utenze',
        'alberghi_ristoranti' => 'Vitto, alloggio e trasferte',
        'rappresentanza_re' => 'Rappresentanza',
        'formazione' => 'Formazione e aggiornamento',
        'veicoli_re' => 'Autovettura',
        'interessi' => 'Interessi e oneri finanziari',
        'altre_spese' => 'Altre spese',
        'imposte_re' => 'Imposte',
        'non_reddito' => 'Non concorrono al reddito',
        'fuori_re' => 'Non deducibili nel quadro RE',
        // Stato patrimoniale (modulo Cespiti)
        'immobilizzazioni_immateriali' => 'Immobilizzazioni immateriali',
        'immobilizzazioni_materiali' => 'Immobilizzazioni materiali',
        'fondi_ammortamento' => 'Fondi ammortamento',
        'cessione_cespiti' => 'Cessione cespiti',
        'riserve' => 'Riserve di patrimonio netto',
    ];

    /** Per la scelta nella finestra di dialogo: quali sezioni appartengono a quale modello. */
    public const GROUPS = [
        'Contabilità semplificata' => ['ricavi', 'altri_proventi', 'proventi_finanziari', 'acquisti', 'servizi', 'godimento', 'personale', 'ammortamenti', 'oneri_diversi', 'oneri_finanziari', 'imposte'],
        'Contabilità professionisti (quadro RE)' => ['compensi', 'altri_proventi_re', 'ammortamenti_re', 'immobili', 'lavoro_dipendente', 'compensi_terzi', 'consumi', 'alberghi_ristoranti', 'rappresentanza_re', 'formazione', 'veicoli_re', 'interessi', 'altre_spese', 'imposte_re', 'non_reddito', 'fuori_re'],
        'Stato patrimoniale (cespiti)' => ['immobilizzazioni_immateriali', 'immobilizzazioni_materiali', 'fondi_ammortamento', 'cessione_cespiti', 'riserve'],
    ];
}
