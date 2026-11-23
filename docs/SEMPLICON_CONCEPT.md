# Semplicon – Concept di prodotto

Stato del pacchetto 16.11.2026 · ambito iniziale vincolante: solo IVA

## Idea

**La tua contabilità IVA, semplice.** Semplicon accompagna piccole imprese e liberi professionisti dal documento XML, passando per la verifica e la registrazione, fino a registri IVA e liquidazioni tracciabili.

## Destinatari

Ex forfettari e imprese in contabilità semplificata soggetti a IVA, in ogni caso con casi d'uso espressamente supportati. L'ambito iniziale copre solo la contabilità IVA, non tutti gli obblighi di una contabilità semplificata.

L'inizio dell'obbligo IVA viene confermato sul piano fiscale. Semplicon non attiva l'IVA automaticamente al raggiungimento di una somma di fatture XML di 85.000 euro. La delimitazione fiscale, i casi transitori e le fonti sono documentati nel piano di programmazione completo allegato e vanno riverificati prima dell'implementazione produttiva.

## Flusso principale

1. Selezionare l'impresa e il profilo IVA confermato sul piano fiscale.
2. Importare le fatture di acquisto e di vendita.
3. Verificare dati, ricevuta di ricezione e trattamento IVA.
4. Approvare la registrazione.
5. Riconciliare registri e liquidazione.
6. Chiarire i casi aperti, chiudere il periodo, esportare il rendiconto.

## Ambito di prodotto

| Prima versione produttiva obiettivo | Sviluppi successivi |
|---|---|
| Mandanti e autorizzazioni | Contabilità generale completa |
| Import XML/ZIP e documenti validati | Determinazione dell'utile, imposta sul reddito, INPS |
| Registrazione IVA e rettifiche | Riconciliazione bancaria, partite aperte |
| Registri e liquidazione mensile/trimestrale verificata | Emissione di fatture e scambio diretto con SDI |
| Riporti, adeguamenti ammessi e blocchi di periodo | Funzioni IA avanzate |
| Tracciabilità ed esportazioni | Dichiarazioni fiscali e trasmissioni alle autorità |

Questo descrive l'obiettivo, non lo stato di implementazione attuale. La differenza è illustrata nel confronto stato attuale/obiettivo in `04_Uebergabe`.

## Utilizzo

Interfaccia italiana. Navigazione verde scuro, area di lavoro chiara, menta come colore di selezione. Dashboard con IVA a debito, IVA detraibile, saldo provvisorio e documenti da verificare. Documento e rilevazione IVA affiancati.

## Ruolo dell'IA

Facoltativo e a valle: suggerimenti per codici IVA, avvisi di verifica e spiegazioni. Calcolo e approvazione restano deterministici e tracciabili. L'attuale prototipo browser non richiede IA e non genera chiamate API IA esterne.

## Direzione tecnica

Obiettivo di produzione: monolite modulare con Laravel, Vue/TypeScript/Inertia, PostgreSQL, documenti privati, job in background e autorizzazioni verificate lato server. Attualmente disponibile: prototipo browser di UX e flussi come punto di partenza per il feedback fiscale.

## Prossima pietra miliare concreta

Base backend con login, aziende protette, profilo IVA e archiviazione permanente dei documenti. Solo dopo, aggiungere la validazione XML completa e le regole IVA approvate sul piano fiscale. Il prototipo browser è il riferimento visivo e non sostituisce questo lavoro.
