# Dati di riferimento

## ateco_2025.csv

Codici ATECO 2025, livello sottocategoria (6 cifre, `NN.NN.NN`), con titolo italiano.

- Fonte: ISTAT, «Struttura ATECO 2025 IT-EN-DE» (`StrutturaATECO-2025-IT-EN-DE.xlsx`), https://www.istat.it/classificazione/ateco-2025/
- Scaricato il 18.11.2026. Ripreso solo il livello 6 (1.290 codici), colonne `CODICE_ATECO_2025` e `TITOLO_ITALIANO_ATECO_2025`.
- Licenza: Creative Commons Attribuzione 4.0 (CC BY 4.0), secondo le note legali di ISTAT. Attribuzione: «Fonte: ISTAT – Classificazione ATECO 2025».
- ISTAT ha pubblicato a luglio 2026 tabelle di raccordo aggiornate (ATECO 2022 → 2025). In caso di nuova versione della struttura, sostituire questo file ed eseguire i test.

## piano_conti_ordinaria_2026.csv

Modello di piano dei conti per la contabilità ordinaria (solo conto economico, schema art. 2425 c.c.) con indicazioni fiscali indicative, 189 conti. Conservato per uso futuro; nell'applicazione è selezionabile solo quando la contabilità ordinaria sarà supportata.

- Fonte: file «Piano_dei_conti_con_indicazioni_fiscali_2026.xlsx» (18.11.2026), foglio «Piano conti CE». Colonne riprese invariate (`descrizione`, `voce_cee`, `categoria`, `deducibilita_nota`, `irap_nota`, `iva_nota`, `limite`, `note`, `normativa`).
- Derivati, non presenti nella fonte: `tipo` (ricavo per A1–A5, C15, C16, D18 e per 804001 Utili su cambi e 999001 Utile; altrimenti costo), `iva_rilevante` (0 per «Fuori campo» e «IVA in genere non applicabile»), `iva_detraibile_pct` (40 per «Generalmente 40%»/«40% in uso promiscuo», 100 per «Generalmente 100% se inerente»/«100% se esclusivo/inerente», altrimenti vuoto), `deducibilita_pct` (solo per indicazioni univoche come «80%», «20%», «0%», «100% se inerente», «75% / regole speciali»).
- Secondo la legenda della fonte le percentuali sono indicative (stato novembre 2026) e non sono formule di calcolo; vanno verificate caso per caso.

## parametri_fiscali_2026.csv

Foglio «Parametri fiscali» dello stesso file (10 righe: Ambito, Deducibilità, IVA indicativa, Limiti, Riferimento, Fonte URL), invariato.

## piano_conti_semplificata_2026.csv

Modello standard per le nuove aziende, derivato da `piano_conti_ordinaria_2026.csv`, 158 conti.

- Rimossi (sensati solo in contabilità ordinaria): voci A2, A3, A4 (Rimanenze, Lavori in corso, Capitalizzazioni), B10c, B10d (Svalutazioni), B11 (Variazione rimanenze), B12, B13 (Accantonamenti), C15 (Dividendi), D18, D19 (Rettifiche finanziarie), 21 (Utile/Perdita), nonché 951001 IRES, 951003 Imposte anni precedenti, 951004/951005 Imposte differite/anticipate. L'IRAP corrente resta.
- Nuova colonna `sezione` (Ricavi, Altri proventi, Proventi finanziari, Acquisti, Servizi, Godimento beni di terzi, Personale, Ammortamenti, Oneri diversi, Oneri finanziari, Imposte), derivata dalla voce CEE; 804001 Utili su cambi → Proventi finanziari, 804002 Perdite su cambi → Oneri finanziari. L'assegnazione ai righi del quadro RG seguirà con i dati della dichiarazione.
- Rappresentanza (art. 19-bis1 c.1 lett. h DPR 633/72): `iva_detraibile_pct` 0 per 60201805 e 60201902–60201906, 100 per 60201901 (omaggi fino a 50 euro); norma aggiunta in `normativa`.

## piano_conti_professionisti_2026.csv

Modello per la contabilità professionisti (lavoro autonomo, quadro RE, art. 54 TUIR), 58 conti, creato il 19.11.2026. Le nuove aziende con tipologia «Professionista» lo ricevono automaticamente.

- Struttura secondo le categorie di costo dell'art. 54 TUIR (Compensi, Beni strumentali, Studio, Personale, Compensi a terzi, Consumi, Vitto e alloggio, Rappresentanza, Formazione, Autovettura, Interessi, Altre spese, Imposte, Non nel quadro RE). Colonna `sezione`; nessuna voce CEE e nessuna assegnazione ai righi del quadro RE (seguirà con i dati della dichiarazione).
- Indicazioni fiscali dove la norma è univoca: Vitto e alloggio 75 % con limite 2 % dei compensi; Rappresentanza limite 1 % dei compensi, IVA 0 % (omaggi fino a 50 euro 100 %, art. 19-bis1 c.1 lett. h DPR 633/72); Formazione 100 % fino a 10.000 euro, certificazione delle competenze fino a 5.000 euro; Beni strumentali fino a 516,46 euro nell'anno del pagamento; immobile promiscuo 50 %; Autovettura 20 % con limiti art. 164 TUIR e IVA 40 %; Telefonia 80 %. Contributi previdenziali propri: non nel quadro RE (onere deducibile, art. 10 TUIR).
- Chiarito dal punto di vista fiscale (19.11.2026): il contributo integrativo delle casse private non è ricavo né reddito (711003, sezione «Non concorrono al reddito», solo per la registrazione IVA); solo la rivalsa INPS 4 % della gestione separata è reddito (711002). I rimborsi spese analitici in fattura non sono reddito (711004); i costi riaddebitati sono in 641010 (non deducibile). L'IRAP non è dovuta dalle persone fisiche (professionisti, ditte individuali): nessun conto IRAP, indicazione IRAP «Non dovuta (persona fisica)». In contabilità semplificata solo le società conservano il conto 951002.
