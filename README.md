# Semplicon

> Progetto in sviluppo. Le regole fiscali non sono ancora state approvate da un professionista: non usare per adempimenti reali.

**La tua contabilità IVA, semplice.** Contabilità IVA per ex forfettari e imprese in contabilità semplificata.

| Cartella | Contenuto |
|---|---|
| `web/` | Applicazione: Laravel 13, Vue 3/TypeScript, Inertia, PostgreSQL |
| `demo/` | Demo autonoma in un solo file HTML (`semplicon-demo.html`), senza server: apribile direttamente nel browser |
| `docs/` | Piano, concept, STATUS, DECISIONS, FISCAL_RULES, TEST_MATRIX, HOSTING |
| `design/` | Linee guida di design, token CSS, logo |

Stato attuale e limiti: `docs/STATUS.md`. **Nessuna approvazione per la produzione.** Le regole fiscali non sono ancora state approvate.

## Avvio in locale

Requisiti: PHP 8.3+, Composer, Node.js 20+, PostgreSQL (oppure SQLite per provare).

```sh
cd web
composer install
npm install
cp .env.example .env
php artisan key:generate
```

Inserire il database in `.env` (`DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`). Senza PostgreSQL, per provare si può usare anche SQLite: impostare `DB_CONNECTION=sqlite`, eliminare le altre righe `DB_` ed eseguire `touch database/database.sqlite`.

```sh
php artisan migrate --seed
npm run build
php artisan serve
```

Poi aprire http://127.0.0.1:8000. Account demo (dati sintetici):

- `admin@semplicon.test` / `semplicon-demo` (amministratore, vede e modifica tutte le aziende)
- `giulia@semplicon.test` / `semplicon-demo` (cliente, Contabilità professionisti, forfettario con IVA: 2026 confermato, 2027 come bozza, quattro fatture importate da registrare)
- `marco@semplicon.test` / `semplicon-demo` (cliente, due aziende con bozze 2026, una delle quali bloccata)

I nuovi clienti si registrano su `/registrati`. Un amministratore si crea solo da console: `php artisan semplicon:user name@example.com "Name" --admin`.

Durante lo sviluppo, al posto di `npm run build` tenere in esecuzione in parallelo `npm run dev`.

Gli import vengono eseguiti come job. Per l'avvio in locale impostare in `.env` `QUEUE_CONNECTION=sync` (import immediato al caricamento) oppure, con `QUEUE_CONNECTION=database`, avviare in parallelo `php artisan queue:work`. File di esempio per provare: `web/tests/fixtures/fatturapa/official/` (esempi ufficiali, Cedente P.IVA 01234567890).

## Test

```sh
cd web
php artisan test
```

I test richiedono un database PostgreSQL vuoto `semplicon_test` (vedere `phpunit.xml`). In alternativa, senza PostgreSQL:

```sh
DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan test
```

## Licenza

Semplicon è software libero, rilasciato sotto la licenza [GNU Affero General Public License v3.0](LICENSE) (AGPL-3.0-only). Chi offre una versione modificata come servizio in rete deve mettere a disposizione degli utenti il relativo codice sorgente.
