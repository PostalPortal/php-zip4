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
 * Standardizes US addresses to USPS Publication 28 format against a local ZIP+4 SQLite
 * database (the ZIP4 and ZIPCodes tables from zip-codes.com's ZIP+4 product).
 *
 * This is for cleaning up address data, not CASS-grade validation: it finds the street
 * in the ZIP (or in the city's ZIPs), corrects the directionals, suffix, ZIP and city,
 * and fills in the +4 and carrier route when the house number falls in a known range.
 * Anything it can't place is still run through the Pub 28 abbreviations.
 *
 * Most code should use the static ZIP4 class instead; this is the engine behind it.
 *
 *   $std = new Standardizer("/path/to/zip4.sqlite");
 *   $r = $std->standardize("123 north main street apt 4", "", "helena", "mt", "59601");
 *   // $r["match"] is "exact", "street" or "none"; $r["street1"] == "123 N MAIN ST APT 4"
 */
class Standardizer {

    /** Exact: street found and the house number is in one of its ranges. */
    const MATCH_EXACT = "exact";

    /** Street: the street exists in that ZIP, but the number isn't in any range (or there's no number). */
    const MATCH_STREET = "street";
    const MATCH_NONE = "none";

    /** Pub 28 Appendix B */
    const DIRECTIONALS = [
        "N" => "N", "NORTH" => "N", "S" => "S", "SOUTH" => "S", "E" => "E", "EAST" => "E", "W" => "W", "WEST" => "W",
        "NE" => "NE", "NORTHEAST" => "NE", "NW" => "NW", "NORTHWEST" => "NW",
        "SE" => "SE", "SOUTHEAST" => "SE", "SW" => "SW", "SOUTHWEST" => "SW"
    ];

    /** Pub 28 Appendix C1: standard abbreviation => spellings seen in the wild */
    const SUFFIXES = [
        "ALY" => ["ALLEY", "ALLEE", "ALLY"], "ANX" => ["ANNEX", "ANEX", "ANNX"], "ARC" => ["ARCADE"],
        "AVE" => ["AVENUE", "AV", "AVEN", "AVENU", "AVN", "AVNUE"], "BYU" => ["BAYOU", "BAYOO"], "BCH" => ["BEACH"],
        "BND" => ["BEND"], "BLF" => ["BLUFF", "BLUF"], "BLFS" => ["BLUFFS"], "BTM" => ["BOTTOM", "BOT", "BOTTM"],
        "BLVD" => ["BOULEVARD", "BOUL", "BOULV"], "BR" => ["BRANCH", "BRNCH"], "BRG" => ["BRIDGE", "BRDGE"],
        "BRK" => ["BROOK"], "BRKS" => ["BROOKS"], "BG" => ["BURG"], "BGS" => ["BURGS"],
        "BYP" => ["BYPASS", "BYPA", "BYPAS", "BYPS"], "CP" => ["CAMP", "CMP"], "CYN" => ["CANYON", "CANYN", "CNYN"],
        "CPE" => ["CAPE"], "CSWY" => ["CAUSEWAY", "CAUSWA"],
        "CTR" => ["CENTER", "CEN", "CENT", "CENTR", "CENTRE", "CNTER", "CNTR"], "CTRS" => ["CENTERS"],
        "CIR" => ["CIRCLE", "CIRC", "CIRCL", "CRCL", "CRCLE"], "CIRS" => ["CIRCLES"], "CLF" => ["CLIFF"],
        "CLFS" => ["CLIFFS"], "CLB" => ["CLUB"], "CMN" => ["COMMON"], "CMNS" => ["COMMONS"], "COR" => ["CORNER"],
        "CORS" => ["CORNERS"], "CRSE" => ["COURSE"], "CT" => ["COURT"], "CTS" => ["COURTS"], "CV" => ["COVE"],
        "CVS" => ["COVES"], "CRK" => ["CREEK"], "CRES" => ["CRESCENT", "CRSENT", "CRSNT"], "CRST" => ["CREST"],
        "XING" => ["CROSSING", "CRSSNG"], "XRD" => ["CROSSROAD"], "XRDS" => ["CROSSROADS"], "CURV" => ["CURVE"],
        "DL" => ["DALE"], "DM" => ["DAM"], "DV" => ["DIVIDE", "DIV", "DVD"], "DR" => ["DRIVE", "DRIV", "DRV"],
        "DRS" => ["DRIVES"], "EST" => ["ESTATE"], "ESTS" => ["ESTATES"],
        "EXPY" => ["EXPRESSWAY", "EXP", "EXPR", "EXPRESS", "EXPW"], "EXT" => ["EXTENSION", "EXTN", "EXTNSN"],
        "EXTS" => ["EXTENSIONS"], "FALL" => [], "FLS" => ["FALLS"], "FRY" => ["FERRY", "FRRY"], "FLD" => ["FIELD"],
        "FLDS" => ["FIELDS"], "FLT" => ["FLAT"], "FLTS" => ["FLATS"], "FRD" => ["FORD"], "FRDS" => ["FORDS"],
        "FRST" => ["FOREST", "FORESTS"], "FRG" => ["FORGE", "FORG"], "FRGS" => ["FORGES"], "FRK" => ["FORK"],
        "FRKS" => ["FORKS"], "FT" => ["FORT", "FRT"], "FWY" => ["FREEWAY", "FREEWY", "FRWAY", "FRWY"],
        "GDN" => ["GARDEN", "GARDN", "GRDEN", "GRDN"], "GDNS" => ["GARDENS", "GRDNS"],
        "GTWY" => ["GATEWAY", "GATEWY", "GATWAY", "GTWAY"], "GLN" => ["GLEN"], "GLNS" => ["GLENS"], "GRN" => ["GREEN"],
        "GRNS" => ["GREENS"], "GRV" => ["GROVE", "GROV"], "GRVS" => ["GROVES"], "HBR" => ["HARBOR", "HARB", "HARBR", "HRBOR"],
        "HBRS" => ["HARBORS"], "HVN" => ["HAVEN"], "HTS" => ["HEIGHTS", "HT"],
        "HWY" => ["HIGHWAY", "HIGHWY", "HIWAY", "HIWY", "HWAY"], "HL" => ["HILL"], "HLS" => ["HILLS"],
        "HOLW" => ["HOLLOW", "HLLW", "HOLLOWS", "HOLWS"], "INLT" => ["INLET"], "IS" => ["ISLAND", "ISLND"],
        "ISS" => ["ISLANDS", "ISLNDS"], "ISLE" => ["ISLES"], "JCT" => ["JUNCTION", "JCTION", "JCTN", "JUNCTN", "JUNCTON"],
        "JCTS" => ["JUNCTIONS", "JCTNS"], "KY" => ["KEY"], "KYS" => ["KEYS"], "KNL" => ["KNOLL", "KNOL"],
        "KNLS" => ["KNOLLS"], "LK" => ["LAKE"], "LKS" => ["LAKES"], "LAND" => [], "LNDG" => ["LANDING", "LNDNG"],
        "LN" => ["LANE"], "LGT" => ["LIGHT"], "LGTS" => ["LIGHTS"], "LF" => ["LOAF"], "LCK" => ["LOCK"],
        "LCKS" => ["LOCKS"], "LDG" => ["LODGE", "LDGE", "LODG"], "LOOP" => ["LOOPS"], "MALL" => [], "MNR" => ["MANOR"],
        "MNRS" => ["MANORS"], "MDW" => ["MEADOW"], "MDWS" => ["MEADOWS", "MEDOWS"], "MEWS" => [], "ML" => ["MILL"],
        "MLS" => ["MILLS"], "MSN" => ["MISSION", "MISSN", "MSSN"], "MTWY" => ["MOTORWAY"], "MT" => ["MOUNT", "MNT"],
        "MTN" => ["MOUNTAIN", "MNTAIN", "MNTN", "MOUNTIN", "MTIN"], "MTNS" => ["MOUNTAINS", "MNTNS"], "NCK" => ["NECK"],
        "ORCH" => ["ORCHARD", "ORCHRD"], "OVAL" => ["OVL"], "OPAS" => ["OVERPASS"], "PARK" => ["PRK", "PARKS"],
        "PKWY" => ["PARKWAY", "PARKWY", "PKWAY", "PKY", "PARKWAYS", "PKWYS"], "PASS" => [], "PSGE" => ["PASSAGE"],
        "PATH" => ["PATHS"], "PIKE" => ["PIKES"], "PNE" => ["PINE"], "PNES" => ["PINES"], "PL" => ["PLACE"],
        "PLN" => ["PLAIN"], "PLNS" => ["PLAINS"], "PLZ" => ["PLAZA", "PLZA"], "PT" => ["POINT"], "PTS" => ["POINTS"],
        "PRT" => ["PORT"], "PRTS" => ["PORTS"], "PR" => ["PRAIRIE", "PRR"], "RADL" => ["RADIAL", "RAD", "RADIEL"],
        "RAMP" => [], "RNCH" => ["RANCH", "RANCHES", "RNCHS"], "RPD" => ["RAPID"], "RPDS" => ["RAPIDS"],
        "RST" => ["REST"], "RDG" => ["RIDGE", "RDGE"], "RDGS" => ["RIDGES"], "RIV" => ["RIVER", "RVR", "RIVR"],
        "RD" => ["ROAD"], "RDS" => ["ROADS"], "RTE" => ["ROUTE"], "ROW" => [], "RUE" => [], "RUN" => [],
        "SHL" => ["SHOAL"], "SHLS" => ["SHOALS"], "SHR" => ["SHORE", "SHOAR"], "SHRS" => ["SHORES", "SHOARS"],
        "SKWY" => ["SKYWAY"], "SPG" => ["SPRING", "SPNG", "SPRNG"], "SPGS" => ["SPRINGS", "SPNGS", "SPRNGS"],
        "SPUR" => ["SPURS"], "SQ" => ["SQUARE", "SQR", "SQRE", "SQU"], "SQS" => ["SQUARES", "SQRS"],
        "STA" => ["STATION", "STATN", "STN"], "STRA" => ["STRAVENUE", "STRAV", "STRAVEN", "STRAVN", "STRVN", "STRVNUE"],
        "STRM" => ["STREAM", "STREME"], "ST" => ["STREET", "STRT", "STR"], "STS" => ["STREETS"],
        "SMT" => ["SUMMIT", "SUMIT", "SUMITT"], "TER" => ["TERRACE", "TERR"], "TRWY" => ["THROUGHWAY"],
        "TRCE" => ["TRACE", "TRACES"], "TRAK" => ["TRACK", "TRACKS", "TRK", "TRKS"], "TRFY" => ["TRAFFICWAY"],
        "TRL" => ["TRAIL", "TRAILS", "TRLS"], "TRLR" => ["TRAILER", "TRLRS"],
        "TUNL" => ["TUNNEL", "TUNEL", "TUNLS", "TUNNELS", "TUNNL"], "TPKE" => ["TURNPIKE", "TRNPK", "TURNPK"],
        "UPAS" => ["UNDERPASS"], "UN" => ["UNION"], "UNS" => ["UNIONS"], "VLY" => ["VALLEY", "VALLY", "VLLY"],
        "VLYS" => ["VALLEYS"], "VIA" => ["VIADUCT", "VDCT", "VIADCT"], "VW" => ["VIEW"], "VWS" => ["VIEWS"],
        "VLG" => ["VILLAGE", "VILL", "VILLAG", "VILLG", "VILLIAGE"], "VLGS" => ["VILLAGES"], "VL" => ["VILLE"],
        "VIS" => ["VISTA", "VIST", "VST", "VSTA"], "WALK" => ["WALKS"], "WALL" => [], "WAY" => ["WY"], "WAYS" => [],
        "WL" => ["WELL"], "WLS" => ["WELLS"]
    ];

    /** Pub 28 Appendix C2, designators that need a unit number after them */
    const SECONDARY_WITH_ID = [
        "APT" => ["APARTMENT", "APTS"], "BLDG" => ["BUILDING", "BLD"], "DEPT" => ["DEPARTMENT"], "FL" => ["FLOOR", "FLR"],
        "HNGR" => ["HANGAR", "HANGER"], "KEY" => [], "LOT" => [], "PIER" => [], "RM" => ["ROOM"], "SLIP" => [],
        "SPC" => ["SPACE"], "STOP" => [], "STE" => ["SUITE", "SUIT"], "TRLR" => ["TRAILER"], "UNIT" => [],
        "#" => ["NO", "NUM", "NUMBER"]
    ];

    /** Pub 28 Appendix C2, designators that stand alone (some take an optional number) */
    const SECONDARY_WITHOUT_ID = [
        "BSMT" => ["BASEMENT"], "FRNT" => ["FRONT"], "LBBY" => ["LOBBY"], "LOWR" => ["LOWER"], "OFC" => ["OFFICE"],
        "PH" => ["PENTHOUSE"], "REAR" => [], "SIDE" => [], "UPPR" => ["UPPER"]
    ];

    /** Spelled-out numbered streets, which the ZIP+4 file stores as 1ST, 2ND... */
    const ORDINALS = [
        "FIRST" => "1ST", "SECOND" => "2ND", "THIRD" => "3RD", "FOURTH" => "4TH", "FIFTH" => "5TH", "SIXTH" => "6TH",
        "SEVENTH" => "7TH", "EIGHTH" => "8TH", "NINTH" => "9TH", "TENTH" => "10TH", "ELEVENTH" => "11TH",
        "TWELFTH" => "12TH", "THIRTEENTH" => "13TH", "FOURTEENTH" => "14TH", "FIFTEENTH" => "15TH",
        "SIXTEENTH" => "16TH", "SEVENTEENTH" => "17TH", "EIGHTEENTH" => "18TH", "NINETEENTH" => "19TH",
        "TWENTIETH" => "20TH"
    ];

    const TENS = ["TWENTY" => 20, "THIRTY" => 30, "FORTY" => 40, "FIFTY" => 50, "SIXTY" => 60, "SEVENTY" => 70, "EIGHTY" => 80, "NINETY" => 90];
    const TENS_ORDINAL = ["TWENTIETH" => 20, "THIRTIETH" => 30, "FORTIETH" => 40, "FIFTIETH" => 50, "SIXTIETH" => 60, "SEVENTIETH" => 70, "EIGHTIETH" => 80, "NINETIETH" => 90];

    /** Words inside a street name that the ZIP+4 file might spell either way. Each is tried both ways. */
    const NAME_ALTERNATES = [
        "ST" => "SAINT", "STE" => "SAINTE", "MT" => "MOUNT", "FT" => "FORT", "PT" => "POINT",
        "HWY" => "HIGHWAY", "HIWAY" => "HIGHWAY", "HGWY" => "HIGHWAY", "RT" => "ROUTE", "RTE" => "ROUTE",
        "CR" => "COUNTY ROAD", "CNTY" => "COUNTY", "CO" => "COUNTY", "RD" => "ROAD", "FM" => "FARM TO MARKET",
        "DR" => "DOCTOR", "JR" => "JUNIOR", "MLK" => "MARTIN LUTHER KING"
    ];

    private static ?array $suffixLookup = null;
    private static ?array $secondaryLookup = null;
    private PDO $db;
    private array $statements = [];
    private array $cache = ["zip" => [], "city" => [], "lastline" => [], "streets" => []];

    /**
     * @param PDO|string $db An open PDO connection to the ZIP+4 database, or the path to the SQLite file.
     * @throws Exception if the file or the tables aren't there.
     */
    public function __construct(PDO|string $db) {
        if (is_string($db)) {
            if (!is_file($db)) {
                throw new Exception("ZIP+4 database not found at $db");
            }
            $db = new PDO("sqlite:$db");
        }
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->db = $db;
        foreach (["ZIP4", "ZIPCodes"] as $table) {
            if (!$this->db->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = " . $this->db->quote($table))->fetch()) {
                throw new Exception("ZIP+4 database is missing the $table table");
            }
        }
    }

    /**
     * Without an index on ZIP4.ZipCode every lookup reads the whole table, so this adds the ones
     * the matcher needs if they're missing. The first run on a full national file takes a few minutes.
     *
     * @param callable|null $log Called with a message before each index is built.
     * @return array The names of the indexes created.
     */
    public function ensureIndexes(?callable $log = null): array {
        $created = [];
        foreach ($this->missingIndexes() as $name => [$table, $columns]) {
            if ($log) {
                $log("Creating index $name on $table(" . implode(", ", $columns) . ")...");
            }
            $this->db->exec("CREATE INDEX IF NOT EXISTS \"$name\" ON \"$table\" (\"" . implode("\", \"", $columns) . "\")");
            $created[] = $name;
        }
        return $created;
    }

    const INDEXES = [
        "idx_zip4_zipcode_stname" => ["ZIP4", ["ZipCode", "StName"]],
        "idx_zipcodes_zipcode" => ["ZIPCodes", ["ZipCode"]],
        "idx_zipcodes_state_city" => ["ZIPCodes", ["State", "City"]],
        "idx_zipcodes_citystatekey" => ["ZIPCodes", ["CityStateKey"]]
    ];

    /** The indexes the matcher needs that aren't there (an existing index on the same leading columns counts). */
    public function missingIndexes(): array {
        return array_filter(self::INDEXES, fn($i) => !$this->hasIndexStartingWith($i[0], $i[1]));
    }

    private function hasIndexStartingWith(string $table, array $columns): bool {
        foreach ($this->db->query("PRAGMA index_list(" . $this->db->quote($table) . ")")->fetchAll() as $index) {
            $have = array_column($this->db->query("PRAGMA index_info(" . $this->db->quote($index["name"]) . ")")->fetchAll(), "name", "seqno");
            ksort($have);
            if (array_slice(array_values($have), 0, count($columns)) == $columns) {
                return true;
            }
        }
        return false;
    }

    /*
     * Text normalization and parsing (no database needed)
     */

    /** Uppercases and strips the punctuation Pub 28 drops, keeping # / and - where they mean something. */
    public static function cleanText(?string $s): string {
        $s = strtoupper(trim($s ?? ""));
        $s = str_replace(["'", "`", "\u{2019}"], "", $s);
        $s = preg_replace('/(?<=\d)\.(?=\d)/', "\x01", $s); // decimal points
        $s = preg_replace('/[^A-Z0-9#\/\-&\x01]+/', " ", $s);
        $s = str_replace("\x01", ".", $s);
        // A hyphen between words is a separator ("RD - REAR"); inside one it stays ("12-34", "TWENTY-NINTH")
        $s = preg_replace('/\s+-+\s*|\s*-+\s+|^-+|-+$/', " ", $s);
        $s = preg_replace('/#\s*/', "# ", $s);
        return trim(preg_replace('/\s+/', " ", $s));
    }

    /** ZIP from whatever's in the column: "59601-1234", "596011234", or "2134" (Excel ate the leading zero). */
    public static function splitZip(?string $zip): array {
        $zip = preg_replace('/\s+/', "", $zip ?? "");
        if (preg_match('/^(\d{5})-?(\d{4})$/', $zip, $m)) {
            return [$m[1], $m[2]];
        }
        if (preg_match('/^\d{3,4}$/', $zip)) {
            return [str_pad($zip, 5, "0", STR_PAD_LEFT), ""];
        }
        if (preg_match('/^(\d{5})/', $zip, $m)) {
            return [$m[1], ""];
        }
        return [$zip, ""];
    }

    public static function suffixAbbr(string $word): ?string {
        if (self::$suffixLookup === null) {
            self::$suffixLookup = [];
            foreach (self::SUFFIXES as $abbr => $variants) {
                self::$suffixLookup[$abbr] = $abbr;
                foreach ($variants as $v) {
                    self::$suffixLookup[$v] = $abbr;
                }
            }
        }
        return self::$suffixLookup[$word] ?? null;
    }

    public static function directionalAbbr(string $word): ?string {
        return self::DIRECTIONALS[$word] ?? null;
    }

    /** @return array|null [abbreviation, takes an ID: true / optional: null] */
    public static function secondaryAbbr(string $word): ?array {
        if (self::$secondaryLookup === null) {
            self::$secondaryLookup = [];
            foreach ([[self::SECONDARY_WITH_ID, true], [self::SECONDARY_WITHOUT_ID, null]] as [$list, $needsId]) {
                foreach ($list as $abbr => $variants) {
                    self::$secondaryLookup[$abbr] = [$abbr, $needsId];
                    foreach ($variants as $v) {
                        self::$secondaryLookup[$v] = [$abbr, $needsId];
                    }
                }
            }
        }
        return self::$secondaryLookup[$word] ?? null;
    }

    private static function isUnitId(string $t): bool {
        return preg_match('/^[0-9A-Z][0-9A-Z\-]{0,7}$/', $t) && (preg_match('/\d/', $t) || strlen($t) <= 2);
    }

    /**
     * Reads a secondary unit ("APT 4", "STE 200 BLDG B", "# 5", "REAR") from the start of a token list.
     * @return array|null ["designator", "id", "text"] with designators abbreviated, or null if it isn't one.
     */
    public static function parseSecondary(array $tokens): ?array {
        if (count($tokens) == 0) {
            return null;
        }
        $sec = self::secondaryAbbr($tokens[0]);
        if ($sec === null) {
            return null;
        }
        [$designator, $needsId] = $sec;
        $id = "";
        $used = 1;
        if (isset($tokens[1]) && self::isUnitId($tokens[1]) && self::suffixAbbr($tokens[1]) === null) {
            $id = $tokens[1];
            $used = 2;
        } else if ($needsId) {
            return null;
        }
        $rest = array_slice($tokens, $used);
        if (count($rest) > 0) {
            // Anything after has to be another unit ("APT 4 BLDG 2"), not more street name
            $more = self::parseSecondary($rest);
            if ($more === null) {
                return null;
            }
            $text = trim("$designator $id") . " " . $more["text"];
        } else {
            $text = trim("$designator $id");
        }
        return ["designator" => $designator, "id" => $id, "text" => $text];
    }

    /**
     * Splits an address line into its parts. Returns every plausible reading in "readings",
     * the most likely first, so the database can decide between "123 RIVER FRONT" (a street)
     * and "123 MAIN ST FRONT" (a unit).
     *
     * @return array ["type" => street|pobox|rural|military|general|secondary|other, ...]
     */
    public static function parseLine(string $line): array {
        $line = self::cleanText($line);
        $out = ["type" => "other", "text" => $line, "number" => "", "fraction" => "", "readings" => []];
        if ($line === "") {
            return $out;
        }

        if (preg_match('/^(GENERAL DELIVERY|GEN DELIVERY|GEN DEL)$/', $line)) {
            return ["type" => "general", "name" => "GENERAL DELIVERY", "number" => ""] + $out;
        }
        if (preg_match('/^(?:P ?O ?BOX|P ?O ?BX|POB|P ?O|POST OFFICE BOX|POST OFFICE|POST BOX|BOX|BX|DRAWER|CALLER|FIRM CALLER|BIN|LOCKBOX|LOCK BOX)(?: #)? ([0-9A-Z\-]+)$/', $line, $m)
                && preg_match('/\d/', $m[1])) {
            return ["type" => "pobox", "name" => "PO BOX", "number" => $m[1]] + $out;
        }
        $route = '(RR|R R|RURAL ROUTE|RURAL RTE|RURAL RT|RFD|RD|RT|RTE|ROUTE|HC|HCR|H C|HIGHWAY CONTRACT|HIGHWAY CONTRACT ROUTE|HWY CONTRACT|STAR ROUTE)(?: #)? (\d+)';
        $box = '(?:BOX|BX|#)(?: #)? ([0-9A-Z\-]+)';
        if (preg_match("/^$route $box$/", $line, $m) || (preg_match("/^$box $route$/", $line, $n) && $m = [$n[0], $n[2], $n[3], $n[1]])) {
            $isHC = preg_match('/^(HC|HCR|H C|HIGHWAY|HWY|STAR)/', $m[1]);
            return ["type" => "rural", "name" => ($isHC ? "HC " : "RR ") . ltrim($m[2], "0"), "number" => $m[3]] + $out;
        }
        if (preg_match('/^(PSC|CMR|UNIT) (\d+) BOX ([0-9A-Z\-]+)$/', $line, $m)) {
            return ["type" => "military", "name" => "$m[1] $m[2]", "number" => $m[3]] + $out;
        }

        $tokens = explode(" ", $line);
        $secondary = self::parseSecondary($tokens);
        if ($secondary !== null) {
            return ["type" => "secondary", "secondary" => $secondary] + $out;
        }

        // House number: 123, 123A, 12-34 (Queens), N123W4567 (Wisconsin grid). Not 1ST, which is a street name.
        $number = "";
        if (preg_match('/^(\d+[A-Z]?(-\d*[A-Z]?)?|[NSEW]\d+[NSEW]\d+|\d+-[A-Z])$/', $tokens[0])) {
            $number = array_shift($tokens);
        }
        if ($number === "") {
            return $out;
        }
        $fraction = "";
        if (isset($tokens[0]) && preg_match('/^\d\/\d$/', $tokens[0])) {
            $fraction = array_shift($tokens);
        }
        if (count($tokens) == 0) {
            return $out;
        }

        // Possible places the unit starts. Index 0 can't be one: the street needs a name.
        $readings = [];
        $preferred = null;
        for ($i = 1; $i < count($tokens); $i++) {
            $sec = self::parseSecondary(array_slice($tokens, $i));
            if ($sec === null) {
                continue;
            }
            $street = array_slice($tokens, 0, $i);
            $prev = $tokens[$i - 1];
            $afterSuffix = self::suffixAbbr($prev) !== null || self::directionalAbbr($prev) !== null;
            // A word like FRONT or LOWER is only a unit when the street before it already looks complete
            $likely = $sec["designator"] == "#" || self::secondaryAbbr($tokens[$i])[1] === true || $afterSuffix;
            $readings[] = ["street" => $street, "secondary" => $sec, "likely" => $likely];
            if ($likely && $preferred === null) {
                $preferred = count($readings) - 1;
            }
        }
        $readings[] = ["street" => $tokens, "secondary" => null, "likely" => $preferred === null];
        // A bare trailing unit: "123 MAIN ST 4B". Only used if the database likes it better.
        $last = end($tokens);
        if (count($tokens) >= 3 && self::isUnitId($last) && preg_match('/\d/', $last) && $preferred === null
                && self::suffixAbbr(prev($tokens)) !== null) {
            $readings[] = ["street" => array_slice($tokens, 0, -1), "secondary" => ["designator" => "#", "id" => $last, "text" => "# $last"], "likely" => false];
        }
        usort($readings, fn($a, $b) => $b["likely"] <=> $a["likely"]);

        return ["type" => "street", "number" => $number, "fraction" => $fraction, "readings" => $readings] + $out;
    }

    /**
     * The ways a list of street tokens could split into predirectional, name, suffix and postdirectional.
     * @return array of ["pre", "name", "suffix", "post", "names" => [name spellings to look up]]
     */
    public static function streetCandidates(array $tokens): array {
        // "TWENTY NINTH" typed without the hyphen
        for ($i = 0; $i < count($tokens) - 1; $i++) {
            if (isset(self::TENS[$tokens[$i]]) && isset(self::ORDINALS[$tokens[$i + 1]])) {
                array_splice($tokens, $i, 2, [$tokens[$i] . "-" . $tokens[$i + 1]]);
            }
        }
        $n = count($tokens);
        if ($n == 0) {
            return [];
        }
        $out = [];
        $preOptions = [["", 0, ""]];
        if ($n > 1 && self::directionalAbbr($tokens[0]) !== null) {
            $preOptions[] = [self::directionalAbbr($tokens[0]), 1, $tokens[0]];
        }
        foreach ($preOptions as [$pre, $start, $preWord]) {
            $postOptions = [["", $n, ""]];
            if ($n - $start > 1 && self::directionalAbbr($tokens[$n - 1]) !== null) {
                $postOptions[] = [self::directionalAbbr($tokens[$n - 1]), $n - 1, $tokens[$n - 1]];
            }
            foreach ($postOptions as [$post, $end, $postWord]) {
                $suffixOptions = [["", $end]];
                if ($end - $start > 1 && self::suffixAbbr($tokens[$end - 1]) !== null) {
                    $suffixOptions[] = [self::suffixAbbr($tokens[$end - 1]), $end - 1];
                }
                foreach ($suffixOptions as [$suffix, $nameEnd]) {
                    $name = array_slice($tokens, $start, $nameEnd - $start);
                    if (count($name) == 0) {
                        continue;
                    }
                    $out[] = ["pre" => $pre, "name" => implode(" ", $name), "suffix" => $suffix, "post" => $post,
                        "preWord" => $preWord, "postWord" => $postWord, "names" => self::nameSpellings($name)];
                }
            }
        }
        return $out;
    }

    /** "FIFTH" => "5TH", "TWENTY-NINTH" => "29TH", "FORTIETH" => "40TH", anything else => null */
    public static function ordinalWord(string $w): ?string {
        if (isset(self::ORDINALS[$w])) {
            return self::ORDINALS[$w];
        }
        if (isset(self::TENS_ORDINAL[$w])) {
            return self::TENS_ORDINAL[$w] . "TH";
        }
        if (preg_match('/^([A-Z]+)-([A-Z]+)$/', $w, $m) && isset(self::TENS[$m[1]]) && isset(self::ORDINALS[$m[2]]) && (int) self::ORDINALS[$m[2]] < 10) {
            $n = self::TENS[$m[1]] + (int) self::ORDINALS[$m[2]];
            return $n . substr(self::ORDINALS[$m[2]], -2);
        }
        return null;
    }

    /** "SAINT JOHNS" => ["SAINT JOHNS", "ST JOHNS"], "FIRST" => ["FIRST", "1ST"], "HWY 12" => ["HWY 12", "HIGHWAY 12"] */
    public static function nameSpellings(array $words): array {
        $expanded = [];
        $abbreviated = [];
        $reverse = array_flip(self::NAME_ALTERNATES);
        foreach ($words as $w) {
            $num = self::ordinalWord($w) ?? $w;
            $expanded[] = self::NAME_ALTERNATES[$num] ?? $num;
            $abbreviated[] = $reverse[$num] ?? $num;
        }
        $spellings = [implode(" ", $words), implode(" ", $expanded), implode(" ", $abbreviated)];
        // "N SLOPE" typed for the street named NORTH SLOPE (and "BOULDER W" for BOULDER WEST)
        $full = ["N" => "NORTH", "S" => "SOUTH", "E" => "EAST", "W" => "WEST", "NE" => "NORTHEAST", "NW" => "NORTHWEST", "SE" => "SOUTHEAST", "SW" => "SOUTHWEST"];
        if (count($words) > 1 && (isset($full[$words[0]]) || isset($full[end($words)]))) {
            $w = $words;
            $w[0] = $full[$w[0]] ?? $w[0];
            $w[count($w) - 1] = $full[$w[count($w) - 1]] ?? $w[count($w) - 1];
            $spellings[] = implode(" ", $w);
        }
        // County roads: "CR 5", "CO RD 5", "COUNTY RD 5" all mean COUNTY ROAD 5
        $joined = implode(" ", $words);
        $county = preg_replace('/^(CR|CO RD|CNTY RD|COUNTY RD|CO ROAD|CNTY ROAD) /', "COUNTY ROAD ", $joined);
        $highway = preg_replace('/^(US HWY|US HIGHWAY|US) (\d+)/', 'US HIGHWAY $2', preg_replace('/^(HWY|HIWAY|HGWY|HY) /', "HIGHWAY ", $joined));
        $state = preg_replace('/^(STATE HWY|ST HWY|SH|STATE HIGHWAY) /', "STATE HIGHWAY ", $joined);
        $route = preg_replace('/^(SR|ST RT|ST RTE|STATE RT|STATE RTE) /', "STATE ROUTE ", $joined);
        array_push($spellings, $county, $highway, $state, $route);
        return array_values(array_unique($spellings));
    }

    /** Pub 28 formatting of a parsed line without any database, for addresses that couldn't be matched. */
    public static function formatParsed(array $p): string {
        switch ($p["type"]) {
            case "general":
                return "GENERAL DELIVERY";
            case "pobox":
                return "PO BOX $p[number]";
            case "rural":
            case "military":
                return "$p[name] BOX $p[number]";
            case "secondary":
                return $p["secondary"]["text"];
            case "street":
                $reading = $p["readings"][0];
                // Without the database, NORTH SLOPE RD and NORTH MAIN ST look the same, so a spelled-out
                // directional stays spelled out; only N or N. is treated as one
                $c = array_values(array_filter(self::streetCandidates($reading["street"]), fn($x) => strlen($x["preWord"]) <= 2 && strlen($x["postWord"]) <= 2));
                // The reading that uses the most of the standard parts
                usort($c, fn($a, $b) => (($b["pre"] != "") + ($b["suffix"] != "") + ($b["post"] != "")) <=> (($a["pre"] != "") + ($a["suffix"] != "") + ($a["post"] != "")));
                $street = $c[0];
                return self::joinParts($p["number"], $p["fraction"], $street["pre"], $street["name"], $street["suffix"], $street["post"], $reading["secondary"]["text"] ?? "");
            default:
                return $p["text"];
        }
    }

    private static function joinParts(string ...$parts): string {
        return trim(preg_replace('/\s+/', " ", implode(" ", $parts)));
    }

    /*
     * Range comparison
     */

    private static function numberParts(string $s): ?array {
        $s = strtoupper(trim($s));
        if (!preg_match('/^([A-Z]*?)0*(\d+)([A-Z]*)$/', $s, $m)) {
            return null;
        }
        return [$m[1], (int) $m[2], $m[3]];
    }

    /**
     * Whether a house or unit number is inside a ZIP+4 range. The file zero-pads the bounds
     * ("0000002100"), and the parity is E(ven), O(dd) or B(oth).
     */
    public static function inRange(string $number, ?string $low, ?string $high, ?string $parity = "B"): bool {
        $low = trim($low ?? "");
        $high = trim($high ?? "");
        if ($low === "" && $high === "") {
            return false;
        }
        if ($high === "") {
            $high = $low;
        }
        $candidates = [$number];
        if (str_contains($number, "-")) {
            $candidates[] = str_replace("-", "", $number); // Queens-style 12-34
        }
        foreach ($candidates as $num) {
            $n = self::numberParts($num);
            $l = self::numberParts($low);
            $h = self::numberParts($high);
            if ($n === null || $l === null || $h === null) {
                if (ltrim($num, "0") === ltrim($low, "0") || ltrim($num, "0") === ltrim($high, "0")) {
                    return true;
                }
                continue;
            }
            if ($n[0] !== $l[0] || $l[0] !== $h[0]) {
                continue;
            }
            if ($n[1] < $l[1] || $n[1] > $h[1]) {
                continue;
            }
            if (($l[2] !== "" || $h[2] !== "") && (($n[1] == $l[1] && strcmp($n[2], $l[2]) < 0) || ($n[1] == $h[1] && strcmp($n[2], $h[2]) > 0))) {
                continue;
            }
            $parity = strtoupper(trim($parity ?? "B"));
            if (($parity == "E" && $n[1] % 2 != 0) || ($parity == "O" && $n[1] % 2 != 1)) {
                continue;
            }
            return true;
        }
        return false;
    }

    /*
     * Database lookups
     */

    private function query(string $sql, array $params): array {
        if (!isset($this->statements[$sql])) {
            $this->statements[$sql] = $this->db->prepare($sql);
        }
        $this->statements[$sql]->execute($params);
        $rows = $this->statements[$sql]->fetchAll();
        foreach ($rows as &$row) {
            foreach ($row as $k => $v) {
                $row[$k] = is_string($v) ? trim($v) : $v;
            }
        }
        return $rows;
    }

    private static function placeholders(array $a): string {
        return implode(",", array_fill(0, count($a), "?"));
    }

    /** ZIPCodes rows for a ZIP (one per city name). */
    public function zipRows(string $zip): array {
        if (!isset($this->cache["zip"][$zip])) {
            $this->cache["zip"][$zip] = $this->query("SELECT ZipCode, City, State, CityType, CityAliasName, CityAliasAbbreviation, PrimaryRecord, CityStateKey, PreferredLastLineKey FROM ZIPCodes WHERE ZipCode = ?", [$zip]);
        }
        return $this->cache["zip"][$zip];
    }

    /** ZIPs that a city name is used for in a state. */
    public function zipsForCity(string $city, string $state): array {
        $key = "$state|$city";
        if (!isset($this->cache["city"][$key])) {
            $rows = $this->query("SELECT DISTINCT ZipCode FROM ZIPCodes WHERE State = ? AND (City = ? OR CityAliasName = ? OR CityAliasAbbreviation = ?)", [$state, $city, $city, $city]);
            $this->cache["city"][$key] = array_column($rows, "ZipCode");
        }
        return $this->cache["city"][$key];
    }

    /** The city name a last line key stands for. */
    private function lastLineCity(?string $key): ?string {
        if (empty($key)) {
            return null;
        }
        if (!array_key_exists($key, $this->cache["lastline"])) {
            $rows = $this->query("SELECT CityAliasName, City FROM ZIPCodes WHERE CityStateKey = ? LIMIT 1", [$key]);
            $this->cache["lastline"][$key] = count($rows) ? ($rows[0]["CityAliasName"] ?: $rows[0]["City"]) : null;
        }
        return $this->cache["lastline"][$key];
    }

    /** The city to print: the input's if USPS accepts it for that ZIP, else the preferred one. */
    private function chooseCity(string $zip, ?string $lastLineKey, string $inputCity): string {
        $rows = $this->zipRows($zip);
        if ($inputCity !== "") {
            foreach ($rows as $r) {
                if (in_array($r["CityType"], ["P", "A"]) && in_array($inputCity, [$r["CityAliasName"], $r["CityAliasAbbreviation"], $r["City"]], true)) {
                    return $r["CityAliasName"] ?: $r["City"];
                }
            }
        }
        $preferred = $this->lastLineCity($lastLineKey);
        if ($preferred !== null) {
            return $preferred;
        }
        foreach ($rows as $r) {
            if ($r["CityType"] == "P" || $r["PrimaryRecord"] == "P") {
                return $r["CityAliasName"] ?: $r["City"];
            }
        }
        return count($rows) ? $rows[0]["City"] : $inputCity;
    }

    private function streetRows(array $zips, array $names, array $types): array {
        $sql = "SELECT ZipCode, RecordType, CarrierRoute, StPreDirAbbr, StName, StSuffixAbbr, StPostDirAbbr, BuildingName, "
                . "AddressPrimaryLowNumber, AddressPrimaryHighNumber, AddressPrimaryEvenOdd, AddressSecAbbr, "
                . "AddressSecLowNumber, AddressSecHighNumber, AddressSecOddEven, Plus4Low, Plus4High, "
                . "BaseAlternateCode, LACSStatus, State, PreferredLastLineKey FROM ZIP4 "
                . "WHERE ZipCode IN (" . self::placeholders($zips) . ") AND StName IN (" . self::placeholders($names) . ") "
                . "AND RecordType IN (" . self::placeholders($types) . ") AND (Action IS NULL OR Action <> 'D')";
        return $this->query($sql, array_merge($zips, $names, $types));
    }

    /** Every street name in a ZIP, for spelling correction. */
    private function streetNamesInZip(string $zip): array {
        if (!isset($this->cache["streets"][$zip])) {
            if (count($this->cache["streets"]) > 200) {
                $this->cache["streets"] = [];
            }
            $rows = $this->query("SELECT DISTINCT StName FROM ZIP4 WHERE ZipCode = ? AND RecordType IN ('S', 'H', 'F')", [$zip]);
            $this->cache["streets"][$zip] = array_column($rows, "StName");
        }
        return $this->cache["streets"][$zip];
    }

    /** The one street name in these ZIPs that's a likely misspelling of $name, if there's exactly one. */
    private function closestStreetName(array $zips, string $name): ?string {
        if (strlen($name) < 4 || preg_match('/\d/', $name)) {
            return null;
        }
        $limit = strlen($name) >= 8 ? 2 : 1;
        $best = [];
        $bestDistance = $limit + 1;
        foreach ($zips as $zip) {
            foreach ($this->streetNamesInZip($zip) as $street) {
                if ($street === "" || $street[0] !== $name[0] || abs(strlen($street) - strlen($name)) > $limit) {
                    continue;
                }
                $d = self::typoDistance($name, $street);
                if ($d < $bestDistance) {
                    $bestDistance = $d;
                    $best = [$street => true];
                } else if ($d == $bestDistance) {
                    $best[$street] = true;
                }
            }
        }
        return count($best) == 1 ? array_key_first($best) : null;
    }

    /** Edit distance counting a swap of two neighboring letters as one typo (optimal string alignment). */
    public static function typoDistance(string $a, string $b): int {
        $la = strlen($a);
        $lb = strlen($b);
        $d = [];
        for ($i = 0; $i <= $la; $i++) {
            $d[$i] = [$i];
        }
        for ($j = 0; $j <= $lb; $j++) {
            $d[0][$j] = $j;
        }
        for ($i = 1; $i <= $la; $i++) {
            for ($j = 1; $j <= $lb; $j++) {
                $cost = $a[$i - 1] === $b[$j - 1] ? 0 : 1;
                $d[$i][$j] = min($d[$i - 1][$j] + 1, $d[$i][$j - 1] + 1, $d[$i - 1][$j - 1] + $cost);
                if ($i > 1 && $j > 1 && $a[$i - 1] === $b[$j - 2] && $a[$i - 2] === $b[$j - 1]) {
                    $d[$i][$j] = min($d[$i][$j], $d[$i - 2][$j - 2] + 1);
                }
            }
        }
        return $d[$la][$lb];
    }

    /*
     * Matching
     */

    /**
     * Scores how well a ZIP+4 record fits what was typed. Higher is better; the in-range bonus
     * dominates, then the street parts, then the unit.
     */
    private static function scoreRow(array $row, array $cand, string $number, ?array $secondary, bool $likely, string $company): array {
        $score = $likely ? 1 : 0;
        // Firm records give a +4 to one business at an address: only use one for that business
        $firmMatched = false;
        if ($row["RecordType"] == "F") {
            $firmMatched = self::sameFirm($company, $row["BuildingName"] ?? "");
            $score += $firmMatched ? 8 : -5;
        }
        foreach ([["pre", "StPreDirAbbr", 10], ["post", "StPostDirAbbr", 10], ["suffix", "StSuffixAbbr", 4]] as [$key, $col, $wrong]) {
            $typed = $cand[$key] ?? "";
            $have = $row[$col] ?? "";
            if ($typed === $have) {
                // A typed N is a directional; a typed NORTH might be the name (NORTH SLOPE RD), so it earns nothing here
                $spelledOut = strlen($cand[$key . "Word"] ?? "") > 2;
                $score += ($typed === "" || $spelledOut ? 0 : 2);
            } else if ($typed !== "" && $have !== "") {
                $score -= $wrong;
            } else if ($typed !== "") {
                $score -= 3;
            } else {
                $score -= 1;
            }
        }
        // ...and when NORTH was kept in the name and the street's name does start with it, that's the 2 instead
        $nameWords = explode(" ", $cand["name"]);
        foreach ([[$nameWords[0], "pre", "StPreDirAbbr", fn($n) => explode(" ", $n)[0]], [end($nameWords), "post", "StPostDirAbbr", fn($n) => substr($n, strrpos(" $n", " "))]] as [$word, $key, $col, $edge]) {
            if (count($nameWords) > 1 && strlen($word) > 2 && self::directionalAbbr($word) !== null && ($cand[$key] ?? "") === ""
                    && ($row[$col] ?? "") === "" && $edge($row["StName"]) === $word) {
                $score += 2;
            }
        }
        $inRange = $number !== "" && self::inRange($number, $row["AddressPrimaryLowNumber"], $row["AddressPrimaryHighNumber"], $row["AddressPrimaryEvenOdd"]);
        if ($inRange) {
            $score += 20;
        }
        $rowHasUnits = ($row["AddressSecLowNumber"] ?? "") !== "" || ($row["AddressSecHighNumber"] ?? "") !== "";
        $unitInRange = false;
        if ($secondary !== null) {
            if ($rowHasUnits) {
                $unitInRange = $secondary["id"] !== "" ? self::inRange($secondary["id"], $row["AddressSecLowNumber"], $row["AddressSecHighNumber"], $row["AddressSecOddEven"])
                        : $row["AddressSecAbbr"] === $secondary["designator"]; // FRNT, REAR...
                if ($unitInRange) {
                    $score += ($row["AddressSecAbbr"] === $secondary["designator"] || $secondary["designator"] === "#") ? 6 : 4;
                } else {
                    $score -= 2;
                }
            } else {
                $score += ($row["RecordType"] == "H" ? 2 : 1);
            }
        } else {
            // No unit typed: a building's default record beats the plain street record
            $score += $rowHasUnits ? -3 : ($row["RecordType"] == "H" ? 2 : 1);
        }
        if (($row["BaseAlternateCode"] ?? "B") == "B") {
            $score += 1;
        }
        return ["score" => $score, "inRange" => $inRange, "unitInRange" => $unitInRange, "firmMatched" => $firmMatched];
    }

    /** Whether a company name is the firm on a firm record ("SMITH SCHOOL" / "Smith School Inc"). */
    public static function sameFirm(string $company, string $firm): bool {
        $a = preg_replace('/\b(INC|LLC|CO|CORP|CORPORATION|COMPANY|LTD|THE)\b/', "", self::cleanText($company));
        $b = preg_replace('/\b(INC|LLC|CO|CORP|CORPORATION|COMPANY|LTD|THE)\b/', "", self::cleanText($firm));
        $a = preg_replace('/[^A-Z0-9]/', "", $a);
        $b = preg_replace('/[^A-Z0-9]/', "", $b);
        if (strlen($a) < 3 || strlen($b) < 3) {
            return false;
        }
        return $a === $b || (min(strlen($a), strlen($b)) >= 5 && (str_contains($a, $b) || str_contains($b, $a)));
    }

    /**
     * Finds the best record for a parsed line in some ZIPs.
     * @return array|null ["row", "cand", "reading", "inRange", "unitInRange", "fuzzy"], or ["ambiguous" => true]
     */
    private function bestMatch(array $parsed, array $zips, string $company = ""): ?array {
        if (count($zips) == 0) {
            return null;
        }
        // What to look up: [candidate street parts, secondary, likely]
        $tries = [];
        if ($parsed["type"] == "street") {
            foreach ($parsed["readings"] as $reading) {
                foreach (self::streetCandidates($reading["street"]) as $cand) {
                    $tries[] = [$cand, $reading["secondary"], $reading["likely"]];
                }
            }
            $types = ["S", "H", "F"];
        } else {
            $tries[] = [["pre" => "", "name" => $parsed["name"], "suffix" => "", "post" => "", "names" => [$parsed["name"]]], null, true];
            $types = ["pobox" => ["P"], "rural" => ["R"], "military" => ["S", "H", "P"], "general" => ["G"]][$parsed["type"]];
        }
        if (count($tries) == 0) {
            return null;
        }

        $names = [];
        foreach ($tries as [$cand]) {
            foreach ($cand["names"] as $nm) {
                $names[$nm] = true;
            }
        }
        $rows = $this->streetRows($zips, array_keys($names), $types);
        $fuzzy = false;
        if (count($rows) == 0 && $parsed["type"] == "street" && count($zips) <= 5) {
            // Maybe it's misspelled. Try the likeliest name spellings against the streets in the ZIP.
            $fixed = [];
            foreach ($tries as $i => [$cand]) {
                $close = $this->closestStreetName($zips, $cand["name"]);
                if ($close !== null) {
                    $tries[$i][0]["names"][] = $close;
                    $fixed[$close] = true;
                }
            }
            if (count($fixed) > 0) {
                $rows = $this->streetRows($zips, array_keys($fixed), $types);
                $fuzzy = true;
            }
        }
        if (count($rows) == 0) {
            return null;
        }

        // Best scoring row for each distinct street (same name in a different ZIP counts as different)
        $streets = [];
        foreach ($rows as $row) {
            foreach ($tries as [$cand, $secondary, $likely]) {
                if (!in_array($row["StName"], $cand["names"], true)) {
                    continue;
                }
                $number = $parsed["number"];
                $s = self::scoreRow($row, $cand, $number, $secondary, $likely, $company);
                $key = implode("|", [$row["ZipCode"], $row["StPreDirAbbr"], $row["StName"], $row["StSuffixAbbr"], $row["StPostDirAbbr"]]);
                if (!isset($streets[$key]) || $s["score"] > $streets[$key]["score"]) {
                    $streets[$key] = $s + ["row" => $row, "cand" => $cand, "secondary" => $secondary, "fuzzy" => $fuzzy];
                }
            }
        }
        uasort($streets, fn($a, $b) => $b["score"] <=> $a["score"]);
        $ranked = array_values($streets);
        $best = $ranked[0];
        // N MAIN ST vs S MAIN ST when no directional was typed, or the same street in two of the city's ZIPs: don't guess
        if (isset($ranked[1]) && $best["score"] - $ranked[1]["score"] < 2) {
            return ["ambiguous" => true];
        }
        return $best;
    }

    /** An alternate record (an old or vanity street name) points at the base record with the same +4. */
    private function baseForAlternate(array $row): ?array {
        if (($row["BaseAlternateCode"] ?? "B") != "A" || $row["Plus4Low"] === "" || $row["Plus4Low"] !== $row["Plus4High"]) {
            return null;
        }
        $bases = $this->query("SELECT StPreDirAbbr, StName, StSuffixAbbr, StPostDirAbbr FROM ZIP4 WHERE ZipCode = ? AND Plus4Low = ? AND Plus4High = ? AND BaseAlternateCode = 'B' AND RecordType = ? AND (Action IS NULL OR Action <> 'D')",
                [$row["ZipCode"], $row["Plus4Low"], $row["Plus4High"], $row["RecordType"]]);
        $distinct = [];
        foreach ($bases as $b) {
            $distinct[implode("|", $b)] = $b;
        }
        return count($distinct) == 1 ? reset($distinct) : null;
    }

    /**
     * Standardizes an address. The company is optional; it's only used to pick a firm's own +4.
     *
     * @return array [
     *   "match" => exact|street|none, "street1", "street2", "city", "state", "zip", "plus4",
     *   "carrier_route", "record_type" (S street, H highrise, F firm, P PO box, R rural route, G general delivery), "zip_changed" (bool), "fuzzy" (bool, the street name was misspelled),
     *   "lacs" (bool, USPS has converted this rural-style address to a city-style one), "message"
     * ]
     */
    public function standardize(?string $street1, ?string $street2, ?string $city, ?string $state, ?string $zip, ?string $company = ""): array {
        $company = $company ?? "";
        $city = self::cleanText($city);
        $state = self::cleanText($state);
        [$zip, $plus4In] = self::splitZip($zip);

        $deliverable = ["street", "pobox", "rural", "military", "general"];
        $line1 = self::parseLine($street1 ?? "");
        $line2 = self::parseLine($street2 ?? "");
        // "STE 200" or "ATTN BOB" on line 1 and the street on line 2: the delivery line goes first
        if (!in_array($line1["type"], $deliverable) && in_array($line2["type"], $deliverable)) {
            [$line1, $line2] = [$line2, $line1];
        }

        $result = [
            "match" => self::MATCH_NONE, "street1" => self::formatParsed($line1), "street2" => self::formatParsed($line2),
            "city" => $city, "state" => $state, "zip" => $zip, "plus4" => "", "carrier_route" => "", "record_type" => "",
            "zip_changed" => false, "fuzzy" => false, "lacs" => false, "message" => ""
        ];

        // The unit on line 2 counts for matching, if line 1 doesn't have one
        $unitOnLine2 = $line2["type"] == "secondary" ? $line2["secondary"] : null;
        if ($unitOnLine2 !== null && $line1["type"] == "street") {
            foreach ($line1["readings"] as &$r) {
                $r["secondary"] ??= $unitOnLine2;
            }
            unset($r);
        }

        // Fill in the city and state from the ZIP even if the street can't be matched, unless the
        // city typed is a real one somewhere else in the state (then it's unclear which is wrong)
        $zipValid = preg_match('/^\d{5}$/', $zip) && count($this->zipRows($zip)) > 0;
        if ($zipValid) {
            $zr = $this->zipRows($zip);
            $cityElsewhere = $city !== "" && $state === $zr[0]["State"] && count($this->zipsForCity($city, $state)) > 0
                    && !in_array($zip, $this->zipsForCity($city, $state));
            if ($state === "" || ($state === $zr[0]["State"] && !$cityElsewhere)) {
                $result["state"] = $zr[0]["State"];
                $result["city"] = $this->chooseCity($zip, $zr[0]["PreferredLastLineKey"], $city);
            }
        }

        if (!in_array($line1["type"], $deliverable)) {
            $result["message"] = "Not a recognizable delivery address line";
            return $result;
        }

        // Look in the ZIP given first, then in the city's other ZIPs (wrong ZIP typed)
        $match = null;
        $ambiguous = false;
        $searches = [];
        if ($zipValid) {
            $searches[] = [$zip];
        }
        if ($city !== "" && $state !== "") {
            $searches[] = array_values(array_diff($this->zipsForCity($city, $state), [$zip]));
        }
        foreach ($searches as $zips) {
            $zips = array_slice($zips, 0, 200);
            $m = $this->bestMatch($line1, $zips, $company);
            if ($m === null) {
                continue;
            }
            if (isset($m["ambiguous"])) {
                // The street is in the ZIP given, we just can't tell which one: looking elsewhere would only find a wrong one
                $ambiguous = true;
                break;
            }
            if ($match === null || ($m["inRange"] && !$match["inRange"])) {
                $match = $m;
            }
            if ($match["inRange"] || $line1["number"] === "" && $line1["type"] == "general") {
                break;
            }
        }
        if ($match === null) {
            $result["message"] = $ambiguous ? "More than one street matches; add the directional or suffix" : "Street not found";
            return $result;
        }

        $row = $match["row"];
        $street = ["pre" => $row["StPreDirAbbr"], "name" => $row["StName"], "suffix" => $row["StSuffixAbbr"], "post" => $row["StPostDirAbbr"]];
        $base = $this->baseForAlternate($row);
        if ($base !== null) {
            $street = ["pre" => $base["StPreDirAbbr"], "name" => $base["StName"], "suffix" => $base["StSuffixAbbr"], "post" => $base["StPostDirAbbr"]];
        }

        $secondary = $match["secondary"] ?? null;
        $secondaryText = "";
        if ($secondary !== null) {
            // "# 4" or "UNIT 4" where the building's units are APTs: use the building's designator
            $designator = $secondary["designator"];
            if ($match["unitInRange"] && $row["AddressSecAbbr"] !== "" && in_array($designator, ["#", "APT", "UNIT"])) {
                $designator = $row["AddressSecAbbr"];
            }
            $head = trim($secondary["designator"] . " " . $secondary["id"]);
            $secondaryText = trim("$designator $secondary[id]") . substr($secondary["text"], strlen($head));
        }
        $secondaryOnLine1 = $secondary !== null && $secondary !== $unitOnLine2;

        switch ($line1["type"]) {
            case "street":
                $result["street1"] = self::joinParts($line1["number"], $line1["fraction"], $street["pre"], $street["name"], $street["suffix"], $street["post"], $secondaryOnLine1 ? $secondaryText : "");
                break;
            case "pobox":
                $result["street1"] = "PO BOX " . $line1["number"];
                break;
            case "general":
                $result["street1"] = "GENERAL DELIVERY";
                break;
            default:
                $result["street1"] = "$street[name] BOX $line1[number]";
        }
        if ($secondary !== null && !$secondaryOnLine1) {
            $result["street2"] = $secondaryText;
        }

        $result["match"] = ($match["inRange"] || $line1["type"] == "general") ? self::MATCH_EXACT : self::MATCH_STREET;
        $result["zip"] = $row["ZipCode"];
        $result["zip_changed"] = $zip !== "" && $row["ZipCode"] !== $zip;
        $result["state"] = $row["State"] ?: $result["state"];
        $result["city"] = $this->chooseCity($row["ZipCode"], $row["PreferredLastLineKey"], $city);
        $result["fuzzy"] = $match["fuzzy"];
        $result["record_type"] = $row["RecordType"];
        $result["lacs"] = ($row["LACSStatus"] ?? "") == "L";
        if ($result["match"] == self::MATCH_EXACT) {
            $result["carrier_route"] = $row["CarrierRoute"];
            if ($row["Plus4Low"] !== "" && $row["Plus4Low"] === $row["Plus4High"] && ($row["RecordType"] != "F" || $match["firmMatched"])) {
                $result["plus4"] = $row["Plus4Low"];
            }
        } else {
            $result["message"] = $line1["number"] === "" ? "No house number" : "Number not in any range for this street";
        }
        return $result;
    }
}
