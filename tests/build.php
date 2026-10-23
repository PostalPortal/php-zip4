<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at http://mozilla.org/MPL/2.0/.
 */

/*
 * Tests bin/zip4-build: packs the fixture data the way zip-codes.com ships it (a ZIP4-XX.zip per
 * state inside the ZIP+4 archive, CSVs inside the 5-digit one), builds a database from that, and
 * matches against it.
 *
 *   php tests/build.php
 */

use PostalPortal\ZIP4\ZIP4;

require __DIR__ . "/../vendor/autoload.php";
require __DIR__ . "/fixture.php";

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

function rmTree(string $dir): void {
    foreach (glob("$dir/*") as $f) {
        is_dir($f) ? rmTree($f) : unlink($f);
    }
    rmdir($dir);
}

function csvFile(string $path, array $rows, bool $bom = false): void {
    $h = fopen($path, "w");
    if ($bom) {
        fwrite($h, "\xEF\xBB\xBF");
    }
    fputcsv($h, array_keys($rows[0]), ",", "\"", "");
    foreach ($rows as $r) {
        fputcsv($h, array_values($r), ",", "\"", "");
    }
    fclose($h);
}

function zipOf(string $path, array $files): void {
    $z = new ZipArchive();
    $z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($files as $name => $source) {
        $z->addFile($source, $name);
    }
    $z->close();
}

function build(array $args): array {
    $cmd = "php " . escapeshellarg(__DIR__ . "/../bin/zip4-build") . " " . implode(" ", array_map("escapeshellarg", $args)) . " 2>&1";
    exec($cmd, $out, $code);
    return [$code, implode("\n", $out)];
}

$dir = sys_get_temp_dir() . "/zip4-build-test-" . getmypid();
if (is_dir($dir)) {
    rmTree($dir);
}
mkdir($dir);

// The fixture, as zip-codes.com's files: ZIP4 per state, without StreetFull (the builder makes that)
$source = zip4FixtureDatabase();
$zip4Rows = $source->query("SELECT * FROM ZIP4 ORDER BY rowid")->fetchAll(PDO::FETCH_ASSOC);
foreach ($zip4Rows as &$r) {
    unset($r["StreetFull"]);
}
unset($r);
$zip4Rows[0]["BuildingName"] = "SMITH, JONES & CO \"EAST\""; // a comma and quotes, quoted in the CSV
$byState = [];
foreach ($zip4Rows as $r) {
    $byState[$r["State"]][] = $r;
}
$product = [];
foreach ($byState as $state => $rows) {
    csvFile("$dir/ZIP4-$state.csv", $rows, $state == "MT");
    zipOf("$dir/ZIP4-$state.zip", ["ZIP4-$state.csv" => "$dir/ZIP4-$state.csv"]);
    $product["ZIP4-$state.zip"] = "$dir/ZIP4-$state.zip";
}
$product["readme.txt"] = __FILE__; // other files in the archive are ignored
zipOf("$dir/zip4-product.zip", $product);

$zipcodeRows = $source->query("SELECT * FROM ZIPCodes ORDER BY rowid")->fetchAll(PDO::FETCH_ASSOC);
csvFile("$dir/zip-codes-database-STANDARD.csv", $zipcodeRows);
csvFile("$dir/zip-codes-database-MULTI-COUNTY.csv", [
    ["ZipCode" => "59601", "StateFIPS" => "30", "State" => "MT", "CountyFIPS" => "30049", "County" => "LEWIS AND CLARK", "CountyMixedCase" => "Lewis and Clark"],
    ["ZipCode" => "59601", "StateFIPS" => "30", "State" => "MT", "CountyFIPS" => "30043", "County" => "JEFFERSON", "CountyMixedCase" => "Jefferson"]
]);
zipOf("$dir/zip5-product.zip", ["zip-codes-database-STANDARD.csv" => "$dir/zip-codes-database-STANDARD.csv",
    "zip-codes-database-MULTI-COUNTY.csv" => "$dir/zip-codes-database-MULTI-COUNTY.csv"]);

echo "Both products in one run\n";
[$code, $out] = build(["--quiet", "$dir/zip4-product.zip", "$dir/zip5-product.zip", "$dir/zip4.sqlite"]);
check("builds", $code == 0 && file_exists("$dir/zip4.sqlite"), $out);
$db = new PDO("sqlite:$dir/zip4.sqlite");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$count = fn($sql) => (int) $db->query($sql)->fetchColumn();
check("every ZIP+4 row from every state", $count("SELECT COUNT(*) FROM ZIP4") == count($zip4Rows) && count($byState) > 2, [$count("SELECT COUNT(*) FROM ZIP4"), count($zip4Rows)]);
check("every 5-digit row", $count("SELECT COUNT(*) FROM ZIPCodes") == count($zipcodeRows));
check("multi-county rows", $count("SELECT COUNT(*) FROM ZIPCodesMultiCounty") == 2);
check("States table", $count("SELECT COUNT(*) FROM States") == 62 && $db->query("SELECT name FROM States WHERE code = 'AE'")->fetchColumn() == "Armed Forces Europe, the Middle East, and Canada");
check("StreetFull is built", $db->query("SELECT StreetFull FROM ZIP4 WHERE UpdateKey = 'T9'")->fetchColumn() === "US HIGHWAY 12 W"
        && $db->query("SELECT StreetFull FROM ZIP4 WHERE UpdateKey = 'T1'")->fetchColumn() === "N MAIN ST");
check("leading zeros are kept", $db->query("SELECT ZipCode FROM ZIPCodes WHERE City = 'HOLTSVILLE' LIMIT 1")->fetchColumn() === "00501"
        && $db->query("SELECT Plus4Low FROM ZIP4 WHERE UpdateKey = 'T14'")->fetchColumn() === "0001");
check("quoted commas and quotes survive", $db->query("SELECT BuildingName FROM ZIP4 WHERE UpdateKey = " . $db->quote($zip4Rows[0]["UpdateKey"]))->fetchColumn() === "SMITH, JONES & CO \"EAST\"");
check("a BOM on the header is ignored", $count("SELECT COUNT(*) FROM ZIP4 WHERE State = 'MT' AND ZipCode <> ''") == count($byState["MT"]));
$indexes = array_column($db->query("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'ZIP4'")->fetchAll(PDO::FETCH_ASSOC), "name");
check("zipdbgen.py's indexes, plus the (ZipCode, StName) one", count(array_diff(["addressnumber", "key", "zipcode_route", "state", "streetfull_state",
            "stname_state", "zip", "streetfull_state_zip", "stname_state_zip", "idx_zip4_zipcode_stname"], $indexes)) == 0, $indexes);
$parity = array_column($db->query("PRAGMA index_info(addressnumber)")->fetchAll(PDO::FETCH_ASSOC), "name");
check("addressnumber really indexes the parity column", $parity == ["AddressPrimaryLowNumber", "AddressPrimaryHighNumber", "AddressPrimaryEvenOdd"], $parity);
check("big pages", $count("PRAGMA page_size") == 65536);
$db = null;

ZIP4::init("$dir/zip4.sqlite", false);
$m = ZIP4::match("123 north main street", "", "helena", "mt", "59601");
check("the library uses it as is and matches", $m->verified && $m->zipPlus4() == "59601-1002" && $m->street1 == "123 N MAIN ST", $m->toArray());
$m = ZIP4::match("39825 I-15 Unit FDA", "", "", "", "59484");
check("...in other states too", $m->verified && $m->plus4 == "8510");

echo "Rebuilding one product\n";
$before = filesize("$dir/zip4.sqlite");
[$code, $out] = build(["$dir/zip5-product.zip", "$dir/zip4.sqlite"]);
$db = new PDO("sqlite:$dir/zip4.sqlite");
check("the 5-digit tables are rebuilt and ZIP4 is left alone", $code == 0 && (int) $db->query("SELECT COUNT(*) FROM ZIP4")->fetchColumn() == count($zip4Rows)
        && (int) $db->query("SELECT COUNT(*) FROM ZIPCodes")->fetchColumn() == count($zipcodeRows), $out);
check("no free space left behind (reused, or VACUUMed)", (int) $db->query("PRAGMA freelist_count")->fetchColumn() == 0, $out);
$db = null;

echo "Other inputs\n";
[$code, $out] = build(["--quiet", "--states=MT", "$dir/zip4-product.zip", "$dir/zip5-product.zip", "$dir/mt.sqlite"]);
$db = new PDO("sqlite:$dir/mt.sqlite");
check("--states loads only those states", $code == 0 && $db->query("SELECT GROUP_CONCAT(DISTINCT State) FROM ZIP4")->fetchColumn() == "MT", $out);
$db = null;
[$code, $out] = build(["--quiet", "$dir/ZIP4-NY.zip", "$dir/zip-codes-database-STANDARD.csv", "$dir/loose.sqlite"]);
$db = new PDO("sqlite:$dir/loose.sqlite");
check("a ZIP4-XX.zip and a CSV on their own", $code == 0 && (int) $db->query("SELECT COUNT(*) FROM ZIP4")->fetchColumn() == count($byState["NY"])
        && (int) $db->query("SELECT COUNT(*) FROM ZIPCodes")->fetchColumn() == count($zipcodeRows), $out);
$db = null;
[$code, $out] = build(["$dir/readme.zip", "$dir/x.sqlite"]);
check("a missing input is an error", $code == 1 && str_contains($out, "not found"));
zipOf("$dir/other.zip", ["readme.txt" => __FILE__]);
[$code, $out] = build(["$dir/other.zip", "$dir/x.sqlite"]);
check("an archive with nothing from zip-codes.com in it is an error", $code == 1 && str_contains($out, "Nothing from zip-codes.com"), $out);
[$code, $out] = build([]);
check("no arguments shows the usage", $code == 2 && str_contains($out, "Usage:"));

rmTree($dir);
echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
