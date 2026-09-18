<?php
// Line 74 = "public static function encodeAccess(...)" — signature line.
// Infection maps default-param mutants to the SIGNATURE line attribution.
// If signature lines have no attribution, default-param mutants are "not covered".
$xml = simplexml_load_file(__DIR__ . '/../coverage/phpunit-xml/Auth/JWT.php.xml');
$xml->registerXPathNamespace('d', 'https://schema.phpunit.de/coverage/1.0');
$attributed = [];
foreach ($xml->xpath('//d:coverage/d:line') as $l) {
    $attributed[(int) $l['nr']] = true;
}
for ($n = 70; $n <= 112; $n++) {
    echo str_pad((string) $n, 5), isset($attributed[$n]) ? 'attributed' : 'NOT attributed', PHP_EOL;
}
