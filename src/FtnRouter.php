<?php
namespace BinktermPHP;


// --- Usage Example ---
/*
$router = new FtnRouter();

// Define which zones/nets go to which uplinks
$router->addRoute('1:*\/*', 'fidonet');
$router->addRoute('21:*\/*', 'fsxnet');
$router->addRoute('99:123/*', 'Private_BBS_Link'); // Route specifically for Net 123 in Zone 99

$destinations = [
    'user@21:1/100',
    'sysop@1:261/38',
    'foo@99:123/43',
    'lost@77:7/7'
];

echo "Routing Results:\n";
foreach ($destinations as $dest) {
    $uplink = $router->routeAddress($dest) ?? "REJECTED/UNKNOWN";
    echo "Destination: $dest -> Route to: $uplink\n";
}*/


use PDO;

class FtnRouter {
    /**
     * Routing table: [ 'pattern' => 'uplink_name' ]
     * Patterns follow Zone:Net/Node format. Use '*' as a wildcard.
     */
    private array $routingTable = [];

    public function __construct(array $initialRoutes = [])
    {
        $this->routingTable = $initialRoutes;
    }


    /**
     * Adds a route to the table.
     */
    public function addRoute(string $pattern, string $uplinkName): void {
        $this->routingTable[$pattern] = $uplinkName;
    }

    public function getCrashAddress($address)
    {
        $db = \BinktermPHP\Database::getInstance()->getPdo();
        // 1:153/123
        $node = explode(":", $address);
        $local = explode("/");
        $zone=$node[0];
        $net = $node[1];
        $node = $local[0];
        $point = (int)@$local[1]; // not used?

        $sql = $db->prepare("SELECT * FROM nodelist WHERE zone=? AND net=? AND node=? AND point=?");
        $sql->execute([
            $zone,
            $net,
            $node,
            $point
        ]);
        $rows=$sql->fetchall(PDO::FETCH_ASSOC);
        if(!empty($rows)){
            // TODO: 1) the node list needs to actually contain the ip address of the nodes!
            // 2) Fill this in

        }
    }
    /**
     * Resolves an address to an uplink.
     * Logic: Specificity wins (Point > Node > Net > Zone).
     * Supports addresses with optional point: Zone:Net/Node or Zone:Net/Node.Point
     */
    public function routeAddress(string $address, $crash = false): ?string {
        // Normalize address: Strip 'foo@' if present
        if (strpos($address, '@') !== false) {
            $address = explode('@', $address)[1];
        }

        // Parse target: Zone:Net/Node or Zone:Net/Node.Point
        if (!preg_match('/^(\d+):(\d+)\/(\d+)(?:\.(\d+))?$/', $address, $matches)) {
            return null; // Invalid address format
        }

        $z = $matches[1]; // Zone
        $n = $matches[2]; // Net
        $f = $matches[3]; // Node
        $p = $matches[4] ?? '0'; // Point (default to 0 if not specified)

        // Define search patterns in order of specificity
        $searchPatterns = [];

        // Point-specific patterns (even for .0)
        $searchPatterns[] = "$z:$n/$f.$p";  // Specific Point (e.g. 1:123/456.78 or 1:123/456.0)
        $searchPatterns[] = "$z:$n/$f.*";   // Any Point on this Node (e.g. 1:123/456.*)

        // Node and broader patterns
        $searchPatterns[] = "$z:$n/$f";   // Specific Node (e.g. 21:1/100)
        $searchPatterns[] = "$z:$n/*";    // Any Node in this Net (e.g. 21:1/*)
        $searchPatterns[] = "$z:*/*";     // Any Net in this Zone (e.g. 21:*/*)
        $searchPatterns[] = "*:*/*";      // Default route

        foreach ($searchPatterns as $pattern) {
            if (isset($this->routingTable[$pattern])) {
                return $this->routingTable[$pattern];
            }
        }

        return null; // No route found
    }

    public function getRoutes()
    {
        return $this->routingTable;
    }
}

