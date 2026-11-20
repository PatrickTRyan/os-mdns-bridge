<?php

/*
 * Copyright (C) 2026 Pat Ryan
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice,
 *    this list of conditions and the following disclaimer.
 *
 * 2. Redistributions in binary form must reproduce the above copyright
 *    notice, this list of conditions and the following disclaimer in the
 *    documentation and/or other materials provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
 * INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
 * AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
 * OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 */

function mdnsbridge_enabled()
{
    return (new \OPNsense\MDNSBridge\MDNSBridge())->isServiceEnabled();
}

function mdnsbridge_services()
{
    if (!mdnsbridge_enabled()) {
        return [];
    }

    return [[
        'description' => gettext('mDNS Bridge'),
        'name' => 'mdns_bridge',
        'pidfile' => '/var/run/mdns_bridge.pid',
        'configd' => [
            'restart' => ['mdnsbridge restart'],
            'start' => ['mdnsbridge start'],
            'stop' => ['mdnsbridge stop'],
        ],
    ]];
}

function mdnsbridge_configure()
{
    return [
        'newwanip' => ['mdnsbridge_configure_newwanip:2'],
    ];
}

/**
 * mdns-bridge reads interface addresses once, at startup. When a bridged
 * interface gets a new address (for example an IPv6 prefix through
 * track-interface), restart it so that address family is picked up.
 * Other interfaces, such as WAN DHCP renewals, are ignored.
 *
 * @param bool $verbose
 * @param array $interfaces logical interface names that changed
 */
function mdnsbridge_configure_newwanip($verbose = false, $interfaces = [])
{
    if (!mdnsbridge_enabled()) {
        return;
    }

    $model = new \OPNsense\MDNSBridge\MDNSBridge();
    $bridged = $model->activeInterfaces();
    if (empty(array_intersect((array)$interfaces, $bridged))) {
        return;
    }

    if (!isvalidpid('/var/run/mdns_bridge.pid')) {
        /* Not running here (stopped, or standby on a CARP BACKUP node). */
        return;
    }

    service_log('Restarting mDNS Bridge after an address change...', $verbose);
    configd_run('mdnsbridge restart');
    service_log("done.\n", $verbose);
}

/**
 * Register a syslog-ng destination so the daemon (program "mdns-bridge")
 * and the control script ("mdns-bridge-ctl") log to /var/log/mdnsbridge/.
 * Must match templates/OPNsense/Syslog/local/mdnsbridge.conf.
 */
function mdnsbridge_syslog()
{
    return ['mdnsbridge' => ['facility' => ['mdns-bridge', 'mdns-bridge-ctl']]];
}

function mdnsbridge_xmlrpc_sync()
{
    return [[
        'description' => gettext('mDNS Bridge'),
        'section' => 'OPNsense.MDNSBridge',
        'id' => 'mdns-bridge', /* keep: stored in HA sync selections since 0.1 */
        'services' => ['mdns_bridge'],
    ]];
}
