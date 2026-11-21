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

namespace OPNsense\MDNSBridge\FieldTypes;

use OPNsense\Base\FieldTypes\BaseListField;

/**
 * Multi-select list of common DNS-SD service labels.
 *
 * The stored value is the label itself (for example "_airplay"), so the
 * configuration template can write it to mdns-bridge.conf unchanged and
 * there is no separate preset-to-label map to keep in sync.
 *
 * Each entry is the service label only. "_tcp"/"_udp" and "local" are
 * deliberately omitted: mdns-bridge matches on labels, and those labels
 * are common to every service (see the mdns-bridge README, "Filters").
 */
class ServicePresetField extends BaseListField
{
    protected $internalMultiSelect = true;

    /**
     * Label => description. Keep sorted by description within each group.
     */
    private const PRESETS = [
        // Apple / AirPlay / HomeKit
        '_airplay' => 'AirPlay (_airplay)',
        '_raop' => 'AirPlay audio / RAOP (_raop)',
        '_companion-link' => 'Apple Companion Link (_companion-link)',
        '_touch-able' => 'Apple Remote / Remote app (_touch-able)',
        '_sleep-proxy' => 'Bonjour Sleep Proxy (_sleep-proxy)',
        '_hap' => 'HomeKit Accessory Protocol (_hap)',
        '_homekit' => 'HomeKit hub, legacy (_homekit)',
        '_daap' => 'iTunes / Music library sharing (_daap)',
        '_adisk' => 'Time Machine disks (_adisk)',
        // Media
        '_googlecast' => 'Google Cast / Chromecast (_googlecast)',
        '_sonos' => 'Sonos (_sonos)',
        '_spotify-connect' => 'Spotify Connect (_spotify-connect)',
        // Smart home
        '_matter' => 'Matter, operational (_matter)',
        '_matterc' => 'Matter, commissionable (_matterc)',
        '_meshcop' => 'Thread border router (_meshcop)',
        // Printing and scanning
        '_ipp' => 'IPP printing (_ipp)',
        '_ipps' => 'IPP printing over TLS (_ipps)',
        '_printer' => 'LPD printing (_printer)',
        '_pdl-datastream' => 'Raw / JetDirect printing (_pdl-datastream)',
        '_uscan' => 'eSCL scanning (_uscan)',
        '_uscans' => 'eSCL scanning over TLS (_uscans)',
        '_scanner' => 'Scanner, legacy (_scanner)',
        // File sharing and remote access
        '_afpovertcp' => 'AFP file sharing (_afpovertcp)',
        '_smb' => 'SMB file sharing (_smb)',
        '_rfb' => 'Screen sharing / VNC (_rfb)',
        '_sftp-ssh' => 'SFTP (_sftp-ssh)',
        '_ssh' => 'SSH (_ssh)',
        // Generic
        '_device-info' => 'Device information (_device-info)',
        '_http' => 'HTTP (_http)',
        '_https' => 'HTTPS (_https)',
        '_workstation' => 'Workstation (_workstation)',
    ];

    /**
     * @return string[] all preset labels
     */
    public static function labels(): array
    {
        return array_keys(self::PRESETS);
    }

    protected function actionPostLoadingEvent()
    {
        if (!$this->hasStaticOptions()) {
            $options = [];
            foreach (self::PRESETS as $label => $description) {
                $options[$label] = gettext($description);
            }
            $this->setStaticOptions($options);
        }
        $this->internalOptionList = $this->getStaticOptions();
    }
}
