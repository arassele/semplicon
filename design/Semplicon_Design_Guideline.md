# Semplicon · Linee guida di design

Versione 1.0 · 15 novembre 2026 · Prodotto: contabilità IVA

**Calmo. Preciso. Accessibile.**

Queste linee guida traducono il moodboard del marchio e la bozza desktop forniti in indicazioni di progettazione attuabili. Integrano il piano di programmazione IA di Semplicon versione 2.0. Le schermate servono come riferimento visivo; per funzioni e navigazione vale l'attuale ambito solo IVA.

I valori di colore, la scelta del font e le misure definiti di seguito sono design token stabiliti ex novo, visivamente ispirati ai modelli. Non sono valori originali dichiarati ricavati dalle immagini raster. Il logo resta una grafica separata; il font dell'interfaccia non sostituisce il suo logotipo.

## 1. Principi di progettazione

1. **I numeri guidano.** Documenti, valori IVA e verifiche aperte vengono prima degli elementi decorativi.
2. **Orientamento scuro, lavoro chiaro.** Navigazione verde scuro; area di lavoro bianco caldo.
3. **La menta indica la selezione.** Navigazione attiva e accenti discreti, non genericamente «successo».
4. **Uno stato è un'affermazione.** Importato, verificato, registrato e chiuso sono stati diversi.
5. **L'azione successiva è chiara.** Per ogni area di lavoro un'azione principale evidenziata.
6. **L'IA suggerisce.** I suggerimenti restano visivamente separati dai dati confermati.

Da non adottare: grandi foto di marketing nelle maschere di lavoro, superfici molto lucide, grafici decorativi, card annidate più volte, numeri verdi senza motivo fiscale e l'affermazione non documentata «senza errori».

## 2. Marchio e logo

Il riferimento mostra una C geometrica formata da una parte superiore chiara e da un segmento inferiore verde, combinata con il logotipo in minuscolo «semplicon».

| Impiego | Indicazione |
|---|---|
| Sidebar | Logotipo chiaro e marchio figurativo chiaro/menta su Forest |
| Superfici chiare | Logotipo scuro, marchio figurativo nella variante positiva approvata |
| Icona dell'app | Marchio figurativo senza logotipo su quadrato verde scuro con angoli arrotondati |
| Area di rispetto | Almeno un quarto dell'altezza del marchio figurativo attorno a tutto il marchio |
| Dimensione nella sidebar | Intero gruppo logo ca. 160–176 px di larghezza; marchio figurativo ca. 32 px di altezza |
| Visualizzazione piccola | Marchio figurativo da 24 px; al di sotto, variante semplificata verificata separatamente |
| Larghezza minima del logotipo | 112 px come valore di partenza, verificare la leggibilità nell'asset originale |

Non stirare, ruotare, ridisegnare né simulare come carattere di testo. Non programmare una nuova forma di C «simile». Per la produzione è necessario un SVG originale approvato; i riferimenti JPEG non bastano come file master scalabile. Finché manca, usare un segnaposto temporaneo chiaramente contrassegnato.

Claim del marchio per la comunicazione generale: «La contabilità, finalmente semplice.»
Descrizione del prodotto della prima versione: **«La tua contabilità IVA, semplice.»**

## 3. Palette dei colori

### Colori di base

| Token | Hex | Utilizzo |
|---|---|---|
| `forest-950` | `#003D35` | Sidebar, pulsanti principali, accenti forti |
| `forest-900` | `#005247` | Hover sulle azioni scure |
| `forest-1000` | `#002E28` | Stato premuto |
| `mint-500` | `#47E58A` | Accento del marchio, breve sottolineatura; non come testo piccolo |
| `mint-100` | `#C5F7D7` | Voce di menu attiva, elementi selezionati |
| `mint-50` | `#EDF9F1` | Superficie dei suggerimenti IA ed evidenziazione discreta |
| `canvas` | `#F7F5F1` | Intera area di lavoro |
| `surface` | `#FFFFFF` | Card, moduli, tabelle |
| `surface-subtle` | `#F1F0EB` | Superfici secondarie, hover |
| `ink` | `#102F2A` | Testo principale |
| `text-muted` | `#52665F` | Indicazioni supplementari |
| `border` | `#DCE3DD` | Separatori decorativi e bordi delle card |
| `control-border` | `#788A81` | Bordi riconoscibili di campi/controlli |

Nessun testo bianco su menta. Su menta usare sempre Ink o Forest. I bordi sottili delle card servono solo al raggruppamento; i campi di input richiedono il colore di controllo più marcato.

### Colori semantici

| Significato | Testo/icona | Sfondo | Esempio |
|---|---|---|---|
| Successo | `#17603D` | `#E6F5EB` | Registrata |
| Verifica necessaria | `#7A4B00` | `#FFF0CC` | Da verificare |
| Errore / bloccato | `#A32626` | `#FDECEC` | Importazione non riuscita |
| Informazione | `#245B78` | `#EAF3FA` | Duplicato rilevato |
| Neutro | `#52665F` | `#EEF1ED` | Bozza, periodo chiuso |

Combinare sempre i colori semantici con testo e, se necessario, icona. Mai solo un codice rosso/verde. Gli importi a debito e a credito sono inizialmente normali numeri scuri; «a debito» e «a credito» ne spiegano il significato. Un debito IVA non è un errore.

## 4. Tipografia e numeri

**Font dell'interfaccia: Inter**, ospitato in proprio dopo verifica della fonte di approvvigionamento/licenza. Fallback: system-ui, -apple-system, BlinkMacSystemFont, Segoe UI, sans-serif. Questa è una decisione di design, non l'identificazione del font dall'immagine.

| Ruolo | Dimensione / interlinea | Peso |
|---|---|---|
| Titolo di pagina | 32 / 40 px | 650–700 |
| Titolo di pagina mobile | 26 / 34 px | 650–700 |
| Sezione | 20 / 28 px | 600 |
| Importo KPI | 28 / 36 px | 650 |
| Testo standard | 15 / 22 px | 400 |
| Etichetta / navigazione | 14 / 20 px | 500–600 |
| Tabella | 14 / 20 px | 400; ID importante 600 |
| Integrazione / badge | 12 / 16 px | 500 |

Valori rilevanti per il lavoro non più piccoli di 14 px. 12 px solo per brevi informazioni aggiuntive. Niente maiuscole continue, tranne il compatto badge «DEMO».

I numeri ricevono `font-variant-numeric: tabular-nums lining-nums`. Importi allineati a destra, testo a sinistra. Formato: **1.234,56 Euro**. Nelle tabelle dense la colonna si chiama «Importo (Euro)» e le celle contengono solo «1.234,56». All'interno di una tabella non mescolare Euro e €.

Data: `15/11/2026`; periodo con nome: «Novembre 2026». Mantenere il segno meno; non contrassegnare i valori negativi solo in rosso. Valuta e arrotondamento provengono da dati verificati; la visualizzazione non deve sostituire il calcolo fiscale. Zero «0,00», sconosciuto «—» con testo esplicativo. Sconosciuto non è zero.

## 5. Spaziature, raggi e superfici

Scala delle spaziature: **4, 8, 12, 16, 24, 32, 48, 64 px**. Tra etichetta e campo 8 px; tra i campi del modulo 16–24 px; tra le sezioni 32 px.

| Elemento | Indicazione |
|---|---|
| Pulsante / input | Altezza 44 px; raggio 10 px |
| Grande azione touch | Altezza 48 px |
| Card / pannello | Raggio 16 px; padding 24 px desktop, 16 px mobile |
| Badge di stato | Raggio 999 px; verticale 4, orizzontale 8 px |
| Riga di tabella | Standard 52 px; vista compatta desktop opzionale 44 px |
| Dropdown / dialogo | Raggio 12 / 16 px |

Card per impostazione predefinita solo con bordo sottile. Ombre solo per elementi sovrapposti, ad es. dropdown e dialogo: `0 12px 32px rgb(0 61 53 / 12%)`. Nessuna ombra sotto ogni riga di tabella. Le grandi tessere di marketing con angoli arrotondati non vengono riprese con le stesse misure nelle maschere di lavoro.

## 6. Cornice dell'app e comportamento responsive

### Desktop da 1280 px

- Sidebar 248 px, fissa, con area di scorrimento verticale propria se necessario.
- Topbar 72 px; ricerca flessibile, notifiche e profilo a destra.
- Area di lavoro: margine esterno 32 px, larghezza massima del contenuto 1600 px.
- Intestazione: titolo/sottotitolo a sinistra, selezione del periodo e azione principale a destra.
- Quattro KPI come superficie unica suddivisa invece di quattro grandi card decorative.
- Contenuto principale flessibile; colonna di contesto opzionale a destra di 320 px, distanza 24 px.
- Non riservare un pannello IA se l'IA non è disponibile nella release attuale.

### Tablet 768–1279 px

- Navigazione tramite barra di icone da 72 px o drawer accessibile; etichette complete nel drawer.
- Colonna di contesto sotto il contenuto principale; superficie KPI come griglia 2×2.
- Margine esterno 24 px. I filtri vanno a capo in un ordine definito.
- Su iPad scorrimento e selezione delle righe non devono presupporre un'azione hover.

### Mobile sotto 768 px

- Navigazione a drawer, riga di intestazione 56 px, margine esterno 16 px.
- Titolo e azione principale uno sotto l'altro; ricerca tramite un accesso alla ricerca chiaramente etichettato.
- Verifica del documento in due tab «Documento» e «Registrazione IVA».
- L'elenco delle fatture può mostrare voci compatte con numero del documento, controparte, importo e stato. I registri con molte colonne restano scorrevoli in orizzontale, con un avviso visibile.
- Nessuna informazione obbligatoria nascosta in silenzio. Azioni di almeno 44×44 px come area touch interna.
- La barra delle azioni fissa non deve coprire l'ultima riga, i messaggi di errore o la tastiera su schermo.

Larghezze da verificare: 390, 768, 1024, 1440 e 1920 px. Nessun allargamento orizzontale della pagina; solo le aree di tabella espressamente previste possono scorrere in orizzontale.

## 7. Navigazione e ambito del prodotto

| Area | Etichetta | Scopo |
|---|---|---|
| Panoramica | Panoramica | Situazione IVA e prossime verifiche |
| Import | Importa XML | Upload e cronologia degli import |
| Entrate | Acquisti | Documenti in entrata e rilevazione IVA |
| Uscite | Vendite | Documenti in uscita e rilevazione IVA |
| Lista di lavoro | Da verificare | Casi aperti e richieste di chiarimento |
| Registri | Registri IVA | Acquisti/Vendite ed esportazione |
| Liquidazione | Liquidazioni | Periodi, risultati e chiusura |
| Impostazioni | Impostazioni | Mandante, profilo IVA, utenti e diritti |

L'azione principale «Importa XML» può comparire anche nell'intestazione della pagina. Apre lo stesso flusso della voce di navigazione.

Da non riprendere dal vecchio screenshot: Contabilità come contabilità generale completa, Movimenti, calcolatore di imposte per forfettari, Fatturato e Da incassare come indicatori chiave. Nessun presunto import diretto dall'Agenzia, finché è implementato solo l'upload di file.

Mandante e periodo restano riconoscibili in ogni momento. Al cambio di mandante con input non salvati: offrire Salva/Scarta/Annulla. Non mescolare mai in silenzio risultati e risultati di ricerca tra mandanti.

## 8. Componenti

### Pulsanti

| Variante | Aspetto | Utilizzo |
|---|---|---|
| Primary | Forest, testo bianco | Importa XML; Registra; Chiudi periodo |
| Secondary | Bianco, testo Forest, Control-Border | Esporta; Salva bozza |
| Tertiary | Sfondo trasparente, testo Forest | Vedi tutte; Annulla |
| Destructive | Colore di errore, se necessario superficie di errore chiara | Conferma storno |

Hover di Primary: Forest-900; premuto: Forest-1000. Focus: anello marcato con distanza. Loading: icona/spinner più testo specifico dell'azione, larghezza stabile. Impedire l'attivazione ripetuta durante l'elaborazione. Disabled: motivo esplicativo visibile; non solo un pulsante grigio.

«Chiudi periodo» porta a un riepilogo con mandante, periodo, casi aperti ed effetto. Le conferme critiche nominano l'azione, non un semplice «OK». Registrazioni e chiusura del periodo sono azioni diverse.

### Moduli

Etichetta sempre sopra il campo; il placeholder solo come esempio. «Data fattura», «Data ricezione», «Data registrazione» e «Periodo IVA» come campi separati. Spiegare i campi obbligatori con il testo. Errore direttamente sotto il campo, in aggiunta un riepilogo in caso di più errori. Conservare gli input dell'utente dopo un errore.

Selezione del codice IVA: codice più denominazione comprensibile. Non precompilare i dati richiesti mancanti solo per ottenere un'interfaccia verde. «Non disponibile» e «Da confermare» sono stati espliciti.

### Tabelle

Riga di intestazione 44 px, riga 52 px. Separatori orizzontali discreti, nessuna griglia verticale nell'elenco normale dei documenti. Intestazioni e assi dei numeri stabili. Ordinamento come pulsante con nome e direzione. Mostrare paginazione e numero di risultati; mantenere lo stato dei filtri.

Colonne consigliate per i documenti: Documento · Cliente/Fornitore · Data fattura · Imponibile (Euro) · IVA (Euro) · Stato · Azioni. In caso di poco spazio, offrire le colonne meno importanti nella vista di dettaglio, mantenendo importi/stato. Nomi lunghi al massimo su due righe; il nome completo deve essere accessibile tramite focus o apertura del dettaglio.

Le righe possono aprirsi, ma hanno inoltre un link univoco alla vista di dettaglio. La selezione con checkbox non provoca l'apertura. Il menu di riga non è visibile solo all'hover. L'azione collettiva mostra il numero e riguarda esclusivamente la selezione.

### Upload

Pulsante di selezione file più area drag-and-drop; il drag-and-drop non è mai l'unica via. Mostrare i formati consentiti e gli effettivi limiti di dimensione dalla configurazione. Non dichiarare il supporto P7M se manca.

Durante l'elaborazione: avanzamento complessivo, nome del file, stato del singolo file ed errori. Contare il risultato separatamente: «24 file elaborati · 22 importati · 2 da verificare». Importare non significa registrare. In caso di errori parziali i file riusciti restano visibili; «Riprova file non riusciti» ripete solo le operazioni interessate.

## 9. Separare correttamente gli stati

| Contesto | Stato | Rappresentazione / affermazione |
|---|---|---|
| Import | In elaborazione | Neutro, avanzamento/spinner |
| Import | Importato | Info; ancora nessuna approvazione fiscale |
| Import | Duplicato | Info più riferimento al documento esistente |
| Import | Non riuscito | Errore più rimedio concreto |
| Registrazione | Bozza | Neutro |
| Registrazione | Da verificare | Ambra più motivo |
| Registrazione | Registrata | Successo, approvazione tracciabile |
| Registrazione | Stornata | Neutro, originale e controregistrazione collegati |
| Periodo | Aperto | Neutro |
| Periodo | Bozza | Calcolo provvisorio, nessun effetto di chiusura |
| Periodo | Bloccato | Errore o ambra a seconda della causa; spiegare l'ostacolo |
| Periodo | Chiuso | Icona lucchetto, neutro; sola lettura |

Lo stato di pagamento come «Da incassare» non appartiene a questa colonna di stato né all'MVP solo IVA.

Empty state: «Non hai ancora importato documenti.» + «Importa XML».
Nessun risultato dei filtri: «Nessun documento corrisponde ai filtri.» + «Azzera filtri».
Dati mancanti: «Data di ricezione da confermare.» + «Verifica documento».
Caso non supportato: «Questo caso IVA richiede una verifica dello studio.»
Stato di caricamento: aree skeleton stabili, nessun importo zero simulato.

## 10. Tre schermate centrali

### A. Panoramica

Titolo «La tua situazione IVA». Il sottotitolo indica mandante e periodo. «Tutto sotto controllo» dal moodboard solo se effettivamente non ci sono problemi aperti; altrimenti un titolo sobrio.

Quattro indicatori: IVA a debito · IVA detraibile · Saldo provvisorio · Da verificare. In caso di dati incompleti «Bozza» più avviso. Contrassegnare sempre i valori di esempio come «Dati dimostrativi».

Sotto, la tabella «Documenti recenti» e l'area di contesto «Situazione IVA». Azione principale «Importa XML». Seconda priorità «Verifica documenti». Nessun grafico finanziario senza una domanda fiscale concreta.

### B. Verificare il documento

Desktop: a sinistra ca. 55 % documento/originale leggibile, a destra ca. 45 % modulo. Intestazione comune con numero del documento, controparte e stato. Blocchi del modulo: Dati documento · Trattamento IVA · Periodo e registrazione. Più righe IVA come griglia chiaramente strutturata.

Sotto, assegnati in modo fisso: «Salva bozza» e «Registra». Avvisi critici vicino all'input interessato; alla registrazione, focus sul primo campo non risolto. Cronologia in una sezione espandibile; nessuna visualizzazione permanente sovraccarica.

### C. Liquidazione

Intestazione: mandante, periodo, stato e stato di calcolo. Voci tracciabili con fonti collegate; debito, detraibile, riporti/adeguamenti ammessi separati. L'interfaccia mostra i calcoli del nucleo fiscale e non inventa una formula propria.

Ostacoli aperti visibili prima della chiusura. «Chiudi periodo» solo in uno stato fiscalmente ammissibile. Dopo la chiusura mostrare snapshot, momento e responsabile; la riapertura come azione autorizzata a sé con motivazione.

## 11. Area IA

Pannello opzionale in Mint-50, nessuna chatbox dominante. Titolo «Suggerimenti IA», sottotitolo «Da confermare». Il suggerimento mostra il documento interessato, il codice IVA suggerito, la motivazione e la fonte. Nessuna indicazione generica «sicuro al 99 %» senza una base valida.

Azioni: «Esamina», poi «Applica alla bozza» o «Ignora». L'adozione in una bozza non è una registrazione. L'IA non deve generare uno stato di approvazione verde. In caso di indisponibilità: «I suggerimenti non sono disponibili. Puoi continuare manualmente.»

Se l'IA non è ancora implementata, nascondere il pannello; nessun pulsante demo artificialmente attivo in un'interfaccia produttiva.

## 12. Icone, linguaggio visivo e movimento

Uno stile di icone outline coerente: 20 px, spessore del tratto ca. 1,75–2 px, estremità arrotondate. Navigazione 20–22 px; icona KPI 24 px in un'area di supporto di massimo 40 px. Non mescolare più librerie di icone. I pulsanti solo icona ricevono nomi accessibili; i tooltip sono un'integrazione.

Le foto appartengono alla comunicazione del marchio o, facoltativamente, all'introduzione, non agli elenchi di fatture e alle liquidazioni. Le superfici verdi botaniche e organiche del modello usarle solo con parsimonia come motivo del marchio. Nessun motivo dietro i numeri.

Transizioni 120–180 ms per colore/opacità; nessun effetto elastico. Rispettare il movimento ridotto. I processi di caricamento mostrano lo stato effettivo, nessuna animazione di attesa artificiale.

## 13. Accessibilità come obiettivo di approvazione

Obiettivi di design interni: contrasto del testo almeno 4,5:1 per il testo normale, 3:1 per i caratteri grandi; bordi dei controlli essenziali e indicatori di focus almeno 3:1 rispetto alle superfici adiacenti. Misurare le combinazioni concrete, non presumerle in base al nome del colore. Questa non è una certificazione dell'applicazione non ancora implementata.

L'ordine di tabulazione segue il flusso di lavoro. Focus visibile su ogni azione. Drawer e dialogo hanno gestione del focus, comportamento di Escape e ritorno all'elemento che li ha attivati. In presenza di modifiche non salvate Escape non deve scartare dati inavvertitamente. Annunciare successo/errore alle tecnologie assistive. Moduli con vere etichette, tabelle con intestazioni semantiche.

Con zoom al 200 % tutte le azioni restano raggiungibili. Selezione, avvisi e navigazione attiva sono riconoscibili anche tramite testo, forma o icona. Le informazioni non devono essere disponibili esclusivamente tramite hover.

## 14. Token CSS per l'implementazione

```css
:root {
  --ct-forest: #003d35;
  --ct-forest-hover: #005247;
  --ct-forest-pressed: #002e28;
  --ct-accent: #47e58a;
  --ct-selected: #c5f7d7;
  --ct-tint: #edf9f1;
  --ct-canvas: #f7f5f1;
  --ct-surface: #ffffff;
  --ct-surface-subtle: #f1f0eb;
  --ct-text: #102f2a;
  --ct-muted: #52665f;
  --ct-border: #dce3dd;
  --ct-control-border: #788a81;
  --ct-success: #17603d;
  --ct-success-bg: #e6f5eb;
  --ct-warning: #7a4b00;
  --ct-warning-bg: #fff0cc;
  --ct-error: #a32626;
  --ct-error-bg: #fdecec;
  --ct-info: #245b78;
  --ct-info-bg: #eaf3fa;
  --ct-neutral-bg: #eef1ed;
  --ct-font: Inter, system-ui, -apple-system, BlinkMacSystemFont,
             "Segoe UI", sans-serif;
  --ct-space-1: 4px;
  --ct-space-2: 8px;
  --ct-space-3: 12px;
  --ct-space-4: 16px;
  --ct-space-6: 24px;
  --ct-space-8: 32px;
  --ct-space-12: 48px;
  --ct-space-16: 64px;
  --ct-radius-control: 10px;
  --ct-radius-panel: 16px;
  --ct-radius-popover: 12px;
  --ct-sidebar: 248px;
  --ct-topbar: 72px;
  --ct-control-height: 44px;
  --ct-shadow-overlay: 0 12px 32px rgb(0 61 53 / 12%);
  --ct-duration: 160ms;
}
.ct-amount { font-variant-numeric: tabular-nums lining-nums; }
.ct-focusable:focus-visible {
  outline: 3px solid var(--ct-forest);
  outline-offset: 3px;
}
.ct-sidebar .ct-focusable:focus-visible {
  outline-color: var(--ct-selected);
}
@media (prefers-reduced-motion: reduce) {
  :root { --ct-duration: 0ms; }
}
```

Usare questi token in modo centralizzato; nessun valore hex divergente per pagina. I componenti ereditano la semantica tramite props come `variant="warning"`, non tramite raccolte arbitrarie di classi in ogni schermata.

Componenti consigliati: AppShell, CompanySwitcher, PeriodPicker, PageHeader, MetricStrip, DocumentTable, StatusBadge, XmlUpload, VatRegistrationForm, PeriodSummary, EmptyState, ConfirmDialog e opzionalmente AiSuggestionPanel.

## 15. Incarico di implementazione per un assistente di programmazione IA

```text
Leggi PLAN.md versione 2.0 e Semplicon_Design_Guideline.md.
Usa il moodboard del marchio per la direzione visiva e la schermata
desktop per la disposizione, ma le linee guida per i token concreti e
per l'ambito solo IVA. Non inventare moduli di prodotto aggiuntivi.

Crea design token centrali e componenti riutilizzabili.
Costruisci prima AppShell, Sidebar, PageHeader, MetricStrip, DocumentTable,
StatusBadge, EmptyState e campi dei moduli. Poi Panoramica,
Importa XML, verifica del documento e Liquidazione.

Usa Forest/Mint/Ivory, Inter per l'interfaccia, numeri tabulari ed
etichette italiane. Usa un asset del logo approvato; se manca,
contrassegna il segnaposto e non inventare una nuova forma di logo.
Mostra i quattro indicatori IVA invece di Fatturato/Da incassare.

Distingui lo stato di import, di registrazione e di periodo. Mostra errori,
stati vuoti, stati di caricamento e diritti mancanti. Collega ad azioni
produttive solo funzioni esistenti; contrassegna chiaramente le demo.
I suggerimenti IA non possono né chiudere registrazioni né periodi.

Verifica il layout a 390/768/1024/1440/1920 px, l'uso da tastiera,
focus, contrasto e zoom al 200 %. Consegna screenshot dell'implementazione
effettiva, risultati delle verifiche e limiti aperti. Non dichiarare
verifiche superate che non hai eseguito.
```

## 16. Checklist di approvazione

- [ ] Asset del logo approvato; area di rispetto e dimensioni rispettate.
- [ ] Token e tipografia centralizzati; nessuno stile divergente per schermata.
- [ ] Navigazione e indicatori corrispondono al solo IVA.
- [ ] Mandante, periodo e stato di bozza/chiusura chiaramente visibili.
- [ ] Numeri allineati a destra, tabulari, formattati all'italiana; Euro coerente.
- [ ] Importare è visibilmente separato da registrare.
- [ ] Lo stato non è mai trasmesso solo dal colore.
- [ ] Campi obbligatori, errori e casi non supportati comprensibili.
- [ ] Tutte le funzioni raggiungibili con tastiera e touch.
- [ ] Larghezze responsive e zoom effettivamente verificati.
- [ ] Nessuna promessa non documentata su IA/SDI/assenza di errori.
- [ ] I periodi chiusi sono visibilmente di sola lettura.

Queste linee guida sono una specifica di design e di implementazione. Non modificano alcuna regola fiscale e non sostituiscono l'approvazione tecnica o fiscale del programma.
