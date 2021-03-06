<?php
require_once('include/config.php');

require_once('vendor/autoload.php');
require_once('include/database.php');
require_once('ASAPI.php');

$asapi = new ASAPI([
    'username' => $username,
    'password' => $password,
]);

$nodes_all = $asapi->call('nodes?b');
//$nodes_all = [0 => ['id' => 195], 1 => ['id' => 824], 2 => ['id' => 472]];
//$nodes_all = [0 => ['id' => 195]];
//$config['debug'] = 1;

echo "Fetching Node list...\n";
$nodes_data = [];
foreach($nodes_all as $node_all) {
    if(@$node_id = $node_all['id']) {
        // Get node data
        $data = $asapi->call("nodes/$node_id");
        if($data !== false) {
            echo "Processing " . $data['id'] . ' ' . $data['name'] . "\n";

            // Build subnets array
            $subnets_data = [];
            $nodes_subnets_data = [];
            foreach ($data['hosts'] as $host) {
                $subnets_data[$host['subnet']['id']] = [
                    'id' => $host['subnet']['id'],
                    'addr' => ip2long($host['subnet']['addr']),
                    'mask' => $host['subnet']['mask'],
                    'type' => $host['subnet']['type'],
                ];
            }
            $subnets_data = array_values($subnets_data);

            // Build hosts and host_aliases arrays
            $hosts_data = [];
            $host_aliases_data = [];
            foreach ($data['hosts'] as $host) {
                $hosts_data[$host['id']] = [
                    'id' => $host['id'],
                    'node_id' => $host['node_id'],
                    'subnet_id' => $host['subnet']['id'],
                    'name' => $host['name'],
                    'fqdn' => $host['fqdn'],
                    'addr' => ip2long($host['addr']),
                ];

                foreach ($host['aliases'] as $host_alias) {
                    $host_aliases_data[$host_alias['id']] = [
                        'id' => $host_alias['id'],
                        'host_id' => $host['id'],
                        'name' => $host_alias['name'],
                    ];
                }
            }
            $hosts_data = array_values($hosts_data);
            $host_aliases_data = array_values($host_aliases_data);

            // Build interfaces array
            $interfaces_data = [];
            $interfaces_links_data = [];
            foreach ($data['devices'] as $device) {
                foreach ($device['interfaces'] as $interface) {
                    // Only support AP, Client and Backbone interfaces modes
                    if (($interface['radio']['mode'] == 'AP' || $interface['radio']['mode'] == 'CL' || $interface['radio']['mode'] == 'BB')) {
                        // Build interface_links array
                        $link_ids = [];
                        if(@sizeof($interface['hosts'][0]['links'])) {
                            foreach ($interface['hosts'][0]['links'] as $host_link) {
                                $link_ids[] = $host_link['id'];
                            }
                        }
                        $interfaces_links_data[$interface['id']] = [
                            'id' => $interface['id'],
                            'link_ids' => $link_ids,
                        ];

                        // Build interfaces_data array
                        $interfaces_data[$interface['id']] = [
                            'id' => $interface['id'],
                            'host_id' => @$interface['hosts'][0]['id'] ? $interface['hosts'][0]['id'] : NULL,
                            'type' => $interface['type'],
                            'ssid' => $interface['radio']['ssid'],
                            'mode' => $interface['radio']['mode'],
                            'protocol' => $interface['radio']['band'],
                            'freq' => $interface['radio']['freq'],
                            'passphrase' => @$interface['radio']['nwkey'] ? $interface['radio']['nwkey'] : '',
                        ];
                    }
                }
            }
            $interfaces_data = array_values($interfaces_data);
            $interfaces_links_data = array_values($interfaces_links_data);

            // Build links array
            $links_data = [];
            foreach ($data['links'] as $link) {
                $links_data[$link['id']] = [
                    'id' => $link['id'],
                    'name' => $link['name'],
                    'type' => $link['type'],
                    'freq' => $link['freq'],
                ];
            }
            $links_data = array_values($links_data);

            // Build Node Data array
            $node_data = [
                'node' => [
                    'id' => $data['id'],
                    'suburb_id' => isset($data['suburb']['id']) ? $data['suburb']['id'] : 0,
                    'user_id' => isset($data['manager']['id']) ? $data['manager']['id'] : 0,
                    'status_id' => isset($data['status']['id']) ? $data['status']['id'] : 0,
                    'name' => $data['name'],
                    'region' => $data['region'],
                    'zone' => $data['zone'],
                    'lat' => $data['lat'],
                    'lng' => $data['lng'],
                    'elevation' => $data['elevation'],
                    'antHeight' => $data['antHeight'],
                    'asNum' => $data['asNum'],
                ],
                'suburb' => [
                    'id' => isset($data['suburb']['id']) ? $data['suburb']['id'] : 0,
                    'name' => isset($data['suburb']['id']) ? $data['suburb']['name'] : 'UNSET',
                    'state' => isset($data['suburb']['id']) ? $data['suburb']['state'] : 'UNSET',
                    'postcode' => isset($data['suburb']['id']) ? $data['suburb']['postcode'] : 0,
                ],
                'user' => [
                    'id' => isset($data['manager']['id']) ? $data['manager']['id'] : 0,
                    'name' => isset($data['manager']['id']) ? $data['manager']['username'] : 'UNSET',
                ],
                'status' => [
                    'id' => isset($data['status']['id']) ? $data['status']['id'] : 0,
                    'name' => isset($data['status']['status']) ? $data['status']['status'] : 'UNKNOWN',
                ],
                'subnets' => $subnets_data,
                'hosts' => $hosts_data,
                'host_aliases' => $host_aliases_data,
                'links' => $links_data,
                'interfaces' => $interfaces_data,
                'interfaces_links' => $interfaces_links_data,
            ];

            // Add to $nodes_data
            $nodes_data[] = $node_data;
        }
    }
}

// Output test data
if($config['debug']) {
    sU::debug($nodes_data);
    exit();
}

// Update database with node data
foreach ($nodes_data as $node_data) {
    echo "Updating " . $node_data['node']['id'] . ' ' . $node_data['node']['name'] . "\n";

    // Node
    $user = User::updateOrCreate(['id' => $node_data['user']['id']], $node_data['user']);
    $status = Status::updateOrCreate(['id' => $node_data['status']['id']], $node_data['status']);
    $suburb = Suburb::updateOrCreate(['id' => $node_data['suburb']['id']], $node_data['suburb']);
    $node = Node::updateOrCreate(['id' => $node_data['node']['id']], $node_data['node']);

    // Subnets
    foreach($node_data['subnets'] as $subnet_data) {
        $subnet = Subnet::updateOrCreate(['id' => $subnet_data['id']], $subnet_data);

        // Associate subnet with Node
        $node = Node::find($node_data['node']['id']);
        $subnet = Subnet::find($subnet_data['id']);
        $node->subnet()->syncWithoutDetaching($subnet);
    }

    // Hosts
    foreach($node_data['hosts'] as $host_data) {
        $host = Host::updateOrCreate(['id' => $host_data['id']], $host_data);
    }

    // HostsAliases
    foreach($node_data['host_aliases'] as $host_alias_data) {
        $host_alias = HostAlias::updateOrCreate(['id' => $host_alias_data['id']], $host_alias_data);
    }

    // Interfaces
    foreach($node_data['interfaces'] as $interface_data) {
        $interface = NetworkInterface::updateOrCreate(['id' => $interface_data['id']], $interface_data);
    }

    // Links
    foreach($node_data['links'] as $link_data) {
        $link = Link::updateOrCreate(['id' => $link_data['id']], $link_data);

        // Associate link with node
        $node = Node::find($node_data['node']['id']);
        $link = Link::find($link_data['id']);
        $node->link()->syncWithoutDetaching($link);
    }

    // Associate Interfaces with Links
    foreach($node_data['interfaces_links'] as $interfaces_link_data) {
        foreach($interfaces_link_data['link_ids'] as $link_id) {
            $node = Node::find($node_data['node']['id']);
            $link = Link::find($link_id);

            $link_node = $node->link->find($link_id)->pivot;
            $link_node->interface_id = $interfaces_link_data['id'];
            $link_node->save();
        }
    } 
}

echo "Done.\n";
?>
