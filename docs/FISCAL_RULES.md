# Registro delle regole · Versione IVA-I01-2026.6

Colonne secondo il piano: ID regola, caso, fonte, validità, input, risultato atteso, test di riferimento, approvazione fiscale.

Versione 2026.6: Reverse charge anche con l'estero; verifica del Codice fiscale; ATECO solo dall'elenco ISTAT 2025. Integrata con le regole di import R-IMP (I02). **Approvazione fiscale: aperta.**

| Regola | Caso | Fonte | Input | Risultato | Test di riferimento |
|---|---|---|---|---|---|
| R-START-01 | Nessun regime automatico | Piano 2.0 §1 | – | Nessun campo importo, nessuna derivazione dalle somme XML; ogni anno viene confermato dal cliente | `test_nothing_is_created_or_confirmed_automatically`, `test_schema_has_no_amount_or_date_range_inputs` |
| R-YEAR-01 | Regime per anno solare | Indicazione del committente, 17.11.2026 | Anno (2000–2100) | Un regime valido per azienda e anno, sempre dal 1° gennaio al 31 dicembre | `test_each_year_has_its_own_regime`, `test_year_must_be_in_range`, `test_only_one_draft_per_year` |
| R-REG-01 | Regime principale | Indicazione del committente, 18.11.2026 | Regime | Contabilità semplificata o Contabilità professionisti | `test_there_are_two_main_regimes_and_two_semplificata_variants`, `test_unknown_regime_or_variant_is_rejected` |
| R-REG-02 | Variante della semplificata | Indicazione del committente, 18.11.2026 | Variante | Obbligatoria per la semplificata: regime opzionale senza annotazione incassi/pagamenti (per competenza) oppure regime naturale con annotazione incassi/pagamenti (per cassa); per i professionisti nessuna | `test_semplificata_requires_a_variant`, `test_professionisti_has_no_variant` |
| R-FORF-01 | Soggetto forfettario con IVA | Indicazione del committente, 18.11.2026 | Sì/No | Per anno, predefinito No. L'effetto sui dati della dichiarazione dei redditi e sulla contabilità segue in I03–I05 | `test_every_valid_combination_can_be_confirmed`, `test_forfettario_flag_defaults_to_no` |
| R-PRORATA-01 | Aliquota pro rata | Indicazione del committente, 18.11.2026 | Percentuale | Per anno 0–100 %, fino a 2 cifre decimali, predefinito 100 %; inferiore al 100 % ammesso. Applicata all'IVA acquisti a partire da I03 | `test_pro_rata_defaults_to_100`, `test_pro_rata_below_100_is_allowed_and_kept_exactly`, `test_invalid_pro_rata_is_rejected` |
| R-PROF-01 | Dati obbligatori | Piano 2.0 §2 | Regime, cadenza | Senza entrambi nessuna conferma | `test_confirmation_requires_regime_and_periodicity` |
| R-OPS-01 | Operazioni supportate | Indicazione del committente, 18.11.2026 | – | Corrispettivi, Reverse charge (anche Intra-UE ed Extra-UE), Split payment sempre supportati, nessuna selezione | `test_operations_and_unsupported_cases_are_information_only` |
| R-SUP-02 | Non disponibile | Indicazione del committente, 18.11.2026 | – | Regime del margine, altri regimi particolari, IVA per cassa (art. 32-bis): solo avviso, nessun campo di selezione. IVA per cassa eventualmente in seguito | `test_operations_and_unsupported_cases_are_information_only` |
| R-PROF-05 | Correzione di un anno confermato | Piano 2.0 §4 | Motivazione | Nuova versione dello stesso anno; la vecchia resta invariata come «Sostituito» | `test_correction_creates_new_version_of_same_year_and_keeps_history` |
| R-PER-01 | Cadenza mensile o trimestrale | Piano 2.0 §2 | Indicazione del cliente | Il software **non** verifica il diritto alla cadenza trimestrale | – (volutamente nessuna regola) |

## Anagrafica (Dati azienda)

| Verifica | Regola | Test di riferimento |
|---|---|---|
| Partita IVA | 11 cifre con cifra di controllo (variante di Luhn) | `ValidationTest::test_partita_iva_check_digit`, `CompanyProfileTest::test_partita_iva_check_digit_and_uniqueness` |
| IBAN | ISO 13616 modulo 97, IT con 27 caratteri; ABI/CAB derivati | `ValidationTest::test_iban_mod97`, `CompanyProfileTest::test_iban_ateco_and_email_are_checked` |
| Codice fiscale | 16 caratteri: struttura e carattere di controllo (D.M. 23/12/1976, incl. Omocodia); 11 cifre: cifra di controllo come Partita IVA | `ValidationTest::test_codice_fiscale_check_character`, `CompanyProfileTest::test_codice_fiscale_check_character` |
| ATECO | Solo codici dell'elenco ISTAT ATECO 2025 (6 cifre), denominazione dall'elenco | `AtecoTest`, `CompanyProfileTest::test_iban_ateco_and_email_are_checked`, `CompanyProfileTest::test_ateco_search_endpoint` |

## Import FatturaPA (I02)

| Regola | Caso | Fonte | Risultato | Test di riferimento |
|---|---|---|---|---|
| R-IMP-01 | Formato | Specifiche tecniche FatturaPA 1.4, Schema 1.2.3 | Solo FPR12 e FPA12, validi rispetto allo schema; altrimenti errore con messaggio dello schema | `test_official_fpr01_is_imported_as_sale_with_values_from_the_file`, `test_official_fpa_examples_are_supported`, `test_schema_violation_is_rejected_with_explanation` |
| R-IMP-02 | Direzione | Piano 2.0 §4 | L'azienda è Cedente → Vendita; Cessionario → Acquisto (P.IVA o C.F.); entrambi → direzione aperta, «da verificare»; nessuno → errore | `test_same_file_is_a_purchase_for_the_customer`, `test_file_for_another_company_is_rejected` |
| R-IMP-03 | Più fatture per file | Piano 2.0 §4 | Ogni FatturaElettronicaBody diventa una fattura, la posizione resta conservata | `test_multiple_bodies_create_multiple_invoices` |
| R-IMP-04 | Duplicati | Piano 2.0 §4 | Stesso file o stessa fattura (identità + contenuto) solo una volta; stessa identità con contenuto diverso → «da verificare» | `test_same_file_twice_is_a_duplicate`, `test_same_invoice_in_another_file_is_a_duplicate`, `test_same_identity_with_different_content_is_held_for_review`, `test_database_rejects_duplicate_identity_and_content` |
| R-IMP-05 | Valuta estera | Piano 2.0 §6 | Nessuna acquisizione silenziosa: «da verificare» | `test_foreign_currency_and_total_mismatch_are_flagged` |
| R-IMP-06 | Importo totale | – (plausibilità, non una regola fiscale) | Se ImportoTotaleDocumento è indicato e ≠ Imponibile + Imposta + Arrotondamento → «da verificare» | `test_foreign_currency_and_total_mismatch_are_flagged` |
| R-IMP-07 | Regime dell'anno | R-YEAR-01 | Senza regime confermato per l'anno della fattura → «da verificare» | `test_invoice_without_issues_when_regime_is_confirmed` |
| R-IMP-08 | Importi | Piano 2.0 §4 | Testo decimale, calcolo in centesimi come intero; più cifre decimali del consentito = errore, nessun arrotondamento silenzioso | `DecimalTest` |

## Registrazione (I03)

| Regola | Caso | Fonte | Risultato | Test di riferimento |
|---|---|---|---|---|
| R-REGI-01 | Periodo | R-YEAR-01, regime dell'anno | Mese (mensile) o trimestre (trimestrale); l'anno richiede un regime confermato; periodo non anteriore alla data del documento. Proposta, da confermare dal cliente | `test_purchase_is_registered_with_protocol_period_and_deductible_vat`, `test_quarterly_regime_uses_quarters`, `test_validation_rules` |
| R-REGI-02 | Data di ricezione | Piano 2.0 §4 | Obbligatoria per gli Acquisti, tra la data del documento e oggi | `test_validation_rules` |
| R-REGI-03 | Detraibilità per riga | Indicazione | 0–100 %, arrotondamento al centesimo (mezzo lontano dallo zero); pro rata solo nella liquidazione | `test_partial_deduction_is_rounded_to_cents` |
| R-REGI-04 | Conto | Piano dei conti | Conto dell'azienda, attivo; conto di costo per gli Acquisti, conto di ricavo per le Vendite | `test_validation_rules`, `test_foreign_and_inactive_accounts_are_rejected` |
| R-REGI-05 | Nota di credito | TipoDocumento TD04 | Segno −1, importi come valore assoluto | `test_credit_note_reduces_with_negative_sign` |
| R-REGI-06 | Valuta estera | Piano 2.0 §6 | Registrazione bloccata | `test_foreign_currency_is_blocked` |
| R-REGI-07 | Avvisi di verifica | Piano 2.0 §4 | Confermare singolarmente tutti gli avvisi dell'import | `test_issues_must_be_acknowledged` |
| R-REGI-08 | Immodificabilità, Storno | Piano 2.0 §4; indicazione del committente, 18.11.2026 | Nessuna modifica; storno con protocollo proprio nel periodo della data dello storno, fattura di nuovo aperta | `test_registration_is_immutable`, `test_reversal_goes_into_the_period_of_its_own_date`, `test_reversal_with_chosen_date_uses_that_period_and_year_sequence` |
| R-REGI-09 | Nessuna doppia registrazione | Piano 2.0 §4 | Indice univoco sulla registrazione attiva | `test_double_registration_is_impossible` |

Confermato sul piano fiscale (18.11.2026): % detraibile per riga, pro rata nella liquidazione, proposta del periodo senza verifica dei termini, piano dei conti semplice.

Documenti di integrazione (TD16–TD19) in entrambi i registri: realizzato in I04 (R-REGS-05). Il software non verifica i termini per l'esercizio della detrazione dell'IVA a credito, propone solo il periodo della data di ricezione.

## Registri IVA (I04)

| ID | Regola | Fonte | Realizzazione | Test |
|---|---|---|---|---|
| R-REGS-01 | Registro dalle registrazioni, ordine per protocollo | Piano 2.0 §4 (I04) | Calcolato da vat_registrations; filtro anno/periodo | `test_purchase_register_totals_equal_the_sum_of_registrations`, `test_period_filter_and_whole_year` |
| R-REGS-02 | I totali coincidono con le registrazioni | Piano 2.0 (accettazione I04) | Totali in centesimi per Aliquota/Natura e complessivi | `test_purchase_register_totals_equal_the_sum_of_registrations` |
| R-REGS-03 | Storno nel proprio periodo, anno netto zero | Indicazione del committente, 18.11.2026 | L'originale resta «stornata» nel periodo originario | `test_reversal_nets_to_zero_in_its_own_period` |
| R-REGS-04 | La nota di credito riduce | art. 26 DPR 633/1972 | Segno −1 | `test_credit_note_is_negative` |
| R-REGS-05 | Documenti di integrazione in entrambi i registri | art. 17 c. 2, 5–6 DPR 633/1972; artt. 46–47 DL 331/1993 | TD16–TD19 inoltre nel Registro vendite | `test_sales_register_contains_sales_and_reverse_charge_integrations` |

Punto fiscale aperto: confermare la rappresentazione delle integrazioni nel Registro vendite (identificativo «A», nessun numero proprio).

## Piano dei conti 2026 e Impostazioni fiscali

| ID | Regola | Fonte | Realizzazione | Test |
|---|---|---|---|---|
| R-PDC-01 | Modello con Voce CEE, Sezione e dati fiscali (semplificata 158, ordinaria 189 conti) | File del committente, 18.11.2026; art. 2425 c.c. | `database/data/piano_conti_2026.csv`, nuove aziende | `test_new_company_gets_model_2026_with_fiscal_settings` |
| R-PDC-02 | I conti «fuori campo IVA» non sono registrabili | File (colonna IVA «Fuori campo») | Filtro e verifica nella registrazione | `test_account_outside_vat_scope_is_rejected_and_hidden` |
| R-PDC-03 | % IVA detraibile del conto come proposta | File (ad es. autovetture 40 %, art. 19-bis1 DPR 633/72) | Precompilazione, modificabile dal cliente | `test_account_outside_vat_scope_is_rejected_and_hidden` |
| R-PDC-04 | Conto predefinito attivo e rilevante ai fini IVA, i conti usati non sono eliminabili, tipo fisso | Piano 2.0 (immodificabilità) | Verifiche in AccountService | `test_default_account_rules`, `test_used_account_cannot_be_deleted_or_change_type` |

| R-PDC-05 | Modello della semplificata come predefinito, ordinaria salvata e bloccata | Indicazione del committente, 18.11.2026 | `piano_conti_semplificata_2026.csv`, `AccountService::MODELS` | `test_new_company_gets_model_2026_with_fiscal_settings`, `test_model_can_be_applied_to_an_existing_chart` |
| R-PDC-06 | IVA sulle Spese di rappresentanza non detraibile, Omaggi fino a 50 euro detraibili | art. 19-bis1 c.1 lett. h DPR 633/72 | Proposta 0 % rispettivamente 100 % | `test_new_company_gets_model_2026_with_fiscal_settings` |

Valori di proposta per il resto solo in presenza di indicazioni univoche della fonte (40 %/100 %); per «Secondo operazione» e «Secondo inerenza» vale 100 % come proposta. | R-PDC-07 | I Professionisti ricevono un modello per il quadro RE | Indicazione del committente, 19.11.2026; art. 54 TUIR | `piano_conti_professionisti_2026.csv`, assegnazione per Tipologia | `test_professional_gets_dedicated_model_for_quadro_re` |

| R-PDC-08 | Contributo integrativo casse private non è reddito; Rivalsa INPS 4 % reddito | Indicazione del committente, 19.11.2026 | 711003 «Non concorrono al reddito», 711002 Compensi | `test_professional_gets_dedicated_model_for_quadro_re` |
| R-PDC-09 | Rimborsi spese analitici in fattura non sono reddito, costi riaddebitati non deducibili | Indicazione del committente, 19.11.2026; art. 54 TUIR (D.Lgs. 192/2024) | 711004, 641010 | `test_professional_gets_dedicated_model_for_quadro_re` |
| R-PDC-10 | IRAP solo per Società di persone, non per Persone fisiche | Indicazione del committente, 19.11.2026 | `AccountService::rowsFor()` | `test_new_company_gets_model_2026_with_fiscal_settings`, `test_professional_gets_dedicated_model_for_quadro_re` | L'assegnazione alle Righe del quadro RE/RG segue con i dati della dichiarazione dei redditi.

## Fatture manuali, documenti, Movimenti fuori campo IVA

| ID | Regola | Fonte | Realizzazione | Test |
|---|---|---|---|---|
| R-MAN-01 | La data di ricezione viene proposta con la data della fattura | Indicazione del committente, 19.11.2026 | Precompilazione, periodo ricavato da essa | `test_receipt_date_is_proposed_as_invoice_date` |
| R-MAN-02 | Il documento appartiene alla registrazione | Indicazione del committente, 19.11.2026 | `vat_registrations.document_id` | `test_purchase_is_registered_with_protocol_period_and_deductible_vat`, `test_manual_purchase_with_attachment_is_registered_with_its_document` |
| R-MAN-03 | Fattura manuale: Imposta = Imponibile × Aliquota, Natura a 0 %, nessun duplicato | Indicazione del committente; logica FatturaPA | `ManualInvoiceService` | `test_validation` (ManualInvoiceTest) |
| R-EDIT-01 | Registrazione modificabile/eliminabile, il protocollo resta, gli storni solo con la fattura | Indicazione del committente, 19.11.2026 | `RegistrationService::update/delete` | `EditDeleteTest` |
| R-EDIT-02 | Fattura eliminabile con le registrazioni; fattura manuale modificabile, registrazione ricalcolata | Indicazione del committente, 19.11.2026 | `InvoiceService::delete`, `ManualInvoiceService::update` | `EditDeleteTest` |
| R-NV-01 | Fuori campo IVA non nei Registri IVA, protocollo per anno, storno | Indicazione del committente, 19.11.2026 | `NonVatEntryService` | `NonVatEntryTest` |
| R-SC-01 | Rate da DatiPagamento; da pagare = Totale − Ritenuta − Split payment; senza scadenza mai scaduto | Indicazione del committente, 19.11.2026; FatturaPA 1.2.3 | `ScheduleService` | `ScadenziarioTest::test_installments_from_xml_and_reimport_without_duplicates`, `test_case4_*` |
| R-SC-02 | Saldata solo con pagamento o Compensazione; Nota di credito senza fittizio accredito bancario | Indicazione del committente, 19.11.2026 | `PaymentService`, `Ledger` | `test_case7_*` |
| R-SC-03 | Per cassa (semplificata cassa, professionisti): ogni pagamento parziale nell'anno della sua data, proporzionalmente per conto in centesimi | Art. 66 e 54 TUIR; indicazione del committente, 19.11.2026 | `FiscalService` | `test_case1_*`, `test_case2_*` |
| R-SC-04 | Presunzione di incasso/pagamento alla registrazione (semplificata per competenza): nessuna seconda rilevazione al pagamento | Art. 18 c. 5 DPR 600/1973 | `FiscalService` | `test_case3_*` |
| R-SC-05 | Sostituto d'imposta paga a dicembre, accredito a gennaio: assegnazione all'anno del pagamento del sostituto con motivazione | Indicazione del committente, 19.11.2026 (prassi «principio di cassa allargato») | `FiscalService` | `test_case5_*` |
| R-SC-06 | Senza profilo confermato, forfettario o cambio di regime: compensare sul piano commerciale, fiscalmente «Da verificare» | Indicazione del committente, 19.11.2026 | `FiscalService::modeFor` | `test_unconfirmed_profile_marks_tax_effect_to_verify` |
| R-SC-06a | Professionista (anche forfettario): fiscalmente sempre per cassa (Incasso/Pagamento); Forfettario in Semplificata resta «Da verificare» | Indicazione del committente, 21.11.2026 | `FiscalService::modeFor` | `IncomeStatementTest::test_professionista_forfettario_is_cash_basis` |
| R-LIQ-01 | Periodo della registrazione; IVA a debito = vendite (senza split payment) + integrazioni TD16–TD19 ± rettifiche | Art. 1 DPR 100/1998; art. 17 c. 5–6, 17-ter DPR 633/1972 | `SettlementCalculator` | `LiquidazioniTest::test_td17_reverse_charge_split_payment_and_pro_rata` |
| R-LIQ-02 | IVA detraibile = detraibile specifica × Pro rata, una sola volta | Art. 19, 19-bis DPR 633/1972 | `SettlementCalculator` | idem |
| R-LIQ-03 | Codici 6001–6012, 6031–6033, 6034, 6099; Q4 per opzione tramite il saldo annuale | Agenzia delle Entrate, Codici tributo IVA | `Rules::code` | `test_january_*`, `test_third_quarter_*` |
| R-LIQ-04 | Interessi 1 % trimestrali per opzione, non speciali | Art. 7 DPR 542/1999; art. 74 c. 4 DPR 633/1972 | `SettlementCalculator` | `test_third_quarter_option_interest_and_6033` |
| R-LIQ-05 | Debito ≤ 100 € rinviato, cumulato, al più tardi 16 dicembre | Art. 1 c. 4 DPR 100/1998 (D.Lgs. 1/2024) | `SettlementCalculator` | `test_small_debts_deferral_threshold_and_year_end` |
| R-LIQ-06 | Scadenze 16/20 agosto/giorno lavorativo | DPR 100/1998, DPR 542/1999, art. 3-quater DL 16/2012 | `Rules::dueDate` | `test_january_*`, `test_third_quarter_*` |
| R-LIQ-07 | Credito del periodo solo Riporto; TR: trimestre solare senza riporti, > 2.582,28 €, Q1–Q3 | Art. 38-bis c. 2 DPR 633/1972; art. 8 DPR 542/1999 | `CreditService::trCheck` | `test_monthly_credit_*`, `test_tr_threshold_*` |
| R-LIQ-08 | Compensazione: credito annuale dal 1/1, > 5.000 € dopo Dichiarazione + 10 giorni, Visto > 5.000 €, limite 2 milioni; credito TR dopo Presentazione | Art. 17 D.Lgs. 241/1997; art. 10 DL 78/2009; art. 34 L. 388/2000 | `CreditService::availability` | `test_credit_destination_page_and_tr_request`, `test_f24_balance_zero_*` |
| R-LIQ-09 | Una Destinazione attiva per quota; prenotazione sotto blocco; Sblocco con motivo | Indicazione Ticket 11 | `CreditService`, `F24Service` | `test_concurrency_*`, `test_mixed_destination_*` |
| R-LIQ-10 | Liquidazione confermata immodificabile; modifica → «da riesaminare», nuova versione | Indicazione Ticket 11 | `SettlementService` | `test_correction_*` |
| R-LIQ-11 | Regole per 2027 e 2028 (`LIQ-2027.1`, `LIQ-2028.1`): parametri, Codici tributo e scadenze come nel 2026, finché non è nota alcuna modifica; per gli anni successivi resta «Da verificare». Verificare ogni anno alla fonte | Indicazione del committente, 21.11.2026 (richiesta su «Regole di liquidazione per il 2027 non disponibili») | `config/semplicon_iva.php` | `test_rules_exist_for_2027_and_2028_but_not_beyond` |

## Fonti dei dati di riferimento

- FatturaPA: Schema 1.2.3 e fatture di esempio da www.fatturapa.gov.it, consultati il 18.11.2026 (vedere `web/resources/schemas/fatturapa/README.md`).
- Piano dei conti 2026 e Parametri fiscali: file del committente del 18.11.2026 (vedere `web/database/data/README.md`).
- ATECO 2025: ISTAT, «Struttura ATECO 2025 IT-EN-DE», CC BY 4.0, consultata il 18.11.2026 (vedere `web/database/data/README.md`).

## Questioni aperte

- Nessuna questione aperta su I01.

## Emissione fattura elettronica (indicazione 20.11.2026)

- Formato FPR12 (privati, B2B, estero), FPA12 per la Pubblica Amministrazione (Codice univoco ufficio, 6 caratteri). CodiceDestinatario: 7 caratteri, vuoto = 0000000 (recapito tramite PEC o area del cliente), estero XXXXXXX con CAP 00000.
- Numerazione progressiva e senza interruzioni per anno (art. 21 DPR 633/72), data non anteriore all'ultima fattura emessa. Imposta per Aliquota/Natura arrotondata al centesimo; somma della riga = quantità × prezzo (2 cifre decimali).
- Bollo virtuale 2,00 € per importi senza IVA superiori a 77,47 € (DatiBollo con BolloVirtuale SI). Scissione dei pagamenti (art. 17-ter): EsigibilitaIVA «S», da pagare = Imponibile; la Liquidazione non conteggia l'IVA.
- Nota di credito (TD04): importi positivi, riferimento alla fattura rettificata (DatiFattureCollegate), registrazione come riduzione.
- Cassa previdenziale: Contributo = Imponibile × % su imponibile × % cassa (Massimale come limite massimo); fa parte dell'Imponibile del suo gruppo IVA. Ritenuta d'acconto = (compenso + Cassa «soggetta a ritenuta») × % su base × aliquota; il Totale documento non include la Ritenuta, essa riduce soltanto l'importo da pagare.
- Acconti: TD02 (su fattura) e TD03 (su parcella) con IVA al momento dell'emissione; fattura a saldo con righe negative per gli Acconti detratti.

## Cespiti (decisione n. 79)

| Regola | Contenuto | Fonte | Realizzazione | Test |
|---|---|---|---|---|
| R-CSP-01 | Aliquote massime Materiali dal D.M. 31.12.1988 (Gruppo/Specie), versione del 16/05/1996; tabella immodificabile; ultima sezione senza numero («Attività non precedentemente specificate») internamente Gruppo 23, Specie 1 Fiere e rassegne, 2 Altre attività (decisione n. 80) | D.M. 31/12/1988, GU 27/1989; D.M. 28/03/1996 | `DepreciationTableImporter` | `test_civil_fiscal_difference_and_rate_above_limit`, `test_dm_table_contains_unnumbered_last_section` |
| R-CSP-02 | Primo anno: aliquota massima fiscale dimezzata, solo Materiali | Art. 102 c. 2 TUIR | `DepreciationEngine` | `test_material_first_year_half_fiscal_and_ordinary_quota`, `test_intangible_has_no_automatic_half_rate` |
| R-CSP-03 | Deducibile al massimo la quota contabilizzata e l'aliquota massima; l'eccedenza non è deducibile | Art. 102 TUIR | `DepreciationEngine` | `test_reduced_quota`, `test_civil_fiscal_difference_and_rate_above_limit` |
| R-CSP-04 | Beni di modesto valore: importo intero nell'anno (soglia impostabile, predefinita 516,46 €, da verificare) | Art. 102 c. 5 TUIR | `DepreciationEngine` | `test_low_value_and_limited_deductibility` |
| R-CSP-05 | Deducibilità limitata: limite di costo e percentuale per Cespite, nessun valore automatico | Art. 164 TUIR (verificare i valori caso per caso) | `DepreciationEngine` | `test_low_value_and_limited_deductibility` |
| R-CSP-06 | Plus/Minus = prezzo netto − costo − valore contabile; fiscalmente con valore fiscale; regole sulle rate non automatiche | Art. 86, 101 TUIR | `AssetMovementService` | `test_sale_with_gain_and_journal`, `test_sale_with_loss`, `test_partial_alienation` |
| R-CSP-07 | Rivalutazione fiscalmente solo con base giuridica propria | Specifica §8 | `AssetMovementService` | `test_revaluation_not_fiscally_recognized` |
| R-CSP-08 | Immateriali: aliquote proprie, nessun D.M., nessun dimezzamento | Art. 103 TUIR | `AssetService::saveCategory` | `test_intangible_has_no_automatic_half_rate` |
| R-CSP-09 | Quota ridotta solo come decisione al Calcolo (esecuzione, per categoria), mai per Immateriali; deducibile al massimo la quota contabilizzata | Art. 102 c. 2 TUIR (quote «non superiori» ai coefficienti) | `DepreciationService::options`, `DepreciationEngine` | `test_reduced_quota_per_category_and_never_intangible` |
| R-CSP-10 | Maggiorazione (Super-/Iperammortamento) = quota deducibile × % del Cespite, Variazione in diminuzione senza registrazione; % e Spettanza per norma, non precompilati | Norma rispettiva (ad es. L. 232/2016), da verificare | `DepreciationEngine`, `IncomeStatementService` | `test_extra_deduction_calculated_and_in_income_statement` |
| R-CSP-11 | Progressivi: Fondo al 31.12. dell'ultimo anno ammortizzato, piano dall'anno successivo; Fondo ≤ costo | Specifica §10 | `AssetService::openingInput` | `test_manual_opening_balance_in_form` |
