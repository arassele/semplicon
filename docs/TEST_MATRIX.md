# Matrice dei test · I00–I04 (versione delle regole 2026.6)

Eseguire in `web`: `php artisan test` (PostgreSQL) oppure `DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan test`.

Stato 18.11.2026: 114/114 superati su PostgreSQL 16 e su SQLite. MySQL: **non eseguito**.

| Area | Requisito | Test |
|---|---|---|
| Login | Gli ospiti vengono indirizzati al login | AuthTest::test_guest_is_redirected_to_login |
| Login | Accesso e uscita, registro | AuthTest::test_user_can_log_in_and_out |
| Login | Password errata respinta | AuthTest::test_wrong_password_is_rejected |
| Login | Blocco dopo 5 tentativi falliti | AuthTest::test_login_is_throttled_after_five_failures |
| Registrazione | Il cliente si registra, mai amministratore | AuthTest::test_customer_can_register_and_is_not_admin |
| Registrazione | Regole della password, e-mail univoca | AuthTest::test_registration_validates_password_and_unique_email |
| Registro | Non modificabile | AuthTest::test_audit_events_are_append_only |
| Aziende | Ogni cliente crea la propria azienda | TenancyTest::test_any_customer_can_create_own_company |
| Aziende | L'elenco mostra solo le proprie aziende | TenancyTest::test_company_list_only_shows_own_companies |
| Separazione | Pagine altrui → 404 | TenancyTest::test_foreign_company_pages_return_404 |
| Separazione | Accessi in scrittura a un'azienda altrui → 404 | TenancyTest::test_foreign_company_writes_return_404_and_change_nothing |
| Separazione | ID di profilo altrui sotto il proprio URL → 404 | TenancyTest::test_foreign_profile_id_under_own_company_url_returns_404 |
| Separazione | ID non numerici → 404 | TenancyTest::test_non_numeric_ids_return_404 |
| Amministratore | Vede e modifica tutte le aziende, registrato | TenancyTest::test_admin_sees_and_edits_every_company |
| Amministratore | Panoramica solo per gli amministratori | TenancyTest::test_admin_overview_is_only_for_admins |
| R-START-01 | Nulla in automatico | VatProfileTest::test_nothing_is_created_or_confirmed_automatically |
| R-START-01, R-YEAR-01 | Nessun campo importo, nessuna data di inizio/fine | VatProfileTest::test_schema_has_no_amount_or_date_range_inputs |
| R-YEAR-01 | Regime proprio per ogni anno | VatProfileTest::test_each_year_has_its_own_regime |
| R-YEAR-01 | Anno nell'intervallo 2000–2100 | VatProfileTest::test_year_must_be_in_range |
| R-YEAR-01 | Una sola bozza per anno | VatProfileTest::test_only_one_draft_per_year |
| Modello | Un nuovo anno prende l'ultimo anno confermato | VatProfileTest::test_new_year_starts_from_previous_confirmed_year |
| R-REG-01, R-REG-02 | Due regimi principali, due varianti, denominazioni | VatProfileTest::test_there_are_two_main_regimes_and_two_semplificata_variants |
| R-REG-01/02, R-FORF-01 | Tutte le combinazioni valide confermabili | VatProfileTest::test_every_valid_combination_can_be_confirmed |
| R-REG-02 | La semplificata richiede la variante | VatProfileTest::test_semplificata_requires_a_variant |
| R-REG-02 | Professionisti senza variante | VatProfileTest::test_professionisti_has_no_variant |
| R-REG-02 | Denominazione con variante | VatProfileTest::test_regime_label_includes_variant |
| R-REG-01/02 | Valori sconosciuti respinti | VatProfileTest::test_unknown_regime_or_variant_is_rejected |
| R-FORF-01 | Predefinito No | VatProfileTest::test_forfettario_flag_defaults_to_no |
| R-PROF-01 | Dati obbligatori | VatProfileTest::test_confirmation_requires_regime_and_periodicity |
| R-PROF-01 | Chi, quando, versione delle regole | VatProfileTest::test_confirmation_records_who_when_and_rule_version |
| R-OPS-01, R-SUP-02 | Solo avviso, nessun campo di selezione | VatProfileTest::test_operations_and_unsupported_cases_are_information_only |
| R-PRORATA-01 | Predefinito 100 % | VatProfileTest::test_pro_rata_defaults_to_100 |
| R-PRORATA-01 | Inferiore al 100 % ammesso, salvato esattamente | VatProfileTest::test_pro_rata_below_100_is_allowed_and_kept_exactly |
| R-PRORATA-01 | Virgola come separatore decimale | VatProfileTest::test_pro_rata_accepts_comma_over_http |
| R-PRORATA-01 | Valori non validi respinti | VatProfileTest::test_invalid_pro_rata_is_rejected |
| Immodificabilità | Anno confermato | VatProfileTest::test_confirmed_year_is_immutable |
| R-PROF-05 | Correzione come nuova versione | VatProfileTest::test_correction_creates_new_version_of_same_year_and_keeps_history |
| Idempotenza | Doppia conferma respinta | VatProfileTest::test_confirming_twice_is_rejected_and_creates_no_duplicate |
| Flusso | Flusso completo via HTTP | VatProfileTest::test_full_flow_over_http |

| Cifra di controllo | Partita IVA (ricalcolata a mano) | ValidationTest::test_partita_iva_check_digit |
| Cifra di controllo | IBAN modulo 97, ABI/CAB | ValidationTest::test_iban_mod97 |
| Dati azienda | Pagina per il titolare | CompanyProfileTest::test_page_renders_for_owner |
| Dati azienda | Salvare completamente, nome da Cognome/Nome, registro | CompanyProfileTest::test_owner_saves_full_profile |
| Dati azienda | Gli elenchi vengono sostituiti | CompanyProfileTest::test_saving_replaces_lists |
| Dati azienda | Campi obbligatori Persona vs. Società | CompanyProfileTest::test_person_needs_surname_and_name_company_needs_denominazione |
| Dati azienda | Cifra di controllo e univocità della Partita IVA | CompanyProfileTest::test_partita_iva_check_digit_and_uniqueness |
| Dati azienda | Formati (CF, SDI, CAP, Provincia, Sesso, data di nascita) | CompanyProfileTest::test_formats_are_validated |
| Dati azienda | IBAN, ATECO, e-mail/PEC | CompanyProfileTest::test_iban_ateco_and_email_are_checked |
| Dati azienda | Un solo predefinito per elenco | CompanyProfileTest::test_only_one_default_per_list |
| Dati azienda | Altrui 404, l'amministratore può | CompanyProfileTest::test_foreign_customer_gets_404_and_admin_may_edit |
| Aziende | Creazione solo con Partita IVA valida | CompanyProfileTest::test_create_requires_valid_partita_iva |

| Cifra di controllo | Codice fiscale (ricalcolato a mano, Omocodia, 11 cifre) | ValidationTest::test_codice_fiscale_check_character |
| ATECO | Elenco completo (1.290), voci note, vecchi codici respinti | AtecoTest |
| Dati azienda | Carattere di controllo del Codice fiscale | CompanyProfileTest::test_codice_fiscale_check_character |
| Dati azienda | Ricerca ATECO per codice, parola italiana e tedesca | CompanyProfileTest::test_ateco_search_endpoint |

| I02 Riferimento | FPR01 come vendita, valori dal file, originale archiviato byte per byte | ImportTest::test_official_fpr01_is_imported_as_sale_with_values_from_the_file |
| I02 Riferimento | Lo stesso file come acquisto presso il Cessionario | ImportTest::test_same_file_is_a_purchase_for_the_customer |
| I02 Riferimento | Due corpi di fattura | ImportTest::test_multiple_bodies_create_multiple_invoices |
| I02 Riferimento | FPA12 | ImportTest::test_official_fpa_examples_are_supported |
| I02 Riferimento | Errore di schema spiegato (FPR02) | ImportTest::test_schema_violation_is_rejected_with_explanation |
| I02 Duplicati | Stesso file | ImportTest::test_same_file_twice_is_a_duplicate |
| I02 Duplicati | Stessa fattura, altro file | ImportTest::test_same_invoice_in_another_file_is_a_duplicate |
| I02 Duplicati | Stessa identità, contenuto diverso | ImportTest::test_same_identity_with_different_content_is_held_for_review |
| I02 Duplicati | Indice del database contro gli import paralleli | ImportTest::test_database_rejects_duplicate_identity_and_content |
| I02 Duplicati | Stesso numero, altro fornitore | ImportTest::test_same_number_from_different_suppliers_is_not_a_duplicate |
| I02 Avvisi | Valuta estera, importo totale | ImportTest::test_foreign_currency_and_total_mismatch_are_flagged |
| I02 Avvisi | Senza avvisi con regime confermato | ImportTest::test_invoice_without_issues_when_regime_is_confirmed |
| I02 Codici | Tipo documento e Natura dallo schema | ImportTest::test_document_types_and_natura_come_from_the_schema |
| I02 Sicurezza | XXE/DOCTYPE respinti | ImportTest::test_xxe_and_doctype_are_rejected |
| I02 Sicurezza | XML danneggiato, formato errato | ImportTest::test_invalid_xml_and_wrong_format_are_rejected |
| I02 Sicurezza | Azienda estranea nel file | ImportTest::test_file_for_another_company_is_rejected |
| I02 P7M | DER e Base64 | ImportTest::test_signed_p7m_der_and_base64_are_imported |
| I02 P7M | Firma manipolata | ImportTest::test_tampered_p7m_is_rejected |
| I02 Upload | Flusso, pulizia, registro | ImportTest::test_upload_runs_the_import_and_cleans_up |
| I02 ZIP | Percorsi, tipi estranei, annidati, __MACOSX | ImportTest::test_zip_is_expanded_safely |
| I02 ZIP | Troppe voci | ImportTest::test_zip_with_too_many_entries_is_rejected |
| I02 Upload | Tipo di file, numero | ImportTest::test_upload_validation |
| I02 Job | Voci altrui nell'esecuzione | ImportTest::test_job_ignores_items_of_another_company |
| I02 Separazione | Esecuzione, fattura, download, upload altrui → 404 | ImportTest::test_other_customer_cannot_see_or_download |
| I02 Download | Originale byte per byte, registro, amministratore | ImportTest::test_download_returns_the_original_and_is_logged |
| I02 Elenchi | Direzione, ricerca, anno, stato | ImportTest::test_invoice_list_filters |
| Importi | Decimali senza float, nessun arrotondamento silenzioso | DecimalTest |

| I03 Conti | Modello con conti predefiniti | RegistrationTest::test_new_company_gets_simple_chart_of_accounts |
| I03 Registrazione | Acquisto con protocollo, periodo, detraibile | RegistrationTest::test_purchase_is_registered_with_protocol_period_and_deductible_vat |
| I03 Registrazione | Detrazione parziale, arrotondamento al centesimo | RegistrationTest::test_partial_deduction_is_rounded_to_cents |
| I03 Registrazione | Protocolli separati, Vendite senza detrazione | RegistrationTest::test_sales_have_their_own_protocol_sequence_and_no_deduction |
| I03 Registrazione | Trimestri con trimestrale | RegistrationTest::test_quarterly_regime_uses_quarters |
| I03 Registrazione | Campi obbligatori e limiti | RegistrationTest::test_validation_rules |
| I03 Registrazione | Conti altrui e inattivi | RegistrationTest::test_foreign_and_inactive_accounts_are_rejected |
| I03 Registrazione | Confermare gli avvisi di verifica | RegistrationTest::test_issues_must_be_acknowledged |
| I03 Registrazione | L'avviso sul regime cade dopo la conferma | RegistrationTest::test_regime_issue_from_import_is_resolved_once_regime_exists |
| I03 Registrazione | Valuta estera bloccata | RegistrationTest::test_foreign_currency_is_blocked |
| I03 Registrazione | Nota di credito | RegistrationTest::test_credit_note_reduces_with_negative_sign |
| I03 Registrazione | Autofattura: scegliere la direzione | RegistrationTest::test_autofattura_requires_direction |
| I03 Registrazione | Nessuna doppia registrazione | RegistrationTest::test_double_registration_is_impossible |
| I03 Registrazione | Non modificabile | RegistrationTest::test_registration_is_immutable |
| I03 Storno | Controregistrazione nel periodo della data dello storno, fattura di nuovo aperta | RegistrationTest::test_reversal_goes_into_the_period_of_its_own_date |
| I03 Storno | Data scelta, protocollo dell'anno dello storno, anno senza regime | RegistrationTest::test_reversal_with_chosen_date_uses_that_period_and_year_sequence |
| I03 Scarto | Scarta e ripristina | RegistrationTest::test_discard_and_restore |
| I03 Modalità di verifica | Documento successivo | RegistrationTest::test_verification_mode_goes_to_next_document |
| I03 Proposte | Conto predefinito senza cronologia | RegistrationTest::test_default_account_is_suggested_without_history |
| I03 Proposte | Conto della controparte, anni | RegistrationTest::test_invoice_page_offers_suggestions |
| I03 Separazione | Registrare, Storno, Scarto altrui → 404 | RegistrationTest::test_other_customer_cannot_register_reverse_or_discard |
| Tabella ATECO | Lettura e ricerca | TablesTest::test_ateco_table_is_readable_by_customers_and_searchable |
| Tabella ATECO | Solo l'amministratore modifica | TablesTest::test_only_admin_can_change_ateco_codes |
| Tabella ATECO | Codice disattivato non selezionabile | TablesTest::test_deactivated_ateco_code_cannot_be_chosen |

| I04 Totali | Registro = somma delle registrazioni, per aliquota/Natura | VatRegisterTest::test_purchase_register_totals_equal_the_sum_of_registrations |
| I04 Filtri | Periodo, anno intero, anno vuoto | VatRegisterTest::test_period_filter_and_whole_year |
| I04 Storno | Periodo proprio, anno netto zero | VatRegisterTest::test_reversal_nets_to_zero_in_its_own_period |
| I04 Nota di credito | Negativa | VatRegisterTest::test_credit_note_is_negative |
| I04 Integrazioni | TD17 in Acquisti e Vendite | VatRegisterTest::test_sales_register_contains_sales_and_reverse_charge_integrations |
| I04 Pagina | Le righe collegano il documento, periodi secondo il regime | VatRegisterTest::test_page_rows_link_to_invoices |
| I04 CSV | BOM, punto e virgola, virgola decimale, formula neutralizzata, registro | VatRegisterTest::test_csv_export_for_excel |
| I04 PDF | PDF valido | VatRegisterTest::test_pdf_export |
| I04 Separazione | Azienda altrui 404 (pagina, CSV, PDF), registro sconosciuto 404 | VatRegisterTest::test_other_customer_cannot_see_registers |

| Piano dei conti 2026 | Modello semplificata (158), senza conti dell'ordinaria, Rappresentanza 0 %/Omaggi 100 %, Sezione, tipi, conti predefiniti | TablesTest::test_new_company_gets_model_2026_with_fiscal_settings |
| Piano dei conti 2026 | Creare, modificare, eliminare con Impostazioni fiscali | TablesTest::test_account_can_be_added_with_fiscal_settings_and_edited |
| Piano dei conti 2026 | Validazione (codice, tipo, percentuale, Voce CEE) | TablesTest::test_account_validation |
| Piano dei conti 2026 | Conto predefinito attivo e rilevante ai fini IVA | TablesTest::test_default_account_rules |
| Piano dei conti 2026 | Conto usato: non eliminabile, tipo fisso | TablesTest::test_used_account_cannot_be_deleted_or_change_type |
| Piano dei conti 2026 | Applicare il modello; ordinaria salvata ma bloccata | TablesTest::test_model_can_be_applied_to_an_existing_chart |
| Piano dei conti professionisti | Modello quadro RE (58), conti predefiniti, limiti, assegnazione per Tipologia | TablesTest::test_professional_gets_dedicated_model_for_quadro_re |
| Parametri fiscali | Tabelle | TablesTest::test_fiscal_parameters_table |
| Piano dei conti 2026 | Fuori campo non registrabile, % IVA come proposta | RegistrationTest::test_account_outside_vat_scope_is_rejected_and_hidden |

| Registrazione | Data di ricezione = data della fattura come proposta | RegistrationTest::test_receipt_date_is_proposed_as_invoice_date |
| Fattura manuale | Modulo con Tipi documento e Nature | ManualInvoiceTest::test_form_lists_document_types_and_nature |
| Fattura manuale | Rilevare con PDF, registrare, documento nella registrazione, registro, CSV, download | ManualInvoiceTest::test_manual_purchase_with_attachment_is_registered_with_its_document |
| Fattura manuale | Vendita e Nota di credito, allegato facoltativo | ManualInvoiceTest::test_manual_sale_and_credit_note |
| Fattura manuale | Validazione (Imposta, Natura, importi, tipo, data, tipo di file, duplicato) | ManualInvoiceTest::test_validation |
| Fattura manuale | Integrare l'allegato fino alla registrazione | ManualInvoiceTest::test_attachment_can_be_added_until_registration |
| Fattura manuale | Azienda altrui 404 | ManualInvoiceTest::test_other_customer_gets_404 |
| Fuori campo IVA | Protocollo, elenco, somme, allegato, non nei Registri IVA | NonVatEntryTest::test_entries_are_numbered_listed_and_kept_out_of_vat_registers |
| Fuori campo IVA | Validazione | NonVatEntryTest::test_validation |
| Fuori campo IVA | Storno, immodificabilità | NonVatEntryTest::test_reversal_and_immutability |
| Fuori campo IVA | Azienda altrui 404 | NonVatEntryTest::test_other_customer_gets_404 |

| Modifica | Modificare la registrazione, il protocollo resta, registro prima/dopo, modifica non valida senza effetto | EditDeleteTest::test_registration_can_be_changed_keeping_its_protocol |
| Eliminazione | Eliminare la registrazione, registrare di nuovo | EditDeleteTest::test_registration_can_be_deleted_and_invoice_registered_again |
| Modifica/Eliminazione | Storno e registrazione stornata bloccati | EditDeleteTest::test_reversed_registrations_cannot_be_changed_or_deleted_alone |
| Eliminazione | Fattura importata con registrazioni e documento, reimportabile | EditDeleteTest::test_imported_invoice_is_deleted_with_registrations_and_can_be_imported_again |
| Fattura manuale | Modificare (duplicato, sostituire/rimuovere allegato) ed eliminare | EditDeleteTest::test_manual_invoice_can_be_edited_and_deleted_before_registration |
| Fattura manuale | Modifica dopo la registrazione: registrazione ricalcolata; numero di righe diverso respinto | EditDeleteTest::test_registered_manual_invoice_edit_updates_registration |
| Modifica/Eliminazione | Fattura importata non modificabile, azienda altrui 404 | EditDeleteTest::test_imported_invoice_cannot_be_edited_and_strangers_get_404 |

| Briefing 01 | Avviso sul regime per ogni anno visualizzato | BriefingTest::test_01_regime_badge_follows_displayed_year |
| Briefing 03 | Quota vuota o non valida respinta | BriefingTest::test_03_empty_or_out_of_range_percentage_is_rejected |
| Briefing 04 | Ripartizione su due conti, 1 centesimo di differenza impedisce la registrazione | BriefingTest::test_04_group_split_on_two_accounts_and_exact_residual |
| Briefing 04 | Ripartizione per Nota di credito con segno | BriefingTest::test_04_split_credit_note_reduces_with_correct_sign |
| Briefing 05 | Anteprima = registrazione (26,40 / 6,60), NC-12 negativa | BriefingTest::test_05_preview_uses_the_same_prepared_lines_as_the_booking |
| Briefing 06 | Stesso requestId idempotente, contenuto diverso 409, seconda sessione 409 | BriefingTest::test_06_same_request_id_is_idempotent_and_conflicts_are_reported |
| Briefing 06 | Errore prima del commit: nulla salvato, ripetizione possibile | BriefingTest::test_06_error_before_commit_leaves_nothing_and_allows_retry_with_same_id |
| Briefing 06 | Nuova registrazione dopo storno collegata | BriefingTest::test_06_reregistration_after_reversal_is_linked |
| Briefing 07 | Conferma JSON, flash, link al registro, ritorno | BriefingTest::test_07_json_confirmation_and_flash_for_next_page |

| Briefing 08 | Reimport: per ogni documento «già presente», riepilogo | ImportTest::test_08_reimport_reports_each_document_as_already_present |
| Briefing 08 | Copia formattata diversamente non è un nuovo documento | ImportTest::test_08_reformatted_copy_is_not_a_new_document |
| Briefing 08 | Importo divergente: conflitto, pagina di confronto, azienda altrui 404 | ImportTest::test_08_different_amount_is_a_conflict_with_comparison |
| Briefing 08 | File con più corpi: risultato per corpo | ImportTest::test_08_multi_body_file_reports_each_body |
| Briefing 08 | Altra azienda (controparti) e file illeggibile | ImportTest::test_08_foreign_company_and_unreadable_file_are_explained |
| Briefing 09 | Memorizzare la regola, proposta solo per la posizione corrispondente, altra azienda non toccata, solo conto | BriefingTest::test_09_confirmed_rule_suggests_account_for_matching_lines_only |
| Briefing 09 | Più regole → selezione; gruppo misto → ripartizione | BriefingTest::test_09_multiple_rules_let_the_user_choose_and_mixed_group_is_split |
| Briefing 09 | Pagina delle regole, eliminazione, azienda altrui 404 | BriefingTest::test_09_rules_page_and_tenancy |

| Scadenziario 1 | Fattura di dicembre 1000 + 220, due pagamenti da 610 (dic/gen): 500 Ricavo per anno, 610 aperti al 31/12 | ScadenziarioTest::test_case1_december_invoice_two_payments_split_across_years |
| Scadenziario 2 | Fattura del fornitore registrata a dicembre, pagata a gennaio: aperta a fine anno, costi a gennaio | ScadenziarioTest::test_case2_supplier_invoice_registered_december_paid_january_cash |
| Scadenziario 3 | Presunzione: registrata a dicembre, pagata a gennaio: aperta a fine anno, nessun secondo costo | ScadenziarioTest::test_case3_presunzione_no_second_expense |
| Scadenziario 4 | Parcella 1000, Ritenuta 200, banca 800: residuo 0, movimento 800, Compenso 1000 | ScadenziarioTest::test_case4_honorarnote_with_ritenuta |
| Scadenziario 5 | Sostituto paga a dicembre, accredito a gennaio: entrambe le date, assegnazione a dicembre con motivazione | ScadenziarioTest::test_case5_professionista_sostituto_pays_december_credited_january |
| Scadenziario 6 | Tre rate, una pagata in parte: residui propri, solo la rata scaduta tra le Scadute | ScadenziarioTest::test_case6_three_rates_one_partial_only_due_rate_is_overdue |
| Scadenziario 7 | La Nota di credito riduce senza accredito bancario; lo storno riapre il residuo e corregge l'imposta | ScadenziarioTest::test_case7_credit_note_and_payment_storno |
| Scadenziario 8 | Ripetizione senza duplicato; nessuna compensazione tra aziende | ScadenziarioTest::test_case8_idempotency_and_tenancy, test_http_payment_double_submit_and_tenancy |
| Scadenziario | Bonifico cumulativo 1000 = 600 + 400, pagamento in eccesso «Importo da abbinare» | ScadenziarioTest::test_bulk_transfer_and_overpayment |
| Scadenziario | Pagine, filtri per data incasso, data di riferimento 31/12, Riepilogo fiscale, CSV | ScadenziarioTest::test_http_views_csv_and_cutoff |
| Scadenziario | Modificare le rate senza scendere sotto gli importi pagati, confermare lo Stato pagamento | ScadenziarioTest::test_installment_edit_protects_paid_amounts_and_migration_flag |
| Scadenziario | Nessun profilo confermato → Da verificare, dopo la conferma per cassa | ScadenziarioTest::test_unconfirmed_profile_marks_tax_effect_to_verify |

| Ticket 11 | Gennaio a debito: 4.400 − 1.100 − 300 = 3.000, F24 6001/2026, Scadenza 16/02 | LiquidazioniTest::test_january_debit_with_previous_credit_and_f24_6001 |
| Ticket 11 | 3° trimestre per opzione: 2.000 + 20 interessi, F24 6033 2.020; speciali senza interessi, Q4 6034/6099 | LiquidazioniTest::test_third_quarter_option_interest_and_6033 |
| Ticket 11 | Piccolo debito 80 rinviato, +70 → 150; esattamente 100 rinviato; Novembre nessun Rinvio (16/12) | LiquidazioniTest::test_small_debts_deferral_threshold_and_year_end |
| Ticket 11 | Credito febbraio 1.400 → marzo; nessun credito TR da un mese | LiquidazioniTest::test_monthly_credit_carried_to_march_without_tr |
| Ticket 11 | Soglia TR: 2.400 + Riporto 1.000 non richiedibile; 2.582,28 no, 2.582,29 sì | LiquidazioniTest::test_tr_threshold_excludes_previous_carry_and_is_strict |
| Ticket 11 | Destinazione mista 4.000/3.000/3.000; 4.000 nel periodo successivo | LiquidazioniTest::test_mixed_destination_of_annual_credit |
| Ticket 11 | F24 a saldo zero (3.000 − 3.000), Scadenza resta aperta fino a Presentato | LiquidazioniTest::test_f24_balance_zero_keeps_deadline_open, test_http_pages_pdfs_permissions_and_double_click |
| Ticket 11 | Correzione: lo Storno riduce il credito già destinato; differenza, periodi, Deleghe; Originale conservati | LiquidazioniTest::test_correction_identifies_affected_periods_and_keeps_originals |
| Ticket 11 | Concorrenza: doppio clic una Liquidazione; due Deleghe non la stessa Quota | LiquidazioniTest::test_concurrency_double_confirm_and_competing_f24 |
| Ticket 11 | TD17 220 € / 50 %: 220 debito, 110 detraibile; split payment; IVA per cassa Da verificare; Pro rata una sola volta | LiquidazioniTest::test_td17_reverse_charge_split_payment_and_pro_rata |
| Ticket 11 | L'impostazione mancante nomina il campo | LiquidazioniTest::test_setup_issue_names_missing_field |
| Ticket 11 | Pagina Destinazione, Pratica TR, Presentazione, Esito | LiquidazioniTest::test_credit_destination_page_and_tr_request |

| Flusso 1 | Archiviare, reimport, Rielabora, Ripristina | CoreWorkflowTest::test_01_archive_reimport_reprocess_and_restore |
| Flusso 2 | File identico byte per byte rielaborato, riga N2.2 mancante una volta | CoreWorkflowTest::test_02_byte_identical_xml_reprocessed_after_importer_fix |
| Flusso 3 | 1000 → 1100 con 600 pagati, residuo 500; periodo bloccato | CoreWorkflowTest::test_03_correction_1000_to_1100_with_600_paid_and_locked_period |
| Flusso 4 | Stesso file due volte nel lotto: 2 documenti, rate una volta | CoreWorkflowTest::test_04_same_batch_file_twice_no_duplicates |
| Flusso 5 | Lente e ritorno all'elenco | CoreWorkflowTest::test_05_magnifier_opens_invoice_and_returns_to_list_state |
| Flusso 5b/5c | Nessuna offerta di integrazione per le proprie vendite RC; TD16, assegnazione dell'import, storno su entrambi i lati | CoreWorkflowTest::test_05b_…, test_05c_… |
| Flusso 6 | Fattura 28.12.2026, ricezione 04.01.2027 sotto il 2027 | CoreWorkflowTest::test_06_invoice_received_in_new_year_listed_under_receipt_year |
| Flusso 7 | Cinque righe N2.2 da 20 € → cinque righe, 100, IVA 0 | CoreWorkflowTest::test_07_five_n22_lines_give_five_register_rows |
| Flusso 8 | 1000 @22 % + N2.2 100 → due righe, IVA 220, 1320 | CoreWorkflowTest::test_08_mixed_22_and_n22_gives_two_rows |
| Flusso 9 | Servizio tedesco tramite PDF, TD17 22 %/50 %, termine 15.12., XML caricato due volte | CoreWorkflowTest::test_09_foreign_service_pdf_integration_td17 |
| Flusso 10 | Non rilevante 5.000 escluso, 5.001 con Natura; errore di campo nell'XML | CoreWorkflowTest::test_10_…, test_10b_xml_errors_point_to_fields |
| Flusso 11 | Crediti «Tutti»: Riporto conteggiato una volta | CoreWorkflowTest::test_11_transferred_credit_counted_once_with_all_years |

| Emissione 1 | Numero, FatturaPA valida, registrazione, rate | IssuingTest::test_issue_creates_numbered_valid_registered_invoice_with_installments |
| Emissione 2 | Rollback senza numero consumato | IssuingTest::test_failure_rolls_back_without_consuming_a_number |
| Emissione 3 | Dati azienda mancanti, data anteriore all'ultima fattura | IssuingTest::test_blocks_when_company_data_missing_and_date_before_last_invoice |
| Emissione 4 | Bollo, Natura, Riferimento normativo | IssuingTest::test_exempt_line_with_bollo_and_natura_group |
| Emissione 5 | PA: FPA12, Scissione, importo netto | IssuingTest::test_public_administration_split_payment_uses_fpa12_and_net_payable |
| Emissione 6 | Estero XXXXXXX, Nota di credito | IssuingTest::test_foreign_customer_gets_xxxxxxx_and_credit_note_has_no_payment |
| Emissione 7 | Reimport del proprio file = «già presente» | IssuingTest::test_reimporting_the_issued_xml_is_recognized_as_already_present |
| Emissione 8 | Cassa + Ritenuta da Dati azienda, XML e importo da pagare | IssuingTest::test_cassa_and_ritenuta_from_company_settings |
| Emissione 9 | Impostazioni solo su richiesta, somma delle rate errata rifiutata | IssuingTest::test_settings_are_only_applied_when_requested_and_wrong_payment_sum_is_rejected |
| Emissione 10 | Due Casse con Massimale e Natura | IssuingTest::test_two_casse_with_cap_and_two_blocks_in_xml |
| Emissione 11 | Sconto di riga, somme esatte | IssuingTest::test_line_discount_and_exact_totals |
| Emissione 12 | Acconto TD02 e detrazione nella fattura a saldo | IssuingTest::test_acconto_td02_then_final_invoice_deducts_it |
| Emissione 13 | Endpoint di anteprima, salvare le impostazioni in Dati azienda | IssuingTest::test_quote_endpoint_and_company_settings_page_roundtrip |

## Non automatizzato

- Conferma parallela da due processi, vero import parallelo da due processi (protetto tramite indici univoci), test di carico con grandi archivi ZIP, MySQL/MariaDB, screen reader, zoom al 200 %.
| Haben 1.2 | Catena per azienda; manomissione, eliminazione a metà, dati preesistenti senza hash | AuditChainTest |
| Haben 1.3 | ZIP con checksum SHA-256 validi, contenuti, voce di audit | ArchiveTest |
| Haben 2.1 | Creazione e validazione, «Emetti ora» con segnaposto e aggiornamento, giorno di ancoraggio, esecuzione automatica ed errori, fine | RecurringInvoiceTest |
| Haben 2.2 | Precompilazione, emissione con riferimento, collegamento, solo fatture emesse | CreditNoteTest |
| Haben 5.2 | Normalizzazione, valido con numero di richiesta, non valido/non disponibile/non raggiungibile, cifra di controllo IT | ViesTest |
| Haben 9.4 | Risultati per tipo, maiuscole/minuscole, lunghezza minima, segnaposto, separazione dei clienti | QuickSearchTest |
| Haben 2.5–2.7 | Articoli CRUD e validazione, testi, unità, accesso altrui, dati del modulo | CatalogTest |
| Anteprima/PDF 1 | HTML e PDF dall'XML, voce di audit | InvoicePdfTest::test_html_preview_and_pdf_come_from_the_xml |
| Anteprima/PDF 2 | Ritenuta/Cassa/riferimenti letti dall'XML | InvoicePdfTest::test_view_model_reads_ritenuta_cassa_bollo_and_credit_note_link |
| Anteprima/PDF 3 | Anteprima prima dell'emissione senza numero, errori come nell'emissione | InvoicePdfTest::test_draft_preview_does_not_issue_and_reports_errors |
| Anteprima/PDF 4 | Accesso solo all'azienda, solo con XML | InvoicePdfTest::test_access_is_limited_to_the_company_and_to_invoices_with_xml |
| Emissione 8 | Libero professionista/persona privata: Nome/Cognome in Anagrafica, valido rispetto allo schema | IssuingTest::test_professional_issuer_and_private_customer_produce_schema_valid_xml |
| Anno 1 | Anno predefinito, il cambio vale ovunque, ?anno sceglie in modo centrale, tutti no | ActiveYearTest::test_default_year_is_injected_and_switch_applies_everywhere |
| Anno 2 | Aree della Panoramica e analisi seguono l'anno | ActiveYearTest::test_dashboard_areas_and_analysis_follow_the_active_year |
| Anagrafiche | Verifica, duplicato, ruoli, ricerca, isolamento, import, modulo | PartnerTest |
| Fatture elettroniche | Elenco, ZIP XML+PDF, stato con regole sulle date, isolamento | ElectronicInvoiceTest |
| Logo/scala di grigi | Upload ricodificato, logo grigio, nessuna tonalità di verde | InvoicePdfTest::test_logo_upload_is_reencoded_shown_on_preview_pdf_and_document_is_grayscale |
| Elimina anno | Password, dati dell'anno eliminati, altri anni restano, numeri, rate limit | YearDeleteTest |
| Backup | ZIP con checksum, senza account, accesso altrui | BackupTest |
| Parametri per anno | Vista annuale, copia, modifica, riferimento protetto | FiscalParametersYearTest |
| Opzioni | Moduli disattivati: nascosti, bloccati, di nuovo attivi; fonte data di pagamento | CompanyOptionsTest |
| Data di pagamento nel registro | Colonna dallo Scadenziario o dalla registrazione, CSV, PDF | CompanyOptionsTest::test_register_shows_payment_date_from_scadenziario_or_registration |
| Stampa | Pagina, PDF di Registro/Liquidazione, Registro incassi e pagamenti solo Professionisti | PrintTest |
| Calendario fiscale | 2027/2028, slittamento al giorno lavorativo, Lunedì dell'Angelo, pagina | FiscalCalendarTest, FiscalCalendarPageTest |
| Import | Sostituire, registrati non sostituiti/eliminati, eliminare il file e reimportare, verifica disattivata | ImportReplaceTest |
| Elenchi | Flag has_xml/deletable, ZIP/CSV/Elimina, Fuori campo ZIP/CSV | BulkListsTest |
| Anno non selezionabile | anno eliminato, la ricreazione lo rende selezionabile | YearDeleteTest, ActiveYearTest |
| Ripristino | sostituire, nuova azienda, checksum/percorso/tabella/colonna, azienda altrui, upload | BackupRestoreTest |
| LIPE | Trimestre mensile: due Moduli (gennaio, marzo), febbraio senza movimento omesso; VP2/VP3/VP4/VP14, struttura XML e importi con la virgola | LipeTest::test_monthly_quarter_builds_xml_with_modules_and_skips_empty_months |
| LIPE | Periodo non confermato o da riesaminare blocca l'XML (422), la stampa di controllo PDF funziona | LipeTest::test_unconfirmed_period_blocks_xml_and_review_blocks |
| LIPE | Trimestrale: Modulo `Trimestre`, IVA a credito, download e audit | LipeTest::test_quarterly_credit_module_and_download_route |
| LIPE | Scadenze 31.5., 16.9., 30.11., fine febbraio (giorno lavorativo) | LipeTest::test_deadlines_follow_the_calendar |
| Conto economico | Professionista: Competenza 1.000/500, Cassa 500 (pagamento parziale), resto nell'anno successivo; fiscale = cassa | IncomeStatementTest::test_professionista_views_competenza_cassa_fiscale |
| Conto economico | Professionista forfettario fiscalmente per cassa | IncomeStatementTest::test_professionista_forfettario_is_cash_basis |
| Conto economico | Semplificata per competenza: fiscale alla data di registrazione, Cassa senza pagamento 0 | IncomeStatementTest::test_semplificata_competenza_fiscal_uses_registration_date |
| Conto economico | Fuori campo in tutte le viste, senza regime «Da verificare»; pagina, CSV, PDF, azienda altrui 404 | IncomeStatementTest::test_non_vat_entries_pending_and_downloads |
| R-LIQ-11 | Regole 2027/2028 presenti (versione, scadenza 16/02/2027), 2029 no | LiquidazioniTest::test_rules_exist_for_2027_and_2028_but_not_beyond |
| Cespiti 1 + 3 | Materiale, primo anno metà limite fiscale, poi ordinario | CespitiTest::test_material_first_year_half_fiscal_and_ordinary_quota |
| Cespiti 2 | Immateriale senza dimezzamento; D.M. non per Immateriali | CespitiTest::test_intangible_has_no_automatic_half_rate |
| Cespiti 4 | Quota ridotta | CespitiTest::test_reduced_quota |
| Cespiti 5 | Civile/fiscale, aliquota oltre il coefficiente | CespitiTest::test_civil_fiscal_difference_and_rate_above_limit |
| Cespiti 6 | Completamente ammortizzato | CespitiTest::test_fully_depreciated |
| Cespiti 7 | Import Saldi (parzialmente ammortizzato) | CespitiTest::test_import_partially_depreciated_asset_opening_balances |
| Cespiti 8 | Import Storico annuale | CespitiTest::test_import_full_annual_history |
| Cespiti 9 | Incremento | CespitiTest::test_increment_updates_future_plan |
| Cespiti 10 | Modifica di aliquota solo per il futuro | CespitiTest::test_future_rate_change_keeps_past |
| Cespiti 11 | Rivalutazione non riconosciuta fiscalmente | CespitiTest::test_revaluation_not_fiscally_recognized |
| Cespiti 12 | Vendita con Plusvalenza, registrazione | CespitiTest::test_sale_with_gain_and_journal |
| Cespiti 13 | Vendita con Minusvalenza | CespitiTest::test_sale_with_loss |
| Cespiti 14 | Eliminazione con valore residuo | CespitiTest::test_elimination_with_residual |
| Cespiti 15 | Alienazione parziale | CespitiTest::test_partial_alienation |
| Cespiti 16 + 17 | Quadratura Prima nota, nessuna doppia registrazione; Storno e ricalcolo | CespitiTest::test_journal_balances_and_no_double_posting, test_reverse_run_and_recalculate |
| Cespiti 18 | Esercizio chiuso | CespitiTest::test_closed_year_blocks_recalculation_and_movements |
| Cespiti 16 + 19 | Registro, Riconciliazione senza differenza, PDF/CSV di tutte le Stampe | CespitiTest::test_register_print_and_reconciliation |
| Cespiti 20 | Import con errori/duplicati | CespitiTest::test_import_with_errors_and_duplicates |
| Cespiti + | Arrotondamento, piano mai oltre il costo | CespitiTest::test_rounding_and_plan_never_exceeds_cost |
| Cespiti + | Modesto valore, deducibilità limitata | CespitiTest::test_low_value_and_limited_deductibility |
| Cespiti + | Pagine, HTTP, diritti | CespitiTest::test_pages_render_and_http_flow |
| Cespiti + | D.M.: Attività non precedentemente specificate (Gruppo 23, Specie 1/2), XVIII VI–IX, XXII/II | CespitiTest::test_dm_table_contains_unnumbered_last_section |
| Cespiti + | Correzione di una tabella D.M. importata (gli ID restano, riferimenti) | CespitiTest::test_dm_resync_keeps_ids_and_moves_misattributed_rows |
| Cespiti + | Quota ridotta al Calcolo, per categoria, mai Immateriali | CespitiTest::test_reduced_quota, CespitiTest::test_reduced_quota_per_category_and_never_intangible |
| Cespiti + | Progressivi nel modulo (acquisto 2000, Fondo 2025) | CespitiTest::test_manual_opening_balance_in_form |
| Cespiti + | Maggiorazione: Calcolo, Stampa K, Conto economico fiscale | CespitiTest::test_extra_deduction_calculated_and_in_income_statement |

## Richieste del 24.11.2026 (Decisione n. 101)

| Caso | Atteso | Test |
|---|---|---|
| Notifiche | JSON con attività e scadenze solo per i membri dell'azienda | RequestsNov24Test::test_notifications_return_tasks_and_deadlines_only_for_members |
| Account | E-mail e password cambiano solo con la password attuale | RequestsNov24Test::test_user_changes_email_and_password_with_current_password |
| Utenti | L'amministratore crea utenti e assegna/toglie aziende; il cliente no | RequestsNov24Test::test_admin_creates_user_with_companies_and_customer_cannot |
| Cliente privato | Privato italiano senza partita IVA, basta il codice fiscale | RequestsNov24Test::test_private_customer_in_italy_needs_no_vat_number |
| Conto economico | Cinque anni di confronto, anno senza dati non applicato | RequestsNov24Test::test_income_statement_offers_previous_years_for_comparison |
| Cespiti | Civilistico = aliquota fiscale anche con categoria diversa | CespitiTest::test_civil_always_follows_fiscal_rate |
