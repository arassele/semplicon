# Installazione locale

Questa guida serve a provare la beta con dati sintetici. Per lo stato dei controlli e le limitazioni consulta [Beta e limiti](BETA.md).

## Requisiti

- Git, PHP 8.3+ e Composer; estensioni richieste dalle dipendenze, incluse quelle per XML, ZIP e il database scelto.
- Node.js compatibile con Vite 8: `^20.19.0 || >=22.12.0`. Per un ambiente uniforme puoi usare la linea Node.js 22, dalla versione 22.12.
- PostgreSQL (la documentazione tecnica riporta prove su PostgreSQL 16), oppure SQLite per le prove locali.

## 1. Scarica il progetto e installa le dipendenze

```sh
git clone https://github.com/arassele/semplicon.git
cd web
composer install
npm ci
cp .env.example .env
php artisan key:generate
```

I comandi seguenti vanno eseguiti dalla cartella `web/`.

## 2. Configura il database e gli import

Per PostgreSQL crea prima un database locale dedicato e un utente autorizzato. Imposta nel file `.env`:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=semplicon
DB_USERNAME=semplicon
DB_PASSWORD=la_tua_password_locale
QUEUE_CONNECTION=sync
```

In alternativa, per SQLite imposta `DB_CONNECTION=sqlite`, rimuovi le altre righe `DB_` dell’esempio e crea il file:

```sh
php -r "touch('database/database.sqlite');"
```

Con `QUEUE_CONNECTION=sync` gli import vengono elaborati durante la richiesta. Se usi `QUEUE_CONNECTION=database`, avvia anche un worker in un secondo terminale:

```sh
php artisan queue:work
```

## 3. Inizializza e avvia

Su un database locale dedicato alle prove:

```sh
php artisan migrate --seed
npm run build
php artisan serve
```

Apri <http://127.0.0.1:8000>.

### Account di esempio

La password dei tre account demo è `semplicon-demo`.

| E-mail | Percorso di prova |
|---|---|
| `jana@semplicon.test` | Contabilità professionisti, anno 2026 confermato e 2027 in bozza, documenti da registrare. |
| `marco@semplicon.test` | Due aziende con configurazioni annuali in bozza; una delle aziende è bloccata. |
| `admin@semplicon.test` | Amministratore con accesso a tutte le aziende. |

Queste credenziali sono pubbliche e servono esclusivamente per le prove locali. Il seeder demo non va usato su un’installazione esposta al pubblico.

I nuovi utenti si registrano su `/registrati`. Un amministratore si crea da console:

```sh
php artisan semplicon:user nome@example.com "Nome Cognome" --admin
```

## Sviluppo dell’interfaccia

Durante lo sviluppo, al posto della compilazione statica, avvia `npm run dev` in un secondo terminale e mantieni attivo `php artisan serve`.

## Verifiche tecniche

Il file [`phpunit.xml`](../web/phpunit.xml) configura PostgreSQL e il database `semplicon_test`. Usa un database di test dedicato, mai quello contenente dati da conservare.

```sh
php artisan test
npx vue-tsc --noEmit
npm run build
```

Per eseguire la suite con SQLite in memoria, in una shell compatibile:

```sh
DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan test
```

Gli esempi XML sono in [`web/tests/fixtures/fatturapa/official/`](../web/tests/fixtures/fatturapa/official/). La raccolta contiene anche un esempio che la documentazione segnala come non valido rispetto allo schema utilizzato: un rifiuto non indica necessariamente un malfunzionamento.

## Hosting

L’applicazione può essere ospitata su un server proprio. Node.js serve per compilare l’interfaccia e non deve necessariamente essere presente sul server che la esegue. Il document root deve puntare a `web/public`.

Vedi le [note tecniche di hosting](HOSTING.md). Si tratta di istruzioni infrastrutturali, non di un’approvazione della beta per l’uso reale. MySQL/MariaDB non risultano ancora verificati nella documentazione di stato.
