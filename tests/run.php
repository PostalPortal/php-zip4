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

echo "A database built by AddressDatabase's zipdbgen.py\n";
$tmp = sys_get_temp_dir() . "/zip4-test-" . getmypid() . ".sqlite";
if (file_exists($tmp)) {
    unlink($tmp);
}
$built = zip4FixtureDatabase("sqlite:$tmp");
foreach (['CREATE INDEX "zip" ON "ZIP4" ("ZipCode")', 'CREATE INDEX "stname_state_zip" ON "ZIP4" ("StName", "State", "ZipCode")',
    'CREATE INDEX Index_ZIPCodes_ZipCode ON ZIPCodes (ZipCode)', 'CREATE INDEX Index_ZIPCodes_State ON ZIPCodes (State)',
    'CREATE INDEX Index_ZIPCodes_CityStateKey ON ZIPCodes (CityStateKey)'] as $sql) {
    $built->exec($sql);
}
$built = null;
chmod($tmp, 0444);
$threw = null;
try {
    ZIP4::init($tmp, false);
} catch (Exception $ex) {
    $threw = $ex->getMessage();
}
check("its indexes are used as they are, on a read-only file", $threw === null && ZIP4::standardizer()->missingIndexes() == [], $threw);
expect("...and it matches", ZIP4::match("123 N Main St", "", "", "", "59601"), ["verified" => true, "plus4" => "1002"]);
chmod($tmp, 0644);
unlink($tmp);
ZIP4::init(zip4FixtureDatabase());

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
check("accents and look-alike dashes", Standardizer::cleanText("Peña Blvd") == "PENA BLVD" && Standardizer::cleanText("12–34 Main St") == "12-34 MAIN ST"
        && Standardizer::cleanText("Doña Ana Rd") == "DONA ANA RD", [Standardizer::cleanText("Peña Blvd"), Standardizer::cleanText("12–34 Main St")]);
check("state names and codes", Standardizer::stateCode("montana") == "MT" && Standardizer::stateCode("PR") == "PR" && Standardizer::stateCode("AE") == "AE" && Standardizer::stateCode("XX") === null);

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

echo "Directional words in street names\n";
expect("NORTH SLOPE RD is a name, not N SLOPE RD", ZIP4::match("1234 North Slope Road", "", "East Helena", "MT", "59635"), ["verified" => true, "street1" => "1234 NORTH SLOPE RD", "plus4" => "6201"]);
expect("N SLOPE RD typed for it still matches", ZIP4::match("1234 N Slope Rd", "", "East Helena", "MT", "59635"), ["verified" => true, "street1" => "1234 NORTH SLOPE RD"]);
expect("number out of range keeps the name", ZIP4::match("9999 North Slope Road", "", "East Helena", "MT", "59635"), ["match_level" => "street", "normalized" => ["street1" => "9999 NORTH SLOPE RD", "street2" => "", "city" => "EAST HELENA", "state" => "MT", "zip" => "59635"]]);
expect("both streets in the ZIP: spelled out means the name", ZIP4::match("1234 NORTH SLOPE ROAD", "", "Helena", "MT", "59601"), ["verified" => true, "street1" => "1234 NORTH SLOPE RD", "plus4" => "6201"]);
expect("both streets in the ZIP: N means the directional", ZIP4::match("1234 N. Slope Rd", "", "Helena", "MT", "59601"), ["verified" => true, "street1" => "1234 N SLOPE RD", "plus4" => "6301"]);
expect("a street that isn't in the file isn't abbreviated into a different name", ZIP4::match("55 North Ridge Road", "", "Helena", "MT", "59601"), ["verified" => false, "normalized" => ["street1" => "55 NORTH RIDGE RD", "street2" => "", "city" => "HELENA", "state" => "MT", "zip" => "59601"]]);
expect("...but N. is still a directional there", ZIP4::match("55 N. Ridge Road", "", "Helena", "MT", "59601"), ["verified" => false, "normalized" => ["street1" => "55 N RIDGE RD", "street2" => "", "city" => "HELENA", "state" => "MT", "zip" => "59601"]]);
expect("EAST WEST HWY stays whole", ZIP4::match("10 East West Highway", "", "Helena", "MT", "59601"), ["verified" => false, "normalized" => ["street1" => "10 EAST WEST HWY", "street2" => "", "city" => "HELENA", "state" => "MT", "zip" => "59601"]]);

echo "Units\n";
expect("unit on line 1", ZIP4::match("316 n park avenue apt 5", "", "helena", "mt", "59601"), ["verified" => true, "street1" => "316 N PARK AVE APT 5", "plus4" => "4101"]);
expect("# on line 2 takes the building's designator", ZIP4::match("316 N Park Ave", "#15", "helena", "mt", "59601"), ["verified" => true, "street1" => "316 N PARK AVE", "street2" => "APT 15", "plus4" => "4102"]);
expect("no unit: the building's default +4", ZIP4::match("316 N Park Ave", "", "helena", "mt", "59601"), ["verified" => true, "plus4" => "4100"]);
expect("unit and street on the wrong lines (and STE becomes the building's APT)", ZIP4::match("Suite 2", "316 N PARK AVE", "helena", "mt", "59601"), ["verified" => true, "street1" => "316 N PARK AVE", "street2" => "APT 2", "plus4" => "4101"]);
expect("ATTN line moves below the street", ZIP4::match("ATTN BOB", "123 N MAIN ST", "Helena", "MT", "59601"), ["verified" => true, "street1" => "123 N MAIN ST", "street2" => "ATTN BOB"]);
expect("FL unit in a highrise, spelled-out ordinal", ZIP4::match("292 Fifth Ave Fl 4", "", "New York", "NY", "10001"), ["verified" => true, "street1" => "292 5TH AVE FL 4", "plus4" => "4513"]);
expect("FRNT with no number", ZIP4::match("290 5th avenue front", "", "New York", "NY", "10001"), ["verified" => true, "street1" => "290 5TH AVE FRNT", "plus4" => "4511"]);

foreach (["#456", "UNIT 456", "LOT 456", "Space 456", "SP 456", "Trailer 456", "Apt. 456"] as $typed) {
    expect("$typed at a trailer park is TRLR 456", ZIP4::match("123 sample road $typed", "", "", "", "59601"), ["verified" => true, "street1" => "123 SAMPLE RD TRLR 456", "plus4" => "7123"]);
}
expect("a bare unit number on line 2", ZIP4::match("123 Sample Rd", "456", "", "", "59601"), ["verified" => true, "street2" => "TRLR 456", "plus4" => "7123"]);
expect("a bare line 2 that isn't a unit here is left alone", ZIP4::match("150 S Main St", "B2", "", "", "59601"), ["verified" => true, "street2" => "B2", "plus4" => "2001"]);
expect("BLDG isn't a trailer: kept, with the street's +4", ZIP4::match("123 sample road bldg 456", "", "", "", "59601"), ["verified" => true, "street1" => "123 SAMPLE RD BLDG 456", "plus4" => "7100"]);
expect("a unit that isn't in the file: verified on the street, and says so", ZIP4::match("123 sample road #999", "", "", "", "59601"), ["verified" => true, "street1" => "123 SAMPLE RD # 999", "plus4" => "7100", "message" => "Unit not found at this address"]);
expect("APT 4 in a building with APT 4 and STE 4", ZIP4::match("500 Broadway St Apt 4", "", "", "", "59601"), ["verified" => true, "street1" => "500 BROADWAY ST APT 4", "plus4" => "7201"]);
expect("STE 4 there", ZIP4::match("500 Broadway St Ste 4", "", "", "", "59601"), ["verified" => true, "street1" => "500 BROADWAY ST STE 4", "plus4" => "7202"]);
expect("# 4 there: not guessed", ZIP4::match("500 Broadway St #4", "", "", "", "59601"), ["verified" => true, "street1" => "500 BROADWAY ST # 4", "plus4" => "", "message" => "Unit matches more than one kind of unit here; add the designator"]);

echo "Interstates and named units (Sweet Grass border station)\n";
foreach (["39825 Interstate 15", "39825 I-15", "39825 I 15", "39825 I15", "39825 IH-15", "39825 Interstate Hwy 15", "39825 Hwy I-15"] as $typed) {
    expect($typed, ZIP4::match($typed, "", "Sweet Grass", "MT", "59484"), ["verified" => true, "street1" => "39825 INTERSTATE 15", "plus4" => "8504"]);
}
expect("Unit FDA", ZIP4::match("39825 I-15 Unit FDA", "", "", "", "59484"), ["verified" => true, "street1" => "39825 INTERSTATE 15 UNIT FDA", "plus4" => "8510"]);
expect("#FDA", ZIP4::match("39825 I-15 #FDA", "", "", "", "59484"), ["verified" => true, "street1" => "39825 INTERSTATE 15 UNIT FDA"]);
expect("FDA alone on line 2", ZIP4::match("39825 I-15", "FDA", "", "", "59484"), ["verified" => true, "street2" => "UNIT FDA", "plus4" => "8510"]);
expect("hyphenated unit name", ZIP4::match("39825 Interstate 15 Unit FDA-OTED", "", "", "", "59484"), ["verified" => true, "street1" => "39825 INTERSTATE 15 UNIT FDA-OTED", "plus4" => "8510"]);
expect("the company is the unit: acronym", ZIP4::match("39825 I-15", "", "", "", "59484", "U.S. Fish and Wildlife Service"), ["verified" => true, "street1" => "39825 INTERSTATE 15 UNIT USFWS", "plus4" => "8509"]);
expect("the company is the unit: name", ZIP4::match("39825 I-15", "", "", "", "59484", "FDA"), ["verified" => true, "street1" => "39825 INTERSTATE 15 UNIT FDA", "plus4" => "8510"]);
expect("no unit or company: the street's +4", ZIP4::match("39825 I-15", "", "", "", "59484"), ["verified" => true, "street1" => "39825 INTERSTATE 15", "plus4" => "8504"]);
check("company-to-unit names", Standardizer::companyIsUnit("Food and Drug Administration", "FDA") && Standardizer::companyIsUnit("FDA OTED", "FDA-OTED")
        && !Standardizer::companyIsUnit("Smith Hardware", "FDA") && !Standardizer::companyIsUnit("Unit 4 Inc", "4"));

echo "Spellings people use (from AddressDatabase)\n";
$h = fn($street, $c = "Helena", $st = "MT", $z = "59601") => ZIP4::match($street, "", $c, $st, $z);
expect("S CRK RD is S CREEK RD", $h("123 S Crk Rd"), ["verified" => true, "street1" => "123 S CREEK RD", "plus4" => "8801"]);
expect("MTN VIEW DR is MOUNTAIN VIEW DR", $h("123 Mtn View Drive"), ["verified" => true, "street1" => "123 MOUNTAIN VIEW DR"]);
foreach (["MT-200", "MT 200", "Montana Hwy 200", "MT Highway 200", "MT State Hwy 200"] as $typed) {
    expect("$typed is MT HIGHWAY 200", $h("1500 $typed"), ["verified" => true, "street1" => "1500 MT HIGHWAY 200", "plus4" => "8804"]);
}
foreach (["FS Rd 4100", "FSR 4100", "NFSR 4100", "Forest Rd 4100", "Forest Service Road 4100 Rd"] as $typed) {
    expect("$typed is FOREST SERVICE ROAD 4100", $h("150 $typed"), ["verified" => true, "street1" => "150 FOREST SERVICE ROAD 4100", "plus4" => "8805"]);
}
expect("OLD HIGHWAY 10 is OLD HWY 10 in the file", $h("150 Old Highway 10"), ["verified" => true, "street1" => "150 OLD HWY 10"]);
expect("E I-90", $h("150 E I-90"), ["verified" => true, "street1" => "150 INTERSTATE 90"]);
expect("accents", $h("10 Peña Blvd"), ["verified" => true, "street1" => "10 PENA BLVD"]);
expect("MLK", $h("50 MLK Jr Blvd"), ["verified" => true, "street1" => "50 MARTIN LUTHER KING JR BLVD"]);
expect("M L KING", $h("50 M L King Jr Blvd"), ["verified" => true, "street1" => "50 MARTIN LUTHER KING JR BLVD"]);
expect("informal suffix CR for CRK (only because the file has it)", $h("50 Bear Cr"), ["verified" => true, "street1" => "50 BEAR CRK"]);
expect("informal suffix PW for PKWY", $h("50 Fir Pw"), ["verified" => true, "street1" => "50 FIR PKWY"]);
expect("...but not rewritten without a match", $h("50 Calle La", "", "", "59601"), ["verified" => false, "normalized" => ["street1" => "50 CALLE LA", "street2" => "", "city" => "HELENA", "state" => "MT", "zip" => "59601"]]);
expect("misspelled suffix", $h("12 Sunset Cresent", "", "", "59601"), ["verified" => false, "normalized" => ["street1" => "12 SUNSET CRES", "street2" => "", "city" => "HELENA", "state" => "MT", "zip" => "59601"]]);
expect("suffix typed twice", $h("123 N Main Street St"), ["verified" => true, "street1" => "123 N MAIN ST", "plus4" => "1002"]);
expect("house number typed twice", $h("124 124 N Main St"), ["verified" => true, "street1" => "124 N MAIN ST"]);
expect("a single-address record beats the block range it's in", $h("1250 Queens Blvd"), ["verified" => true, "plus4" => "8809"]);
expect("...and the range still covers its neighbors", $h("1252 Queens Blvd"), ["verified" => true, "plus4" => "8808"]);
expect("house number letter typed separately", ZIP4::match("245 A W 29th St", "", "New York", "NY", "10001"), ["verified" => true, "street1" => "245A W 29TH ST", "plus4" => "5208"]);
expect("bare trailing unit", $h("316 N Park Ave 15"), ["verified" => true, "street1" => "316 N PARK AVE APT 15", "plus4" => "4102"]);
expect("en dash in a Queens-style house number", ZIP4::match("1–2 Last Chance Gulch", "", "", "", "59601"), ["verified" => true, "street1" => "1-2 LAST CHANCE GULCH"]);

echo "Patterns from a real store's customer list\n";
$h = fn($street, $street2 = "", $c = "Helena", $st = "MT", $z = "59601", $co = "") => ZIP4::match($street, $street2, $c, $st, $z, $co);
expect("suffix typo STRET", $h("6546 quill stret"), ["verified" => true, "street1" => "6546 QUILL ST", "plus4" => "8825"]);
expect("suffix typo TERRANCE", $h("8952 NW Maple Terrance"), ["verified" => true, "street1" => "8952 NW MAPLE TER", "plus4" => "8826"]);
expect("suffix typo BLV, name typo SATELITE, unit on line 2", $h("9550 SATELITE BLV", "SUITE 100"), ["verified" => true, "street1" => "9550 SATELLITE BLVD", "street2" => "STE 100", "plus4" => "8827"]);
expect("WEY for WAY (only because the file has it)", $h("3 Hurd Wey"), ["verified" => true, "street1" => "3 HURD WAY", "plus4" => "8824"]);
expect("CL is CT here", $h("2925 Pine Hollow Cl"), ["verified" => true, "street1" => "2925 PINE HOLLOW CT", "plus4" => "8823"]);
expect("NORTH WEST, and a numbered street without its ordinal", $h("1531 NORTH WEST 153 STREET"), ["verified" => true, "street1" => "1531 NW 153RD ST", "plus4" => "8820"]);
expect("147 PLACE is 147TH PL, in the city's other ZIP", $h("8616 sw 147 place"), ["verified" => true, "street1" => "8616 SW 147TH PL", "zip" => "59602", "zip_changed" => true]);
expect("E 4 ST", $h("531 E 4 ST"), ["verified" => true, "street1" => "531 E 4TH ST", "plus4" => "8822"]);
expect("first two letters swapped (AOK POND), informal CR for CIR", $h("8184 aok pond cr"), ["verified" => true, "street1" => "8184 OAK POND CIR", "plus4" => "8830", "spelling_corrected" => true]);
expect("letters left out (POTTSWN)", $h("1442 Pottswn Pike Unit 173"), ["verified" => true, "street1" => "1442 POTTSTOWN PIKE UNIT 173", "plus4" => "8829"]);
expect("misspelled street on line 2 alone, with a bare unit", $h("", "1375 W LANDSTRET ROAD 601"), ["verified" => true, "street1" => "1375 W LANDSTREET RD # 601", "plus4" => "8828"]);
expect("bare unit after RD", $h("1057 S Hiawassee RD 1915"), ["verified" => true, "street1" => "1057 S HIAWASSEE RD # 1915", "plus4" => "8837"]);
expect("ST Z after a complete street is STE Z", $h("11784 Willow Creek Rd St Z"), ["verified" => true, "street1" => "11784 WILLOW CREEK RD STE Z", "plus4" => "8831"]);
expect("2ND FLOOR and a PMB", $h("7726 WINEGARD RD 2ND FLOOR PMB 43"), ["verified" => true, "street1" => "7726 WINEGARD RD FL 2 PMB 43", "plus4" => "8833"]);
expect("PMB # 43", $h("7726 Winegard Rd PMB #43"), ["verified" => true, "street1" => "7726 WINEGARD RD PMB 43"]);
expect("junk after the street moves to line 2", $h("723 CARTER DR MARINSON"), ["verified" => true, "street1" => "723 CARTER DR", "street2" => "MARINSON", "plus4" => "8832"]);
expect("the city and state after the street are dropped", $h("723 Carter Dr Helena, MT"), ["verified" => true, "street1" => "723 CARTER DR", "street2" => ""]);
expect("Puerto Rico order: street, number, neighborhood", ZIP4::match("calle henna 2209 santurce", "", "Adjuntas", "PR", "00601"),
        ["verified" => true, "street1" => "2209 CALLE HENNA", "street2" => "SANTURCE", "plus4" => "8834"]);
expect("...and left as typed when it isn't in the file", ZIP4::match("calle rosa 15 santurce", "", "Adjuntas", "PR", "00601"),
        ["verified" => false, "normalized" => ["street1" => "CALLE ROSA 15 SANTURCE", "street2" => "", "city" => "ADJUNTAS", "state" => "PR", "zip" => "00601"]]);
expect("Queens number typed without the hyphen", ZIP4::match("14415 41st ave l2", "", "New York", "NY", "10001"), ["verified" => true, "street1" => "144-15 41ST AVE # L2", "plus4" => "8835"]);
expect("a directional stuck to the house number", $h("16n stonington rd"), ["verified" => true, "street1" => "16 N STONINGTON RD", "plus4" => "8836"]);
expect("a PO box's +4 from a range of them", $h("PO Box 616763"), ["verified" => true, "street1" => "PO BOX 616763", "plus4" => "6763"]);
expect("Windows-1252 text (not UTF-8)", $h("10 Pe\xF1a Blvd"), ["verified" => true, "street1" => "10 PENA BLVD"]);
expect("Spanish unit words", $h("316 N Park Ave Apto 5"), ["verified" => true, "street1" => "316 N PARK AVE APT 5", "plus4" => "4101"]);
expect("a foreign-looking line 2 isn't promoted over line 1", $h("URBANIZACION PRADOS", "Sector Tulipan. Casa# 14A", "", "", ""),
        ["verified" => false, "normalized" => ["street1" => "URBANIZACION PRADOS", "street2" => "SECTOR TULIPAN CASA # 14A", "city" => "", "state" => "", "zip" => ""]]);
check("ordinals for numbers", array_map([Standardizer::class, "ordinalSuffix"], [1, 2, 3, 4, 11, 12, 13, 21, 22, 101, 111, 153])
        == ["1ST", "2ND", "3RD", "4TH", "11TH", "12TH", "13TH", "21ST", "22ND", "101ST", "111TH", "153RD"]);
check("Queens ranges", Standardizer::inRange("14415", "144-01", "144-99", "B") && Standardizer::inRange("144-15", "144-01", "144-99", "B")
        && !Standardizer::inRange("14515", "144-01", "144-99", "B") && Standardizer::fileNumberFormat("14415", "144-01") == "144-15");
check("suffix options", Standardizer::suffixOptions("CL") == ["CT", "CIR"] && Standardizer::suffixOptions("BOULEVAD") == ["BLVD"] && Standardizer::suffixOptions("MAPLE") == []);
check("Windows-1252", Standardizer::fromWindows1252("Borb\xF3n \x96 \x93x\x94") == "Borbón – “x”");

echo "Whole addresses in one field\n";
expect("street, city, state ZIP all on line 1", ZIP4::match("123 N Main St, Helena, MT 59601", "", "", "", ""), ["verified" => true, "street1" => "123 N MAIN ST", "city" => "HELENA", "zip+4" => "59601-1002"]);
expect("...with a unit, ZIP+4 and USA", ZIP4::match("316 N Park Ave Apt 5, Helena MT 59601-4101, USA", "", "", "", ""), ["verified" => true, "street1" => "316 N PARK AVE APT 5", "plus4" => "4101"]);
expect("...with the unit after a comma", ZIP4::match("316 N Park Ave, Apt 5, Helena, Montana 59601", "", "", "", ""), ["verified" => true, "street1" => "316 N PARK AVE APT 5"]);
expect("a unit after a comma isn't a city and state", ZIP4::match("316 N Park Ave, Unit PA", "", "Helena", "MT", "59601"), ["street1" => "316 N PARK AVE UNIT PA"]);
expect("city, state ZIP in the city field", ZIP4::match("123 N Main St", "", "Helena, MT 59601", "", ""), ["verified" => true, "city" => "HELENA", "state" => "MT", "zip" => "59601"]);
expect("CITY OF and a spelled-out state", ZIP4::match("123 N Main St", "", "City of Helena", "Montana", "59601"), ["verified" => true, "city" => "HELENA", "state" => "MT"]);
check("splitLastLine leaves a different state alone", Standardizer::splitLastLine("1 A St, Boise, ID 83702", "", "MT", "") == ["1 A St, Boise, ID 83702", "", "MT", ""]);

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
