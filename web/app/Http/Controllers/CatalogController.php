<?php

namespace App\Http\Controllers;

use App\Domain\CatalogService;
use App\Domain\Import\FatturaPaSchema;
use App\Models\CatalogArticle;
use App\Models\CatalogSnippet;
use App\Models\CatalogUnit;
use App\Models\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** Catalogo: articoli e servizi, testi predefiniti, unità di misura. */
class CatalogController extends Controller
{
    public function __construct(private CatalogService $svc) {}

    public function index(Company $company): Response
    {
        $codes = FatturaPaSchema::codes();

        return Inertia::render('Catalog/Index', [
            'articles' => $company->catalogArticles()->orderBy('code')->orderBy('description')->get()->map(fn ($a) => ['id' => $a->id, 'code' => $a->code, 'description' => $a->description, 'unit' => $a->unit,
                'price' => str_replace('.', ',', (string) $a->price), 'rate' => rtrim(rtrim((string) $a->rate, '0'), '.'), 'natura' => $a->natura, 'active' => $a->active])->values(),
            'snippets' => $company->catalogSnippets()->orderBy('kind')->orderBy('title')->get(['id', 'title', 'body', 'kind']),
            'ownUnits' => $company->catalogUnits()->orderBy('code')->get(['id', 'code', 'label']),
            'defaultUnits' => CatalogService::DEFAULT_UNITS,
            'nature' => collect($codes['NaturaType'] ?? [])->map(fn ($l, $c) => ['code' => $c, 'label' => $l])->values(),
        ]);
    }

    public function storeArticle(Request $request, Company $company): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $this->svc->saveArticle($company, $request->user(), null, $this->articleData($request));

        return back()->with('success', 'Articolo salvato.');
    }

    public function updateArticle(Request $request, Company $company, CatalogArticle $catalogArticle): RedirectResponse
    {
        Gate::authorize('manage', $company);
        abort_unless($catalogArticle->company_id === $company->id, 404);
        $this->svc->saveArticle($company, $request->user(), $catalogArticle, $this->articleData($request));

        return back()->with('success', 'Articolo aggiornato.');
    }

    public function destroyArticle(Request $request, Company $company, CatalogArticle $catalogArticle): RedirectResponse
    {
        Gate::authorize('manage', $company);
        abort_unless($catalogArticle->company_id === $company->id, 404);
        $this->svc->delete($company, $request->user(), $catalogArticle);

        return back()->with('success', 'Articolo eliminato.');
    }

    public function storeSnippet(Request $request, Company $company): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $this->svc->saveSnippet($company, $request->user(), null, $this->snippetData($request));

        return back()->with('success', 'Testo salvato.');
    }

    public function updateSnippet(Request $request, Company $company, CatalogSnippet $catalogSnippet): RedirectResponse
    {
        Gate::authorize('manage', $company);
        abort_unless($catalogSnippet->company_id === $company->id, 404);
        $this->svc->saveSnippet($company, $request->user(), $catalogSnippet, $this->snippetData($request));

        return back()->with('success', 'Testo aggiornato.');
    }

    public function destroySnippet(Request $request, Company $company, CatalogSnippet $catalogSnippet): RedirectResponse
    {
        Gate::authorize('manage', $company);
        abort_unless($catalogSnippet->company_id === $company->id, 404);
        $this->svc->delete($company, $request->user(), $catalogSnippet);

        return back()->with('success', 'Testo eliminato.');
    }

    public function storeUnit(Request $request, Company $company): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $this->svc->addUnit($company, $request->user(), $request->validate(['code' => ['required', 'string', 'max:10'], 'label' => ['nullable', 'string', 'max:60']]));

        return back()->with('success', 'Unità aggiunta.');
    }

    public function destroyUnit(Request $request, Company $company, CatalogUnit $catalogUnit): RedirectResponse
    {
        Gate::authorize('manage', $company);
        abort_unless($catalogUnit->company_id === $company->id, 404);
        $this->svc->delete($company, $request->user(), $catalogUnit);

        return back()->with('success', 'Unità eliminata.');
    }

    private function articleData(Request $request): array
    {
        return $request->validate(['code' => ['nullable', 'string', 'max:30'], 'description' => ['required', 'string', 'max:1000'], 'unit' => ['nullable', 'string', 'max:10'], 'price' => ['nullable', 'string', 'max:14'],
            'rate' => ['nullable', 'string', 'max:5'], 'natura' => ['nullable', 'string', 'max:5'], 'active' => ['boolean']]);
    }

    private function snippetData(Request $request): array
    {
        return $request->validate(['title' => ['required', 'string', 'max:80'], 'body' => ['required', 'string', 'max:1000'], 'kind' => ['required', 'in:causale,riga']]);
    }
}
