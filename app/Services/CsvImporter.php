<?php

namespace App\Services;

/**
 * Lecture des exports CSV d'inscriptions (pilotes, planeurs).
 *
 * Les colonnes sont repérées par leur en-tête et non par leur position :
 * l'ordre peut changer et des colonnes peuvent s'ajouter sans rien casser.
 */
class CsvImporter
{
    /**
     * Renvoie les lignes indexées par en-tête normalisé.
     *
     * @param  list<string> $required En-têtes normalisés obligatoires
     * @return list<array<string, string>>|null null si un en-tête requis manque
     */
    public static function read(string $path, array $required = []): ?array
    {
        $content = (string) file_get_contents($path);

        // Les exports Excel français sont souvent en Windows-1252.
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = (string) mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        // BOM UTF-8 éventuel, qui collerait au premier en-tête.
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;

        $handle = fopen('php://memory', 'r+');
        fwrite($handle, $content);
        rewind($handle);

        $header = fgetcsv($handle, 0, ';', '"', '');
        if ($header === false) {
            fclose($handle);
            return null;
        }

        $keys = array_map(fn ($column) => self::key((string) $column), $header);

        foreach ($required as $column) {
            if (!in_array($column, $keys, true)) {
                fclose($handle);
                return null;
            }
        }

        $rows = [];
        while (($values = fgetcsv($handle, 0, ';', '"', '')) !== false) {
            if ($values === [null] || $values === []) {
                continue;   // ligne vide
            }
            $values = array_pad(array_slice($values, 0, count($keys)), count($keys), '');
            $rows[] = array_combine($keys, array_map(fn ($v) => trim((string) $v), $values));
        }

        fclose($handle);

        return $rows;
    }

    /** « Prénom » → « prenom » : minuscules, sans accent ni ponctuation. */
    public static function key(string $column): string
    {
        $column = strtr(mb_strtolower(trim($column)), [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e',
            'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        ]);

        return preg_replace('/[^a-z0-9]/', '', $column) ?? $column;
    }

    /**
     * Capitalisation à la convention de la base : les fichiers mêlent
     * majuscules intégrales (TERNISIEN) et minuscules (glise).
     * Les traits d'union sont préservés (« Jean-Michel »).
     */
    public static function properCase(string $value): string
    {
        return mb_convert_case(
            preg_replace('/\s+/u', ' ', trim($value)) ?? '',
            MB_CASE_TITLE,
            'UTF-8'
        );
    }

    /** Compose « Prénom Nom » à partir des deux colonnes séparées. */
    public static function personName(string $firstName, string $lastName): string
    {
        return trim(self::properCase($firstName) . ' ' . self::properCase($lastName));
    }

    /**
     * Immatriculation à la convention de la base : majuscules, et tiret rétabli
     * sur le format français à cinq caractères (« Fcceb » → « F-CCEB »).
     * Les autres formats sont seulement mis en majuscules, sans rien inventer.
     */
    public static function registration(string $value): string
    {
        $value = mb_strtoupper(trim($value));

        if (preg_match('/^F[A-Z]{4}$/', $value)) {
            return 'F-' . substr($value, 1);
        }

        return $value;
    }

    /** Clé de comparaison d'une immatriculation, comme le fait la carte. */
    public static function registrationKey(string $value): string
    {
        return preg_replace('/[^A-Z0-9]/', '', mb_strtoupper(trim($value))) ?? '';
    }
}
