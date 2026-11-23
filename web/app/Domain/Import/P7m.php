<?php

namespace App\Domain\Import;

/**
 * Leggere il contenuto di un file firmato (CAdES, .p7m), DER o Base64.
 * L'integrità della firma viene verificata da OpenSSL; la catena di fiducia del certificato no
 * (servirebbe l'elenco dei certificatori accreditati, vedi docs/DECISIONS.md).
 */
final class P7m
{
    public static function looksLikeP7m(string $bytes): bool
    {
        if ($bytes !== '' && $bytes[0] === "\x30") {
            return true;
        }
        $decoded = base64_decode(preg_replace('/\s+/', '', $bytes), true);

        return $decoded !== false && $decoded !== '' && $decoded[0] === "\x30";
    }

    public static function extract(string $bytes): string
    {
        if ($bytes === '' || $bytes[0] !== "\x30") {
            $decoded = base64_decode(preg_replace('/\s+/', '', $bytes), true);
            if ($decoded === false || $decoded === '' || $decoded[0] !== "\x30") {
                throw new ImportError('Il file firmato (.p7m) non è leggibile.');
            }
            $bytes = $decoded;
        }

        $dir = storage_path('app/tmp');
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $in = tempnam($dir, 'p7m');
        $out = tempnam($dir, 'xml');
        try {
            file_put_contents($in, $bytes);
            $ok = openssl_cms_verify($in, OPENSSL_CMS_NOVERIFY | OPENSSL_CMS_BINARY, null, [], null, $out, null, null, OPENSSL_ENCODING_DER);
            $content = $ok ? file_get_contents($out) : false;
            if (! $ok || $content === false || $content === '') {
                throw new ImportError('La firma del file .p7m non è valida o il contenuto non è leggibile.');
            }

            return $content;
        } finally {
            @unlink($in);
            @unlink($out);
            while (openssl_error_string() !== false) {
                // svuotare il buffer degli errori OpenSSL
            }
        }
    }
}
