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

use OPNsense\Base\ApiMutableModelControllerBase;

class SettingsController extends ApiMutableModelControllerBase
{
    protected static $internalModelName = 'mdnsbridge';
    protected static $internalModelClass = '\OPNsense\MDNSBridge\MDNSBridge';

    /* Bridge interfaces ([device] sections) */

    public function searchIfaceAction()
    {
        return $this->searchBase('ifaces.iface');
    }

    public function getIfaceAction($uuid = null)
    {
        return $this->getBase('iface', 'ifaces.iface', $uuid);
    }

    public function addIfaceAction()
    {
        return $this->addBase('iface', 'ifaces.iface');
    }

    public function setIfaceAction($uuid)
    {
        return $this->setBase('iface', 'ifaces.iface', $uuid);
    }

    public function delIfaceAction($uuid)
    {
        return $this->delBase('ifaces.iface', $uuid);
    }

    public function toggleIfaceAction($uuid, $enabled = null)
    {
        return $this->toggleBase('ifaces.iface', $uuid, $enabled);
    }

    /* Peer-specific outbound filters */

    public function searchPeerAction()
    {
        return $this->searchBase('peers.peer');
    }

    public function getPeerAction($uuid = null)
    {
        return $this->getBase('peer', 'peers.peer', $uuid);
    }

    public function addPeerAction()
    {
        return $this->addBase('peer', 'peers.peer');
    }

    public function setPeerAction($uuid)
    {
        return $this->setBase('peer', 'peers.peer', $uuid);
    }

    public function delPeerAction($uuid)
    {
        return $this->delBase('peers.peer', $uuid);
    }

    public function togglePeerAction($uuid, $enabled = null)
    {
        return $this->toggleBase('peers.peer', $uuid, $enabled);
    }
}
