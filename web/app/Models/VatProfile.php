<?php

namespace App\Models;

use App\Domain\ImmutableRecordException;
use App\Enums\Periodicity;
use App\Enums\ProfileStatus;
use App\Enums\SemplificataMethod;
use App\Enums\VatRegime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Regime IVA di un'azienda per un anno solare (dal 1° gennaio al 31 dicembre). */
class VatProfile extends Model
{
    protected $fillable = [
        'company_id', 'year', 'version', 'status', 'regime', 'method', 'forfettario_iva', 'periodicity', 'quarterly_type',
        'pro_rata', 'rule_version', 'notes', 'change_reason',
        'created_by', 'confirmed_by', 'confirmed_at',
    ];

    protected $attributes = ['pro_rata' => '100.00', 'forfettario_iva' => false];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'status' => ProfileStatus::class,
            'regime' => VatRegime::class,
            'method' => SemplificataMethod::class,
            'forfettario_iva' => 'boolean',
            'periodicity' => Periodicity::class,
            'pro_rata' => 'decimal:2',
            'confirmed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $p) {
            $original = $p->getOriginal('status');
            if ($original === ProfileStatus::Bozza) {
                return;
            }
            // Versione confermata: è consentita solo la sostituzione con una nuova versione dello stesso anno.
            $allowed = $original === ProfileStatus::Confermato
                && $p->status === ProfileStatus::Sostituito
                && array_diff(array_keys($p->getDirty()), ['status', 'updated_at']) === [];
            if (! $allowed) {
                throw new ImmutableRecordException('Un regime IVA confermato non può essere modificato. Crea una nuova versione.');
            }
        });
        static::deleting(function (self $p) {
            if ($p->getOriginal('status') !== ProfileStatus::Bozza) {
                throw new ImmutableRecordException('Solo le bozze possono essere eliminate.');
            }
        });
    }

    /** Denominazione completa, es. «Contabilità semplificata · per cassa». */
    public function regimeLabel(): ?string
    {
        if (! $this->regime) {
            return null;
        }

        return $this->method ? $this->regime->label().' · '.$this->method->shortLabel() : $this->regime->label();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
