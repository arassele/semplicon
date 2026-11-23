# Haben → Semplicon: elenco delle decisioni

Fonte: https://github.com/firsttris/haben (contabilità tedesca, TypeScript/Bun, beta).
**Stato 20.11.2026: decisi e realizzati sono 1.2, 1.3, 2.1, 2.2, 5.2 (come VIES), 9.4 e 2.5–2.7 (vedere DECISIONS n. 72). Tutto il resto è aperto, nulla di ciò è realizzato.** Per ogni punto indicare: **Sì / No / Più avanti** (sigle nella colonna «Impegno»: S ≤ 1 giorno, M 2–4 giorni, L > 1 settimana).

## Decisione 0: licenza (presupposto per tutto)

Haben è sotto **AGPL-3.0** con obbligo di indicazione del nome (§ 7b) e offre una licenza commerciale. Riprendere il codice porrebbe Semplicon sotto AGPL. Inoltre è uno stack diverso (TS invece di PHP). Proposta: **reimplementare solo idee/concetti (clean-room), non copiare codice.** → D'accordo? (Sì/No)

Legenda idoneità per l'Italia: **A** = direttamente trasferibile, **B** = adattabile (serve una regola italiana), **C** = solo Germania, non riprendere.
Nota sull'ambito: secondo `web/CLAUDE.md` Semplicon è pura contabilità IVA (nessuna banca, nessun collegamento SdI). I punti con ⚠ estendono questo ambito.

## 1. Sicurezza della revisione (GoBD → Italia: Immodificabilità / Conservazione)

| N. | Funzione | Italia | Semplicon oggi | Impegno |
|---|---|---|---|---|
| 1.1 | Blocco dei record confermati anche tramite trigger del DB (non solo codice dell'app) | A | solo a livello di app | M |
| 1.2 | Audit log con catena di hash (manomissione riconoscibile) | A | audit presente, senza catena | S |
| 1.3 | Esportazione annuale come ZIP con checksum SHA-256 (archivio per Commercialista/Verifica) | A | esportazione XML presente | M |
| 1.4 | «Verprobung»: verifica automatica di coerenza (Registro ↔ Liquidazione ↔ Scadenziario) | A | in parte (Verifiche) | M |
| 1.5 | Documentazione delle procedure come documento generato (modello di Manuale di conservazione) | B | – | S |
| 1.6 | Blocco di interi anni/periodi dopo la presentazione («annotare presentato all'esterno») | A | periodi di blocco presenti | S |

## 2. Fatture (uscita)

| N. | Funzione | Italia | Semplicon oggi | Impegno |
|---|---|---|---|---|
| 2.1 | Fatture ricorrenti (modelli, mensili/trimestrali/annuali) | A | – | M |
| 2.2 | Storno/correzione → Nota di credito (TD04) direttamente dalla fattura emessa | A | TD04 emettibile, nessuna scorciatoia | S |
| 2.3 | Anteprima dal vivo/copia PDF della fattura (Copia di cortesia) con layout propri | A | – | M |
| 2.4 | Invio della fattura per e-mail (PDF + eventualmente XML) | A ⚠ | – | M |
| 2.5 | Catalogo articoli (Prodotti/Servizi con prezzo, IVA, Natura) | A | – | M |
| 2.6 | Blocchi di testo (modelli di Causale) | A | – | S |
| 2.7 | Unità proprie (UM) | A | testo libero | S |
| 2.8 | Inserimento prezzi netto/lordo | B | solo netto | S |
| 2.9 | Valuta estera con campo cambio | B | solo EUR | M |
| 2.10 | Fattura bilingue (IT/EN) | A | – | M |
| 2.11 | Sconto di cassa | B | Sconti presenti, sconto di cassa no | S |
| 2.12 | Solleciti (livelli, interessi di mora ex D.Lgs. 231/2002) | B | – | L |
| 2.13 | Stralcio / credito inesigibile (Perdita su crediti, Variazione in diminuzione Art. 26) | B | – | M |
| 2.14 | Compensazione senza pagamento | A | – | S |
| 2.15 | Allegati, numero ordine/DDT (DatiOrdineAcquisto/DDT in FatturaPA) | A | in parte | S |
| 2.16 | QR/avviso di pagamento sul PDF (IBAN, Causale, eventualmente pagoPA/QR SEPA) | B | – | S |
| 2.17 | Addebito diretto SEPA cumulativo / file di pagamento (equivalente CBI) | B ⚠ | – | L |

## 3. Offerte e ordini

| N. | Funzione | Italia | Semplicon oggi | Impegno |
|---|---|---|---|---|
| 3.1 | Preventivo con numero proprio, posizioni opzionali/alternative | A | – | L |
| 3.2 | Piano di pagamento (rate/SAL) dal preventivo | A | Acconti TD02/TD03 presenti | M |
| 3.3 | Catena Preventivo → Conferma d'ordine → DDT → Fattura | A | – | L |
| 3.4 | Fattura di acconto/saldo (SAL) con conteggio automatico | A | Detrazione acconto presente | S |

## 4. Rilevazione dei tempi

| N. | Funzione | Italia | Semplicon oggi | Impegno |
|---|---|---|---|---|
| 4.1 | Registrazioni di tempo con tariffa oraria e fatturazione senza doppio addebito | A ⚠ | – | L |
| 4.2 | Import da strumenti di rilevazione dei tempi (Kimai/CSV) | A ⚠ | – | M |

## 5. Contatti

| N. | Funzione | Italia | Semplicon oggi | Impegno |
|---|---|---|---|---|
| 5.1 | Referenti per contatto, «all'attenzione di» sul PDF | A | – | S |
| 5.2 | Verifica online del numero di partita IVA UE → **VIES** (UE) e plausibilità di Codice Fiscale/P.IVA | B | solo formato | M |
| 5.3 | Fatturato/documenti per contatto (scheda cliente) | A | – | M |
| 5.4 | Mandati SEPA | B ⚠ | – | M |
| 5.5 | Import CSV contatti/articoli | A | – | S |
| 5.6 | Riconoscimento dei duplicati nei contatti (P.IVA/CF) | A | – | S |

## 6. Documenti in entrata / fatture dei fornitori

| N. | Funzione | Italia | Semplicon oggi | Impegno |
|---|---|---|---|---|
| 6.1 | Upload di documenti tramite drag&drop/fotocamera/casella e-mail (IMAP) | A ⚠ | import XML presente | L |
| 6.2 | Lettura automatica (OCR/IA, anche in locale con Ollama) per fatture PDF dall'estero | A | fattura estera manuale tramite PDF | L |
| 6.3 | Coda di verifica (review queue) per i documenti appena riconosciuti | A | – | M |
| 6.4 | Documenti ricorrenti (abbonamenti, affitto) pre-registrati periodicamente | A | – | M |
| 6.5 | Duplicare il documento / ripartirlo su più categorie | B | – | S |
| 6.6 | Quota privata / detrazione IVA proporzionale (Detraibilità parziale, Pro-rata Art. 19) | B | Detraibilità per riga presente | M |
| 6.7 | Avviso in caso di cambio di IBAN del fornitore (protezione dalle frodi) | A | – | S |
| 6.8 | Controllo dei duplicati dei nuovi documenti (hash/numero+fornitore) | A | rilevamento duplicati presente | – |

## 7. Banca e pagamenti ⚠ (fuori dall'ambito odierno)

| N. | Funzione | Italia | Semplicon oggi | Impegno |
|---|---|---|---|---|
| 7.1 | Import estratto conto CAMT.053 / CSV con assegnazione delle colonne | A | lo Scadenziario ha CSV | M |
| 7.2 | Accesso bancario PSD2 (Open Banking, ad es. Enable Banking/Tink) | A | – | L |
| 7.3 | Proposte di abbinamento movimento ↔ fattura con motivazione | A | – | L |
| 7.4 | Apprendimento dagli abbinamenti precedenti | A | – | M |
| 7.5 | Registrazione suddivisa, controllo del saldo, hash anti-duplicati | A | – | M |
| 7.6 | Import dai fornitori di pagamenti (PayPal, Stripe, Shopify, Etsy …) | A | – | L |
| 7.7 | Compensare le controregistrazioni (netting) | A | – | S |

## 8. IVA / Scadenze

| N. | Funzione | Italia | Semplicon oggi | Impegno |
|---|---|---|---|---|
| 8.1 | Pagina del calendario delle scadenze | A | Scadenze fiscali presenti | – |
| 8.2 | **Abbonamento iCal** delle scadenze (link segreto, salvato solo l'hash) | A | – | S |
| 8.3 | **Promemoria via e-mail** prima delle scadenze (7/3/1 giorni, scaduto) | A | – | M |
| 8.4 | Indicatori con prova di provenienza (riga → registrazioni, «Provenance») per campi VP/LIPE | A | Drilldown del registro in parte | M |
| 8.5 | Annotare «presentato all'esterno» (numero di protocollo, data) per LIPE/F24 | A | – | S |
| 8.6 | Presentazione assistita (elenco di valori da inserire nel portale) | B | F24/LIPE come dati | M |
| 8.7 | Comunicazione riepilogativa → **Intrastat / Elenco INTRASTAT** | B | CrossBorder presente | L |
| 8.8 | Proroga permanente del termine → n/a (Italia: opzione trimestrale, già presente) | C | ✔ | – |

## 9. Analisi ed esportazione

| N. | Funzione | Italia | Semplicon oggi | Impegno |
|---|---|---|---|---|
| 9.1 | Partite aperte (debitori/creditori) | A | Scadenziario | – |
| 9.2 | Andamento mensile fatturato/IVA (grafico) | A | Panoramica | S |
| 9.3 | Widget di panoramica configurabili | A | Panoramica fissa | M |
| 9.4 | Ricerca rapida (Ctrl+K) su fatture/contatti/documenti | A | – | M |
| 9.5 | Accesso del consulente fiscale (sola lettura, ruolo Commercialista) | A | solo Admin/Cliente | M |
| 9.6 | Esportazione DATEV → **Esportazione per Commercialista** (Teamsystem/Zucchetti/giornale CSV) | B | esportazione XML presente | L |

## 10. Contabilità in senso stretto ⚠ (Semplicon: volutamente no)

Partita doppia, piani dei conti SKR03/04, giornale, bilancio di verifica, registro dei cespiti/AfA (Cespiti/Ammortamento), libro cassa (Registro di cassa), Bilancio/conto economico (schema CEE), forfait (home office/km), buste paga, dichiarazioni annuali (EÜR/ESt/KSt → Redditi/IRAP), casella postale dell'ufficio delle imposte.
→ **Tutti: No, finché Semplicon resta un puro software IVA**, a meno che non cambiate l'ambito (allora Cespiti 10.a e Registro di cassa 10.b come primi candidati, ciascuno L).

## 11. Piattaforma / Tecnica

| N. | Funzione | Italia | Semplicon oggi | Impegno |
|---|---|---|---|---|
| 11.1 | API REST con OpenAPI, token con scope, Idempotency-Key, riferimento esterno | A | – | L |
| 11.2 | Server MCP (sola lettura) per assistenti IA, disattivato per impostazione predefinita | A | – | M |
| 11.3 | Importatore per il trasloco (pattern: anteprima, riconciliazione per anno) per Fatture in Cloud, Danea, TeamSystem, Zucchetti | A | – | L per fonte |
| 11.4 | Login Passkey/OIDC, ruoli, inviti | A | login/ruoli semplici | M |
| 11.5 | Verifica WCAG 2.2 AA in CI (axe/Playwright) | A | – | S |
| 11.6 | PWA (installabile, upload con fotocamera) | A | – | M |
| 11.7 | Temi (chiaro/scuro) | A | – | S |
| 11.8 | Concetto di backup (restic) e documentazione di deployment Docker/Kubernetes | A | – | S |
| 11.9 | Collegamento all'archivio documentale (Paperless-ngx) | B | Documents interno | M |
| 11.10 | Pagine proprie (strumenti iFrame nel menu) | A | – | S |

## Da non riprendere (solo Germania)

ELSTER/ERiC, indicatori UStVA, comunicazione riepilogativa (ZM), moduli EÜR/Anlage, E-Bilanz/XBRL-HGB, SKR03/04, formato DATEV, buste paga (BMF-PAP), ZUGFeRD/XRechnung/KoSIT (Italia: FatturaPA/SdI), § 19 UStG piccoli imprenditori (Italia: il regime forfettario ha regole diverse), § 13b/igE/EUSt (Italia: Reverse charge/Integrazione già in Semplicon), verifica BZSt, imposta ecclesiastica/distribuzione degli utili/KSt.

## La mia raccomandazione (Top 10, se volete restare snelli)

1. 1.2 Catena di hash dell'audit (S) · 2. 1.3 Archivio annuale con SHA-256 (M) · 3. 8.2 Abbonamento iCal delle scadenze (S) · 4. 8.3 Promemoria via e-mail (M) · 5. 2.1 Fatture ricorrenti (M) · 6. 2.2 Nota di credito con un clic (S) · 7. 5.2 Verifica VIES (M) · 8. 9.5 Accesso del Commercialista in sola lettura (M) · 9. 9.4 Ricerca rapida (M) · 10. 2.5/2.6/2.7 Catalogo articoli + blocchi di testo + unità (M).
