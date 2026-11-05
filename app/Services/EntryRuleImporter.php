<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Loads the Passport Index CSV into entry_rules (SR-49). An upsert on the country pair,
 * so running it again changes nothing and adds no rows.
 */
class EntryRuleImporter
{
    public const DEFAULT_PATH = 'database/data/passport-index-tidy-iso2.csv';

    private const CHUNK = 500;

    /**
     * @return int the number of rows read from the file
     */
    public function import(?string $path = null): int
    {
        $path ??= base_path(self::DEFAULT_PATH);
        $file = @fopen($path, 'r');

        if ($file === false) {
            throw new RuntimeException("Cannot read {$path}.");
        }

        if (fgetcsv($file, escape: '') !== ['Passport', 'Destination', 'Requirement']) {
            fclose($file);

            throw new RuntimeException("{$path} is not a Passport Index tidy CSV.");
        }

        $total = 0;

        DB::transaction(function () use ($file, &$total) {
            $rows = [];

            while (($line = fgetcsv($file, escape: '')) !== false) {
                [$passport, $destination, $requirement] = $line;

                $rows[] = [
                    'passport_country' => $passport,
                    'destination_country' => $destination,
                    'requirement' => $requirement,
                ];
                $total++;

                if (count($rows) === self::CHUNK) {
                    $this->save($rows);
                    $rows = [];
                }
            }

            $this->save($rows);
        });

        fclose($file);

        return $total;
    }

    /**
     * @param  list<array<string, string>>  $rows
     */
    private function save(array $rows): void
    {
        DB::table('entry_rules')->upsert($rows, ['passport_country', 'destination_country'], ['requirement']);
    }
}
