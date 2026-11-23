# Beta e limiti attuali

Semplicon è in **versione beta e in fase di test**. Questa pagina riassume il perimetro per chi vuole provarlo. Si basa sul codice e sulla documentazione presenti nel repository al 23 novembre 2026; non certifica il superamento di nuove prove.

## Cosa significa beta

L’applicazione contiene flussi utilizzabili per le prove, una demo e una suite di test. La validazione professionale delle regole fiscali è ancora aperta. Non è approvata per la produzione o per adempimenti reali; funzioni e dati possono cambiare durante lo sviluppo.

## Limiti da conoscere

| Ambito | Situazione attuale |
|---|---|
| Invio SdI | Nessuna trasmissione diretta. Gli XML devono essere inviati con un canale esterno. Lo stato «trasmessa» è un’annotazione nell’applicazione. |
| LIPE | Generazione IVP18 e stampa di controllo presenti; verifica rispetto allo schema ufficiale ancora aperta secondo lo stato tecnico. |
| F24 e IVA TR | Strumenti e prospetti di lavoro in beta. Non presentarli come servizio di invio o pagamento. |
| Regimi particolari | Regime del margine, altri regimi particolari e IVA per cassa non disponibili. La gestione per cassa di incassi e pagamenti non equivale all’IVA per cassa. |
| Contabilità ordinaria | Non rientra nel perimetro operativo descritto per questa beta. |
| Profili fiscali | L’opzione interna «Soggetto forfettario con IVA» richiede verifica del caso concreto; non equivale a una copertura generale del regime forfettario. |
| Conservazione | Archivio privato, hash e backup non sostituiscono la conservazione a norma. |
| P7M | La documentazione segnala la verifica dell’integrità, ma non della catena di fiducia dei certificati. |
| Accessi | Reset password, conferma e-mail e autenticazione a due fattori risultano non implementati. Più persone sulla stessa azienda non sono previste. |
| Database | Prove documentate su PostgreSQL e SQLite; MySQL/MariaDB non ancora testati. |
| Accessibilità e carico | Ulteriori verifiche con screen reader, zoom, grandi volumi di import e operazioni concorrenti restano aperte. |

## Demo HTML e applicazione web

La demo in `demo/semplicon-demo.html` è autonoma: conserva dati nel browser e riproduce l’esperienza dell’applicazione. Alcuni comportamenti sono simulati o semplificati: VIES, cespiti, backup e calendario fiscale. Il backup della demo non è un backup del database e degli originali dell’applicazione web.

Le schermate nella documentazione rappresentano la demo con dati sintetici. Gli importi, i calcoli e le date visibili non sono indicazioni fiscali da applicare a casi reali.

## Priorità di consolidamento

- Validare regole fiscali, casi limite e risultati dei prospetti.
- Completare le verifiche dei file fiscali generati.
- Consolidare accessi, backup e ripristino.
- Ampliare prove di accessibilità, concorrenza e carico.
- Mantenere coerenti codice, demo e documentazione.

Sono aree di lavoro, senza una data di rilascio promessa. Il dettaglio resta nel [piano](PLAN.md), nella [matrice di test](TEST_MATRIX.md) e nello [stato tecnico](STATUS.md). Alcune note storiche di questi documenti possono precedere funzionalità aggiunte successivamente: per una verifica puntuale confronta anche il codice e le [decisioni](DECISIONS.md).
