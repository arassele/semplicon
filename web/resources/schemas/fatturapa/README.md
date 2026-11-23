# Schemi FatturaPA

| Datei | Quelle | Änderung |
|---|---|---|
| `Schema_VFPR12_v1.2.3.xsd` | Agenzia delle Entrate / SDI, https://www.fatturapa.gov.it/export/documenti/fatturapa/v1.4/Schema_VFPR12_v1.2.3.xsd (Spezifikation 1.4). Identisch mit `Schema_VFPA12_V1.2.3.xsd`, deckt FPR12 und FPA12 ab. | Nur `schemaLocation` des xmldsig-Imports auf die lokale Datei umgestellt, damit beim Prüfen keine Netzwerkverbindung entsteht. |
| `xmldsig-core-schema.xsd` | W3C, https://www.w3.org/TR/2002/REC-xmldsig-core-20020212/xmldsig-core-schema.xsd (W3C Software License, Hinweis in der Datei) | DOCTYPE mit externer DTD entfernt; die dort definierten Entitäten werden in der Datei nicht verwendet. |

Abgerufen am 18.11.2026. Bei einer neuen Schemaversion beide Dateien ersetzen, `config/semplicon.php` (`fatturapa_schema`) anpassen und die Tests laufen lassen.

Die Beispielrechnungen in `tests/fixtures/fatturapa/official/` stammen von derselben Seite (Beispiele zur Version 1.2). `IT01234567890_FPR02.xml` ist nach Schema 1.2.3 ungültig (`ContattiTrasmittente` an unerwarteter Stelle) und dient als Testfall für Schemafehler.
