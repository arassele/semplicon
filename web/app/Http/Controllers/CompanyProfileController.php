<?php

namespace App\Http\Controllers;

use App\Domain\Audit;
use App\Domain\CompanyProfileService;
use App\Domain\Import\FatturaPaSchema;
use App\Domain\Issuing\IssuingSettings;
use App\Enums\CompanyType;
use App\Enums\ContactType;
use App\Models\Company;
use App\Support\Ateco;
use App\Support\Present;
use App\Support\Validation\CodiceFiscale;
use App\Support\Validation\Iban;
use App\Support\Validation\PartitaIva;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Pagina «Dati azienda». */
class CompanyProfileController extends Controller
{
    public function show(Company $company): Response
    {
        $company->load('contacts', 'activities', 'bankAccounts');

        return Inertia::render('Companies/Profile', [
            'logo' => $company->logo_path ? ['v' => (int) Storage::disk('local')->lastModified($company->logo_path)] : null,
            'company' => [
                ...$company->only([
                    'name', 'partita_iva', 'codice_fiscale', 'natura_giuridica', 'sigla', 'codice_sdi', 'titolo', 'cognome', 'nome',
                    'sesso', 'comune_nascita', 'provincia_nascita', 'indirizzo', 'numero_civico', 'cap', 'comune', 'provincia', 'nazione',
                ]),
                'tipologia' => $company->tipologia->value,
                'issuing' => IssuingSettings::for($company),
                'data_nascita' => $company->data_nascita?->toDateString(),
                'contacts' => $company->contacts->map(fn ($c) => ['type' => $c->type->value, 'value' => $c->value, 'description' => $c->description, 'is_default' => $c->is_default]),
                'activities' => $company->activities->map(fn ($a) => $a->only(['code', 'description', 'is_primary'])),
                'bank_accounts' => $company->bankAccounts->map(fn ($b) => [...$b->only(['iban', 'bank_name', 'description', 'is_default']), 'abi_cab' => $b->abiCab()]),
            ],
            'issuingOptions' => IssuingSettings::options(),
            'atecoSource' => Ateco::SOURCE,
            'options' => [
                'types' => Present::options(CompanyType::cases()),
                'contact_types' => Present::options(ContactType::cases()),
            ],
        ]);
    }

    public function update(Request $request, Company $company, CompanyProfileService $service): RedirectResponse
    {
        Gate::authorize('manage', $company);

        // Uniformare i dati inseriti prima della verifica.
        $request->merge([
            'partita_iva' => preg_replace('/\s+/', '', (string) $request->input('partita_iva')),
            'codice_fiscale' => strtoupper(trim((string) $request->input('codice_fiscale'))) ?: null,
            'codice_sdi' => strtoupper(trim((string) $request->input('codice_sdi'))) ?: null,
            'provincia' => strtoupper(trim((string) $request->input('provincia'))) ?: null,
            'provincia_nascita' => strtoupper(trim((string) $request->input('provincia_nascita'))) ?: null,
        ]);

        $request->merge(['issuing' => IssuingSettings::normalize($request->input('issuing'))]);
        $isPerson = CompanyType::tryFrom((string) $request->input('tipologia'))?->isPerson() ?? false;
        $one = fn (string $flag) => function (string $attribute, mixed $rows, Closure $fail) use ($flag) {
            if (count(array_filter((array) $rows, fn ($r) => ! empty($r[$flag]))) > 1) {
                $fail('Può essere predefinito un solo elemento.');
            }
        };

        $data = $request->validate([
            'tipologia' => ['required', Rule::enum(CompanyType::class)],
            'name' => [$isPerson ? 'nullable' : 'required', 'string', 'max:160'],
            'partita_iva' => ['required', 'digits:11', Rule::unique('companies', 'partita_iva')->ignore($company->id),
                fn ($a, $v, $fail) => PartitaIva::isValid($v) ? null : $fail('La partita IVA non è valida (cifra di controllo errata).')],
            'codice_fiscale' => ['nullable', 'regex:/^([A-Z0-9]{16}|\d{11})$/',
                fn ($a, $v, $fail) => CodiceFiscale::isValid((string) $v) ? null : $fail('Il codice fiscale non è valido (carattere di controllo errato).')],
            'natura_giuridica' => ['nullable', 'string', 'max:120'],
            'sigla' => ['nullable', 'string', 'max:60'],
            'codice_sdi' => ['nullable', 'regex:/^[A-Z0-9]{7}$/'],
            'titolo' => ['nullable', 'string', 'max:20'],
            'cognome' => [$isPerson ? 'required' : 'nullable', 'string', 'max:80'],
            'nome' => [$isPerson ? 'required' : 'nullable', 'string', 'max:80'],
            'sesso' => ['nullable', Rule::in(['M', 'F'])],
            'data_nascita' => ['nullable', 'date_format:Y-m-d', 'before:today'],
            'comune_nascita' => ['nullable', 'string', 'max:120'],
            'provincia_nascita' => ['nullable', 'regex:/^[A-Z]{2}$/'],
            'indirizzo' => ['nullable', 'string', 'max:160'],
            'numero_civico' => ['nullable', 'string', 'max:12'],
            'cap' => ['nullable', 'regex:/^\d{5}$/'],
            'comune' => ['nullable', 'string', 'max:120'],
            'provincia' => ['nullable', 'regex:/^[A-Z]{2}$/'],
            'contacts' => ['array', 'max:20', $one('is_default')],
            'contacts.*.type' => ['required', Rule::enum(ContactType::class)],
            'contacts.*.value' => ['required', 'string', 'max:190'],
            'contacts.*.description' => ['nullable', 'string', 'max:120'],
            'contacts.*.is_default' => ['boolean'],
            'activities' => ['array', 'max:10', $one('is_primary')],
            'activities.*.code' => ['required', 'regex:/^\d{2}\.\d{2}\.\d{2}$/',
                fn ($a, $v, $fail) => Ateco::find((string) $v) ? null : $fail('Codice non presente nella classificazione ATECO 2025.')],
            'activities.*.is_primary' => ['boolean'],
            'bank_accounts' => ['array', 'max:10', $one('is_default')],
            'bank_accounts.*.iban' => ['required', 'string', fn ($a, $v, $fail) => Iban::isValid((string) $v) ? null : $fail('IBAN non valido.')],
            'bank_accounts.*.bank_name' => ['nullable', 'string', 'max:120'],
            'bank_accounts.*.description' => ['nullable', 'string', 'max:120'],
            'bank_accounts.*.is_default' => ['boolean'],
            'issuing' => ['nullable', 'array'],
            'issuing.ritenuta.enabled' => ['boolean'],
            'issuing.ritenuta.type' => ['required_with:issuing', Rule::in(array_keys(FatturaPaSchema::codes()['TipoRitenutaType']))],
            'issuing.ritenuta.rate' => ['nullable', 'regex:/^\d{1,2}(\.\d{1,2})?$/'],
            'issuing.ritenuta.base_pct' => ['nullable', 'regex:/^(100(\.0{1,2})?|\d{1,2}(\.\d{1,2})?)$/'],
            'issuing.ritenuta.causale' => ['nullable', Rule::in(FatturaPaSchema::enumValues('CausalePagamentoType'))],
            'issuing.casse' => ['array', 'max:2'],
            'issuing.casse.*.type' => ['required', Rule::in(array_keys(FatturaPaSchema::codes()['TipoCassaType']))],
            'issuing.casse.*.rate' => ['required', 'regex:/^\d{1,2}(\.\d{1,2})?$/'],
            'issuing.casse.*.base_pct' => ['required', 'regex:/^(100(\.0{1,2})?|\d{1,2}(\.\d{1,2})?)$/'],
            'issuing.casse.*.vat_rate' => ['required', Rule::in(['0', '4', '5', '10', '22'])],
            'issuing.casse.*.natura' => ['nullable', Rule::in(array_keys(FatturaPaSchema::codes()['NaturaType']))],
            'issuing.casse.*.ritenuta' => ['boolean'],
            'issuing.casse.*.cap' => ['nullable', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
        ], [
            'codice_fiscale.regex' => 'Il codice fiscale ha 16 caratteri (persona) o 11 cifre.',
            'codice_sdi.regex' => 'Il codice destinatario SDI ha 7 caratteri.',
            'cap.regex' => 'Il CAP ha 5 cifre.',
            'provincia.regex' => 'Indica la sigla della provincia (2 lettere).',
            'provincia_nascita.regex' => 'Indica la sigla della provincia (2 lettere).',
            'activities.*.code.regex' => 'Codice ATECO 2025 a 6 cifre (es. 69.20.01).',
        ], [
            'name' => 'denominazione', 'partita_iva' => 'partita IVA', 'contacts.*.value' => 'recapito',
            'activities.*.description' => 'descrizione', 'bank_accounts.*.iban' => 'IBAN',
        ]);

        // E-mail e PEC devono essere indirizzi validi.
        foreach ($data['contacts'] ?? [] as $i => $c) {
            if (ContactType::from($c['type'])->isEmail() && ! filter_var($c['value'], FILTER_VALIDATE_EMAIL)) {
                return back()->withErrors(["contacts.{$i}.value" => 'Indirizzo e-mail non valido.'])->withInput();
            }
        }

        // La denominazione proviene sempre dall'elenco ufficiale.
        foreach ($data['activities'] ?? [] as $i => $a) {
            $data['activities'][$i]['description'] = Ateco::find($a['code'])['title_it'];
        }

        // Ritenuta e cassa: l'aliquota è obbligatoria se attiva; con IVA 0 % la natura è obbligatoria.
        $iss = $data['issuing'] ?? null;
        if ($iss && ! empty($iss['ritenuta']['enabled']) && (float) ($iss['ritenuta']['rate'] ?? 0) <= 0) {
            return back()->withErrors(['issuing.ritenuta.rate' => 'Indica la percentuale di ritenuta.'])->withInput();
        }
        foreach ($iss['casse'] ?? [] as $i => $c) {
            if ((float) $c['vat_rate'] === 0.0 && empty($c['natura'])) {
                return back()->withErrors(["issuing.casse.{$i}.natura" => 'Con IVA 0% indica la natura.'])->withInput();
            }
        }
        $data['issuing_settings'] = $iss;
        $service->update($company, $request->user(), $data);

        return back()->with('success', 'Dati azienda salvati.');
    }

    /** Caricare il logo: viene ricodificato con GD (PNG, al massimo 600×240 px, senza metadati) e salvato in privato. */
    public function uploadLogo(Request $request, Company $company): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $request->validate(['logo' => ['required', 'file', 'mimes:png,jpg,jpeg', 'max:2048']], [], ['logo' => 'logo']);
        $img = @imagecreatefromstring((string) file_get_contents($request->file('logo')->getRealPath()));
        if (! $img) {
            return back()->withErrors(['logo' => 'L’immagine non è leggibile. Usa un file PNG o JPG.']);
        }
        [$w, $h] = [imagesx($img), imagesy($img)];
        $scale = min(1, 600 / $w, 240 / $h);
        [$nw, $nh] = [max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale))];
        $out = imagecreatetruecolor($nw, $nh);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 255, 255, 255, 127));
        imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        ob_start();
        imagepng($out);
        $png = (string) ob_get_clean();
        $path = "logos/{$company->id}/logo.png";
        Storage::disk('local')->put($path, $png);
        $company->update(['logo_path' => $path]);
        Audit::record('company.logo_set', $request->user(), $company, data: ['size' => strlen($png), 'w' => $nw, 'h' => $nh]);

        return back()->with('success', 'Logo salvato: comparirà sull’anteprima e sul PDF delle fatture.');
    }

    public function logo(Company $company)
    {
        abort_unless($company->logo_path && Storage::disk('local')->exists($company->logo_path), 404);

        return response(Storage::disk('local')->get($company->logo_path), 200, ['Content-Type' => 'image/png', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=300']);
    }

    public function deleteLogo(Request $request, Company $company): RedirectResponse
    {
        Gate::authorize('manage', $company);
        if ($company->logo_path) {
            Storage::disk('local')->delete($company->logo_path);
            $company->update(['logo_path' => null]);
            Audit::record('company.logo_removed', $request->user(), $company);
        }

        return back()->with('success', 'Logo rimosso.');
    }
}
