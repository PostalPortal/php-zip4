<?php

namespace PostalPortal\ZIP4;

use Exception;
use PDO;

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at http://mozilla.org/MPL/2.0/.
 */

/**
 * Matches US addresses against a local ZIP+4 SQLite database.
 *
 *   ZIP4::init("/var/lib/zip4/zip4.sqlite");
 *   $m = ZIP4::match("123 north main street apt 4", "", "helena", "mt", "59601");
 *   if ($m->verified) {
 *       // $m->street1 == "123 N MAIN ST APT 4", $m->zipPlus4() == "59601-1002"
 *   } else {
 *       // $m->street1 == "123 north main street apt 4", exactly as given
 *   }
 */
final class ZIP4 {

    private static ?Standardizer $standardizer = null;

    /**
     * Opens the ZIP+4 database for every match() after it. Call once (again to switch files).
     *
     * @param string|PDO $database Path to the SQLite file (or an open PDO connection to one)
     * @param bool $createIndexes Build the indexes the lookups need if they're missing. That takes a
     *   few minutes on the national file and needs it to be writable, so a web app might prefer to
     *   run ZIP4::createIndexes() once at install and pass false here.
     * @throws Exception if the file isn't a ZIP+4 database, or indexes are missing and $createIndexes is false
     */
    public static function init(string|PDO $database, bool $createIndexes = true): void {
        $std = new Standardizer($database);
        $missing = $std->missingIndexes();
        if (count($missing) > 0) {
            if (!$createIndexes) {
                throw new Exception("The ZIP+4 database is missing indexes (" . implode(", ", array_keys($missing)) . "); "
                                . "lookups would scan the whole table. Run ZIP4::createIndexes() on it first.");
            }
            $std->ensureIndexes();
        }
        self::$standardizer = $std;
    }

    /**
     * Adds the indexes the lookups need to a ZIP+4 database, if they aren't there already.
     * @param callable|null $log Called with a message before each index is built
     * @return array The names of the indexes created
     */
    public static function createIndexes(string|PDO $database, ?callable $log = null): array {
        return (new Standardizer($database))->ensureIndexes($log);
    }

    public static function isInitialized(): bool {
        return self::$standardizer !== null;
    }

    /** The engine itself, for the parsing helpers or the raw result array. */
    public static function standardizer(): Standardizer {
        if (self::$standardizer === null) {
            throw new Exception("Call ZIP4::init() with the ZIP+4 database first.");
        }
        return self::$standardizer;
    }

    /**
     * Matches an address.
     *
     * @param string|null $street1 Delivery line. A unit, an ATTN line or the street on line 2 are all sorted out.
     * @param string|null $street2
     * @param string|null $city Either the city and state or the ZIP is needed; both is best.
     * @param string|null $state
     * @param string|null $zip 5 digits, ZIP+4, or 3-4 digits that lost their leading zeros in a spreadsheet
     * @param string|null $company Optional. Only used to give a business the +4 from its own firm record.
     */
    public static function match(?string $street1, ?string $street2 = "", ?string $city = "", ?string $state = "", ?string $zip = "", ?string $company = ""): AddressMatch {
        $r = self::standardizer()->standardize($street1, $street2, $city, $state, $zip, $company);
        if ($r["match"] == Standardizer::MATCH_EXACT) {
            return new AddressMatch(true, $r["street1"], $r["street2"], $r["city"], $r["state"], $r["zip"], $r["plus4"],
                    $r["carrier_route"], $r["record_type"], $r["match"], $r["zip_changed"], $r["fuzzy"], $r["lacs"]);
        }
        $normalized = ["street1" => $r["street1"], "street2" => $r["street2"], "city" => $r["city"], "state" => $r["state"], "zip" => $r["zip"]];
        return new AddressMatch(false, $street1 ?? "", $street2 ?? "", $city ?? "", $state ?? "", $zip ?? "", "",
                "", $r["record_type"], $r["match"], $r["zip_changed"], $r["fuzzy"], $r["lacs"], $r["message"], $normalized);
    }

    /**
     * match() for an array, with the usual key spellings: street1/address1/street, street2/address2/unit,
     * city, state, zip/zipcode/postalcode/postal_code, company. Keys are case-insensitive.
     */
    public static function matchArray(array $address): AddressMatch {
        $a = array_change_key_case($address, CASE_LOWER);
        $pick = function (array $keys) use ($a): string {
            foreach ($keys as $k) {
                if (isset($a[$k]) && is_scalar($a[$k])) {
                    return (string) $a[$k];
                }
            }
            return "";
        };
        return self::match($pick(["street1", "address1", "street", "address"]), $pick(["street2", "address2", "unit"]),
                        $pick(["city"]), $pick(["state"]), $pick(["zip", "zipcode", "postalcode", "postal_code"]), $pick(["company"]));
    }
}
