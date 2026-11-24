# Hosting

Semplicon è una normale applicazione Laravel. Node.js serve solo per compilare l'interfaccia, non sul server.

## Variante A · Webspace (shared hosting)

Adatta per demo e pilota, se il fornitore offre quanto segue:

| Requisito | Nota |
|---|---|
| PHP 8.3+ con `pdo_mysql` o `pdo_pgsql`, `mbstring`, `xml`, `zip`, `openssl` | Laravel 13 e successivo import XML/ZIP |
| Document root impostabile su `web/public` | Obbligatorio. Altrimenti `.env` e i file sono raggiungibili pubblicamente. |
| MySQL/MariaDB o PostgreSQL | Verificare prima MySQL una volta con la suite di test (vedere STATUS.md) |
| Cronjob ogni minuto | `php /percorso/web/artisan schedule:run` – elabora gli import (coda su database) |
| Limiti di upload in PHP | `upload_max_filesize` ≥ 50M, `post_max_size` ≥ 60M, `max_file_uploads` ≥ 50 (per archivi ZIP e upload multiplo) |
| Estensioni | inoltre `dom`, `libxml`, `openssl` (P7M), `zip` |
| SSH (consigliato) | per `php artisan migrate --force` |
| HTTPS, sede UE, backup, contratto di trattamento dati (DPA) | GDPR: dati di fatture e dati fiscali dei clienti |

Procedura:

1. In locale: `composer install --no-dev --optimize-autoloader` e `npm ci && npm run build` in `web`.
2. Caricare la cartella `web` (inclusi `vendor/` e `public/build/`, senza `node_modules/`).
3. Impostare il document root su `web/public`.
4. Creare `.env` sul server (a partire da `.env.example`): `QUEUE_CONNECTION=database`, `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://…`, accesso al database, `SESSION_SECURE_COOKIE=true`, `APP_KEY` con `php artisan key:generate`.
5. Via SSH: `php artisan migrate --force`, `php artisan config:cache`, `php artisan route:cache`, `php artisan view:cache`.
6. Creare l'amministratore: `php artisan semplicon:user name@example.com "Nome Cognome" --admin`. Subito dopo i clienti si registrano da soli su `/registrati`.
7. `storage/` e `bootstrap/cache/` devono essere scrivibili da PHP.

Non eseguire il seeder demo sul server; in `production` si interrompe.

## Variante B · Piccolo server dedicato (consigliato per l'esercizio reale)

Ad esempio un server nell'UE, gestito tramite Laravel Forge o Ploi: PostgreSQL, queue worker come servizio, backup e certificati automatici. In tal caso vale l'architettura del piano senza restrizioni.

## Da chiarire prima dell'esercizio reale

- Conservazione: l'archivio privato non sostituisce la conservazione a norma (Piano §4).
- Testare backup e ripristino (I07).
- Implementare il reset della password e l'autenticazione a due fattori.
