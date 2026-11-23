<?php

namespace App\Models;

use App\Domain\ImmutableRecordException;
use App\Enums\InvoiceDirection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Registrazione IVA. Immutabile senza esplicita autorizzazione; è consentito solo il passaggio «registrata» → «stornata».
 * Modifica ed eliminazione (indicazione del committente, 19.11.2026) avvengono esclusivamente tramite RegistrationService/InvoiceService con unlocked().
 */
class VatRegistration extends Model
{
    protected $guarded = ['id'];

    private static bool $unlocked = false;

    /** Ticket 06: true se un tentativo ripetuto (stessa requestId) restituisce la registrazione esistente. */
    public bool $replayed = false;

    /** Esegue $fn con il blocco sospeso (solo per i servizi che registrano le modifiche). */
    public static function unlocked(callable $fn): mixed
    {
        self::$unlocked = true;
        try {
            return $fn();
        } finally {
            self::$unlocked = false;
        }
    }

    protected function casts(): array
    {
        return [
            'direction' => InvoiceDirection::class,
            'registration_date' => 'date:Y-m-d',
            'receipt_date' => 'date:Y-m-d',
            'taxable_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'deductible_total' => 'decimal:2',
            'acknowledged' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $r) {
            if (self::$unlocked) {
                return;
            }
            $allowed = $r->getOriginal('status') === 'registrata' && $r->status === 'stornata'
                && array_diff(array_keys($r->getDirty()), ['status', 'active_invoice_id', 'updated_at']) === [];
            if (! $allowed) {
                throw new ImmutableRecordException('Una registrazione non può essere modificata: usa lo storno.');
            }
        });
        static::deleting(function () {
            if (! self::$unlocked) {
                throw new ImmutableRecordException('Una registrazione non può essere eliminata: usa lo storno.');
            }
        });
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(VatRegistrationLine::class)->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Documento della registrazione: file originale dell'importazione o allegato della fattura manuale. */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
