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
 * usage: mdnsbridge_ctl.php start|stop|restart|status|carp|check|showconfig
 *
 *   check  report, before the daemon tries, whether every bridged interface
 *          exists and can take part (has an address in an address family
 *          that at least one other bridged interface also has). Used by
 *          "start" and shown in the GUI when Apply fails.
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

/**
 * Parse the generated mdns-bridge.conf: the bridged devices and which
 * address families are disabled globally or per device.
 *
 * @return array|null ['devices' => string[], 'off' => [section => ['ipv4' => bool, 'ipv6' => bool]]]
 */
function parse_conf(): ?array
{
    $conf = @file_get_contents(MDNSBRIDGE_CONF);
    if ($conf === false) {
        return null;
    }
    $devices = [];
    $off = [];
    $section = '';
    foreach (explode("\n", $conf) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (preg_match('/^\[(.+)\]$/', $line, $m)) {
            $section = trim($m[1]);
            continue;
        }
        if (!preg_match('/^([a-z0-9-]+)\s*=\s*(.*)$/', $line, $m)) {
            continue;
        }
        if ($section === 'global' && $m[1] === 'interfaces') {
            $devices = array_values(array_filter(array_map('trim', explode(',', $m[2])), 'strlen'));
        } elseif (($m[1] === 'disable-ipv4' || $m[1] === 'disable-ipv6') && trim($m[2]) === 'yes') {
            $off[$section][substr($m[1], -4)] = true;
        }
    }
    return ['devices' => $devices, 'off' => $off];
}

/**
 * "vlan0.20 (ManagementAccess)" for messages.
 */
function device_label(string $device): string
{
    $config = \OPNsense\Core\Config::getInstance()->toArray();
    foreach ($config['interfaces'] ?? [] as $name => $iface) {
        if (is_array($iface) && ($iface['if'] ?? '') === $device) {
            $descr = !empty($iface['descr']) ? $iface['descr'] : strtoupper($name);
            return sprintf('%s (%s)', $device, $descr);
        }
    }
    return $device;
}

/**
 * Predict what mdns-bridge will do with each interface, using the same
 * rules as the daemon (socket.c / interface.c in 3.1):
 *   - an interface takes part in a family if that family is not disabled
 *     for it and it has an address in it (link-local counts);
 *   - a family is only active with at least two such interfaces;
 *   - an interface active in no family is silently dropped.
 * Missing devices and interfaces that would be dropped are errors, so the
 * service never runs with a network quietly missing. A family that is
 * inactive on an otherwise bridged interface is a note.
 *
 * @return array ['errors' => string[], 'notes' => string[], 'bridged' => int]
 */
function preflight(): array
{
    $result = ['errors' => [], 'notes' => [], 'bridged' => 0];
    $parsed = parse_conf();
    if ($parsed === null || count($parsed['devices']) < 2) {
        $result['errors'][] = 'Fewer than two of the selected interfaces exist and are enabled. Check the Interfaces tab and Interfaces > Assignments.';
        return $result;
    }

    $details = legacy_interfaces_details();
    $candidates = ['ipv4' => [], 'ipv6' => []];
    $reasons = [];
    foreach ($parsed['devices'] as $device) {
        $label = device_label($device);
        if (empty($details[$device])) {
            $result['errors'][] = sprintf(
                '%s does not exist in the system. Apply pending changes under Interfaces > Devices, or reboot.',
                $label
            );
            continue;
        }
        if (!in_array('up', $details[$device]['flags'] ?? [], true)) {
            $result['notes'][] = sprintf('%s is down.', $label);
        }
        foreach (['ipv4', 'ipv6'] as $family) {
            if (!empty($parsed['off']['global'][$family])) {
                $reasons[$device][$family] = 'disabled globally';
            } elseif (!empty($parsed['off'][$device][$family])) {
                $reasons[$device][$family] = 'disabled for this interface';
            } elseif (empty($details[$device][$family])) {
                $reasons[$device][$family] = sprintf('no %s address', $family === 'ipv4' ? 'IPv4' : 'IPv6');
            } else {
                $candidates[$family][] = $device;
            }
        }
    }

    /*
     * Root causes: devices that are missing or have no usable family. An
     * interface that is fine but merely loses its only peer because of a
     * root cause is not reported as an error; fixing the root cause fixes it.
     */
    $rootCause = !empty($result['errors']);
    foreach ($parsed['devices'] as $device) {
        if (!empty($details[$device]) && !in_array($device, $candidates['ipv4'], true) &&
            !in_array($device, $candidates['ipv6'], true)) {
            $rootCause = true;
        }
    }

    foreach ($parsed['devices'] as $device) {
        if (empty($details[$device])) {
            continue;
        }
        $active = [];
        foreach (['ipv4', 'ipv6'] as $family) {
            if (in_array($device, $candidates[$family], true)) {
                if (count($candidates[$family]) >= 2) {
                    $active[] = $family;
                } else {
                    $reasons[$device][$family] = sprintf(
                        'no other bridged interface has %s',
                        $family === 'ipv4' ? 'IPv4' : 'IPv6'
                    );
                }
            }
        }
        $label = device_label($device);
        $usable = in_array($device, $candidates['ipv4'], true) || in_array($device, $candidates['ipv6'], true);
        if (empty($active)) {
            if (!$usable || !$rootCause) {
                $result['errors'][] = sprintf(
                    '%s would not be bridged (IPv4: %s; IPv6: %s). Check its addresses under Interfaces.',
                    $label,
                    $reasons[$device]['ipv4'] ?? 'unavailable',
                    $reasons[$device]['ipv6'] ?? 'unavailable'
                );
            }
            continue;
        }
        $result['bridged']++;
        foreach (['ipv4', 'ipv6'] as $family) {
            if (!in_array($family, $active, true)) {
                $result['notes'][] = sprintf(
                    '%s is bridged over %s only (%s: %s).',
                    $label,
                    $family === 'ipv4' ? 'IPv6' : 'IPv4',
                    $family === 'ipv4' ? 'IPv4' : 'IPv6',
                    $reasons[$device][$family] ?? 'unavailable'
                );
            }
        }
    }
    return $result;
}

/**
 * The daemon reports fatal errors to syslog only. Fetch its most recent
 * error line from today's log, waiting briefly for syslog-ng to write it.
 */
function last_daemon_error(int $maxAge = 10, bool $wait = true): ?string
{
    for ($try = 0; $try < ($wait ? 4 : 1); $try++) {
        if ($wait) {
            usleep(250000);
        }
        $files = glob('/var/log/mdnsbridge/mdnsbridge_*.log') ?: [];
        rsort($files);
        if (empty($files)) {
            continue;
        }
        $lines = @file($files[0], FILE_IGNORE_NEW_LINES) ?: [];
        foreach (array_reverse(array_slice($lines, -20)) as $line) {
            /* RFC 5424: <PRI>1 time host app pid ... [meta ...] message; user.err = <11> */
            if (preg_match('/^<(\d+)>1 (\S+) \S+ mdns-bridge \S+ \S+ (?:\[[^\]]*\] )?(.*)$/', $line, $m)) {
                if ((int)$m[1] % 8 <= LOG_ERR && strtotime($m[2]) >= time() - $maxAge) {
                    return $m[3];
                }
            }
        }
    }
    return null;
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

    $check = preflight();
    foreach ($check['notes'] as $note) {
        ctl_log($note, LOG_NOTICE);
    }
    if (!empty($check['errors'])) {
        foreach ($check['errors'] as $error) {
            ctl_log($error, LOG_ERR);
            echo 'mdns-bridge: ' . $error . PHP_EOL;
        }
        return 1;
    }

    [$code, $out] = rc('start');
    if ($code !== 0) {
        $reason = last_daemon_error();
        echo 'mdns-bridge: ' . ($reason ?? ($out !== '' ? $out : 'the daemon did not start; see Services > mDNS Bridge > Log File')) . PHP_EOL;
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

if ($action === 'check') {
    $check = preflight();
    foreach ($check['errors'] as $error) {
        echo 'ERROR: ' . $error . PHP_EOL;
    }
    foreach ($check['notes'] as $note) {
        echo 'Note: ' . $note . PHP_EOL;
    }
    if (empty($check['errors'])) {
        $model = new MDNSBridge();
        if ($model->isServiceEnabled() && !is_running() && carp_state($model) === null) {
            /* Interfaces look fine but the daemon is not running: say why if it logged a reason. */
            $reason = last_daemon_error(120, false);
            if ($reason !== null) {
                echo 'ERROR: mdns-bridge reported: ' . $reason . PHP_EOL;
                exit(0);
            }
        }
        echo sprintf('OK: %d interfaces will be bridged.', $check['bridged']) . PHP_EOL;
    }
    exit(0);
}

if ($action === 'showconfig') {
    $conf = @file_get_contents(MDNSBRIDGE_CONF);
    echo $conf === false ? "# mdns-bridge.conf has not been generated yet. Press Apply.\n" : $conf;
    exit(0);
}

if (!in_array($action, ['start', 'stop', 'restart', 'carp'], true)) {
    fwrite(STDERR, "usage: mdnsbridge_ctl.php start|stop|restart|status|carp|check|showconfig\n");
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
