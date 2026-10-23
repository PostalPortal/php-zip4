<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at http://mozilla.org/MPL/2.0/.
 */

/*
 * Tests against the fixture database in fixture.php. No PHPUnit, no network.
 *
 *   composer test      (or php tests/run.php)
 *
 * Exits non-zero if anything fails.
 */

use PostalPortal\ZIP4\AddressMatch;
use PostalPortal\ZIP4\Standardizer;
use PostalPortal\ZIP4\ZIP4;

require __DIR__ . "/../vendor/autoload.php";
require __DIR__ . "/fixture.php";

// Any warning or notice is a bug
set_error_handler(function ($level, $message, $file, $line) {
    throw new ErrorException("$message at $file:$line");
});

$pass = 0;
$fail = 0;

function check(string $label, $cond, $detail = null): void {
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  ok    $label\n";
    } else {
        $fail++;
        echo "  FAIL  $label\n";
        if ($detail !== null) {
            echo "        " . str_replace("\n", "\n        ", trim(print_r($detail, true))) . "\n";
        }
    }
}

/** Checks a match against expected values; only the keys given are compared. */
function expect(string $label, AddressMatch $m, array $want): void {
    $got = $m->toArray();
    $got["zip+4"] = $m->zipPlus4();
    $diff = [];
    foreach ($want as $k => $v) {
        if (($got[$k] ?? null) !== $v) {
            $diff[$k] = ["want" => $v, "got" => $got[$k] ?? null];
        }
    }
    check($label, count($diff) == 0, $diff);
}

echo "Before init\n";
$threw = false;
try {
    ZIP4::match("1 MAIN ST", "", "", "", "59601");
} catch (Exception $ex) {
    $threw = str_contains($ex->getMessage(), "ZIP4::init");
}
check("match() before init() says to call init()", $threw && !ZIP4::isInitialized());
$threw = false;
try {
    ZIP4::init(__DIR__ . "/no-such-file.sqlite");
} catch (Exception $ex) {
    $threw = str_contains($ex->getMessage(), "not found");
}
check("a missing database file is an error, not a new empty database", $threw && !file_exists(__DIR__ . "/no-such-file.sqlite"));

echo "Indexes\n";
$db = zip4FixtureDatabase();
$threw = false;
try {
    ZIP4::init($db, false);
} catch (Exception $ex) {
    $threw = str_contains($ex->getMessage(), "createIndexes");
}
check("init() without creating indexes refuses an unindexed database", $threw);
check("createIndexes() adds the four indexes", count(ZIP4::createIndexes($db)) == 4);
check("...and only once", ZIP4::createIndexes($db) == []);
ZIP4::init($db, false);
check("then init() accepts it", ZIP4::isInitialized());
ZIP4::init(zip4FixtureDatabase());
check("init() creates them by default", ZIP4::standardizer()->missingIndexes() == []);

echo "Text helpers\n";
check("punctuation", Standardizer::cleanText("123 N. Main St., #4") == "123 N MAIN ST # 4", Standardizer::cleanText("123 N. Main St., #4"));
check("apostrophes and hyphens", Standardizer::cleanText("12 o'brien rd - rear") == "12 OBRIEN RD REAR", Standardizer::cleanText("12 o'brien rd - rear"));
check("ZIP+4 split", Standardizer::splitZip("59601-1234") == ["59601", "1234"] && Standardizer::splitZip("596011234") == ["59601", "1234"]);
check("ZIP that lost its leading zeros", Standardizer::splitZip("501") == ["00501", ""] && Standardizer::splitZip("2134") == ["02134", ""]);
check("ordinals", Standardizer::ordinalWord("FIFTH") == "5TH" && Standardizer::ordinalWord("TWENTY-NINTH") == "29TH"
        && Standardizer::ordinalWord("TWENTY-FIRST") == "21ST" && Standardizer::ordinalWord("FORTIETH") == "40TH" && Standardizer::ordinalWord("MAIN") === null);
check("a swapped pair of letters is one typo", Standardizer::typoDistance("MIAN", "MAIN") == 1 && Standardizer::typoDistance("MAIN", "MAIN") == 0);
check("numeric range with parity", Standardizer::inRange("124", "0000000100", "0000000198", "E") && !Standardizer::inRange("125", "0000000100", "0000000198", "E"));
check("alpha house numbers", Standardizer::inRange("245A", "245A", "245A", "B") && !Standardizer::inRange("245", "245A", "245A", "B")
        && !Standardizer::inRange("245B", "245A", "245A", "B") && Standardizer::inRange("123A", "0000000100", "0000000199", "B"));
check("zero-padded unit numbers", Standardizer::inRange("4", "00000004", "00000004", "E") && !Standardizer::inRange("5", "00000004", "00000004", "E"));
check("an empty range holds nothing", !Standardizer::inRange("1", "", "", "B"));

echo "Street addresses\n";
expect("directional and suffix spelled out", ZIP4::match("123 north main street", "", "helena", "mt", "59601"),
        ["verified" => true, "street1" => "123 N MAIN ST", "city" => "HELENA", "state" => "MT", "zip+4" => "59601-1002", "carrier_route" => "C001", "record_type" => "S"]);
expect("odd/even ranges", ZIP4::match("124 N Main St", "", "Helena", "MT", "59601"), ["verified" => true, "plus4" => "1001"]);
expect("missing directional with N and S streets: not guessed", ZIP4::match("124 Main St", "", "helena", "mt", "59601"),
        ["verified" => false, "street1" => "124 Main St", "city" => "helena", "match_level" => "none"]);
expect("missing suffix, one street by that name", ZIP4::match("150 S. Main", "", "", "", "59601"),
        ["verified" => true, "street1" => "150 S MAIN ST", "city" => "HELENA", "state" => "MT", "plus4" => "2001"]);
expect("street name with spaces and no suffix", ZIP4::match("50 Last Chance Gulch", "", "helena", "mt", "59601"), ["verified" => true, "street1" => "50 LAST CHANCE GULCH", "plus4" => "3001"]);
expect("highway", ZIP4::match("1500 US Hwy 12 W", "", "helena", "mt", "59601"), ["verified" => true, "street1" => "1500 US HIGHWAY 12 W", "plus4" => "5001"]);
expect("ST meaning SAINT", ZIP4::match("15 St Johns Ave", "", "Helena", "MT", "59601"), ["verified" => true, "street1" => "15 SAINT JOHNS AVE"]);
expect("misspelled street name", ZIP4::match("123 N Mian St", "", "Helena", "MT", "59601"), ["verified" => true, "street1" => "123 N MAIN ST", "spelling_corrected" => true]);
expect("alternate (old) street name becomes the base name", ZIP4::match("550 Old Mill Rd", "", "helena", "mt", "59601"), ["verified" => true, "street1" => "550 MILL ST", "plus4" => "6001"]);
expect("deleted (Action D) records are ignored", ZIP4::match("210 N Main St", "", "Helena", "MT", "59601"),
        ["verified" => false, "match_level" => "street", "street1" => "210 N Main St", "normalized" => ["street1" => "210 N MAIN ST", "street2" => "", "city" => "HELENA", "state" => "MT", "zip" => "59601"]]);
expect("ZIP+4 typed in is replaced by the right one", ZIP4::match("123 N MAIN ST", "", "Helena", "MT", "59601-9999"), ["verified" => true, "zip" => "59601", "plus4" => "1002"]);

echo "Units\n";
expect("unit on line 1", ZIP4::match("316 n park avenue apt 5", "", "helena", "mt", "59601"), ["verified" => true, "street1" => "316 N PARK AVE APT 5", "plus4" => "4101"]);
expect("# on line 2 takes the building's designator", ZIP4::match("316 N Park Ave", "#15", "helena", "mt", "59601"), ["verified" => true, "street1" => "316 N PARK AVE", "street2" => "APT 15", "plus4" => "4102"]);
expect("no unit: the building's default +4", ZIP4::match("316 N Park Ave", "", "helena", "mt", "59601"), ["verified" => true, "plus4" => "4100"]);
expect("unit and street on the wrong lines", ZIP4::match("Suite 2", "316 N PARK AVE", "helena", "mt", "59601"), ["verified" => true, "street1" => "316 N PARK AVE", "street2" => "STE 2", "plus4" => "4101"]);
expect("ATTN line moves below the street", ZIP4::match("ATTN BOB", "123 N MAIN ST", "Helena", "MT", "59601"), ["verified" => true, "street1" => "123 N MAIN ST", "street2" => "ATTN BOB"]);
expect("FL unit in a highrise, spelled-out ordinal", ZIP4::match("292 Fifth Ave Fl 4", "", "New York", "NY", "10001"), ["verified" => true, "street1" => "292 5TH AVE FL 4", "plus4" => "4513"]);
expect("FRNT with no number", ZIP4::match("290 5th avenue front", "", "New York", "NY", "10001"), ["verified" => true, "street1" => "290 5TH AVE FRNT", "plus4" => "4511"]);

echo "Alpha house numbers (Manhattan)\n";
expect("245A", ZIP4::match("245A W 29th St", "", "new york", "ny", "10001"), ["verified" => true, "street1" => "245A W 29TH ST", "plus4" => "5208"]);
expect("245 without the A is not in the 245A range", ZIP4::match("245 W 29TH ST", "", "new york", "ny", "10001"), ["verified" => false, "match_level" => "street", "street1" => "245 W 29TH ST"]);
expect("compound ordinal spelled out", ZIP4::match("43A West Twenty-Ninth Street", "", "new york", "ny", "10001"), ["verified" => true, "street1" => "43A W 29TH ST", "plus4" => "4597"]);
expect("...and without the hyphen", ZIP4::match("43A W Twenty Ninth St", "", "new york", "ny", "10001"), ["verified" => true, "street1" => "43A W 29TH ST"]);

echo "Firms\n";
expect("a firm's own +4 when the company matches", ZIP4::match("2320 5th Ave", "", "helena", "mt", "59601", "Smith School Inc."), ["verified" => true, "plus4" => "4996", "record_type" => "F"]);
expect("the street's +4 for anyone else at that address", ZIP4::match("2320 5th Ave", "", "helena", "mt", "59601"), ["verified" => true, "plus4" => "4990", "record_type" => "S"]);
expect("only a firm record covers it: verified, but no +4", ZIP4::match("1410 Eighth Avenue", "", "helena", "mt", "59601"), ["verified" => true, "street1" => "1410 8TH AVE", "plus4" => "", "carrier_route" => "C620"]);

echo "Boxes and routes\n";
expect("PO box with no ZIP", ZIP4::match("PO Box 55", "", "Helena", "MT", ""), ["verified" => true, "street1" => "PO BOX 55", "zip+4" => "59601-0001", "zip_changed" => false]);
expect("P.O. Box", ZIP4::match("P.O. Box 150", "", "", "", "59601"), ["verified" => true, "street1" => "PO BOX 150", "plus4" => "0100"]);
expect("PO box number that doesn't exist", ZIP4::match("PO Box 5000", "", "", "", "59601"), ["verified" => false, "match_level" => "street"]);
expect("rural route, flagged as LACS-converted", ZIP4::match("Rural Route 1 Box 20", "", "helena", "mt", "59601"), ["verified" => true, "street1" => "RR 1 BOX 20", "plus4" => "7001", "lacs_converted" => true]);
expect("general delivery", ZIP4::match("General Delivery", "", "", "", "59601"), ["verified" => true, "street1" => "GENERAL DELIVERY", "plus4" => "9999"]);
expect("APO", ZIP4::match("UNIT 100307 BOX 2150", "", "APO", "AA", "34093"), ["verified" => true, "street1" => "UNIT 100307 BOX 2150", "city" => "APO", "state" => "AA", "plus4" => "0721"]);

echo "Cities and ZIPs\n";
expect("wrong ZIP for the street is corrected from the city's other ZIPs", ZIP4::match("1500 Euclid Ave", "", "Helena", "MT", "59601"), ["verified" => true, "zip" => "59602", "zip_changed" => true]);
expect("no ZIP, abbreviated city", ZIP4::match("10 Main St", "", "E Helena", "MT", ""), ["verified" => true, "city" => "EAST HELENA", "zip" => "59635", "zip_changed" => false]);
expect("an acceptable alternate city name is kept", ZIP4::match("50 Valley Dr", "", "Helena Valley", "MT", "59602"), ["verified" => true, "city" => "HELENA VALLEY"]);
expect("a not-acceptable city name becomes the preferred one", ZIP4::match("292 5th Ave", "", "Empire State", "NY", "10001"), ["verified" => true, "city" => "NEW YORK"]);
expect("not found: as entered, with a cleaned-up version", ZIP4::match("77 Sunset Boulevard Apartment 3", "", "", "", "59601"),
        ["verified" => false, "street1" => "77 Sunset Boulevard Apartment 3", "city" => "", "match_level" => "none",
            "normalized" => ["street1" => "77 SUNSET BLVD APT 3", "street2" => "", "city" => "HELENA", "state" => "MT", "zip" => "59601"]]);
expect("nothing to go on", ZIP4::match("", "", "", "", ""), ["verified" => false, "match_level" => "none"]);

echo "matchArray\n";
expect("usual key spellings", ZIP4::matchArray(["Address1" => "123 north main street", "City" => "Helena", "State" => "MT", "PostalCode" => "59601"]), ["verified" => true, "zip+4" => "59601-1002"]);
check("JSON", json_decode(json_encode(ZIP4::match("123 N MAIN ST", "", "", "", "59601")), true)["zip"] == "59601");

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
