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

/*
 * mdns-bridge service control for OPNsense.
 *
 * usage: mdnsbridge_ctl.php start|stop|restart|status|carp|showconfig
 *
 * Wraps the FreeBSD port's rc script (/usr/local/etc/rc.d/mdns_bridge) and
 * adds one rule: when a CARP dependency is configured, the daemon may only
 * run while this node is MASTER for that VIP.
 *
 * How the gate works:
 *   - rc.conf.d sets required_files=MARKER when a CARP dependency is set.
 *     rc.subr refuses "start" (not "stop") while MARKER is missing, so a
 *     boot-time start on a BACKUP node is blocked even before this script
 *     runs.
 *   - This script creates MARKER only while the VIP is MASTER and removes
 *     it otherwise, then starts or stops the daemon to match.
 *   - "start" on a node that must not run exits 0 and leaves the daemon
 *     stopped, so Apply on the BACKUP node does not report an error.
 *
 * Every invocation re-reads the current CARP state rather than trusting the
 * event that triggered it, and a lock serialises concurrent invocations
 * (for example a CARP event arriving while Apply is running).
 */

require_once('config.inc');
require_once('util.inc');
require_once('interfaces.inc');

use OPNsense\Interfaces\Vip;
use OPNsense\MDNSBridge\MDNSBridge;

const MDNSBRIDGE_RC = '/usr/local/etc/rc.d/mdns_bridge';
const MDNSBRIDGE_CONF = '/usr/local/etc/mdns-bridge.conf';
const MDNSBRIDGE_MARKER = '/var/run/mdns_bridge.carp_master';
const MDNSBRIDGE_LOCK = '/var/run/mdns_bridge.ctl.lock';

openlog('mdns-bridge-ctl', LOG_PID, LOG_DAEMON);

function ctl_log(string $message, int $priority = LOG_NOTICE): void
{
    syslog($priority, $message);
}

/**
 * Run the rc script. Returns [exit code, combined output].
 *
 * mdns-bridge backgrounds itself with fork()/setsid() but does not close or
 * redirect stdin/stdout/stderr, so the daemon inherits whatever they point
 * to. If that were exec()'s output pipe, the pipe would never reach EOF and
 * exec() would wait forever (which hung "configctl mdnsbridge start").
 * Output therefore goes to a temporary file and stdin comes from /dev/null.
 */
function rc(string $action): array
{
    $tmp = tempnam('/tmp', 'mdnsbridge.');
    $ignored = [];
    $code = 0;
    exec(sprintf(
        '%s %s < /dev/null > %s 2>&1',
        MDNSBRIDGE_RC,
        escapeshellarg($action),
        escapeshellarg($tmp)
    ), $ignored, $code);
    $output = trim((string)@file_get_contents($tmp));
    @unlink($tmp);
    return [$code, $output];
}

function is_running(): bool
{
    return rc('onestatus')[0] === 0;
}

/**
 * CARP state of the configured dependency.
 *
 * @return string|null null when no dependency is configured, otherwise
 *                     MASTER, BACKUP, INIT, UNKNOWN or MISSING (VIP gone)
 */
function carp_state(MDNSBridge $model): ?string
{
    $uuid = (string)$model->general->carp_depend_on;
    if ($uuid === '') {
        return null;
    }
    foreach ((new Vip())->vip->iterateItems() as $vipUuid => $vip) {
        if ($vipUuid !== $uuid || !$vip->mode->isEqual('carp')) {
            continue;
        }
        $device = get_real_interface((string)$vip->interface);
        $vhid = (string)$vip->vhid;
        if (empty($device) || $vhid === '') {
            return 'UNKNOWN';
        }
        $details = legacy_interfaces_details($device);
        return $details[$device]['carp'][$vhid]['status'] ?? 'UNKNOWN';
    }
    return 'MISSING';
}

/**
 * Decide whether the daemon may run, and set the rc.subr marker to match.
 *
 * @return array [bool may run, string|null CARP state]
 */
function evaluate(MDNSBridge $model): array
{
    $state = carp_state($model);
    $mayRun = $model->isServiceEnabled() && ($state === null || $state === 'MASTER');

    if ($state !== null && $mayRun) {
        if (!@touch(MDNSBRIDGE_MARKER)) {
            ctl_log('cannot create ' . MDNSBRIDGE_MARKER, LOG_ERR);
            $mayRun = false;
        }
    } else {
        @unlink(MDNSBRIDGE_MARKER);
    }

    if ($state === 'MISSING') {
        ctl_log('the configured CARP dependency no longer exists; not running. Select a CARP VIP in mDNS Bridge settings.', LOG_ERR);
    }

    return [$mayRun, $state];
}

function do_stop(): int
{
    if (is_running()) {
        [$code, $out] = rc('onestop');
        if ($code !== 0) {
            echo $out . PHP_EOL;
            return $code;
        }
    }
    return 0;
}

function do_start(MDNSBridge $model): int
{
    [$mayRun, $state] = evaluate($model);

    if (!$model->isServiceEnabled()) {
        /* Nothing to start; make sure nothing is left running either. */
        do_stop();
        return 0;
    }

    if (!$mayRun) {
        do_stop();
        ctl_log(sprintf('CARP dependency is %s; staying in standby', $state));
        return 0;
    }

    if (is_running()) {
        return 0;
    }

    /*
     * The template omits the interfaces line when fewer than two of the
     * selected interfaces exist and are enabled; say so instead of letting
     * the daemon fail with a less obvious message.
     */
    $conf = @file_get_contents(MDNSBRIDGE_CONF);
    if ($conf === false || !preg_match('/^\s*interfaces\s*=/m', $conf)) {
        $message = 'fewer than two of the selected interfaces exist and are enabled; check the Interfaces tab';
        ctl_log($message, LOG_ERR);
        echo 'mdns-bridge: ' . $message . PHP_EOL;
        return 1;
    }

    [$code, $out] = rc('start');
    if ($code !== 0) {
        echo $out . PHP_EOL;
        echo 'See Services: mDNS Bridge: Log File for the reason.' . PHP_EOL;
    }
    return $code;
}

function do_carp(MDNSBridge $model): int
{
    [$mayRun, $state] = evaluate($model);
    if ($state === null) {
        /* No dependency configured: CARP events are not our concern. */
        return 0;
    }

    $running = is_running();
    if ($mayRun && !$running) {
        ctl_log(sprintf('CARP dependency is %s; starting', $state));
        return do_start($model);
    }
    if (!$mayRun && $running) {
        ctl_log(sprintf('CARP dependency is %s; stopping', $state));
        return do_stop();
    }
    return 0;
}

$action = $argv[1] ?? '';

if ($action === 'status') {
    /* Read-only, no lock needed. Output is parsed by the service controller. */
    echo rc('onestatus')[1] . PHP_EOL;
    exit(0);
}

if ($action === 'showconfig') {
    $conf = @file_get_contents(MDNSBRIDGE_CONF);
    echo $conf === false ? "# mdns-bridge.conf has not been generated yet. Press Apply.\n" : $conf;
    exit(0);
}

if (!in_array($action, ['start', 'stop', 'restart', 'carp'], true)) {
    fwrite(STDERR, "usage: mdnsbridge_ctl.php start|stop|restart|status|carp|showconfig\n");
    exit(2);
}

$lock = fopen(MDNSBRIDGE_LOCK, 'c');
if ($lock === false || !flock($lock, LOCK_EX)) {
    ctl_log('cannot acquire ' . MDNSBRIDGE_LOCK, LOG_ERR);
    exit(1);
}

try {
    $model = new MDNSBridge();
    switch ($action) {
        case 'start':
            $result = do_start($model);
            break;
        case 'stop':
            $result = do_stop();
            break;
        case 'restart':
            $result = do_stop();
            if ($result === 0) {
                $result = do_start($model);
            }
            break;
        case 'carp':
            $result = do_carp($model);
            break;
    }
} catch (\Throwable $e) {
    ctl_log('failed: ' . $e->getMessage(), LOG_ERR);
    echo 'mdns-bridge: ' . $e->getMessage() . PHP_EOL;
    $result = 1;
}

flock($lock, LOCK_UN);
fclose($lock);
exit($result);
