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

namespace OPNsense\MDNSBridge\Migrations;

use OPNsense\Base\BaseModelMigration;
use OPNsense\Core\Config;
use OPNsense\MDNSBridge\FieldTypes\FilterListField;
use OPNsense\MDNSBridge\FieldTypes\ServicePresetField;

/**
 * Migrate the flat 0.1.x settings to the 1.0.0 structure.
 *
 * 0.1.x stored everything directly below //OPNsense/MDNSBridge:
 *   enabled, enablecarp, interfaces (csv), disableipv4, disableipv6,
 *   inboundfilterpresets (csv of keys such as "companionlink"),
 *   allowinboundfilters (csv)
 *
 * 1.0.0 moves these into general/, one ifaces/iface entry per interface,
 * and replaces the "any selected interface is MASTER" CARP switch with an
 * explicit CARP VIP dependency.
 *
 * The 0.1.x nodes are read from the raw configuration because the model
 * no longer defines them; they are dropped when the model is saved.
 * Nothing is lost silently: every value that cannot be carried over is
 * logged to the system log.
 */
class M1_0_0 extends BaseModelMigration
{
    private const PRESET_MAP = [
        'airplay' => '_airplay',
        'raop' => '_raop',
        'googlecast' => '_googlecast',
        'hap' => '_hap',
        'homekit' => '_homekit',
        'companionlink' => '_companion-link',
        'sleepproxy' => '_sleep-proxy',
        'ipp' => '_ipp',
        'ipps' => '_ipps',
        'printer' => '_printer',
        'pdldatastream' => '_pdl-datastream',
        'scanner' => '_scanner',
        'smb' => '_smb',
        'ssh' => '_ssh',
        'http' => '_http',
        'https' => '_https',
        'workstation' => '_workstation',
    ];

    private function log(string $message): void
    {
        syslog(LOG_WARNING, 'mdns-bridge migration: ' . $message);
    }

    private static function csv($node): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string)$node)), 'strlen'));
    }

    public function run($model)
    {
        parent::run($model);

        $config = Config::getInstance()->object();
        $src = $config->OPNsense->MDNSBridge ?? null;

        /* Fresh install, or already in the new layout: nothing to convert. */
        if ($src === null || (!isset($src->enablecarp) && !isset($src->interfaces))) {
            return;
        }

        $model->general->enabled = (string)$src->enabled === '1' ? '1' : '0';
        $model->general->disable_ipv4 = (string)$src->disableipv4 === '1' ? '1' : '0';
        $model->general->disable_ipv6 = (string)$src->disableipv6 === '1' ? '1' : '0';

        /* interfaces -> one enabled entry each */
        $interfaces = self::csv($src->interfaces);
        foreach ($interfaces as $name) {
            $iface = $model->ifaces->iface->Add();
            $iface->enabled = '1';
            $iface->interface = $name;
        }

        /* inbound presets + custom allow filters -> global allow list */
        $presets = [];
        foreach (self::csv($src->inboundfilterpresets) as $key) {
            if (isset(self::PRESET_MAP[$key]) && in_array(self::PRESET_MAP[$key], ServicePresetField::labels(), true)) {
                $presets[] = self::PRESET_MAP[$key];
            } else {
                $this->log(sprintf('dropped unknown inbound preset "%s"', $key));
            }
        }
        $filters = [];
        foreach (self::csv($src->allowinboundfilters) as $filter) {
            if (FilterListField::checkFilter($filter) === null && $filter !== FilterListField::DENY_ALL) {
                $filters[] = $filter;
            } else {
                $this->log(sprintf('dropped invalid inbound filter "%s"', $filter));
            }
        }
        $presets = array_values(array_unique($presets));
        $filters = array_values(array_unique($filters));
        if (count($presets) + count($filters) > 0) {
            $model->general->inbound_mode = 'allow';
            $model->general->inbound_presets = implode(',', $presets);
            $model->general->inbound_filters = implode(',', $filters);
        } else {
            $model->general->inbound_mode = 'none';
        }

        /*
         * CARP: 0.1.x ran while any CARP VIP on a selected interface was
         * MASTER. Pick the first CARP VIP on one of those interfaces. If
         * there is none, disable the service rather than risk running it
         * on both nodes of an HA pair.
         */
        if ((string)$src->enablecarp === '1') {
            $vipUuid = null;
            if (isset($config->virtualip->vip)) {
                foreach ($config->virtualip->vip as $vip) {
                    if ((string)$vip->mode === 'carp' && in_array((string)$vip->interface, $interfaces, true)) {
                        $vipUuid = (string)$vip['uuid'];
                        break;
                    }
                }
            }
            if (!empty($vipUuid)) {
                $model->general->carp_depend_on = $vipUuid;
            } else {
                $model->general->enabled = '0';
                $this->log('CARP failover was enabled but no CARP VIP exists on a bridged interface; service disabled, select a CARP dependency and re-enable');
            }
        }
    }
}
