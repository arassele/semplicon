# Stato · 19.11.2026

Valori di stato secondo il piano: aperto · in lavorazione · testato tecnicamente · approvazione fiscale aperta · completato · bloccato.

| Ticket | Contenuto | Stato |
|---|---|---|
| I00 | Struttura di base, login, registrazione, aziende con anagrafica (Dati azienda), amministratore | testato tecnicamente · approvazione fiscale aperta |
| I01 | Regime IVA per anno solare: regime principale, variante, forfettario con IVA, cadenza, Aliquota pro rata, versioni | testato tecnicamente · approvazione fiscale aperta |
| I02 | Import XML/ZIP/P7M, archiviazione sicura, verifica dello schema, duplicati, vista della fattura | testato tecnicamente · approvazione fiscale aperta |
| I03 | Verifica e registrazione, Piano dei conti, Tabelle (Conti, ATECO), Storno, Note di credito | testato tecnicamente · approvazione fiscale aperta |
| I04 | Registri IVA acquisti/vendite, documenti di integrazione, CSV/PDF | testato tecnicamente · approvazione fiscale aperta |
| Aggiunta | Piano dei conti 2026 con Impostazioni fiscali (indicazione 18.11.2026) | testato tecnicamente · approvazione fiscale aperta |
| Aggiunta | Scadenziario: rate, Incassi/Pagamenti, partite aperte alla data di riferimento, Riepilogo fiscale per cassa (indicazione 19.11.2026, decisione n. 67) | testato tecnicamente · approvazione fiscale aperta |
| I05 / Ticket 11 | Liquidazioni IVA, F24 (bozza), Registro crediti IVA, Destinazione del credito, IVA TR, Scadenze fiscali (decisione n. 68) | testato tecnicamente · approvazione fiscale aperta |
| Aggiunta | Incarico di programmazione «Fatture e IVA»: Archivia/Ripristina, Rielabora XML, Visualizza fattura, selezione dell'anno, Registri per posizione, Integra IVA/Reverse charge, fattura estera tramite PDF, Operazioni transfrontaliere con XML (decisione n. 69) | testato tecnicamente · approvazione fiscale aperta |
| Aggiunta | Emissione fattura elettronica: modulo, FatturaPA con deved/fattura-elettronica, numerazione progressiva, registrazione, rate, download (decisione n. 71); nessuna trasmissione SdI | testato tecnicamente · approvazione fiscale aperta |
| Aggiunta | Funzioni riprese da Haben (decisione n. 72): catena di hash dell'audit, Archivio annuale con SHA-256, Fatture ricorrenti, Nota di credito con un clic, verifica VIES, ricerca rapida, Catalogo (articoli, testi, unità) | testato tecnicamente · approvazione fiscale aperta · anche nella demo (VIES lì simulato) |
| Aggiunta | Anagrafiche, Fatture elettroniche (stato trasmessa), PDF in scala di grigi con logo, Panoramica con analisi, anno contabile centrale, eliminazione anno, backup dell'archivio, Parametri per anno (decisione n. 74) | testato tecnicamente · approvazione fiscale aperta · anche nella demo (il backup contiene solo dati del browser, logo e PDF in scala di grigi, calendario senza proroghe) |
| Aggiunta | Stampa, Funzioni e opzioni, Data incasso/pagamento nel registro, Calendario fiscale 2027/2028, sostituire/eliminare import, elenchi con selezione multipla, ripristino tramite backup (decisione n. 75) | testato tecnicamente · approvazione fiscale aperta · anche nella demo (il backup contiene solo dati del browser, logo e PDF in scala di grigi, calendario senza proroghe) |
| Aggiunta | LIPE: file IVP18 e stampa di controllo per trimestre (decisione n. 76) | testato tecnicamente (276/276) · approvazione fiscale aperta · non verificato rispetto allo schema ufficiale · anche nella demo |
| Aggiunta | Conto economico per competenza, per cassa, ai fini fiscali (decisione n. 77); Professionisti fiscalmente sempre per cassa | testato tecnicamente (280/280) · approvazione fiscale aperta · anche nella demo (la vista fiscale lì è calcolata direttamente da regime e pagamenti) |
| Aggiunta | Modulo Cespiti: D.M. 31.12.1988, categorie, Anagrafica, motore civile/fiscale, Prima nota cespiti, 13 tipi di movimento, import Saldi/Storico, Stampe A–J, Esercizi chiusi (decisione n. 79); D.M. completo incl. Attività non precedentemente specificate (n. 80); Progressivi nel modulo, Quota ridotta nel Calcolo, Maggiorazione calcolata (n. 81) | testato tecnicamente (308/308) · approvazione fiscale aperta · nella demo semplificato (categorie dal D.M., Anagrafica con Progressivi, import CSV, Calcolo con Quota ridotta e Maggiorazione, tutti i tipi di movimento, Registro con Prospetto K, Prima nota) |
| I06–I09 | vedere docs/PLAN.md | aperto |

## Indicazioni (committente, 17/18.11.2026)

- **Clienti e amministratore.** I clienti configurano tutto da soli, l'amministratore ha accesso a tutto.
- **Regime per anno solare,** sempre dal 1° gennaio al 31 dicembre, senza data di inizio.
- **Due regimi principali:** Contabilità semplificata · Contabilità professionisti.
- **Variante della semplificata:** a) Regime opzionale senza annotazione incassi/pagamenti (per competenza) · b) Regime naturale con annotazione incassi/pagamenti (per cassa).
- **Impostazione «Soggetto forfettario con IVA»** per anno.
- **Aliquota pro rata** per anno, anche inferiore al 100 %.
- **Sempre supportati (senza selezione):** Corrispettivi, Reverse charge (anche Intra-UE ed Extra-UE), Split payment.
- **Non disponibili (solo avviso):** Regime del margine, altri regimi particolari, IVA per cassa (art. 32-bis, eventualmente in seguito).
- **Dati azienda** sul modello dell'Anagrafica: Soggetto, Persona, Sede, Recapiti, Attività, Conti bancari.

## Realizzato (semplicon/web)

**I00**
- Laravel 13, Vue 3 + TypeScript, Inertia 3, PostgreSQL 16. CSS proprio ricavato dai design token.
- Auto-registrazione per i clienti (`/registrati`), mai con diritti di amministratore. Login con blocco dopo 5 tentativi falliti.
- I clienti creano e gestiscono completamente le proprie aziende. Aziende altrui e ID figli altrui danno 404.
- Amministratore (solo tramite `php artisan semplicon:user --admin`): accesso a tutte le aziende, panoramica su `/amministrazione`, avviso «Accesso amministratore» nelle aziende altrui.
- Pagina «Dati azienda»: Tipologia, Partita IVA (con cifra di controllo), Codice fiscale (con carattere di controllo), Codice SDI, Persona fisica, Sede, Recapiti (un contatto predefinito), Attività ATECO 2025 dall'elenco ufficiale ISTAT con ricerca per codice o parola, in italiano e tedesco (una prevalente), Conti bancari (verifica IBAN, ABI/CAB derivati).
- Registro delle attività non modificabile.

**I01**
- Regime per azienda e anno solare: barra degli anni, «Aggiungi anno», bozza → conferma.
- Per anno: regime principale, variante (solo semplificata), Soggetto forfettario con IVA, cadenza, Aliquota pro rata (0–100 %, predefinita 100), note. I casi supportati e non disponibili compaiono come avviso accanto. La navigazione mostra «Forfettario con IVA» se vale nell'anno in corso.
- Un nuovo anno prende come modello l'ultimo anno confermato.
- Gli anni confermati non sono modificabili; correzione come nuova versione dello stesso anno con motivazione.
- Versione delle regole `IVA-I01-2026.6`.

**I02**
- Pagina «Importa XML»: più file tramite drag-and-drop, .xml, .xml.p7m (DER o Base64) e .zip; limiti 50 file, 10 MB per XML/P7M, 50 MB per ZIP.
- Originali invariati nell'archivio privato (`storage/app/private/documents/{azienda}/…`), SHA-256 univoco per azienda, download solo tramite la propria azienda.
- Lettura sicura (nessun DOCTYPE, nessuna entità esterna, nessuna rete) e verifica rispetto allo schema ufficiale FatturaPA 1.2.3 (FPR12 e FPA12).
- Direzione da P.IVA/C.F. dell'azienda; più corpi di fattura per file; Riepilogo IVA e Righe vengono acquisiti.
- Duplicati (stesso file, stessa fattura) e conflitti (stesso numero, contenuto diverso); indice univoco del database contro gli import paralleli.
- Avvisi di verifica: valuta estera, importo totale, direzione poco chiara, regime mancante per l'anno della fattura.
- Elaborazione come job; pagina dei risultati per ogni esecuzione di import con stato per file e messaggi, si aggiorna da sola.
- Elenchi Acquisti, Vendite, Da verificare con ricerca, anno e stato; dettaglio della fattura con Riepilogo, Righe, controparti e originale.

**I03**
- Impostazioni › Tabelle: Piano dei conti per azienda (modello con 16 conti, modificabile, i conti usati sono solo disattivabili) e Codici ATECO (tabelle ISTAT, ricerca, gestione da parte dell'amministratore).
- «Da registrare» con «Avvia verifica»: verificare e registrare le fatture una dopo l'altra.
- Registrazione: periodo dal regime dell'anno (proposta), data di registrazione e di ricezione, conto e % detraibile per ogni riga di Riepilogo con anteprima, conferma degli avvisi di verifica, protocollo per registro e anno.
- Proposta del conto: ultimo conto usato per la stessa controparte, altrimenti conto predefinito.
- Storno con motivazione e data, nel periodo IVA della data dello storno; scarto e ripristino di fatture, cronologia per fattura, voci del registro delle attività.

**I04**
- Registri IVA › Acquisti / Vendite: registrazioni in ordine di protocollo, filtri anno e periodo (mese o trimestre secondo il regime), ogni riga collega il documento.
- Storni e registrazioni stornate restano visibili (con segno e avviso «Storno di prot. n»); Note di credito in negativo.
- Riepilogo per Aliquota/Natura con Imponibile, Imposta e (Acquisti) IVA detraibile; totale complessivo come saldo del periodo.
- I documenti di integrazione TD16–TD19 compaiono nel Registro acquisti e inoltre nel Registro vendite (protocollo «A n/anno», IVA a debito, detraibile 0).
- Esportazione CSV (UTF-8 con BOM, punto e virgola, virgola decimale, formule neutralizzate) e PDF (A4 orizzontale, dompdf); ogni esportazione viene registrata.

**Piano dei conti 2026 (aggiunta)**
- Modello dal file del committente, adattato alla contabilità semplificata: 158 conti raggruppati per Sezione, con Voce CEE, Categoria e dati fiscali; le nuove aziende lo ricevono automaticamente, quelle esistenti tramite «Applica modello». Il modello dell'ordinaria (189 conti) è salvato e bloccato.
- Contabilità professionisti: modello proprio per il quadro RE (58 conti, art. 54 TUIR), automatico per le aziende con Tipologia Professionista; verificato nel browser (15 Sezioni, conto predefinito 641009, Rappresentanza con 0 % e limite 1 % dei compensi).
- Rappresentanza: proposta 0 % IVA detraibile, Omaggi fino a 50 euro 100 % (art. 19-bis1 lett. h DPR 633/72).
- Tabelle › Piano dei conti: ricerca, filtri (tipo, solo rilevanti ai fini IVA, disattivati), gruppi per Voce CEE, finestra di modifica con Impostazioni fiscali, conto predefinito, eliminazione di conti non usati.
- Registrazione: solo conti «rilevante ai fini IVA», selezione raggruppata per Categoria, % IVA detraibile del conto come proposta con avviso (Indicazione IVA, Limite).
- Tabella › Parametri fiscali 2026 (solo visualizzazione).

**Aggiunta 19.11.2026**
- La data di ricezione viene proposta con la data della fattura.
- Ogni registrazione reca il documento (file importato o allegato); link nel Registro IVA, colonna «Allegato» nel CSV.
- Fattura manuale: rilevazione senza import con Riepilogo IVA e allegato (PDF, JPG, PNG, XML), poi registrazione come d'abitudine; allegato integrabile successivamente.
- Modificare ed eliminare: registrazioni (il protocollo resta), fatture manuali prima e dopo la registrazione, ogni fattura con le relative registrazioni; stato precedente nel registro delle attività.
- Fuori campo IVA: costi e ricavi senza IVA con protocollo, allegato, storno e somme per conto; non nei Registri IVA.

## Test effettivamente eseguiti (18.11.2026)

- `php artisan test` su PostgreSQL 16: **162 superati, 0 falliti**.
- La stessa suite su SQLite: **162 superati**.
- Browser (Chromium), briefing 08–09: quattro documenti esistenti reimportati («4 documenti già presenti. Nessuna nuova fattura da registrare.»), copia formattata «già presente», importo modificato «Possibile conflitto» con confronto (3 righe divergenti); regola «hosting» → 641003 memorizzata alla registrazione di A-77 e proposta per NC-12.
- Browser (Chromium), briefing 01–07: cambio d'anno 2026/2027 nell'avviso sul regime; A-77 periodo Proposta → manuale → proposta; quota 0 % resta al cambio di conto, «Usa proposta del conto (100 %)»; ripartizione 30/10 con Residuo e «Assegna residuo»; anteprima con Detraibile/Non detraibile; registrare → NC-12 con conferma di A-77, voce di registro contrassegnata, ritorno alla Verifica.
- Browser (Chromium): registrazione modificata (% detraibile) ed eliminata, fattura manuale modificata ed eliminata.
- Browser (Chromium), 19.11.2026: Fattura manuale con PDF rilevata, data di ricezione = data della fattura proposta, registrata, allegato nella registrazione e nel Registro acquisti; Movimento fuori campo IVA (Bollo) rilevato; larghezza smartphone senza overflow laterale.
- Browser (Chromium), Piano dei conti 2026: 189 conti raggruppati per Voce CEE, ricerca, finestra di modifica con Impostazioni fiscali, salvataggio; la registrazione propone dopo la scelta del conto la % IVA detraibile del conto; Parametri fiscali. Desktop e smartphone senza overflow laterale. Trovato e corretto: le voci 20/21 comparivano all'inizio a causa delle chiavi numeriche. Poi modello della semplificata: 11 Sezioni, modello dell'ordinaria bloccato nella finestra.
- Browser (Chromium), I04: Registro acquisti con registrazione e storno in trimestri diversi, Registro vendite vuoto, download CSV, PDF generato e visualizzato; desktop e smartphone senza overflow laterale della pagina. Trovato e corretto: ultima colonna tagliata a 1366 px (la data di ricezione ora sta nella colonna della data).
- Browser (Chromium), I03: modalità di verifica su quattro fatture (Acquisto, Nota di credito, Vendita, Auto con 40 %), Storno, Piano dei conti, ricerca ATECO. Trovato e corretto: nella modalità di verifica il modulo riprendeva i valori della fattura precedente; il conto predefinito non veniva proposto a causa dell'ordinamento.
- File di riferimento: i sei esempi ufficiali di fatturapa.gov.it (FPR01–03, FPA01–03); valori attesi dai file. FPR02 non è valido secondo lo schema 1.2.3 e viene correttamente respinto.
- Browser (Chromium): upload di quattro file (vendita, acquisto con due aliquote, USD, non valido rispetto allo schema), pagina dei risultati, elenco Acquisti, dettaglio della fattura, download dell'originale; larghezza smartphone senza overflow laterale (trovato e corretto un errore di layout con griglia a colonna singola).
- `npx vue-tsc --noEmit` senza errori, `npm run build` riuscito.
- Browser (Chromium): Dati azienda salvati con IBAN non valido e valido; pro rata 101 respinto, 75,5 % salvato e confermato; larghezza smartphone senza overflow laterale (trovato e corretto un errore al riguardo). Nessun errore JavaScript.

### 19.11.2026 · Incarico di programmazione «Fatture e IVA»

- `php artisan test`: 203/203 verdi (PostgreSQL 16); `DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan test`: 203/203 verdi
- `npx vue-tsc --noEmit`, `npm run build`, `vendor/bin/pint`: senza errori
- Browser: Integra IVA (anteprima TD17), Operazioni transfrontaliere (lista di controllo, errori di campo con link a Dati azienda) senza errori di console

## Non verificato / aperto

- Nessuna questione fiscale aperta su I01.
- I04: conferma fiscale ancora aperta sul fatto che TD16–TD19 debbano comparire inoltre nel Registro vendite con un proprio protocollo «A» (invece di una propria numerazione nel registro Vendite). Nessuna stampa «vidimato» con numerazione di pagina progressiva nell'anno.
- I02: la catena di fiducia dei certificati P7M non viene verificata (solo l'integrità). Nessun test di carico con migliaia di file. MySQL ancora non testato.
- **MySQL/MariaDB:** non testato (nessun server disponibile). SQLite funziona.
- Reset della password, conferma dell'e-mail, autenticazione a due fattori: non implementati.
- Più persone per azienda: non previsto.
- Test con screen reader, zoom al 200 %, conferma parallela da due processi.
- Il servizio PostgreSQL di questo ambiente cloud si arresta ai riavvii del container e deve essere riavviato con `pg_ctlcluster 16 main start`.

## Prossimo ticket

**I05**: Liquidazione IVA per periodo dai registri (IVA a debito − IVA detraibile × Aliquota pro rata), riporto del credito, chiusura del periodo (poi nessuna registrazione né storno nel periodo), esportazione.
