# postalportal/zip4

Matches and standardizes US addresses against a local USPS ZIP+4 database in SQLite (the
`ZIP4` and `ZIPCodes` tables from zip-codes.com's ZIP+4 product). There are no network calls
and nothing to pay per lookup.

It's for cleaning up address data, not CASS certification. It finds the street in the ZIP (or
in the city's other ZIPs) and fixes the directionals, suffix, ZIP, city and spelling. When the
house number falls in a known range it also fills in the +4 and carrier route. It doesn't do
DPV, so it can't tell you whether that exact house exists.

## Install

```sh
composer require postalportal/zip4
```

Needs PHP 8.1+ with `pdo_sqlite`. There are no other dependencies.

## Use

```php
use PostalPortal\ZIP4\ZIP4;

ZIP4::init("/var/lib/zip4/zip4.sqlite");

$m = ZIP4::match("123 north main street apt 4", "", "helena", "mt", "59601");
// or ZIP4::matchArray(["street1" => ..., "street2" => ..., "city" => ..., "state" => ..., "zip" => ..., "company" => ...])

if ($m->verified) {
    $m->street1;      // "123 N MAIN ST APT 4"
    $m->city;         // "HELENA"
    $m->zipPlus4();   // "59601-1002"
    $m->carrierRoute; // "C001"
} else {
    $m->street1;      // exactly what was passed in
    $m->message;      // why: "Street not found", "Number not in any range for this street", ...
    $m->normalized;   // Pub 28 cleaned-up version of the input, if you want it anyway
}
```

`match($street1, $street2, $city, $state, $zip, $company)`: you need either the city and state
or the ZIP, and both is best. The ZIP can be 5 digits, ZIP+4, or 3–4 digits that lost their
leading zeros in a spreadsheet. The company is optional; it's only used to give a business the
+4 from its own firm record.

### The result (`AddressMatch`)

| Property | |
|---|---|
| `verified` | The street was found and the number is in one of its ranges |
| `street1`, `street2`, `city`, `state`, `zip` | Standardized if verified; exactly as entered if not |
| `plus4` | Empty if not verified, or if the matching record covers more than one +4 |
| `carrierRoute`, `recordType` | `recordType`: S street, H highrise, F firm, P PO box, R rural route, G general delivery |
| `matchLevel` | `exact` (verified), `street` (street is in the ZIP, number isn't), `none` |
| `zipChanged` | The ZIP given was wrong for this address and was corrected |
| `spellingCorrected` | The street name was misspelled (one or two typos) and was corrected |
| `lacsConverted` | USPS has converted this address (usually a rural route) to a city-style one |
| `message` | Why it wasn't verified. On a verified address: a unit that isn't in the file, or `#4` where the building has both APT 4 and STE 4 (no +4 then) |
| `normalized` | For unverified addresses: `street1`, `street2`, `city`, `state`, `zip` cleaned up as far as possible |

`toArray()` and `json_encode()` give the same fields in snake_case.

## Indexes

Without an index on `ZIP4(ZipCode, StName)`, every lookup scans the whole table. By default,
`init()` builds any indexes that are missing. On the national file that takes a few minutes
and needs the file to be writable.

In a web app, build them once at install and have `init()` refuse an unindexed file rather
than build them mid-request:

```php
ZIP4::createIndexes("/var/lib/zip4/zip4.sqlite", fn($msg) => print("$msg\n")); // setup script
ZIP4::init("/var/lib/zip4/zip4.sqlite", false);                                 // app
```

If you replace the ZIP+4 file by dropping and reloading the tables, build them again.

## What it handles

- Pub 28 abbreviations: suffixes (C1), directionals (B), unit designators (C2), plus punctuation
- Directional and suffix that are missing, extra or wrong, when only one street fits.
  `124 MAIN ST` with both `N MAIN ST` and `S MAIN ST` in the ZIP is left unverified rather than guessed.
- Spelled-out numbered streets (`Fifth`, `Twenty-Ninth`), and `SAINT`/`ST`, `MOUNT`/`MT`, `HWY`/`HIGHWAY`, county roads, US and state highways
- Alpha house numbers (`245A`), fractional (`123 1/2`), Queens-style (`12-34`), Wisconsin grid (`N123W4567`)
- Units on either line, including a bare `456` or `FDA` on line 2 when that's one of the units there;
  the unit and street on the wrong lines; an ATTN or C/O line above the street; highrise unit ranges and default records
- The file's designator for units people name loosely: `#`, `APT`, `UNIT`, `STE`, `LOT`, `SPC`/`SP`, `TRLR` are
  interchangeable, so `#456` at a trailer park comes back `TRLR 456`. `BLDG`, `FL`, `RM` and the rest have to match.
  `#4` in a building with both `APT 4` and `STE 4` isn't guessed.
- Named units (`UNIT FDA`, `UNIT FDA-OTED`), and a company that is a unit at the address (`U.S. Fish and Wildlife
  Service` → `UNIT USFWS`), which adds the unit to the address
- Interstates and state routes: `I-15`, `I15`, `IH-15`, `Hwy I-15` → `INTERSTATE 15`; `MT-200`, `SR 200`, `State Hwy 200`
- PO boxes, rural routes and highway contract routes, general delivery, APO/FPO/DPO (`PSC`, `CMR`, `UNIT ... BOX`)
- Firm records: the firm's +4 only for that firm, and the street's +4 for anyone else at the address
- Alternate (old or vanity) street names rewritten to the base name
- The wrong ZIP for the city, and no ZIP at all
- The city's acceptable alternate names (`CityType` A) are kept; not-acceptable ones are replaced with the preferred name
- Deleted records (`Action` D) are ignored

## What it doesn't

DPV, LACSLink conversion (it only flags those addresses), SuiteLink, Puerto Rico
urbanizations, and intersections.

## Tests

```sh
composer install && composer test
```

The tests use a small fixture database in `tests/fixture.php`: real rows from the
zip-codes.com files, plus made-up streets for the cases those don't cover. There's no PHPUnit
and no network access.

## License

MPL-2.0
