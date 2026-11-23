# Semplicon – IVA-first: concept, roadmap e incarico di programmazione IA

Versione 2.0 · 15 novembre 2026 · Semplicon

Questa versione sostituisce integralmente il precedente ambito di sviluppo più ampio. La prima versione è esclusivamente un modulo di contabilità IVA per piccole imprese e liberi professionisti soggetti a IVA, in particolare dopo l'uscita dal forfettario, nonché per le imprese in contabilità semplificata. Non è un sistema di contabilità completo né una gestione completa del forfettario.

## 1. Obiettivo del prodotto e destinatari

**Semplicon – La tua contabilità IVA, semplice.**

Importare fatture XML, verificare il trattamento IVA, registrarle e ricavarne registri IVA e liquidazioni tracciabili.

Destinatari:
- Ex forfettari che, superato il limite rilevante, passano all'obbligo IVA.
- Imprese in contabilità semplificata con casi d'uso IVA supportati.
- Liberi professionisti corrispondenti soggetti a IVA, limitatamente allo stesso ambito funzionale IVA.

L'appartenenza a un gruppo di destinatari non dimostra da sola che tutti i casi d'uso di un cliente siano supportati. Il pilota viene scelto in base ai casi IVA effettivi.

### Delimitazione fiscale del limite di 85.000 euro

«Forfettario oltre 85.000 euro» non è un tipo di contabilità IVA a sé stante. Secondo la regola illustrata nella Circolare 32/E del 5 dicembre 2023, il superamento di 85.000 euro, fino a un massimo di 100.000 euro, comporta in linea di principio l'uscita dal regime nell'anno successivo. Oltre 100.000 euro di ricavi/compensi incassati si ha un caso di uscita in corso d'anno; l'IVA riguarda già l'operazione che determina il superamento del limite. Il trattamento esatto delle fatture già emesse, di ulteriori operazioni e delle rettifiche va verificato caso per caso.

Per il software ciò significa:
- Non attivare automaticamente l'IVA non appena la somma delle fatture XML supera 85.000 euro.
- Nella versione 1 lo studio stabilisce espressamente l'inizio verificato dell'obbligo IVA, il motivo dell'uscita ed eventualmente l'operazione interessata.
- Nessun monitor automatico delle soglie nella versione 1. La mera somma delle fatture non sostituisce in particolare la verifica degli importi incassati.
- Il semplice passaggio al 1° gennaio è il primo caso transitorio supportato.
- Un passaggio in corso d'anno oltre 100.000 euro resta bloccato per questo cliente finché non esistono test di riferimento propri. Una semplice indicazione di data non basta a tale scopo.
- Le rettifiche dell'IVA a credito dovute al passaggio vengono verificate dallo studio; gli importi documentati vengono considerati solo nelle elaborazioni fiscalmente corrette. Non registrarli genericamente come importo mensile di IVA a credito.
- Prima dell'implementazione produttiva verificare le regole quanto ad aggiornamento, ulteriori requisiti e caso singolo, e versionarle.

Fonti della regola di partenza:
- Agenzia delle Entrate, Circolare 32/E, 5 dicembre 2023, in particolare sezioni 2 e 3; copia del documento: https://www.dirittobancario.it/wp-content/uploads/2023/12/Circolare-Agenzia-delle-Entrate-5-dicembre-2023-n.-32-E.pdf
- Comunicato stampa dell'Agenzia del 5 dicembre 2023: https://www.fiscooggi.it/sites/default/files/file/2023/12/055_Com.%20st.%20Circolare%20_nuovo_regime_forfettario_05.12.2023.pdf

## 2. Ambito vincolante della prima versione

| Componente | Realizzazione |
|---|---|
| Clienti e diritti | Aziende, utenti, cambio di azienda; ruoli studio e cliente |
| Profilo IVA | Inizio IVA, cadenza mensile/trimestrale, regole valide, casi particolari |
| Import XML | Fatture di acquisto/vendita, ZIP, schemi supportati, file originali |
| Verifica | Duplicati, assegnazione controparte/cliente, totali e dati mancanti |
| Registrazione IVA | Codice IVA, aliquota, natura, base imponibile, imposta, quota detraibile, periodo |
| Documenti manuali | Documenti non XML fiscalmente supportati con allegato e prova di provenienza |
| Registri | Acquisti/Vendite, collegamento ai documenti, protocollazione, esportazione |
| Liquidazione | Debito/Credito, riporti documentati e adeguamenti ammessi |
| Chiusura | Verifica di completezza, blocco, riapertura documentata |
| Correzioni | Nota di credito, storno referenziato e nuova registrazione |
| Output | Rendiconti CSV e PDF; nessuna trasmissione automatica alle autorità |
| IA | Solo dopo il nucleo: suggerimenti per codici IVA e avvisi di verifica |

Per ora non sviluppare: contabilità completa in partita doppia, piano dei conti con maschere dare/avere, determinazione dell'utile, imposta sul reddito, INPS, calcolatore di imposte per forfettari, contabilità dei cespiti, riconciliazione bancaria, partite aperte, emissione di fatture, invio/ricezione SDI, trasmissione automatica di LIPE/dichiarazione IVA o F24.

La denominazione contabilità semplificata descrive il gruppo di destinatari. Semplicon versione 1 soddisfa esclusivamente la parte IVA descritta, non tutti gli obblighi di registrazione di questo gruppo di destinatari.

Casi non supportati come pro-rata, regime del margine, IVA per cassa, split payment o regimi particolari di settore impediscono l'approvazione dei periodi interessati. Reverse charge/estero verrà attivato solo dopo un pacchetto di regole verificato a sé. I corrispettivi inizialmente non vengono elaborati automaticamente; i clienti con tali operazioni necessitano, prima dell'uso produttivo, di un percorso di rilevazione adeguato e verificato.

## 3. Flusso di lavoro e interfaccia

1. Selezionare il cliente e il profilo IVA confermato sul piano fiscale.
2. Importare file XML/ZIP o rilevare un documento manuale supportato.
3. Verificare entrata/uscita, tipo di fattura, totali, ricevuta di ricezione e duplicati.
4. Confermare codice IVA, quota detraibile, data di registrazione e periodo IVA.
5. Approvare la registrazione; conservare l'originale invariato.
6. Riconciliare registri e liquidazione IVA.
7. Verificare casi aperti e completezza, chiudere il periodo ed esportare.

Navigazione: Panoramica · Importa XML · Acquisti · Vendite · Da verificare · Registri IVA · Liquidazioni · Impostazioni.

Dashboard: IVA a debito, IVA detraibile, saldo provvisorio e documenti da verificare. Indicare come bozza i totali senza approvazione completa. Nessun indicatore di utile, banca o imposta sul reddito.

Design: navigazione verde scuro, accenti menta, area di lavoro chiara e calda secondo la bozza di Semplicon. Interfaccia italiana, tabelle compatte e vista del documento accanto alla rilevazione IVA. Importi nelle bozze con «Euro».

## 4. Architettura tecnica

Laravel, Vue/TypeScript con Inertia, PostgreSQL, archiviazione privata dei file, code Redis. Un monolite modulare. Verificare le versioni supportate all'atto dell'implementazione sulla base della documentazione ufficiale e usare i lockfile. Ambiente Docker Compose riproducibile in locale; produzione con processi in background gestiti.

La versione 1 contiene un nucleo autonomo di registrazione IVA invece di un nucleo completo di contabilità finanziaria. Nessun dare/avere obbligatorio e nessun piano dei conti completo. Un successivo nucleo contabile potrà rilevare i documenti registrati tramite ID stabili.

Moduli: Identity, Tenancy, Documents, InvoiceImport, VatRegistration, VatRegisters, VatLiquidation, Exports. L'IA è un adattatore opzionale successivo.

### Modello dei dati

| Oggetto | Contenuto |
|---|---|
| companies / memberships | Anagrafica, ruoli, diritti sul cliente |
| vat_profiles | Validità, inizio IVA, cadenza, versione delle regole, persona che conferma |
| regime_transitions | Regime di partenza, motivo dell'uscita, efficacia, operazione di riferimento, stato della verifica |
| documents | Percorso privato, nome originale, hash, provenienza |
| import_batches / import_items | Stato, tentativi, errori e riferimento alla fonte |
| invoices / invoice_lines / vat_summaries | Dati XML normalizzati e riferimento all'originale, body_index |
| receipt_evidence | Data di ricezione, fonte della prova, conferma |
| counterparties | Clienti/fornitori riferiti al cliente |
| vat_codes / vat_rule_versions | Trattamento IVA valido, effetto sul registro, calcolo |
| vat_registrations / vat_registration_lines | Direzione, base, IVA, quota detraibile, periodo, stato, riferimento di storno |
| vat_periods / vat_calculations | Riporti, adeguamenti documentati, snapshot degli input, risultato, versione |
| audit_events | Autore, momento, azione e motivo della modifica |

### Regole irrinunciabili

- Calcolo decimale senza float/double; valori monetari nelle risposte API come stringhe.
- company_id su tutti i dati del cliente; verificare i diritti anche per file, job ed esportazioni.
- File originali invariati; le registrazioni approvate si correggono solo tramite storno documentato.
- Separare data di ricezione, della fattura, di upload, di registrazione e periodo IVA.
- Leggere gli XML in modo sicuro, senza entità esterne e senza accessi di rete. Limiti ZIP, protezione dei percorsi e limiti di dimensione.
- Versionare gli XSD ufficiali; fermare in modo visibile i formati sconosciuti.
- Conservare più corpi di fattura per file.
- Controllo dei duplicati tramite hash e sul piano fiscale; fermare per verifica i contenuti divergenti con la stessa identità.
- Approvazione atomica e idempotente; gli import paralleli non generano doppie registrazioni.
- Nessun calcolo IVA tramite IA; nessun valore predefinito silenzioso per casi sconosciuti.
- Un risultato chiuso resta riproducibile; la riapertura genera una nuova versione tracciabile.
- Documentare riporti e rettifiche con fonte, assegnazione fiscale e approvazione.
- L'archiviazione privata dei file non sostituisce la conservazione a norma. Chiarire prima del pilota il processo di conservazione esterno esistente.

## 5. Nuova roadmap

I precedenti ticket T00–T16 vengono sostituiti da I00–I09. Riutilizzare le basi tecniche già esistenti, ma non proseguire con i moduli accantonati. Nessuna promessa garantita di ore o costi; dopo I02 ristimare sulla base di test reali.

| Ticket | Pacchetto di lavoro | Accettazione |
|---|---|---|
| I00 | Verifica del repository, struttura di base, autenticazione, clienti e ruoli | Avvio in locale; dati di aziende altrui bloccati anche tramite ID manipolati |
| I01 | Profilo IVA e inizio del regime verificato; passaggio a inizio anno | Nessun avvio automatico dell'IVA a 85.000 euro di somma delle fatture; cronologia conservata |
| I02 | Import XML/ZIP, archiviazione sicura, validazione, duplicati, vista della fattura | File di riferimento corretti; import parallelo senza duplicati; file non validi spiegati |
| I03 | Codici IVA, registrazione, detrazione parziale, note di credito, documenti manuali | Casi di riferimento approvati corretti; casi sconosciuti bloccati |
| I04 | Registri acquisti/vendite, filtri, riferimenti, CSV/PDF | I totali coincidono con le registrazioni; ogni riga porta al documento |
| I05 | Liquidazione mensile/trimestrale, riporti, blocchi e versionamento | Due periodi di confronto completi senza differenze non chiarite |
| I06 | Casi transitori e casi particolari IVA secondo le esigenze del pilota | Uscita in corso d'anno solo dopo test separati; unsupported resta visibile |
| I07 | Ripristino, test di accesso, qualità delle esportazioni e verifica operativa | Database e documenti ripristinati in modo coerente; nessun errore critico |
| I08 | Pilota parallelo sul piano fiscale con clienti scelti in modo mirato | Completezza e risultati di confronto approvati sul piano fiscale |
| I09 | IA opzionale per suggerimenti IVA | Suggerimenti validati; nessuna approvazione automatica; un'interruzione non compromette il nucleo |

Primo prototipo cliccabile: I00–I02. Primo ciclo IVA verificabile sul piano fiscale: I00–I05. Approvazione per la produzione solo dopo le parti di I06 necessarie per il pilota nonché I07–I08. Un prototipo XML da solo non è ancora una contabilità IVA pronta per la produzione.

## 6. Test di riferimento

- Fattura con una e con più aliquote IVA; nota di credito; detrazione parziale.
- Importare di nuovo e in parallelo la stessa fattura, stesso numero di fornitori diversi.
- Fattura prima/dopo l'inizio dell'IVA; inizio sconosciuto o contraddittorio.
- Test di limite 85.000, 85.000,01, 100.000 e 100.000,01 euro in fixture transitorie fiscali: nessuna derivazione dal mero fatturato XML; il caso in corso d'anno richiede un'operazione confermata e un trattamento separato.
- Ricevuta di ricezione mancante, cambio di periodo/anno e periodo bloccato.
- Codice IVA sconosciuto, valuta estera o caso particolare non supportato: nessuna registrazione silenziosa.
- File XML/ZIP manipolati e ID di file/record di altri clienti.
- Approvazione ripetuta, storno e nuova liquidazione con stato precedente conservato.
- Prove separate per un ex forfettario con passaggio a inizio anno e per un cliente semplificata già soggetto a IVA.

Fixture di puro calcolo: IVA sulle vendite 220,00 euro meno IVA detraibile sugli acquisti 88,00 euro dà 132,00 euro. Ciò presuppone lo stesso periodo ammissibile, piena detraibilità e nessuna ulteriore voce/riporto. I valori di test fiscali devono essere indicati indipendentemente dall'implementazione testata e approvati dal committente.

## 7. Nuovo master prompt per l'assistente di programmazione IA

```text
Leggi integralmente PLAN.md. Implementa Semplicon versione 2.0:
esclusivamente contabilità IVA per ex forfettari soggetti a IVA
e clienti in contabilità semplificata con casi supportati.

Verifica prima repository, modifiche dell'utente, AGENTS.md e codice esistente.
Conserva le basi adatte. Non costruire una contabilità finanziaria completa,
né un calcolatore di imposta sul reddito/INPS, né riconciliazione bancaria,
né emissione di fatture, né collegamento diretto a SDI.

Flusso principale: importare XML → verificare → registrare IVA → registri →
liquidazione. Usa Laravel, Vue/TypeScript/Inertia e PostgreSQL,
a meno che il progetto esistente non preveda uno stack diverso e motivato.

Crea/aggiorna docs/STATUS.md, docs/DECISIONS.md,
docs/FISCAL_RULES.md e docs/TEST_MATRIX.md. I vecchi ticket T00–T16
vengono sostituiti da I00–I09. Documenta il lavoro già riutilizzato.

In questo incarico implementa completamente solo I00 e I01.
Usa dati sintetici. Non servono API key né una vera IA.

L'IVA non viene attivata automaticamente a partire da 85.000 euro di somma
delle fatture XML. Lo studio conferma l'inizio dell'IVA e il caso transitorio.
Un'uscita in corso d'anno oltre 100.000 euro resta bloccata senza una logica
di caso propria e verificata. Non inventare regole fiscali; documenta fonti e validità.

Nessun calcolo monetario con float. Diritti dei clienti anche per job/download.
File originali invariati. Le registrazioni IVA approvate si correggono solo
tramite storno. Bloccare i periodi chiusi. Leggere gli XML senza entità
esterne. Bloccare in modo visibile codici sconosciuti e casi particolari.

Esegui effettivamente i test adatti e correggi gli errori. Contrassegna
espressamente i test non eseguibili. Aggiorna lo stato.
Nessun deployment in produzione, nessun git push e nessuna trasmissione reale.

Consegna: ticket implementati, file modificati, risultati dei test,
istruzioni esatte di avvio in locale, limiti aperti e prossimo ticket.
Inizia ora con la verifica del repository, I00 e I01.
```

## 8. Prompt successivo

```text
Leggi PLAN.md versione 2.0 e docs/STATUS.md.
Implementa il prossimo ticket aperto I00–I09 con dipendenze soddisfatte.
Resta nell'ambito solo IVA. Conserva le modifiche esistenti dell'utente.
Aggiungi test significativi, eseguili e correggi gli errori.
Non inventare regole fiscali né risultati di verifica positivi.
Aggiorna stato, limiti e istruzioni di verifica; termina dopo questo ticket.
```

Valori di stato: aperto, in lavorazione, testato tecnicamente, approvazione fiscale aperta, completato, bloccato. Registro delle regole: ID regola, caso, fonte ufficiale, validità, input, risultato atteso, test di riferimento, approvazione fiscale.

## 9. Prospettive

Dopo un pilota IVA riuscito, determinazione dell'utile, riconciliazione bancaria, estensioni IA e interfacce potranno essere prioritizzati separatamente. Non fanno parte della prima versione. In caso di cambi di regime o di casi d'uso finora non supportati vale un'approvazione fiscale propria, non un'estensione automatica in base alla denominazione del cliente.
