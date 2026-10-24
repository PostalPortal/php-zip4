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
        "SE" => "SE", "SOUTHEAST" => "SE", "SW" => "SW", "SOUTHWEST" => "SW",
        // Puerto Rico
        "NORTE" => "N", "SUR" => "S", "ESTE" => "E", "OESTE" => "W",
        "NORESTE" => "NE", "NOROESTE" => "NW", "SURESTE" => "SE", "SUROESTE" => "SW"
    ];

    /** Common misspellings of suffixes, safe to correct even without a database match */
    const SUFFIX_MISSPELLINGS = [
        "LN" => ["LANES"], "CT" => ["CRT"], "HTS" => ["HGTS", "HEIGHT"], "TPKE" => ["TPK", "TRPK"], "PR" => ["PRARIE"],
        "CSWY" => ["CAUSWAY"], "CRES" => ["CRECENT", "CRESENT", "CRSCNT"], "XING" => ["CRSSING"], "HVN" => ["HAVN"],
        "PLNS" => ["PLAINES"], "STRA" => ["STRAVE"],
        "ST" => ["STRET", "STEET", "SREET", "STREE", "STRRET", "STRETT", "STREEET"], "TER" => ["TERRANCE", "TERACE", "TERRASE", "TERRCE"],
        "PKWY" => ["PRKWY", "PRKY", "PWKY", "PARKWAYY"], "BLVD" => ["BLV", "BLVRD", "BLDV", "BOULAVARD", "BOULEVERD", "BULEVARD", "BOULVARD"],
        "AVE" => ["AVNEUE", "AVENEU", "AVEUNE"], "DR" => ["DRVIE", "DIRVE", "DRIEV"], "CIR" => ["CIRLCE", "CIRCEL", "CRICLE"]
    ];

    /**
     * Informal suffix abbreviations people (and county GIS data) use. Too ambiguous to rewrite on
     * their own ("CALLE LA"), so they're only tried as suffixes when matching against the file.
     */
    const SUFFIX_INFORMAL = [
        "PW" => ["PKWY"], "CI" => ["CIR"], "CM" => ["CMN"], "BL" => ["BLVD"], "TE" => ["TER"], "LP" => ["LOOP"],
        "LA" => ["LN"], "PK" => ["PARK"], "TR" => ["TRL"], "CK" => ["CRK"], "CR" => ["CRK", "CIR"], "CL" => ["CT", "CIR"],
        "WEY" => ["WAY"]
    ];

    /** Windows-1252 bytes 0x80-0x9F that aren't Latin-1, for input that isn't UTF-8 (PostalMate exports, old Excel) */
    const CP1252 = [0x80 => "\u{20AC}", 0x8A => "\u{0160}", 0x8C => "\u{0152}", 0x8E => "\u{017D}", 0x91 => "\u{2018}", 0x92 => "\u{2019}",
        0x93 => "\u{201C}", 0x94 => "\u{201D}", 0x96 => "\u{2013}", 0x97 => "\u{2014}", 0x9A => "\u{0161}", 0x9C => "\u{0153}",
        0x9E => "\u{017E}", 0x9F => "\u{0178}"];

    const STATE_NAMES = [
        "ALABAMA" => "AL", "ALASKA" => "AK", "ARIZONA" => "AZ", "ARKANSAS" => "AR", "CALIFORNIA" => "CA", "COLORADO" => "CO",
        "CONNECTICUT" => "CT", "DELAWARE" => "DE", "DISTRICT OF COLUMBIA" => "DC", "FLORIDA" => "FL", "GEORGIA" => "GA",
        "HAWAII" => "HI", "IDAHO" => "ID", "ILLINOIS" => "IL", "INDIANA" => "IN", "IOWA" => "IA", "KANSAS" => "KS",
        "KENTUCKY" => "KY", "LOUISIANA" => "LA", "MAINE" => "ME", "MARYLAND" => "MD", "MASSACHUSETTS" => "MA",
        "MICHIGAN" => "MI", "MINNESOTA" => "MN", "MISSISSIPPI" => "MS", "MISSOURI" => "MO", "MONTANA" => "MT",
        "NEBRASKA" => "NE", "NEVADA" => "NV", "NEW HAMPSHIRE" => "NH", "NEW JERSEY" => "NJ", "NEW MEXICO" => "NM",
        "NEW YORK" => "NY", "NORTH CAROLINA" => "NC", "NORTH DAKOTA" => "ND", "OHIO" => "OH", "OKLAHOMA" => "OK",
        "OREGON" => "OR", "PENNSYLVANIA" => "PA", "RHODE ISLAND" => "RI", "SOUTH CAROLINA" => "SC", "SOUTH DAKOTA" => "SD",
        "TENNESSEE" => "TN", "TEXAS" => "TX", "UTAH" => "UT", "VERMONT" => "VT", "VIRGINIA" => "VA", "WASHINGTON" => "WA",
        "WEST VIRGINIA" => "WV", "WISCONSIN" => "WI", "WYOMING" => "WY", "PUERTO RICO" => "PR", "GUAM" => "GU",
        "VIRGIN ISLANDS" => "VI", "AMERICAN SAMOA" => "AS", "NORTHERN MARIANA ISLANDS" => "MP"
    ];

    /** State codes USPS uses, including territories, freely associated states and the military "states" */
    const STATE_CODES = ["AL", "AK", "AZ", "AR", "CA", "CO", "CT", "DE", "DC", "FL", "GA", "HI", "ID", "IL", "IN", "IA", "KS",
        "KY", "LA", "ME", "MD", "MA", "MI", "MN", "MS", "MO", "MT", "NE", "NV", "NH", "NJ", "NM", "NY", "NC", "ND", "OH", "OK",
        "OR", "PA", "RI", "SC", "SD", "TN", "TX", "UT", "VT", "VA", "WA", "WV", "WI", "WY", "PR", "GU", "VI", "AS", "MP",
        "FM", "MH", "PW", "AA", "AE", "AP"];

    /** Accented letters and look-alike punctuation, folded to what the ZIP+4 file uses (PEÑA => PENA, 12–34 => 12-34) */
    const ASCII_FOLD = [
        "À" => "A", "Á" => "A", "Â" => "A", "Ã" => "A", "Ä" => "A", "Å" => "A", "à" => "A", "á" => "A", "â" => "A", "ã" => "A", "ä" => "A", "å" => "A",
        "Ç" => "C", "ç" => "C", "È" => "E", "É" => "E", "Ê" => "E", "Ë" => "E", "è" => "E", "é" => "E", "ê" => "E", "ë" => "E",
        "Ì" => "I", "Í" => "I", "Î" => "I", "Ï" => "I", "ì" => "I", "í" => "I", "î" => "I", "ï" => "I", "Ñ" => "N", "ñ" => "N",
        "Ò" => "O", "Ó" => "O", "Ô" => "O", "Õ" => "O", "Ö" => "O", "Ø" => "O", "ò" => "O", "ó" => "O", "ô" => "O", "õ" => "O", "ö" => "O", "ø" => "O",
        "Ù" => "U", "Ú" => "U", "Û" => "U", "Ü" => "U", "ù" => "U", "ú" => "U", "û" => "U", "ü" => "U", "Ý" => "Y", "ý" => "Y", "ÿ" => "Y",
        "ß" => "SS", "Æ" => "AE", "æ" => "AE", "Œ" => "OE", "œ" => "OE",
        "\u{2010}" => "-", "\u{2011}" => "-", "\u{2012}" => "-", "\u{2013}" => "-", "\u{2014}" => "-", "\u{2212}" => "-",
        "\u{2018}" => "", "\u{2019}" => "", "\u{201C}" => " ", "\u{201D}" => " ", "\u{00A0}" => " ", "\u{00BD}" => " 1/2"
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
        "APT" => ["APARTMENT", "APTS", "APTO", "APARTAMENTO"], "BLDG" => ["BUILDING", "BLD", "EDIF", "EDIFICIO"], "DEPT" => ["DEPARTMENT"], "FL" => ["FLOOR", "FLR", "PISO"],
        "HNGR" => ["HANGAR", "HANGER"], "KEY" => [], "LOT" => [], "PIER" => [], "RM" => ["ROOM"], "SLIP" => [],
        "SPC" => ["SPACE", "SP"], "STOP" => [], "STE" => ["SUITE", "SUIT"], "TRLR" => ["TRAILER"], "UNIT" => ["UNT"],
        "PMB" => [],
        "#" => ["NO", "NUM", "NUMBER"]
    ];

    /**
     * Designators people use interchangeably for "which one of the units here". When the unit number
     * is in the file's range for one of these, the file's designator wins: #456 or LOT 456 at a
     * trailer park whose units are TRLR becomes TRLR 456. The others (BLDG, FL, RM...) mean something
     * specific, so they have to match the file's designator.
     */
    const INTERCHANGEABLE_SECONDARY = ["#", "APT", "UNIT", "STE", "LOT", "SPC", "TRLR"];

    /** Pub 28 Appendix C2, designators that stand alone (some take an optional number) */
    const SECONDARY_WITHOUT_ID = [
        "BSMT" => ["BASEMENT"], "FRNT" => ["FRONT"], "LBBY" => ["LOBBY"], "LOWR" => ["LOWER"], "OFC" => ["OFFICE", "OFICINA"],
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
    private static ?array $informalSuffixLookup = null;
    private static array $suffixOptionCache = [];
    private static ?array $secondaryLookup = null;
    private PDO $db;
    private ?string $path = null;
    private array $statements = [];
    private array $cache = ["zip" => [], "city" => [], "lastline" => [], "streets" => [], "rows" => []];

    /**
     * @param PDO|string $db An open PDO connection to the ZIP+4 database, or the path to the SQLite file.
     *   A path is opened read-only (ensureIndexes() opens its own writable connection when it has to).
     * @throws Exception if the file or the tables aren't there.
     */
    public function __construct(PDO|string $db) {
        if (is_string($db)) {
            if (!is_file($db)) {
                throw new Exception("ZIP+4 database not found at $db");
            }
            $this->path = $db;
            $db = new PDO("sqlite:$db", null, null, [PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
        }
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        // Lookups jump around a multi-gigabyte file: a bigger page cache and memory-mapped reads help a lot
        $db->exec("PRAGMA temp_store = MEMORY; PRAGMA cache_size = -65536; PRAGMA mmap_size = 268435456;");
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
        $missing = $this->missingIndexes();
        if (count($missing) == 0) {
            return [];
        }
        $writer = $this->path === null ? $this->db : new PDO("sqlite:" . $this->path);
        $writer->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $created = [];
        foreach ($missing as $name => [$table, $alternatives, $columns]) {
            if ($log) {
                $log("Creating index $name on $table(" . implode(", ", $columns) . ")...");
            }
            $writer->exec("CREATE INDEX IF NOT EXISTS \"$name\" ON \"$table\" (\"" . implode("\", \"", $columns) . "\")");
            $created[] = $name;
        }
        return $created;
    }

    /**
     * name => [table, leading columns of any existing index that does the job, columns to create it on].
     * The alternatives cover the indexes AddressDatabase's zipdbgen.py builds (zip, stname_state_zip,
     * Index_ZIPCodes_State...), so a database made with it is used as is.
     */
    const INDEXES = [
        "idx_zip4_zipcode_stname" => ["ZIP4", [["ZipCode"], ["StName"]], ["ZipCode", "StName"]],
        "idx_zipcodes_zipcode" => ["ZIPCodes", [["ZipCode"]], ["ZipCode"]],
        "idx_zipcodes_state_city" => ["ZIPCodes", [["State"]], ["State", "City"]],
        "idx_zipcodes_citystatekey" => ["ZIPCodes", [["CityStateKey"]], ["CityStateKey"]]
    ];

    /** The indexes the matcher needs that aren't there (an existing index with suitable leading columns counts). */
    public function missingIndexes(): array {
        return array_filter(self::INDEXES, function ($i) {
            foreach ($i[1] as $leading) {
                if ($this->hasIndexStartingWith($i[0], $leading)) {
                    return false;
                }
            }
            return true;
        });
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
        $s = $s ?? "";
        if ($s !== "" && !preg_match('//u', $s)) {
            $s = self::fromWindows1252($s);
        }
        $s = strtr(trim($s), self::ASCII_FOLD);
        if (preg_match('/[^\x00-\x7F]/', $s) && function_exists("iconv")) {
            $t = @iconv("UTF-8", "ASCII//TRANSLIT//IGNORE", $s);
            $s = $t === false ? $s : $t;
        }
        $s = strtoupper($s);
        $s = str_replace(["'", "`"], "", $s);
        $s = preg_replace('/(?<=\d)\.(?=\d)/', "\x01", $s); // decimal points
        $s = preg_replace('/[^A-Z0-9#\/\-&\x01]+/', " ", $s);
        $s = str_replace("\x01", ".", $s);
        // A hyphen between words is a separator ("RD - REAR"); inside one it stays ("12-34", "TWENTY-NINTH")
        $s = preg_replace('/\s+-+\s*|\s*-+\s+|^-+|-+$/', " ", $s);
        $s = preg_replace('/\s*#\s*/', " # ", $s);
        return trim(preg_replace('/\s+/', " ", $s));
    }

    /** Text that isn't valid UTF-8 is almost always Windows-1252 (Borb\xF3n): convert it so the accents fold instead of vanishing. */
    public static function fromWindows1252(string $s): string {
        $out = "";
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            $b = ord($s[$i]);
            if ($b < 0x80) {
                $out .= $s[$i];
            } else if (isset(self::CP1252[$b])) {
                $out .= self::CP1252[$b];
            } else if ($b >= 0xA0) {
                $out .= chr(0xC0 | ($b >> 6)) . chr(0x80 | ($b & 0x3F));
            } else {
                $out .= " ";
            }
        }
        return $out;
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

    /**
     * The standard suffix abbreviation for a word, or null if it isn't one.
     * @param bool $informal Also accept the informal ones (PW, CI, LA, CR...): only when the file will confirm it.
     */
    public static function suffixAbbr(string $word, bool $informal = false): ?string {
        if (self::$suffixLookup === null) {
            self::$suffixLookup = [];
            foreach ([self::SUFFIXES, self::SUFFIX_MISSPELLINGS] as $list) {
                foreach ($list as $abbr => $variants) {
                    self::$suffixLookup[$abbr] = $abbr;
                    foreach ($variants as $v) {
                        self::$suffixLookup[$v] = $abbr;
                    }
                }
            }
            self::$informalSuffixLookup = self::$suffixLookup;
            foreach (self::SUFFIX_INFORMAL as $variant => $abbrs) {
                self::$informalSuffixLookup[$variant] ??= $abbrs[0];
            }
        }
        return ($informal ? self::$informalSuffixLookup : self::$suffixLookup)[$word] ?? null;
    }

    /**
     * Every suffix a word might be, for matching against the file: the standard one, the informal ones
     * (CL could be CT or CIR), or for a longer word that isn't a suffix, any suffix one typo away
     * (TERRANC => TER). The file decides which, if any.
     */
    public static function suffixOptions(string $word): array {
        if (isset(self::$suffixOptionCache[$word])) {
            return self::$suffixOptionCache[$word];
        }
        $out = [];
        if (($s = self::suffixAbbr($word)) !== null) {
            $out[] = $s;
        }
        foreach (self::SUFFIX_INFORMAL[$word] ?? [] as $s) {
            $out[] = $s;
        }
        if (count($out) == 0 && strlen($word) >= 5 && !preg_match('/\d/', $word)) {
            foreach (self::SUFFIXES as $abbr => $variants) {
                foreach ($variants as $v) {
                    if (abs(strlen($v) - strlen($word)) <= 1 && $v[0] === $word[0] && self::typoDistance($word, $v) <= 1) {
                        $out[] = $abbr;
                        break;
                    }
                }
            }
        }
        if (count(self::$suffixOptionCache) > 5000) {
            self::$suffixOptionCache = [];
        }
        return self::$suffixOptionCache[$word] = array_values(array_unique($out));
    }

    /** NORTH WEST, N W, SOUTH EAST... as two words: the one directional, or null */
    private static function compoundDirectional(string $a, string $b): ?string {
        $ns = ["N" => "N", "NORTH" => "N", "S" => "S", "SOUTH" => "S"];
        $ew = ["E" => "E", "EAST" => "E", "W" => "W", "WEST" => "W"];
        return isset($ns[$a], $ew[$b]) ? $ns[$a] . $ew[$b] : null;
    }

    /** "MONTANA" or "MT" => "MT"; anything else => null */
    public static function stateCode(string $s): ?string {
        $s = self::cleanText($s);
        return self::STATE_NAMES[$s] ?? (in_array($s, self::STATE_CODES, true) ? $s : null);
    }

    /**
     * People paste the whole address into the street line ("123 Main St, Helena, MT 59601") or the
     * last line into the city ("Helena, MT 59601"). Splits those out, filling in only what's empty.
     * @return array [street1, city, state, zip]
     */
    public static function splitLastLine(string $street1, string $city, string $state, string $zip): array {
        $countryTail = '/[,\s]*\b(USA|U\.S\.A\.?|UNITED STATES(?: OF AMERICA)?)\s*$/i';
        $street1 = trim(preg_replace($countryTail, "", $street1));
        $city = trim(preg_replace($countryTail, "", $city));
        $fits = function (string $code, ?string $tailZip) use (&$state, &$zip): bool {
            return ($state === "" || self::stateCode($state) === $code)
                    && ($zip === "" || $tailZip === null || $tailZip === "" || self::splitZip($zip)[0] === substr($tailZip, 0, 5));
        };
        $lastLine = '([A-Za-z][A-Za-z .\'\-]*?)\s*,?\s+([A-Za-z]{2}|[A-Za-z]{4,}(?: [A-Za-z]+)?)\.?(?:\s+(\d{5}(?:-?\d{4})?))?';
        // (not "123 Main St, Unit PA": a unit isn't a city)
        if (preg_match("/^(.+?),\s*$lastLine\s*$/", $street1, $m) && ($code = self::stateCode($m[3])) !== null && $fits($code, $m[4] ?? null)
                && self::secondaryAbbr(explode(" ", self::cleanText($m[2]))[0]) === null) {
            $street1 = trim($m[1]);
            $city = $city === "" ? trim($m[2]) : $city;
            $state = $state === "" ? $code : $state;
            $zip = $zip === "" ? ($m[4] ?? "") : $zip;
        }
        if (preg_match('/^([A-Za-z][A-Za-z .\'\-]*?)\s*[,\s]\s*([A-Za-z]{2})\.?(?:\s+(\d{5}(?:-?\d{4})?))?$/', $city, $m)
                && ($code = self::stateCode($m[2])) !== null && $fits($code, $m[3] ?? null)) {
            $city = trim($m[1]);
            $state = $state === "" ? $code : $state;
            $zip = $zip === "" ? ($m[3] ?? "") : $zip;
        }
        $city = preg_replace('/^(CITY|TOWN|VILLAGE|TOWNSHIP|BOROUGH) OF /i', "", trim($city));
        $city = trim(preg_replace('/\bUNINCORPORATED\b/i', "", $city));
        return [$street1, $city, $state, $zip];
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

    /** After a designator that needs one, a unit ID can be anything short: 4, 4B, B, FDA, FDA-OTED */
    private static function isDesignatedUnitId(string $t): bool {
        return (bool) preg_match('/^[0-9A-Z][0-9A-Z\-]{0,11}$/', $t);
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
        // "APT # 4", "PMB #43": the # is just decoration
        if ($designator !== "#" && ($tokens[1] ?? "") === "#" && isset($tokens[2])) {
            array_splice($tokens, 1, 1);
        }
        if (isset($tokens[1]) && ($needsId ? self::isDesignatedUnitId($tokens[1]) : self::isUnitId($tokens[1])) && self::suffixAbbr($tokens[1]) === null) {
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
        // "2ND FLOOR" is FL 2
        for ($i = 0; $i < count($tokens) - 1; $i++) {
            if (preg_match('/^(\d+)(ST|ND|RD|TH)$/', $tokens[$i], $m) && in_array($tokens[$i + 1], ["FLOOR", "FL", "FLR"])) {
                array_splice($tokens, $i, 2, ["FL", $m[1]]);
            }
        }
        $secondary = self::parseSecondary($tokens);
        if ($secondary !== null) {
            return ["type" => "secondary", "secondary" => $secondary] + $out;
        }

        // House number: 123, 123A, 12-34 (Queens), N123W4567 (Wisconsin grid). Not 1ST, which is a street name.
        $number = "";
        // Puerto Rico's A12 or A-12 too, but not N12 (a directional and a street)
        if (preg_match('/^(\d+[A-Z]?(-\d*[A-Z]?)?|[NSEW]\d+[NSEW]\d+|\d+-[A-Z]|[A-DF-MO-RT-VX-Z]-?\d+[A-Z]?)$/', $tokens[0])) {
            $number = array_shift($tokens);
            // "1234 1234 MAIN ST", from sources that put the number in twice
            if (isset($tokens[1]) && $tokens[0] === $number) {
                array_shift($tokens);
            }
        }
        if ($number === "") {
            // Puerto Rico and Latin American order, the number after the street: CALLE HENNA 2209 SANTURCE
            for ($p = 1; $p < count($tokens); $p++) {
                if (preg_match('/^\d{1,6}[A-Z]?$/', $tokens[$p])
                        && !preg_match('/^(HWY|HIGHWAY|ROUTE|RTE|RT|CARR|CARRETERA|CALLE|AVENIDA|AVE|AV|COUNTY|ROAD|RD|INTERSTATE|I|US|SR|CR|FM|KM|BOX|UNIT|PSC|CMR|PMB|#|NO|CASA|LOTE|BLOQUE|SECTOR|MANZANA)$/', $tokens[$p - 1])
                        && self::secondaryAbbr($tokens[$p - 1]) === null) {
                    $extra = implode(" ", array_slice($tokens, $p + 1));
                    return ["type" => "street", "number" => $tokens[$p], "fraction" => "", "reordered" => true, "readings" => [
                            ["street" => array_slice($tokens, 0, $p), "secondary" => null, "likely" => true, "extra" => $extra]]] + $out;
                }
            }
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
            // "BOGGY CREEK RD ST Z": after a complete street, ST is a suite
            if ($sec === null && $tokens[$i] === "ST" && $i >= 2 && self::suffixAbbr($tokens[$i - 1]) !== null && isset($tokens[$i + 1])) {
                $sec = self::parseSecondary(array_merge(["STE"], array_slice($tokens, $i + 1)));
            }
            if ($sec === null) {
                continue;
            }
            $street = array_slice($tokens, 0, $i);
            $prev = $tokens[$i - 1];
            $afterSuffix = self::suffixAbbr($prev) !== null || self::directionalAbbr($prev) !== null;
            // A word like FRONT or LOWER is only a unit when the street before it already looks complete
            $likely = $sec["designator"] == "#" || (self::secondaryAbbr($tokens[$i])[1] ?? true) === true || $afterSuffix; // (ST read as STE has no entry)
            $readings[] = ["street" => $street, "secondary" => $sec, "likely" => $likely];
            if ($likely && $preferred === null) {
                $preferred = count($readings) - 1;
            }
        }
        $readings[] = ["street" => $tokens, "secondary" => null, "likely" => $preferred === null];
        // A bare trailing unit: "123 MAIN ST 4B", "123 BROADWAY B". Only used if the database likes it better,
        // and not after words that take a number (COUNTY ROAD 5, HIGHWAY 12, BOX 4).
        $last = end($tokens);
        $beforeLast = count($tokens) >= 2 ? $tokens[count($tokens) - 2] : "";
        if (count($tokens) >= 2 && $preferred === null
                && (preg_match('/^[A-Z]?\d{1,5}[A-Z]?$/', $last) || (preg_match('/^[A-Z]$/', $last) && self::directionalAbbr($last) === null))
                && !preg_match('/^(HWY|HIGHWAY|ROUTE|RTE|RT|INTERSTATE|I|US|SR|CR|FM|COUNTY|STATE|BOX|UNIT|PSC|CMR)$/', $beforeLast)) {
            $readings[] = ["street" => array_slice($tokens, 0, -1), "secondary" => ["designator" => "#", "id" => $last, "text" => "# $last"], "likely" => false];
        }
        // Something after a complete street that isn't a unit: the city ("RIVER ST SAXTONS RIVER VT"), a neighborhood,
        // or junk ("CARTER DR MARINSON"). Tried at each suffix (and a directional after it), only if the file likes it.
        for ($p = 1; $p < count($tokens) - 1; $p++) {
            if (self::suffixAbbr($tokens[$p], true) === null) {
                continue;
            }
            $end = $p + (isset($tokens[$p + 1]) && self::directionalAbbr($tokens[$p + 1]) !== null && strlen($tokens[$p + 1]) <= 2 ? 1 : 0);
            $rest = array_slice($tokens, $end + 1);
            if (count($rest) == 0 || self::parseSecondary($rest) !== null) {
                continue;
            }
            // A unit and then junk after it ("RD 601 ORLANDO") isn't worth guessing at
            $readings[] = ["street" => array_slice($tokens, 0, $end + 1), "secondary" => null, "likely" => false, "extra" => implode(" ", $rest), "penalty" => 1];
        }
        // "16N STONINGTON RD": a directional stuck to the house number
        if (preg_match('/^(\d+)([NSEW])$/', $number, $m) && self::directionalAbbr($tokens[0]) === null) {
            $readings[] = ["street" => array_merge([$m[2]], $tokens), "secondary" => null, "likely" => false, "number" => $m[1]];
        }
        // "1234 A MAIN ST" meaning 1234A (a separate letter, not a street named A or a directional)
        if (count($tokens) >= 3 && preg_match('/^\d+$/', $number) && preg_match('/^[A-Z]$/', $tokens[0]) && self::directionalAbbr($tokens[0]) === null) {
            $readings[] = ["street" => array_slice($tokens, 1), "secondary" => null, "likely" => false, "number" => $number . $tokens[0]];
        }
        usort($readings, fn($a, $b) => $b["likely"] <=> $a["likely"]);

        return ["type" => "street", "number" => $number, "fraction" => $fraction, "readings" => $readings] + $out;
    }

    /**
     * The ways a list of street tokens could split into predirectional, name, suffix and postdirectional.
     * @return array of ["pre", "name", "suffix", "post", "names" => [name spellings to look up]]
     */
    public static function streetCandidates(array $tokens, bool $informal = false): array {
        // "TWENTY NINTH" typed without the hyphen
        for ($i = 0; $i < count($tokens) - 1; $i++) {
            if (isset(self::TENS[$tokens[$i]]) && isset(self::ORDINALS[$tokens[$i + 1]])) {
                array_splice($tokens, $i, 2, [$tokens[$i] . "-" . $tokens[$i + 1]]);
            }
        }
        // "MAIN STREET ST", "OAK DR DRIVE": the suffix twice
        while (count($tokens) >= 3 && self::suffixAbbr(end($tokens)) !== null && self::suffixAbbr(end($tokens)) === self::suffixAbbr($tokens[count($tokens) - 2])) {
            array_pop($tokens);
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
        if ($n > 2 && ($d = self::compoundDirectional($tokens[0], $tokens[1])) !== null) {
            $preOptions[] = [$d, 2, "$tokens[0] $tokens[1]"]; // NORTH WEST 153 ST
        }
        foreach ($preOptions as [$pre, $start, $preWord]) {
            $postOptions = [["", $n, ""]];
            if ($n - $start > 1 && self::directionalAbbr($tokens[$n - 1]) !== null) {
                $postOptions[] = [self::directionalAbbr($tokens[$n - 1]), $n - 1, $tokens[$n - 1]];
            }
            if ($n - $start > 2 && ($d = self::compoundDirectional($tokens[$n - 2], $tokens[$n - 1])) !== null) {
                $postOptions[] = [$d, $n - 2, $tokens[$n - 2] . " " . $tokens[$n - 1]];
            }
            foreach ($postOptions as [$post, $end, $postWord]) {
                $suffixOptions = [["", $end]];
                if ($end - $start > 1) {
                    foreach ($informal ? self::suffixOptions($tokens[$end - 1]) : array_filter([self::suffixAbbr($tokens[$end - 1])]) as $sfx) {
                        $suffixOptions[] = [$sfx, $end - 1];
                    }
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

    /** 1 => 1ST, 2 => 2ND, 3 => 3RD, 11 => 11TH, 153 => 153RD */
    public static function ordinalSuffix(int $n): string {
        $suffix = ($n % 100 >= 11 && $n % 100 <= 13) ? "TH" : (["TH", "ST", "ND", "RD"][$n % 10] ?? "TH");
        return $n . $suffix;
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
        // Suffix abbreviations inside the name are spelled out in the file: S CRK RD is S CREEK RD,
        // MTN VIEW DR is MOUNTAIN VIEW DR, LK SHORE DR is LAKE SHORE DR
        $longhand = array_map(fn($w) => isset(self::SUFFIXES[$w][0]) ? self::SUFFIXES[$w][0] : (self::ordinalWord($w) ?? $w), $words);
        $spellings = [implode(" ", $words), implode(" ", $expanded), implode(" ", $abbreviated), implode(" ", $longhand)];
        // A numbered street typed without its ordinal: SW 147 PL is SW 147TH PL, NW 153 ST is NW 153RD ST
        if (preg_grep('/^\d+$/', $words)) {
            $spellings[] = implode(" ", array_map(fn($w) => preg_match('/^\d+$/', $w) ? self::ordinalSuffix((int) $w) : $w, $expanded));
        }
        // "N SLOPE" typed for the street named NORTH SLOPE (and "BOULDER W" for BOULDER WEST)
        $full = ["N" => "NORTH", "S" => "SOUTH", "E" => "EAST", "W" => "WEST", "NE" => "NORTHEAST", "NW" => "NORTHWEST", "SE" => "SOUTHEAST", "SW" => "SOUTHWEST"];
        if (count($words) > 1 && (isset($full[$words[0]]) || isset($full[end($words)]))) {
            $w = $words;
            $w[0] = $full[$w[0]] ?? $w[0];
            $w[count($w) - 1] = $full[$w[count($w) - 1]] ?? $w[count($w) - 1];
            $spellings[] = implode(" ", $w);
        }
        array_push($spellings, ...self::roadNumberSpellings(implode(" ", $words)));
        return array_values(array_unique($spellings));
    }

    /**
     * The ways the file might spell a numbered road. Pub 28 and the ZIP+4 file use INTERSTATE 15,
     * US HIGHWAY 12, KY HIGHWAY 1234, STATE HIGHWAY 200, COUNTY ROAD 5, FM 1960, FOREST SERVICE ROAD 4100...
     * Several spellings of the state ones are tried, since which one the file has depends on the state.
     */
    public static function roadNumberSpellings(string $joined): array {
        $out = [];
        $num = '[ \-]?(\d+[A-Z]?)';
        // County roads and highways: CR 5, CO RD 5, CNTY HWY 5
        $out[] = preg_replace('/^(CR|CO RD|CNTY RD|COUNTY RD|CO ROAD|CNTY ROAD)[ \-]/', "COUNTY ROAD ", $joined);
        $out[] = preg_replace('/^(CO|CNTY|COUNTY) (HWY|HIGHWAY) /', "COUNTY HIGHWAY ", $joined);
        // Indiana-style grid: N COUNTY ROAD 500 E is N 500 E in the file
        if (preg_match('/^(?:COUNTY ROAD|COUNTY RD|CR|CO RD) (\d{3,})$/', $joined, $m)) {
            $out[] = $m[1];
        }
        // US highways: US 12, US-12, U S HWY 12, UNITED STATES HIGHWAY 12, US ROUTE 12
        if (preg_match("/^(?:HWY |HIGHWAY )?(?:US HWY|US HIGHWAY|US ROUTE|US RTE|US RT|U S HWY|U S HIGHWAY|U S|US|UNITED STATES HWY|UNITED STATES HIGHWAY|UNITED STATES ROUTE|UNITED STATES)$num$/", $joined, $m)) {
            $out[] = "US HIGHWAY $m[1]";
        }
        // Interstates: I-15, I 15, I15, IH-15, INTERSTATE HWY 15, HWY I-15
        if (preg_match("/^(?:HWY |HIGHWAY )?(?:I|IH|INTERSTATE|INTERSTATE HWY|INTERSTATE HIGHWAY|INTERSTATE ROUTE)$num$/", $joined, $m)) {
            $out[] = "INTERSTATE $m[1]";
        }
        // Plain highways, and HWY inside a longer name (OLD HWY 10 / OLD HIGHWAY 10)
        $out[] = preg_replace('/^(HWY|HIWAY|HGWY|HY) /', "HIGHWAY ", $joined);
        $out[] = preg_replace('/(?<=\S) HIGHWAY (\d+)/', ' HWY $1', $joined);
        $out[] = preg_replace('/(?<=\S) (HWY|HIWAY) (\d+)/', ' HIGHWAY $2', $joined);
        // State routes with a state named: KY 1234, MT-200, MONTANA HWY 200, KENTUCKY STATE HIGHWAY 625
        if (preg_match("/^(.+?)(?: STATE)?(?: HWY| HIGHWAY| RT| RTE| ROUTE| RD| ROAD)?$num$/", $joined, $m)) {
            $code = self::STATE_NAMES[$m[1]] ?? (in_array($m[1], self::STATE_CODES, true) && $m[1] !== "US" ? $m[1] : null);
            if ($code !== null) {
                array_push($out, "$code HIGHWAY $m[2]", "$code STATE HIGHWAY $m[2]", "STATE HIGHWAY $m[2]", "HIGHWAY $m[2]", "STATE ROUTE $m[2]", "STATE ROAD $m[2]");
            }
        }
        // ...and without: SR 200, SH 200, STATE HWY 200, ST RTE 200
        if (preg_match("/^(?:SR|SH|ST|STATE|ST HWY|STATE HWY|STATE HIGHWAY|ST RT|ST RTE|STATE RT|STATE RTE|STATE ROUTE|ST RD|STATE RD|STATE ROAD)$num$/", $joined, $m)) {
            array_push($out, "STATE HIGHWAY $m[1]", "STATE ROUTE $m[1]", "STATE ROAD $m[1]", "HIGHWAY $m[1]");
        }
        // Texas farm to market roads: FM 1960, FARM TO MARKET 1960, HWY FM 1960
        if (preg_match("/^(?:HWY |HIGHWAY )?(?:FM|F M|FARM TO MARKET|FARM TO MARKET RD|FARM TO MARKET ROAD|FARM RD|FARM ROAD|FM RD|FM ROAD)$num$/", $joined, $m)) {
            $out[] = "FM $m[1]";
        }
        // Forest Service roads: FS RD 4100, FSR 4100, NFSR 4100, USFS 4100, FOREST RD 4100
        if (preg_match("/^(?:FS|FSR|NFSR|NFS|NF|NFD|USFS|FS RD|FS ROAD|FSR RD|USFS RD|USFS ROAD|FOREST SERVICE|FOREST SERVICE RD|FOREST SERVICE ROAD|FOREST RD|FOREST ROAD|NATIONAL FOREST RD|NATIONAL FOREST ROAD|NATIONAL FOREST DEVELOPMENT RD|NATIONAL FOREST DEVELOPMENT ROAD|US FOREST SERVICE RD|US FOREST SERVICE ROAD|UNITED STATES FOREST SERVICE RD|UNITED STATES FOREST SERVICE ROAD)$num(?: RD| ROAD)?$/", $joined, $m)) {
            array_push($out, "FOREST SERVICE ROAD $m[1]", "FOREST ROAD $m[1]", "NATIONAL FOREST DEVELOPMENT ROAD $m[1]");
        }
        // Township and private roads: TSR 12, TWP RD 12, PR 1234, PVT RD 1234
        if (preg_match("/^(?:TSR|TWP RD|TWP ROAD|TWSP RD|TOWNSHIP RD|T R|TR)$num$/", $joined, $m)) {
            $out[] = "TOWNSHIP ROAD $m[1]";
        }
        if (preg_match("/^(?:PR|PVT RD|PVT ROAD|PRIV RD|PRIVATE RD)$num$/", $joined, $m)) {
            $out[] = "PRIVATE ROAD $m[1]";
        }
        // Martin Luther King Jr: MLK, M L KING, MARTIN L KING JR
        if (preg_match('/^(?:M L K|MLK|M L KING|ML KING|MARTIN L KING|MARTIN LUTHER KING|DR MARTIN LUTHER KING|DR M L KING)(?: JR)?$/', $joined)) {
            array_push($out, "MARTIN LUTHER KING JR", "MARTIN LUTHER KING", "DR MARTIN LUTHER KING JR");
        }
        return $out;
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
                if (!empty($p["reordered"])) {
                    return $p["text"];
                }
                $reading = $p["readings"][0];
                // Without the database, NORTH SLOPE RD and NORTH MAIN ST look the same, so a spelled-out
                // directional stays spelled out; only N or N. is treated as one
                $c = array_values(array_filter(self::streetCandidates($reading["street"]), fn($x) => strlen($x["preWord"]) <= 2 && strlen($x["postWord"]) <= 2));
                // The reading that uses the most of the standard parts
                usort($c, fn($a, $b) => (($b["pre"] != "") + ($b["suffix"] != "") + ($b["post"] != "")) <=> (($a["pre"] != "") + ($a["suffix"] != "") + ($a["post"] != "")));
                $street = $c[0];
                return self::joinParts($reading["number"] ?? $p["number"], $p["fraction"], $street["pre"], $street["name"], $street["suffix"], $street["post"], $reading["secondary"]["text"] ?? "");
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
        // Queens: the file has 144-01 to 144-99, typed as 144-15 or 14415
        if (preg_match('/^(\d+)-(\d+)$/', $low, $lm) && preg_match('/^(\d+)-(\d+)$/', $high, $hm)) {
            $w = strlen($lm[2]);
            if (preg_match('/^(\d+)-(\d+)$/', $number, $nm)) {
                [$a, $b] = [(int) $nm[1], (int) $nm[2]];
            } else if (preg_match('/^\d+$/', $number) && strlen($number) > $w) {
                [$a, $b] = [(int) substr($number, 0, -$w), (int) substr($number, -$w)];
            } else {
                return false;
            }
            $lo = [(int) $lm[1], (int) $lm[2]];
            $hi = [(int) $hm[1], (int) $hm[2]];
            $parity = strtoupper(trim($parity ?? "B"));
            return [$a, $b] >= $lo && [$a, $b] <= $hi && !(($parity == "E" && $b % 2) || ($parity == "O" && !($b % 2)));
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
                $bare = fn($x) => ltrim(str_replace("-", "", $x), "0");
                if ($bare($num) === $bare($low) || $bare($num) === $bare($high)) {
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

    /** The house number the way the file writes it: 14415 against a 144-01 range is 144-15 */
    public static function fileNumberFormat(string $number, ?string $low): string {
        if (preg_match('/^(\d+)-(\d+)$/', trim($low ?? ""), $lm) && preg_match('/^\d+$/', $number) && strlen($number) > strlen($lm[2])) {
            return substr($number, 0, -strlen($lm[2])) . "-" . substr($number, -strlen($lm[2]));
        }
        return $number;
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
        $params = array_merge($zips, $names, $types);
        $key = implode("\x1f", $params) . "\x1e" . count($zips) . "," . count($names);
        if (!isset($this->cache["rows"][$key])) {
            if (count($this->cache["rows"]) >= 2000) {
                array_shift($this->cache["rows"]);
            }
            $this->cache["rows"][$key] = $this->query($sql, $params);
        }
        return $this->cache["rows"][$key];
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
        $limit = strlen($name) >= 7 ? 2 : 1;
        $best = [];
        $bestDistance = $limit + 1;
        $abbreviations = [];
        foreach ($zips as $zip) {
            foreach ($this->streetNamesInZip($zip) as $street) {
                if (strlen($street) < 2) {
                    continue;
                }
                // POTTSWN for POTTSTOWN: the letters typed, in order, with some left out
                if ($street[0] === $name[0] && strlen($street) > strlen($name) && strlen($name) >= 0.6 * strlen($street) && self::isSubsequence($name, $street)) {
                    $abbreviations[$street] = true;
                }
                // Same first letter, or the first two swapped (AOK POND for OAK POND)
                if (!($street[0] === $name[0] || ($street[0] === $name[1] && $street[1] === $name[0])) || abs(strlen($street) - strlen($name)) > $limit) {
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
        if (count($best) == 1) {
            return array_key_first($best);
        }
        return count($best) == 0 && count($abbreviations) == 1 ? array_key_first($abbreviations) : null;
    }

    private static function isSubsequence(string $short, string $long): bool {
        $j = 0;
        for ($i = 0, $n = strlen($long); $i < $n && $j < strlen($short); $i++) {
            if ($long[$i] === $short[$j]) {
                $j++;
            }
        }
        return $j === strlen($short);
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
        $unitFromCompany = false;
        if ($secondary !== null) {
            if ($rowHasUnits) {
                $unitInRange = self::designatorFits($secondary["designator"], $row["AddressSecAbbr"])
                        && ($secondary["id"] !== "" ? self::inRange($secondary["id"], $row["AddressSecLowNumber"], $row["AddressSecHighNumber"], $row["AddressSecOddEven"])
                        : $row["AddressSecAbbr"] === $secondary["designator"]); // FRNT, REAR...
                if ($unitInRange) {
                    $score += ($row["AddressSecAbbr"] === $secondary["designator"]) ? 7 : 6;
                } else if (empty($secondary["implicit"])) {
                    $score -= 2;
                }
            } else {
                $score += ($row["RecordType"] == "H" ? 2 : 1);
            }
        } else if ($rowHasUnits && $company !== "" && $row["AddressSecLowNumber"] === $row["AddressSecHighNumber"]
                && self::companyIsUnit($company, $row["AddressSecLowNumber"])) {
            // No unit typed, but the company is the unit: the FDA at a border station whose units are named for agencies
            $unitInRange = true;
            $unitFromCompany = true;
            $score += 6;
        } else {
            // No unit typed: a building's default record beats the plain street record
            $score += $rowHasUnits ? -3 : ($row["RecordType"] == "H" ? 2 : 1);
        }
        if (($row["BaseAlternateCode"] ?? "B") == "B") {
            $score += 1;
        }
        // How many house numbers the record covers: a single-address record beats the block range it sits in
        $lo = self::numberParts($row["AddressPrimaryLowNumber"] ?? "");
        $hi = self::numberParts($row["AddressPrimaryHighNumber"] ?? "");
        $width = ($lo !== null && $hi !== null) ? abs($hi[1] - $lo[1]) : PHP_INT_MAX;
        return ["score" => $score, "inRange" => $inRange, "unitInRange" => $unitInRange, "unitFromCompany" => $unitFromCompany, "firmMatched" => $firmMatched, "width" => $width];
    }

    /** Whether a typed unit designator can mean the file's ("#" can mean anything; APT, LOT, TRLR... each other). */
    public static function designatorFits(string $typed, ?string $file): bool {
        $file = $file ?? "";
        return $typed === $file || $typed === "#" || $file === ""
                || (in_array($typed, self::INTERCHANGEABLE_SECONDARY) && in_array($file, self::INTERCHANGEABLE_SECONDARY));
    }

    /**
     * Whether a company name is a unit's name: "FDA" for unit FDA, "Food and Drug Administration" (FDA),
     * "U.S. Fish and Wildlife Service" (USFWS), "FDA OTED" (FDA-OTED). Only for names, not unit numbers.
     */
    public static function companyIsUnit(string $company, ?string $unit): bool {
        $unit = preg_replace('/[^A-Z0-9]/', "", strtoupper($unit ?? ""));
        if (strlen($unit) < 2 || preg_match('/\d/', $unit)) {
            return false;
        }
        $words = array_values(array_filter(explode(" ", preg_replace('/[^A-Z0-9 ]/', " ", self::cleanText($company))),
                        fn($w) => $w !== "" && !in_array($w, ["AND", "OF", "THE", "FOR", "&", "INC", "LLC"])));
        if (count($words) == 0) {
            return false;
        }
        $joined = implode("", $words);
        $initials = implode("", array_map(fn($w) => $w[0], $words));
        // Initials, keeping short words whole: U.S. => US
        $initialsKeepingShort = implode("", array_map(fn($w) => strlen($w) <= 2 ? $w : $w[0], $words));
        $candidates = [$joined, $initials, $initialsKeepingShort];
        // "U.S. Food and Drug Administration" is the FDA
        foreach ([$initials, $initialsKeepingShort] as $a) {
            if (str_starts_with($a, "US") && strlen($a) > 3) {
                $candidates[] = substr($a, 2);
            }
        }
        return in_array($unit, $candidates, true);
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
                foreach (self::streetCandidates($reading["street"], true) as $cand) {
                    $tries[] = [$cand, $reading["secondary"], $reading["likely"], $reading["number"] ?? $parsed["number"], $reading["penalty"] ?? 0, $reading["extra"] ?? ""];
                }
            }
            $types = ["S", "H", "F"];
        } else {
            $tries[] = [["pre" => "", "name" => $parsed["name"], "suffix" => "", "post" => "", "names" => [$parsed["name"]]], null, true, $parsed["number"], 0, ""];
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
        $unitHits = [];
        foreach ($rows as $row) {
            foreach ($tries as [$cand, $secondary, $likely, $number, $penalty, $extra]) {
                if (!in_array($row["StName"], $cand["names"], true)) {
                    continue;
                }
                $s = self::scoreRow($row, $cand, $number, $secondary, $likely, $company);
                $s["score"] -= $penalty;
                $key = implode("|", [$row["ZipCode"], $row["StPreDirAbbr"], $row["StName"], $row["StSuffixAbbr"], $row["StPostDirAbbr"]]);
                if ($s["unitInRange"] && $s["inRange"]) {
                    $unitHits[$key][$row["AddressSecAbbr"] . "|" . $row["Plus4Low"]] = $row["AddressSecAbbr"];
                }
                if (!isset($streets[$key]) || $s["score"] > $streets[$key]["score"]
                        || ($s["score"] == $streets[$key]["score"] && $s["width"] < $streets[$key]["width"])) {
                    $streets[$key] = $s + ["row" => $row, "cand" => $cand, "secondary" => $secondary, "fuzzy" => $fuzzy, "number" => $number, "extra" => $extra];
                }
            }
        }
        foreach ($streets as $key => &$st) {
            // "# 4" in a building with both APT 4 and STE 4 (and different +4s): can't say which
            $kinds = array_unique($unitHits[$key] ?? []);
            $st["unitAmbiguous"] = $st["unitInRange"] && count($kinds) > 1 && !in_array($st["secondary"]["designator"] ?? "", $kinds, true);
        }
        unset($st);
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
        [$street1, $city, $state, $zip] = self::splitLastLine($street1 ?? "", $city ?? "", $state ?? "", $zip ?? "");
        $city = self::cleanText($city);
        $state = self::stateCode($state) ?? self::cleanText($state);
        $state = self::cleanText($state);
        [$zip, $plus4In] = self::splitZip($zip);

        $deliverable = ["street", "pobox", "rural", "military", "general"];
        $line1 = self::parseLine($street1 ?? "");
        $line2 = self::parseLine($street2 ?? "");
        // "STE 200" or "ATTN BOB" on line 1 and the street on line 2: the delivery line goes first.
        // (Not for a line that only looks like a street with its number moved to the front: too much of a guess.)
        if (!in_array($line1["type"], $deliverable) && in_array($line2["type"], $deliverable) && empty($line2["reordered"])) {
            [$line1, $line2] = [$line2, $line1];
        }

        $result = [
            "match" => self::MATCH_NONE, "street1" => self::formatParsed($line1), "street2" => self::formatParsed($line2),
            "city" => $city, "state" => $state, "zip" => $zip, "plus4" => "", "carrier_route" => "", "record_type" => "",
            "zip_changed" => false, "fuzzy" => false, "lacs" => false, "message" => ""
        ];

        // The unit on line 2 counts for matching, if line 1 doesn't have one. A bare "456" or "FDA" there
        // only counts if it turns out to be one of the units at the address.
        $unitOnLine2 = $line2["type"] == "secondary" ? $line2["secondary"] : null;
        if ($line2["type"] == "other" && preg_match('/^[0-9A-Z][0-9A-Z\-]{0,11}$/', $line2["text"])) {
            $unitOnLine2 = ["designator" => "#", "id" => $line2["text"], "text" => "# " . $line2["text"], "implicit" => true];
        }
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
        $unitNote = "";
        if ($secondary !== null && !empty($secondary["implicit"]) && !$match["unitInRange"]) {
            // A bare "456" on line 2 that isn't a unit here: leave line 2 as it was
            $secondary = null;
        }
        if ($secondary !== null) {
            // "# 4" or "LOT 4" where the units are TRLRs: use the file's designator, unless # fits more than one
            $designator = $secondary["designator"];
            if ($match["unitInRange"] && $row["AddressSecAbbr"] !== "" && !$match["unitAmbiguous"]) {
                $designator = $row["AddressSecAbbr"];
            }
            $head = trim($secondary["designator"] . " " . $secondary["id"]);
            $secondaryText = trim("$designator $secondary[id]") . substr($secondary["text"], strlen($head));
            if (!$match["unitInRange"] && $match["inRange"]) {
                $unitNote = "Unit not found at this address";
            }
        } else if ($match["unitFromCompany"]) {
            // The company is one of the units here: add the unit, since the +4 depends on it
            $secondaryText = trim($row["AddressSecAbbr"] . " " . $row["AddressSecLowNumber"]);
            $secondary = ["designator" => $row["AddressSecAbbr"], "id" => $row["AddressSecLowNumber"], "text" => $secondaryText];
        }
        $secondaryOnLine1 = $secondary !== null && ($secondary !== $unitOnLine2 || $match["unitFromCompany"]);

        switch ($line1["type"]) {
            case "street":
                $number = $match["number"] ?? $line1["number"];
                // Puerto Rico writes A-12 as A12
                if (in_array($row["State"], ["PR", "VI"]) && preg_match('/^[A-Z]-\d/', $number)) {
                    $number = str_replace("-", "", $number);
                }
                $number = self::fileNumberFormat($number, $row["AddressPrimaryLowNumber"]);
                $result["street1"] = self::joinParts($number, $line1["fraction"], $street["pre"], $street["name"], $street["suffix"], $street["post"], $secondaryOnLine1 ? $secondaryText : "");
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
        // Words after the street that weren't part of it: the city, state and ZIP typed again are dropped,
        // anything else (a neighborhood, a note) moves to line 2 rather than being lost
        $extra = trim($match["extra"] ?? "");
        if ($extra !== "") {
            $names = [$city, $result["city"], $row["State"], $state, $zip, $row["ZipCode"]];
            foreach ($this->zipRows($row["ZipCode"]) as $zr) {
                array_push($names, $zr["City"], $zr["CityAliasName"], $zr["CityAliasAbbreviation"]);
            }
            $names = array_unique(array_filter(array_map(fn($n) => trim($n ?? ""), $names), fn($n) => $n !== ""));
            usort($names, fn($a, $b) => strlen($b) <=> strlen($a));
            foreach ($names as $n) {
                $extra = trim(preg_replace('/(^| )' . preg_quote($n, "/") . '( |$)/', " ", " $extra "));
            }
            $extra = trim(preg_replace('/^(\d{5}(-?\d{4})?|USA|US)$/', "", trim(preg_replace('/\s+/', " ", $extra))));
            if ($extra !== "") {
                $result["street2"] = trim($result["street2"] . " " . $extra);
            }
        }
        $result["lacs"] = ($row["LACSStatus"] ?? "") == "L";
        if ($result["match"] == self::MATCH_EXACT) {
            $result["carrier_route"] = $row["CarrierRoute"];
            $result["message"] = $match["unitAmbiguous"] ? "Unit matches more than one kind of unit here; add the designator" : $unitNote;
            if ($row["Plus4Low"] !== "" && $row["Plus4Low"] === $row["Plus4High"] && ($row["RecordType"] != "F" || $match["firmMatched"]) && !$match["unitAmbiguous"]) {
                $result["plus4"] = $row["Plus4Low"];
            } else if ($row["RecordType"] == "P" && preg_match('/^\d{4}$/', $row["Plus4Low"]) && preg_match('/^\d{4}$/', $row["Plus4High"]) && preg_match('/^\d+$/', $line1["number"])) {
                // A range of PO boxes with a matching range of +4s (boxes 616700-616799 are +4 6700-6799): count along
                $lo = self::numberParts($row["AddressPrimaryLowNumber"]);
                $hi = self::numberParts($row["AddressPrimaryHighNumber"]);
                if ($lo !== null && $hi !== null && (int) $row["Plus4High"] - (int) $row["Plus4Low"] == $hi[1] - $lo[1]) {
                    $result["plus4"] = sprintf("%04d", (int) $row["Plus4Low"] + ((int) $line1["number"] - $lo[1]));
                }
            }
        } else {
            $result["message"] = $line1["number"] === "" ? "No house number" : "Number not in any range for this street";
        }
        return $result;
    }
}
