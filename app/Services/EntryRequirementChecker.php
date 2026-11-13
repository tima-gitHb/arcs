<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Enums\EntryStatus;
use App\Enums\SegmentDirection;
use App\Models\Airport;
use App\Models\EntryRule;
use App\Models\Passenger;
use App\Models\TravelDocument;
use Illuminate\Support\Collection;

/**
 * Advisory entry requirements check (SR-49, pending client approval): do the passenger's
 * documents allow entry to the destination country? It reads the entry_rules table and
 * changes nothing. It never blocks check-in; on missing or refused the agent writes a reason.
 * For a Schengen destination, a visa or residence permit from any member counts
 * (config/schengen.php); the 90/180-day stay limit is not checked. Transit rules are not covered.
 */
class EntryRequirementChecker
{
    /**
     * Date of the Passport Index snapshot in database/data.
     */
    public const RULES_AS_OF = '12 January 2025';

    private const NO_DOCUMENT_NEEDED = ['visa free', 'visa on arrival'];

    private const DOCUMENT_NEEDED = ['e-visa', 'eta', 'visa required'];

    public function __construct(private CountryList $countries, private JourneyService $journeys) {}

    /**
     * Entry into the final destination of the direction only (SR-68): BEY→IST→CDG checks France,
     * not Turkey (transit rules are out of scope). Direction: null = the passenger's current one.
     */
    public function check(Passenger $passenger, ?SegmentDirection $direction = null): EntryResult
    {
        $direction ??= $this->journeys->directionOf($passenger);
        $flight = $this->journeys->flightsOf($passenger->reservation, $direction)->first() ?? $passenger->reservation->flight;
        $destination = $this->journeys->finalDestination($passenger->reservation, $direction);

        // Valid means not expired on the departure date.
        $departure = $flight->departureDate();
        $documents = $passenger->travelDocuments()->get()
            ->filter(fn ($document) => $document->expiry_date->gte($departure));

        $passports = $documents->where('type', DocumentType::Passport);

        if ($passports->isEmpty()) {
            return new EntryResult(EntryStatus::Unknown, 'No valid passport to check, check manually');
        }

        // Two valid passports: the one that allows entry counts.
        $results = $passports->map(fn ($passport) => $this->checkPassport($passport->issuing_country, $destination, $documents));

        return $results->first(fn (EntryResult $result) => $result->status === EntryStatus::Ok) ?? $results->first();
    }

    /**
     * @param  Collection<int, TravelDocument>  $documents  the valid documents
     */
    private function checkPassport(string $passportCountry, string $airport, Collection $documents): EntryResult
    {
        $from = $this->countries->name($passportCountry);
        // The destination country comes from the airports table (B13a).
        $destination = Airport::find($airport)?->country;

        if ($destination === null) {
            return new EntryResult(EntryStatus::Unknown, "No entry rule for {$from} → airport {$airport}, check manually");
        }

        $to = $this->countries->name($destination);

        if ($passportCountry === $destination) {
            return new EntryResult(EntryStatus::Ok, "Citizen of {$to}: no visa needed");
        }

        $requirement = EntryRule::query()
            ->where('passport_country', $passportCountry)
            ->where('destination_country', $destination)
            ->value('requirement');

        if (is_numeric($requirement) || in_array($requirement, self::NO_DOCUMENT_NEEDED, true)) {
            return new EntryResult(EntryStatus::Ok, "No visa needed for {$to}");
        }

        if ($requirement === 'no admission') {
            return new EntryResult(EntryStatus::Refused, "No admission to {$to} on a passport from {$from}");
        }

        if (in_array($requirement, self::DOCUMENT_NEEDED, true)) {
            return $this->checkEntryDocuments($documents, $destination, $to);
        }

        return new EntryResult(EntryStatus::Unknown, "No entry rule for {$from} → {$to}, check manually");
    }

    /**
     * The destination's own visa or residence permit counts; for a Schengen destination, one
     * from any other member counts too.
     *
     * @param  Collection<int, TravelDocument>  $documents  the valid documents
     */
    private function checkEntryDocuments(Collection $documents, string $destination, string $to): EntryResult
    {
        $entryDocuments = $documents->filter(
            fn ($document) => in_array($document->type, [DocumentType::Visa, DocumentType::ResidencePermit], true),
        );

        if ($entryDocuments->contains('issuing_country', $destination)) {
            return new EntryResult(EntryStatus::Ok, "Visa or residence permit for {$to} found");
        }

        $schengen = config('schengen');

        if (in_array($destination, $schengen, true)) {
            $document = $entryDocuments->first(fn ($document) => in_array($document->issuing_country, $schengen, true));

            if ($document) {
                return new EntryResult(
                    EntryStatus::Ok,
                    "OK: {$document->issuingCountryName()} ".strtolower($document->type->label()).' (Schengen)',
                    'The 90/180-day Schengen stay limit is not checked.',
                );
            }
        }

        return new EntryResult(EntryStatus::Missing, "Visa required for {$to}");
    }
}
