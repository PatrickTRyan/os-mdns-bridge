#!/usr/local/bin/php
<?php

/*
 * Copyright (C) 2026 Pat Ryan
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice,
 * this list of conditions and the following disclaimer.
 *
 * 2. Redistributions in binary form must reproduce the above copyright
 * notice, this list of conditions and the following disclaimer in the
 * documentation and/or other materials provided with the distribution.
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

require_once('config.inc');
require_once('util.inc');
require_once('interfaces.inc');

const MDNSBRIDGE_CARP_MARKER = '/var/run/mdns_bridge.CARP_MASTER';
const MDNSBRIDGE_CARP_LOCK = '/var/run/mdns_bridge.carp.lock';

/**
 * Write a message to the system log.
 */
function mdnsbridge_carp_log(string $message): void
{
    openlog('mdnsbridge-carp', LOG_PID, LOG_DAEMON);
    syslog(LOG_NOTICE, $message);
    closelog();
}

/**
 * Return configured logical interface names.
 *
 * @return string[]
 */
function mdnsbridge_configured_interfaces(
    \OPNsense\MDNSBridge\MDNSBridge $model
): array {
    return array_values(array_filter(array_map(
        'trim',
        explode(',', (string)$model->interfaces)
    )));
}

/**
 * Return true if a selected interface currently has a CARP MASTER VIP.
 */
function mdnsbridge_has_master_vip(
    \OPNsense\MDNSBridge\MDNSBridge $model
): bool {
    global $config;

    foreach (mdnsbridge_configured_interfaces($model) as $friendlyInterface) {
        $physicalInterface =
            $config['interfaces'][$friendlyInterface]['if'] ?? '';

        if (!is_string($physicalInterface) || $physicalInterface === '') {
            mdnsbridge_carp_log(
                sprintf(
                    'Unable to resolve selected interface "%s".',
                    $friendlyInterface
                )
            );
            continue;
        }

        /*
         * Permit valid FreeBSD device names such as hn1, vtnet1, vlan01,
         * lagg0, bridge0, and pppoe0.
         */
        if (
            preg_match(
                '/^[A-Za-z0-9_.:-]+$/D',
                $physicalInterface
            ) !== 1
        ) {
            mdnsbridge_carp_log(
                sprintf(
                    'Ignoring invalid device name "%s" for interface "%s".',
                    $physicalInterface,
                    $friendlyInterface
                )
            );
            continue;
        }

        $command = sprintf(
            '/sbin/ifconfig %s 2>/dev/null',
            escapeshellarg($physicalInterface)
        );

        $output = shell_exec($command);

        if (
            is_string($output) &&
            preg_match('/^\s*carp:\s+MASTER\b/m', $output) === 1
        ) {
            return true;
        }
    }

    return false;
}
/**
 * Create or remove the CARP marker and optionally manage the service.
 */
function mdnsbridge_reconcile(bool $manageService): int
{
    /** @var \OPNsense\MDNSBridge\MDNSBridge $model */
    $model = new \OPNsense\MDNSBridge\MDNSBridge();

    $enabled = (string)$model->enabled === '1';
    $carpEnabled = (string)$model->enablecarp === '1';

    /*
     * Without CARP integration, the normal OPNsense service controller
     * determines whether the service should run. Remove any stale marker.
     */
    if (!$enabled || !$carpEnabled) {
        @unlink(MDNSBRIDGE_CARP_MARKER);

        if ($manageService && !$enabled) {
            $backend = new \OPNsense\Core\Backend();
            $backend->configdRun('mdnsbridge stop');
        }

        return 0;
    }

    $isMaster = mdnsbridge_has_master_vip($model);

    if ($isMaster) {
        if (!touch(MDNSBRIDGE_CARP_MARKER)) {
            mdnsbridge_carp_log(
                'Unable to create CARP MASTER marker: ' .
                MDNSBRIDGE_CARP_MARKER
            );
            return 1;
        }

        mdnsbridge_carp_log(
            'At least one selected interface has a CARP MASTER VIP.'
        );

        if ($manageService) {
            $backend = new \OPNsense\Core\Backend();
            $backend->configdRun('mdnsbridge start');
        }

        return 0;
    }

    @unlink(MDNSBRIDGE_CARP_MARKER);

    mdnsbridge_carp_log(
        'No selected interface has a CARP MASTER VIP.'
    );

    if ($manageService) {
        $backend = new \OPNsense\Core\Backend();
        $backend->configdRun('mdnsbridge stop');
    }

    return 0;
}

$manageService = in_array('--service', $argv, true);

$lockHandle = fopen(MDNSBRIDGE_CARP_LOCK, 'c');

if ($lockHandle === false) {
    mdnsbridge_carp_log('Unable to open the CARP reconciliation lock.');
    exit(1);
}

if (!flock($lockHandle, LOCK_EX)) {
    mdnsbridge_carp_log('Unable to acquire the CARP reconciliation lock.');
    fclose($lockHandle);
    exit(1);
}

try {
    $result = mdnsbridge_reconcile($manageService);
} catch (\Throwable $error) {
    mdnsbridge_carp_log(
        sprintf('CARP reconciliation failed: %s', $error->getMessage())
    );
    $result = 1;
}

flock($lockHandle, LOCK_UN);
fclose($lockHandle);

exit($result);
