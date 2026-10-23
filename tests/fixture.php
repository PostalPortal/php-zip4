<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at http://mozilla.org/MPL/2.0/.
 */

/*
 * A tiny ZIP+4 database for testing the matcher: real rows from the zip-codes.com files
 * (APO 34093, Holtsville 00501, Helena firms, Manhattan 10001), plus made-up Helena streets for
 * the cases those don't cover. Same schema as the real file.
 *
 *   $pdo = zip4FixtureDatabase();                 // in memory
 *   zip4FixtureDatabase("sqlite:/tmp/zip4.sqlite"); // or on disk, for the import tool
 */

function zip4FixtureDatabase(string $dsn = "sqlite::memory:"): PDO {
    $db = new PDO($dsn);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec(<<<SQL
CREATE TABLE ZIPCodes (
       ZipCode char(5) NOT NULL, City varchar(35) NULL, State char(2), County varchar(45) NULL,
       AreaCode varchar(55) NULL, CityType char(1) NULL, CityAliasAbbreviation varchar(13) NULL,
       CityAliasName varchar(35) NULL, Latitude decimal(12, 6), Longitude decimal(12, 6), TimeZone char(2) NULL,
       Elevation int, CountyFIPS char(5) NULL, DayLightSaving char(1) NULL, PreferredLastLineKey varchar(10) NULL,
       ClassificationCode char(1) NULL, MultiCounty char(1) NULL, StateFIPS char(2) NULL, CityStateKey char(6) NULL,
       CityAliasCode varchar(5) NULL, PrimaryRecord char(1), CityMixedCase varchar(35) NULL,
       CityAliasMixedCase varchar(35) NULL, StateANSI varchar(2) NULL, CountyANSI varchar(3) NULL,
       FacilityCode varchar(1) NULL, CityDeliveryIndicator varchar(1) NULL, CarrierRouteRateSortation varchar(1) NULL,
       FinanceNumber varchar(6) NULL, UniqueZIPName varchar(1) NULL, CountyMixedCase varchar(45) NULL
);
CREATE TABLE States (code TEXT, name TEXT);
CREATE TABLE IF NOT EXISTS "ZIP4" (
"ZipCode"char(5), "UpdateKey"varchar(10), "Action"char(1), "RecordType"varchar(1), "CarrierRoute"varchar(4),
"StPreDirAbbr"varchar(2), "StName"varchar(28), "StSuffixAbbr"varchar(4), "StPostDirAbbr"varchar(2),
"AddressPrimaryLowNumber"varchar(10), "AddressPrimaryHighNumber"varchar(10), "AddressPrimaryEvenOdd"varchar(1),
"BuildingName"varchar(40), "AddressSecAbbr"varchar(4), "AddressSecLowNumber"varchar(10),
"AddressSecHighNumber"varchar(10), "AddressSecOddEven"varchar(1), "Plus4Low"varchar(4), "Plus4High"varchar(4),
"BaseAlternateCode"varchar(1), "LACSStatus"varchar(1), "GovernmentBuilding"varchar(1), "FinanceNumber"varchar(6),
"State"varchar(2), "CountyFIPS"varchar(3), "CongressionalDistrict"varchar(2), "MunicipalityKey"varchar(6),
"UrbanizationKey"varchar(6), "PreferredLastLineKey"varchar(6), "ToLatitude"decimal(18, 10),
"FromLatitude"decimal(18, 10), "ToLongitude"decimal(18, 10), "FromLongitude"decimal(18, 10),
"CensusTract"varchar(15), "CensusBlock"varchar(15), "TLID"varchar(15), "LatLonMultiMatch"varchar(1),
"StreetFull" varchar(36)
);
SQL);

    $zipcodes = [
        // Real
        "00501|HOLTSVILLE|NY|SUFFOLK|631/934|N||INTERNAL REVENUE SERVICE|40.787725|-73.039341|5|109|103|Y|V13916|U| |36|V14021|G| |Holtsville|Internal Revenue Service|36|103|N|N|C|353910|Y|Suffolk",
        "00501|HOLTSVILLE|NY|SUFFOLK|631/934|P||HOLTSVILLE|40.787725|-73.039341|5|109|103|Y|V13916|U| |36|V13916|G|P|Holtsville|Holtsville|36|103|P|N|C|353910||Suffolk",
        "00601|ADJUNTAS|PR|ADJUNTAS|787/939|P||ADJUNTAS|18.196747|-66.736735|4|1424|001|N|V17135| | |72|V17135||P|Adjuntas|Adjuntas|72|001|P|Y|C|420180||Adjuntas",
        // Made up from here on, in the same shape
        "59601|HELENA|MT|LEWIS AND CLARK|406|P||HELENA|46.59|-112.04|7|4090|049|Y|Z13216|U| |30|Z13216||P|Helena|Helena|30|049|P|Y|C|293978||Lewis and Clark",
        "59602|HELENA|MT|LEWIS AND CLARK|406|P||HELENA|46.69|-111.99|7|3900|049|Y|Z13216|U| |30|Z13216||P|Helena|Helena|30|049|P|Y|C|293978||Lewis and Clark",
        "59602|HELENA|MT|LEWIS AND CLARK|406|A||HELENA VALLEY|46.69|-111.99|7|3900|049|Y|Z13216|U| |30|Z99001|||Helena|Helena Valley|30|049|A|Y|C|293978||Lewis and Clark",
        "59635|EAST HELENA|MT|LEWIS AND CLARK|406|P|E HELENA|EAST HELENA|46.58|-111.91|7|3900|049|Y|Z13300|U| |30|Z13300||P|East Helena|East Helena|30|049|P|Y|C|293980||Lewis and Clark",
        "10001|NEW YORK|NY|NEW YORK|212|P||NEW YORK|40.75|-73.99|5|30|061|Y|V14966|U| |36|V14966||P|New York|New York|36|061|P|Y|C|355825||New York",
        "10001|NEW YORK|NY|NEW YORK|212|N||EMPIRE STATE|40.75|-73.99|5|30|061|Y|V14966|U| |36|V14999|||New York|Empire State|36|061|N|Y|C|355825||New York",
        "34093|APO|AA||||| APO|0|0||0||N|Y20004|M| ||Y20004||P|APO|APO|||M|N|C|590001||"
    ];
    $insert = $db->prepare("INSERT INTO ZIPCodes VALUES (" . implode(",", array_fill(0, 31, "?")) . ")");
    foreach ($zipcodes as $line) {
        $insert->execute(array_map("trim", explode("|", $line)));
    }

    $zip4 = [
        // Real: APO, Helena firms, Manhattan highrises and alpha house numbers
        "34093|Y227762339|A|S|C307||UNIT 100307|||0000002100|0000002199|B||||||0721|0721|B|||590001|AA|||||Y20004|0|0|0|0|||||UNIT 100307",
        "34093|Y227834611|A|S|C257||UNIT 100257|||0000000500|0000000599|B||||||5705|5705|B|||590001|AA|||||Y20004|0|0|0|0|||||UNIT 100257",
        "59601|Z125434639|A|F|C024||5TH|AVE||0000002320|0000002320|E|SMITH SCHOOL||||B|4996|4996|B|||293978|MT|049|02|||Z13216|46.585999|46.586004|-111.999259|-111.999688|001100|1012|202360348||5TH AVE",
        "59601|Z125434640|A|F|C620||8TH|AVE||0000001410|0000001410|E|HISTORIC PRESERVATION||||B|3915|3915|B|||293978|MT|049|02|||Z13216|0|0|0|0|||||8TH AVE",
        "59601|Z125434642|A|F|C620||8TH|AVE||0000001205|0000001205|O|POLITICAL PRACTICES||||B|3922|3922|B|||293978|MT|049|02|||Z13216|0|0|0|0|||||8TH AVE",
        "10001|V119042892|A|H|C019||5TH|AVE||0000000290|0000000290|E|||||B|4511|4511|B|||355825|NY|061|12|||V14966|40.746536|40.745921|-73.985949|-73.986393|007600|2000|59653471||5TH AVE",
        "10001|V119042894|A|H|C019||5TH|AVE||0000000290|0000000290|E||FRNT|00000001|00000001|O|4511|4511|B|||355825|NY|061|12|||V14966|40.746536|40.745921|-73.985949|-73.986393|007600|2000|59653471||5TH AVE",
        "10001|V119042895|A|H|C019||5TH|AVE||0000000292|0000000292|E|||||B|4513|4513|B|||355825|NY|061|12|||V14966|40.746536|40.745921|-73.985949|-73.986393|007600|2000|59653471||5TH AVE",
        "10001|V119042896|A|H|C019||5TH|AVE||0000000292|0000000292|E||FL|00000004|00000004|E|4513|4513|B|||355825|NY|061|12|||V14966|40.746536|40.745921|-73.985949|-73.986393|007600|2000|59653471||5TH AVE",
        "10001|V119042897|A|H|C019||5TH|AVE||0000000292|0000000292|E||FRNT|00000001|00000001|O|4513|4513|B|||355825|NY|061|12|||V14966|40.746536|40.745921|-73.985949|-73.986393|007600|2000|59653471||5TH AVE",
        "10001|V119054162|A|S|C015|W|29TH|ST||245A|245A|B||||||5208|5208|A|||355825|NY|061|12|||V14966|40.749052|40.747855|-73.995749|-73.99291|009500|2001|59653387||W 29TH ST",
        "10001|V121382183|A|S|C013|W|30TH|ST||145A|145A|B||||||4078|4078|B|||355825|NY|061|12|||V14966|40.748469|40.747277|-73.992463|-73.989617|009500|1000|59653403||W 30TH ST",
        "10001|V121382184|A|S|C013|W|30TH|ST||145B|145B|B||||||4098|4098|B|||355825|NY|061|12|||V14966|0|0|0|0|||||W 30TH ST",
        "10001|V121936715|A|S|C009|W|29TH|ST||43A|43A|O||||||4597|4597|B|||355825|NY|061|12|||V14966|0|0|0|0|||||W 29TH ST",
        "10001|V122744080|A|S|C065||8TH|AVE||357A|357A|O||||||4825|4825|B|||355825|NY|061|12|||V14966|0|0|0|0|||||8TH AVE",
        // Made up
        "59601|T1|A|S|C001|N|MAIN|ST||0000000100|0000000198|E||||||1001|1001|B|||293978|MT|049|02|||Z13216|0|0|0|0|||||N MAIN ST",
        "59601|T2|A|S|C001|N|MAIN|ST||0000000101|0000000199|O||||||1002|1002|B|||293978|MT|049|02|||Z13216|0|0|0|0|||||N MAIN ST",
        "59601|T3|A|S|C002|S|MAIN|ST||0000000100|0000000199|B||||||2001|2001|B|||293978|MT|049|02|||Z13216|0|0|0|0|||||S MAIN ST",
        "59601|T4|A|S|C003||LAST CHANCE GULCH|||0000000001|0000000099|B||||||3001|3001|B|||293978|MT|049|02|||Z13216|0|0|0|0|||||LAST CHANCE GULCH",
        "59601|T5|A|S|C003|N|PARK|AVE||0000000300|0000000398|E||||||4000|4000|B|||293978|MT|049|02|||Z13216|0|0|0|0|||||N PARK AVE",
        "59601|T6|A|H|C003|N|PARK|AVE||0000000316|0000000316|E|PARK PLAZA||||B|4100|4100|B|||293978|MT|049|02|||Z13216|0|0|0|0|||||N PARK AVE",
        "59601|T7|A|H|C003|N|PARK|AVE||0000000316|0000000316|E|PARK PLAZA|APT|00000001|00000010|B|4101|4101|B|||293978|MT|049|02|||Z13216|0|0|0|0|||||N PARK AVE",
        "59601|T8|A|H|C003|N|PARK|AVE||0000000316|0000000316|E|PARK PLAZA|APT|00000011|00000020|B|4102|4102|B|||293978|MT|049|02|||Z13216|0|0|0|0|||||N PARK AVE",
        "59601|T9|A|S|C004||US HIGHWAY 12||W|0000001000|0000001999|B||||||5001|5001|B|||293978|MT|049|02|||Z13216|0|0|0|0|||||US HIGHWAY 12 W",
        "59601|T10|A|S|C004||OLD MILL|RD||0000000500|0000000599|B||||||6001|6001|A|||293978|MT|049|02|||Z13216|0|0|0|0|||||OLD MILL RD",
        "59601|T11|A|S|C004||MILL|ST||0000000500|0000000599|B||||||6001|6001|B|||293978|MT|049|02|||Z13216|0|0|0|0|||||MILL ST",
        "59601|T12|A|S|C024||5TH|AVE||0000002300|0000002398|E||||||4990|4990|B|||293978|MT|049|02|||Z13216|0|0|0|0|||||5TH AVE",
        "59601|T13|A|S|C005||SAINT JOHNS|AVE||0000000001|0000000099|B||||||3301|3301|B|||293978|MT|049|02|||Z13216|0|0|0|0|||||SAINT JOHNS AVE",
        "59601|T14|A|P|B001||PO BOX|||0000000001|0000000099|B||||||0001|0001|B|||293978|MT|049|02|||Z13216|0|0|0|0|||||PO BOX",
        "59601|T15|A|P|B001||PO BOX|||0000000100|0000000199|B||||||0100|0100|B|||293978|MT|049|02|||Z13216|0|0|0|0|||||PO BOX",
        "59601|T16|A|R|R001||RR 1|||0000000001|0000000099|B||||||7001|7001|B|L||293978|MT|049|02|||Z13216|0|0|0|0|||||RR 1",
        "59601|T17|A|G|G001||GENERAL DELIVERY|||||||||||9999|9999|B|||293978|MT|049|02|||Z13216|0|0|0|0|||||GENERAL DELIVERY",
        "59601|T18|D|S|C001|N|MAIN|ST||0000000200|0000000298|E||||||1003|1003|B|||293978|MT|049|02|||Z13216|0|0|0|0|||||N MAIN ST",
        "59602|T19|A|S|C010||EUCLID|AVE||0000001000|0000001999|B||||||8001|8001|B|||293978|MT|049|02|||Z13216|0|0|0|0|||||EUCLID AVE",
        "59602|T20|A|S|C010||VALLEY|DR||0000000001|0000000099|B||||||8101|8101|B|||293978|MT|049|02|||Z99001|0|0|0|0|||||VALLEY DR",
        "59635|T21|A|S|C001||MAIN|ST||0000000001|0000000099|B||||||0101|0101|B|||293980|MT|049|02|||Z13300|0|0|0|0|||||MAIN ST",
        // A directional word that's part of the name: only NORTH SLOPE in East Helena, both it and N SLOPE in Helena
        "59635|T22|A|S|C001||NORTH SLOPE|RD||0000001000|0000001999|B||||||6201|6201|B|||293980|MT|049|02|||Z13300|0|0|0|0|||||NORTH SLOPE RD",
        "59601|T23|A|S|C009||NORTH SLOPE|RD||0000001000|0000001999|B||||||6201|6201|B|||293978|MT|049|02|||Z13216|0|0|0|0|||||NORTH SLOPE RD",
        "59601|T24|A|S|C009|N|SLOPE|RD||0000001000|0000001999|B||||||6301|6301|B|||293978|MT|049|02|||Z13216|0|0|0|0|||||N SLOPE RD"
    ];
    $insert = $db->prepare("INSERT INTO ZIP4 VALUES (" . implode(",", array_fill(0, 38, "?")) . ")");
    foreach ($zip4 as $line) {
        $fields = array_map("trim", explode("|", $line));
        if (count($fields) != 38) {
            throw new Exception("Fixture ZIP4 row has " . count($fields) . " fields: $line");
        }
        $insert->execute($fields);
    }
    return $db;
}
