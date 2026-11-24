# Contribuire a Semplicon

Grazie per il tuo interesse. Semplicon è in beta: servono contributi sul codice, sui testi, sull’esperienza d’uso e sulla verifica dei casi contabili.

## Segnalare un problema

Controlla prima le [issue aperte](https://github.com/arassele/semplicon/issues). Per una nuova segnalazione indica:

- Demo HTML oppure applicazione web; versione o commit, se disponibile.
- Browser e sistema operativo; database e ambiente PHP/Node se pertinenti.
- Passaggi per riprodurre il problema.
- Risultato atteso e risultato ottenuto.
- Eventuale esempio minimo con dati interamente fittizi.

Non allegare fatture reali, dati dei clienti, credenziali, file `.env` o backup dell’attività. Per una possibile vulnerabilità non pubblicare dettagli sfruttabili: chiedi prima al manutentore un canale riservato, senza esporre il problema tecnico.

## Proporre un miglioramento

Descrivi l’attività che vuoi svolgere, l’ostacolo attuale e il beneficio della proposta. Per modifiche ampie apri prima una issue per concordare il perimetro.

## Contribuire al codice

1. Crea un fork e un ramo dedicato.
2. Segui la [guida di installazione](docs/INSTALLAZIONE.md).
3. Mantieni la modifica concentrata su un problema e aggiorna la documentazione interessata.
4. Per variazioni di comportamento aggiungi o aggiorna test significativi. Per sole modifiche ai testi controlla link, esempi e rendering.
5. Apri una pull request descrivendo problema, modifica, verifiche eseguite ed eventuali limiti.

Per modifiche applicative, dalla cartella `web/`:

```sh
php artisan test
npx vue-tsc --noEmit
npm run build
```

Configura un database di test dedicato come indicato nella guida. Riporta ciò che hai eseguito e segnala eventuali verifiche non effettuate.

## Contribuire alla verifica fiscale

Sono particolarmente utili casi sintetici con dati iniziali, passaggi, risultato atteso, anno di riferimento e fonte normativa o tecnica pertinente. Distingui un errore di implementazione da una regola ancora da validare. I riferimenti di lavoro sono [FISCAL_RULES](docs/FISCAL_RULES.md) e [TEST_MATRIX](docs/TEST_MATRIX.md).

Il superamento dei test tecnici non chiude automaticamente la validazione fiscale.

## Lingua e licenza

Scrivi documentazione, segnalazioni e spiegazioni preferibilmente in italiano. I contributi devono essere compatibili con la [licenza AGPL-3.0-only](LICENSE) del progetto. Non includere materiali o codice di terzi senza averne verificato le condizioni d’uso.
