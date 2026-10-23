<?php

namespace PostalPortal\ZIP4;

use JsonSerializable;

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at http://mozilla.org/MPL/2.0/.
 */

/**
 * What ZIP4::match() returns.
 *
 * Verified: the address fields are the USPS-standardized address, with the +4 and carrier route.
 * Not verified: the address fields are exactly what was passed in, and $normalized holds a
 * Pub 28-cleaned version (abbreviations, punctuation, city and state from the ZIP, and the
 * street's correct directionals and suffix when the street was found) for callers that want it.
 */
final class AddressMatch implements JsonSerializable {

    public function __construct(
            /** The street was found and the house (or box) number is in one of its ZIP+4 ranges */
            public readonly bool $verified,
            public readonly string $street1,
            public readonly string $street2,
            public readonly string $city,
            public readonly string $state,
            /** 5 digits when verified; as entered otherwise */
            public readonly string $zip,
            /** Empty when not verified, or for a firm or range record that doesn't pin down one +4 */
            public readonly string $plus4 = "",
            public readonly string $carrierRoute = "",
            /** S street, H highrise, F firm, P PO box, R rural route, G general delivery; empty when nothing matched */
            public readonly string $recordType = "",
            /** exact (verified), street (the street is in that ZIP but the number isn't), or none */
            public readonly string $matchLevel = Standardizer::MATCH_NONE,
            /** The ZIP was wrong for the address and has been corrected */
            public readonly bool $zipChanged = false,
            /** The street name was misspelled and has been corrected */
            public readonly bool $spellingCorrected = false,
            /** USPS has converted this address (usually a rural route) to a new city-style one; it may need updating */
            public readonly bool $lacsConverted = false,
            /** Why it wasn't verified */
            public readonly string $message = "",
            /** For an unverified address: ["street1", "street2", "city", "state", "zip"] cleaned up as far as possible */
            public readonly ?array $normalized = null
    ) {

    }

    /** "59601-1002", or the 5 digits if there's no +4 */
    public function zipPlus4(): string {
        return $this->plus4 === "" ? $this->zip : "$this->zip-$this->plus4";
    }

    public function toArray(): array {
        return [
            "verified" => $this->verified,
            "street1" => $this->street1,
            "street2" => $this->street2,
            "city" => $this->city,
            "state" => $this->state,
            "zip" => $this->zip,
            "plus4" => $this->plus4,
            "carrier_route" => $this->carrierRoute,
            "record_type" => $this->recordType,
            "match_level" => $this->matchLevel,
            "zip_changed" => $this->zipChanged,
            "spelling_corrected" => $this->spellingCorrected,
            "lacs_converted" => $this->lacsConverted,
            "message" => $this->message,
            "normalized" => $this->normalized
        ];
    }

    public function jsonSerialize(): mixed {
        return $this->toArray();
    }
}
