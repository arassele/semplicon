<?php

namespace App\Http\Controllers;

use App\Domain\Archive\CompanyBackup;
use App\Domain\Archive\CompanyRestore;
use App\Domain\UserAccountService;
use App\Models\AuditEvent;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/** Area utente: account e backup d'archivio delle proprie aziende. */
class UserAreaController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $companies = $user->accessibleCompanies()->orderBy('companies.name')->get(['companies.id', 'companies.name', 'companies.partita_iva']);
        $last = AuditEvent::whereIn('company_id', $companies->pluck('id'))->where('action', 'backup.created')->where('user_id', $user->id)->latest('id')->limit(5)->get();

        return Inertia::render('Account/Index', [
            'companies' => $companies->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'partita_iva' => $c->partita_iva])->values(),
            'backups' => $last->map(fn ($e) => ['at' => $e->created_at?->toIso8601String(), 'company_id' => $e->company_id, 'sha256' => $e->data['sha256'] ?? null, 'files' => $e->data['files'] ?? null, 'companies' => $e->data['companies'] ?? 1])->unique('sha256')->values(),
        ]);
    }

    /** E-mail e password dell'utente (serve la password attuale). */
    public function updateAccess(Request $request, UserAccountService $svc)
    {
        $d = $request->validate(['email' => ['required', 'email', 'max:255'], 'current_password' => ['required', 'string'], 'password' => ['nullable', 'confirmed', Password::min(12)]],
            [], ['current_password' => 'password attuale', 'password' => 'nuova password']);
        $svc->updateAccount($request->user(), $d);

        return back()->with('success', 'Accesso aggiornato.');
    }

    public function backup(Request $request)
    {
        $d = $request->validate(['companies' => ['required', 'array', 'min:1', 'max:50'], 'companies.*' => ['integer']]);
        $user = $request->user();
        $list = $user->accessibleCompanies()->whereIn('companies.id', $d['companies'])->get()->all();
        abort_unless(count($list) === count(array_unique($d['companies'])), 404);
        $b = app(CompanyBackup::class)->build($list, $user);

        return response()->download($b['path'], $b['filename'], ['Content-Type' => 'application/zip', 'X-Backup-Sha256' => $b['sha256']])->deleteFileAfterSend(true);
    }

    /** Ripristino da uno ZIP di backup: nuova azienda o sostituzione dei dati di un'azienda esistente. */
    public function restore(Request $request)
    {
        $d = $request->validate(['file' => ['required', 'file', 'max:153600'], 'mode' => ['required', 'in:new,replace'], 'password' => ['nullable', 'string']]);
        $res = app(CompanyRestore::class)->restore($request->user(), $request->file('file')->getRealPath(), $d['mode'], $d['password'] ?? null);
        $names = collect($res)->map(fn ($r) => $r['company'].($r['created'] ? ' (nuova)' : ' (sostituita)'))->implode(', ');
        $rows = array_sum(array_column($res, 'rows'));

        return redirect('/account')->with('success', "Backup ripristinato: {$names}. {$rows} record e ".array_sum(array_column($res, 'documents')).' documenti.');
    }
}
