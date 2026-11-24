# Funzionalità della beta

Semplicon collega documenti, registrazioni e riepiloghi per professionisti e piccole imprese in contabilità semplificata. Tutte le aree qui descritte sono soggette ai [limiti della beta](BETA.md).

## Fatture e documenti

L’applicazione web importa XML FatturaPA, archivi ZIP e P7M, conserva gli originali e segnala duplicati, conflitti e anomalie. Il dettaglio permette di leggere controparti, righe e riepiloghi IVA. Sono presenti anche fatture manuali con allegati.

L’emissione comprende righe, ritenuta, cassa previdenziale, numerazione, note di credito e ricorrenze. Gli XML vengono scaricati per la trasmissione attraverso un canale esterno. Non è previsto l’invio diretto allo SdI.

## Dalla verifica alla registrazione

I documenti da registrare vengono controllati in un flusso guidato. Il piano dei conti e le regole di contabilizzazione aiutano a proporre conti e detraibilità; l’utente verifica i valori prima di confermare. Sono disponibili storni, cronologia e movimenti fuori campo IVA.

Le registrazioni alimentano i registri IVA acquisti e vendite, con filtri per periodo e collegamenti ai documenti. Le esportazioni CSV e PDF permettono di esaminare i dati fuori dall’applicazione.

## Incassi, pagamenti e risultati

Lo scadenziario raccoglie rate aperte, incassi e pagamenti. I movimenti finanziari possono essere attribuiti alle rate. Il riepilogo per cassa e il conto economico offrono letture distinte dei dati: competenza, cassa e rilevanza fiscale.

La panoramica riunisce attività aperte, scadenze e indicatori. Questi strumenti aiutano a esplorare l’andamento dell’attività, ma i risultati fiscali della beta richiedono ancora validazione.

## IVA e operazioni con l’estero

Sono presenti liquidazioni periodiche, registro dei crediti IVA e strumenti per la destinazione dei crediti, oltre a prospetti F24 in bozza e IVA TR. La generazione LIPE IVP18 è documentata come tecnicamente implementata, con verifica dello schema ufficiale ancora aperta.

Le integrazioni IVA e il reverse charge comprendono documenti TD16–TD19. La sezione transfrontaliera raccoglie le operazioni da completare e i relativi XML. Non costituisce un servizio di invio telematico.

## Cespiti

Il modulo comprende anagrafica dei beni, categorie e aliquote, costo e progressivi, movimenti, calcolo degli ammortamenti civilistici e fiscali, prima nota e stampe. Il backend include import di saldi e storico e gestione degli esercizi chiusi; la demo presenta una versione semplificata.

## Anagrafiche e configurazione

Clienti, fornitori, catalogo, conti e codici ATECO sono consultabili e gestibili dalle sezioni dedicate. Dati dell’azienda, regime, periodicità e pro rata vengono configurati per il relativo contesto; il regime è gestito per anno solare.

Un utente può avere più aziende; il ruolo amministratore accede all’intera installazione. La ricerca rapida collega fatture e anagrafiche. Le schermate si adattano anche a dispositivi mobili.

## Archivio e tracciabilità

L’applicazione include cronologia delle attività, catena di hash dell’audit, archivio annuale con impronte SHA-256 e strumenti di backup e ripristino. Queste funzioni consentono controlli tecnici, ma non sono una certificazione e non sostituiscono la conservazione a norma.

Per la descrizione tecnica e le prove già documentate consulta [STATUS](STATUS.md) e [TEST_MATRIX](TEST_MATRIX.md). Per vedere l’interfaccia, apri la [galleria completa](SCHERMATE.md).
