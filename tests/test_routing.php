#!/usr/bin/env php
<?php
/**
 * Routing validation test
 *
 * Tests FtnRouter specificity and BinkpConfig uplink-selection logic to ensure
 * more specific routes override wildcards regardless of uplink order in config.
 *
 * Run: php tests/test_routing.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\FtnRouter;

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

$pass = 0;
$fail = 0;

function assert_route(FtnRouter $rt, string $address, ?string $expected, string $label): void
{
    global $pass, $fail;
    $got = $rt->routeAddress($address);
    $ok  = ($got === $expected);
    if ($ok) {
        $pass++;
        echo "  PASS  $label\n";
        echo "        $address  →  " . ($got ?? 'null') . "\n";
    } else {
        $fail++;
        echo "  FAIL  $label\n";
        echo "        $address  expected=" . ($expected ?? 'null') . "  got=" . ($got ?? 'null') . "\n";
    }
}

/**
 * Simulate BinkpConfig::getUplinkForDestination() as fixed:
 * one combined router, most-specific pattern wins.
 */
function uplinkForDestination(array $uplinks, string $destAddr): ?array
{
    $rt = new FtnRouter();
    foreach ($uplinks as $uplink) {
        foreach ($uplink['networks'] as $network) {
            $rt->addRoute($network, $uplink['address']);
        }
    }

    $matchedAddress = $rt->routeAddress($destAddr);
    if ($matchedAddress === null) {
        return null;
    }

    foreach ($uplinks as $uplink) {
        if ($uplink['address'] === $matchedAddress) {
            return $uplink;
        }
    }
    return null;
}

/**
 * Simulate the OLD (broken) behaviour: first uplink that matches any pattern wins.
 */
function uplinkForDestinationBroken(array $uplinks, string $destAddr): ?array
{
    foreach ($uplinks as $uplink) {
        $rt = new FtnRouter();
        foreach ($uplink['networks'] as $network) {
            $rt->addRoute($network, $uplink['address']);
        }
        if ($rt->routeAddress($destAddr) !== null) {
            return $uplink;
        }
    }
    return null;
}

function assert_uplink(array $uplinks, string $address, ?string $expectedAddr, string $label): void
{
    global $pass, $fail;
    $uplink = uplinkForDestination($uplinks, $address);
    $got    = $uplink['address'] ?? null;
    $ok     = ($got === $expectedAddr);
    if ($ok) {
        $pass++;
        echo "  PASS  $label\n";
        echo "        $address  →  " . ($got ?? 'null') . "\n";
    } else {
        $fail++;
        echo "  FAIL  $label\n";
        echo "        $address  expected=" . ($expectedAddr ?? 'null') . "  got=" . ($got ?? 'null') . "\n";
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Section 1: FtnRouter — address parsing
// ─────────────────────────────────────────────────────────────────────────────

echo "\n1. Address parsing\n";
echo "─────────────────────────────────────────────────────────────────────────\n";

$rt = new FtnRouter();
$rt->addRoute('1:*/*', 'fidonet');

assert_route($rt, '1:100/200',    'fidonet', '4D address matches zone wildcard');
assert_route($rt, '1:100/200.0',  'fidonet', '5D point .0 matches zone wildcard');
assert_route($rt, '1:100/200.5',  'fidonet', '5D point .5 matches zone wildcard');
assert_route($rt, '2:100/200',    null,       'Different zone returns null');
assert_route($rt, 'bad-address',  null,       'Invalid address returns null');

// Domain-qualified address (foo@zone:net/node)
$rt2 = new FtnRouter();
$rt2->addRoute('21:*/*', 'fsxnet');
assert_route($rt2, 'sysop@21:1/100', 'fsxnet', 'Domain-prefix stripped before routing');

// ─────────────────────────────────────────────────────────────────────────────
// Section 2: FtnRouter — specificity ordering (within one router)
// ─────────────────────────────────────────────────────────────────────────────

echo "\n2. Specificity ordering within a single router\n";
echo "─────────────────────────────────────────────────────────────────────────\n";

$rt = new FtnRouter();
$rt->addRoute('*:*/*',         'default');
$rt->addRoute('1:*/*',         'zone1');
$rt->addRoute('1:123/*',       'net123');
$rt->addRoute('1:123/456',     'node456');
$rt->addRoute('1:123/456.78',  'point78');

assert_route($rt, '2:999/1',       'default',  'Unmatched zone falls to default');
assert_route($rt, '1:999/1',       'zone1',    'Zone wildcard beats default');
assert_route($rt, '1:123/1',       'net123',   'Net route beats zone wildcard');
assert_route($rt, '1:123/456',     'node456',  'Node route beats net wildcard');
assert_route($rt, '1:123/456.0',   'node456',  'Point .0 resolves to node route');
assert_route($rt, '1:123/456.78',  'point78',  'Specific point beats node route');
assert_route($rt, '1:123/456.99',  'node456',  'Non-configured point falls to node route');

// ─────────────────────────────────────────────────────────────────────────────
// Section 3: FtnRouter — point wildcard "node.*"
// ─────────────────────────────────────────────────────────────────────────────

echo "\n3. Point wildcard (node.*)\n";
echo "─────────────────────────────────────────────────────────────────────────\n";

$rt = new FtnRouter();
$rt->addRoute('1:*/*',         'zone1');
$rt->addRoute('1:123/456',     'node456');
$rt->addRoute('1:123/456.*',   'allpoints');
$rt->addRoute('1:123/456.5',   'point5');

assert_route($rt, '1:123/456.5',   'point5',    'Specific point beats node.* wildcard');
assert_route($rt, '1:123/456.99',  'allpoints', 'Unknown point matches node.* wildcard');
assert_route($rt, '1:123/789',     'zone1',     'Different node falls to zone wildcard');

// ─────────────────────────────────────────────────────────────────────────────
// Section 4: Multi-uplink — specific route beats wildcard (the fixed bug)
// ─────────────────────────────────────────────────────────────────────────────

echo "\n4. Multi-uplink routing — specific route overrides wildcard\n";
echo "─────────────────────────────────────────────────────────────────────────\n";

// Uplink order: wildcard FIRST (this was the failing case before the fix)
$uplinks = [
    ['address' => '1:1/1',   'me' => '1:100/1',   'networks' => ['1:*/*']],
    ['address' => '1:2/100', 'me' => '1:100/1.1', 'networks' => ['1:123/456.12']],
];

assert_uplink($uplinks, '1:123/456.12', '1:2/100', 'Specific point route beats earlier wildcard uplink');
assert_uplink($uplinks, '1:123/456.5',  '1:1/1',   'Non-configured point falls to wildcard uplink');
assert_uplink($uplinks, '1:200/300',    '1:1/1',   'General zone traffic routes to wildcard uplink');
assert_uplink($uplinks, '2:100/1',      null,       'No matching route returns null');

// Uplink order: specific FIRST (was also correct before; must still work)
$uplinksReversed = array_reverse($uplinks);
assert_uplink($uplinksReversed, '1:123/456.12', '1:2/100', 'Specific point wins even when listed first');
assert_uplink($uplinksReversed, '1:200/300',    '1:1/1',   'Wildcard uplink still catches general traffic');

// ─────────────────────────────────────────────────────────────────────────────
// Section 5: Multi-uplink — zone separation
// ─────────────────────────────────────────────────────────────────────────────

echo "\n5. Multi-zone uplink separation\n";
echo "─────────────────────────────────────────────────────────────────────────\n";

$uplinks = [
    ['address' => 'hub1.fidonet',  'me' => '1:100/1', 'networks' => ['1:*/*']],
    ['address' => 'hub2.fsxnet',   'me' => '21:1/99', 'networks' => ['21:*/*']],
    ['address' => 'hub3.lovly',    'me' => '99:1/5',  'networks' => ['99:*/*']],
];

assert_uplink($uplinks, '1:261/38',   'hub1.fidonet',  'Zone 1 routes to fidonet uplink');
assert_uplink($uplinks, '21:1/100',   'hub2.fsxnet',   'Zone 21 routes to fsxnet uplink');
assert_uplink($uplinks, '99:123/45',  'hub3.lovly',    'Zone 99 routes to lovlynet uplink');
assert_uplink($uplinks, '5:1/1',      null,            'Unknown zone returns null');

// ─────────────────────────────────────────────────────────────────────────────
// Section 6: Demonstrate the OLD broken behaviour for comparison
// ─────────────────────────────────────────────────────────────────────────────

echo "\n6. Old (broken) behaviour comparison\n";
echo "─────────────────────────────────────────────────────────────────────────\n";

$uplinks = [
    ['address' => '1:1/1',   'me' => '1:100/1',   'networks' => ['1:*/*']],
    ['address' => '1:2/100', 'me' => '1:100/1.1', 'networks' => ['1:123/456.12']],
];

$broken = uplinkForDestinationBroken($uplinks, '1:123/456.12');
$fixed  = uplinkForDestination($uplinks, '1:123/456.12');

$brokenAddr = $broken['address'] ?? 'null';
$fixedAddr  = $fixed['address']  ?? 'null';

echo "  Route 1:123/456.12 with wildcard uplink listed first:\n";
echo "    Old result : $brokenAddr  " . ($brokenAddr === '1:1/1'   ? "(wrong — wildcard won)" : "") . "\n";
echo "    New result : $fixedAddr   " . ($fixedAddr  === '1:2/100' ? "(correct — specific wins)" : "") . "\n";

if ($brokenAddr === '1:1/1' && $fixedAddr === '1:2/100') {
    $pass++;
    echo "  PASS  Old behaviour was wrong, new behaviour is correct\n";
} else {
    $fail++;
    echo "  FAIL  Unexpected values — review test\n";
}

// ─────────────────────────────────────────────────────────────────────────────
// Summary
// ─────────────────────────────────────────────────────────────────────────────

$total = $pass + $fail;
echo "\n═══════════════════════════════════════════════════════════════════════════\n";
echo "Results: $pass/$total passed";
if ($fail > 0) {
    echo ", $fail FAILED";
}
echo "\n";

exit($fail > 0 ? 1 : 0);
