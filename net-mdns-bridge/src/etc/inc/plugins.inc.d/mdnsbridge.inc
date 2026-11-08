<?php

function mdnsbridge_services()
{
    global $config;

    $services = [];

    if (
        isset($config['OPNsense']['MDNSBridge']['enabled']) &&
        $config['OPNsense']['MDNSBridge']['enabled'] == '1'
    ) {
        $services[] = [
            'description' => gettext('mDNS Bridge'),
            'configd' => [
                'start' => ['mdnsbridge start'],
                'restart' => ['mdnsbridge restart'],
                'stop' => ['mdnsbridge stop'],
            ],
            'name' => 'mdns_bridge',
            'pidfile' => '/var/run/mdns_bridge.pid',
        ];
    }

    return $services;
}

function mdnsbridge_xmlrpc_sync()
{
    $result = [];

    $result[] = [
        'description' => gettext('mDNS Bridge'),
        'section' => 'OPNsense.MDNSBridge',
        'id' => 'mdns-bridge',
        'services' => ['mdns_bridge'],
    ];

    return $result;
}