<?php

namespace App\Domain;

use App\Domain\Import\FatturaPaSchema;
use App\Models\CatalogArticle;
use App\Models\CatalogSnippet;
use App\Models\CatalogUnit;
use App\Models\Company;
use App\Models\User;
use App\Support\Decimal;

/** Catalogo per azienda: articoli, blocchi di testo, unità. Le modifiche vengono registrate nel registro delle attività; le fatture copiano i valori (nessun collegamento). */
final class CatalogService
{
    /** Unità standard; quelle proprie si aggiungono da catalog_units. */
    public const DEFAULT_UNITS = ['pz', 'ore', 'giorni', 'mesi', 'kg', 'g', 'l', 'm', 'mq', 'mc', 'km', 'forfait', 'kWh', 'nr'];

    /** @return list<string> */
    public function units(Company $company): array
    {
        $own = $company->catalogUnits()->orderBy('code')->pluck('code')->all();
        $all = array_values(array_unique([...self::DEFAULT_UNITS, ...$own]));

        return $all;
    }

    /** Tutto ciò che serve al modulo della fattura. */
    public function forForm(Company $company): array
    {
        return [
            'articles' => $company->catalogArticles()->where('active', true)->orderBy('code')->orderBy('description')->get()
                ->map(fn ($a) => ['id' => $a->id, 'code' => $a->code, 'description' => $a->description, 'unit' => $a->unit, 'price' => str_replace('.', ',', (string) $a->price), 'rate' => rtrim(rtrim((string) $a->rate, '0'), '.'), 'natura' => $a->natura])->values()->all(),
            'snippets' => $company->catalogSnippets()->orderBy('title')->get(['id', 'title', 'body', 'kind'])->all(),
            'units' => $this->units($company),
        ];
    }

    public function saveArticle(Company $company, User $actor, ?CatalogArticle $article, array $d): CatalogArticle
    {
        $code = trim((string) ($d['code'] ?? '')) ?: null;
        if ($code !== null && $company->catalogArticles()->where('code', $code)->when($article, fn ($q) => $q->whereKeyNot($article->id))->exists()) {
            RuleViolation::fail(['code' => 'Esiste già un articolo con questo codice.']);
        }
        $price = str_replace(',', '.', trim((string) ($d['price'] ?? '0')) ?: '0');
        if (! preg_match('/^\d{1,8}(\.\d{1,2})?$/', $price)) {
            RuleViolation::fail(['price' => 'Prezzo non valido (es. 150,00, massimo 2 decimali).']);
        }
        $rate = str_replace(',', '.', trim((string) ($d['rate'] ?? '22')) ?: '0');
        $natura = trim((string) ($d['natura'] ?? '')) ?: null;
        if (! preg_match('/^\d{1,2}(\.\d{1,2})?$/', $rate) || (float) $rate > 99) {
            RuleViolation::fail(['rate' => 'Aliquota non valida.']);
        }
        if ($natura !== null) {
            if (! array_key_exists($natura, FatturaPaSchema::codes()['NaturaType'] ?? [])) {
                RuleViolation::fail(['natura' => 'Natura non valida.']);
            }
            $rate = '0';
        } elseif ((float) $rate === 0.0) {
            RuleViolation::fail(['natura' => 'Con aliquota 0 serve la natura dell’operazione.']);
        }
        $values = ['code' => $code, 'description' => trim((string) $d['description']), 'unit' => trim((string) ($d['unit'] ?? '')) ?: null, 'price' => Decimal::normalize($price), 'rate' => Decimal::normalize($rate),
            'natura' => $natura, 'active' => (bool) ($d['active'] ?? true)];
        $article = $article ? tap($article)->update($values) : $company->catalogArticles()->create($values);
        Audit::record($article->wasRecentlyCreated ? 'catalog.article.created' : 'catalog.article.updated', $actor, $company, $article, data: ['code' => $code, 'description' => $values['description']]);

        return $article;
    }

    public function saveSnippet(Company $company, User $actor, ?CatalogSnippet $snippet, array $d): CatalogSnippet
    {
        $values = ['title' => trim((string) $d['title']), 'body' => trim((string) $d['body']), 'kind' => $d['kind']];
        $snippet = $snippet ? tap($snippet)->update($values) : $company->catalogSnippets()->create($values);
        Audit::record($snippet->wasRecentlyCreated ? 'catalog.snippet.created' : 'catalog.snippet.updated', $actor, $company, $snippet, data: ['title' => $values['title']]);

        return $snippet;
    }

    public function addUnit(Company $company, User $actor, array $d): CatalogUnit
    {
        $code = trim((string) $d['code']);
        if (in_array(mb_strtolower($code), array_map('mb_strtolower', $this->units($company)), true)) {
            RuleViolation::fail(['code' => 'Questa unità esiste già.']);
        }
        $unit = $company->catalogUnits()->create(['code' => $code, 'label' => trim((string) ($d['label'] ?? '')) ?: null]);
        Audit::record('catalog.unit.created', $actor, $company, $unit, data: ['code' => $code]);

        return $unit;
    }

    public function delete(Company $company, User $actor, CatalogArticle|CatalogSnippet|CatalogUnit $item): void
    {
        Audit::record('catalog.'.strtolower(str_replace('Catalog', '', class_basename($item))).'.deleted', $actor, $company, $item, data: $item->only(['code', 'title', 'description']));
        $item->delete();
    }
}
