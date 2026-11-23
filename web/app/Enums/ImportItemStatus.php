<?php

namespace App\Enums;

enum ImportItemStatus: string
{
    case InAttesa = 'in_attesa';
    case Importato = 'importato';
    case DaVerificare = 'da_verificare';
    case Duplicato = 'duplicato';
    case Errore = 'errore';
    case Archivio = 'archivio';   // file ZIP il cui contenuto segue come voci proprie

    public function label(): string
    {
        return match ($this) {
            self::InAttesa => 'In attesa',
            self::Importato => 'Importato',
            self::DaVerificare => 'Importato, da verificare',
            self::Duplicato => 'Già presente',
            self::Errore => 'Errore',
            self::Archivio => 'Archivio ZIP',
        };
    }
}
