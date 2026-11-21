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

namespace OPNsense\MDNSBridge\Api;

use OPNsense\Base\ApiMutableServiceControllerBase;
use OPNsense\Core\Backend;
use OPNsense\MDNSBridge\MDNSBridge;

/**
 * Service control. start/stop/restart/status are inherited and run the
 * configd actions in actions_mdnsbridge.conf, which go through
 * mdnsbridge_ctl.php so that CARP gating applies to GUI actions, the
 * dashboard widget, HA sync and the CARP hook alike.
 */
class ServiceController extends ApiMutableServiceControllerBase
{
    protected static $internalServiceClass = '\OPNsense\MDNSBridge\MDNSBridge';
    protected static $internalServiceTemplate = 'OPNsense/MDNSBridge';
    protected static $internalServiceEnabled = 'general.enabled';
    protected static $internalServiceName = 'mdnsbridge';

    /**
     * The daemon refuses to start with fewer than two interfaces, so
     * "enabled" means the switch plus two enabled interface entries.
     */
    protected function serviceEnabled()
    {
        return (new MDNSBridge())->isServiceEnabled();
    }

    /**
     * Apply, and when the service fails to start, add the reason: the
     * interface check (missing devices, interfaces without addresses) or
     * the daemon's own error. The page shows "detail" in its own panel.
     */
    public function reconfigureAction()
    {
        $result = parent::reconfigureAction();
        if (($result['status'] ?? '') !== 'ok' && $this->request->isPost()) {
            $result['detail'] = trim((string)(new Backend())->configdRun('mdnsbridge check'));
        }
        return $result;
    }

    /**
     * The generated mdns-bridge.conf plus the interface check, so the
     * engineer can see exactly what the daemon is given and what it will
     * do with each interface. Read-only.
     */
    public function showconfigAction()
    {
        $backend = new Backend();
        return [
            'status' => 'ok',
            'config' => (string)$backend->configdRun('mdnsbridge showconfig'),
            'check' => trim((string)$backend->configdRun('mdnsbridge check')),
        ];
    }
}
