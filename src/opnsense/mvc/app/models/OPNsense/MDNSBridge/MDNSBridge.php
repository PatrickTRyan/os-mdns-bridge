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

namespace OPNsense\MDNSBridge;

use OPNsense\Base\BaseModel;
use OPNsense\Base\Messages\Message;
use OPNsense\MDNSBridge\FieldTypes\FilterListField;

/**
 * mDNS Bridge model.
 *
 * performValidation() adds the cross-field rules that mdns-bridge enforces
 * when it parses mdns-bridge.conf (config.c and filter.c in mdns-bridge
 * 3.1). The daemon exits on any of these, so they are rejected at save
 * time instead of surfacing as a service that will not start.
 *
 * Rules that depend on other grid entries (a peer rule naming an interface
 * that is no longer bridged) are only checked for entries being saved.
 * The configuration template independently skips anything that would be
 * invalid at render time, so a later change elsewhere cannot produce a
 * file the daemon rejects.
 */
class MDNSBridge extends BaseModel
{
    /**
     * Logical names (lan, opt1, ...) of the enabled bridge interfaces,
     * in configuration order, without duplicates.
     *
     * @return string[]
     */
    public function activeInterfaces(): array
    {
        $result = [];
        foreach ($this->ifaces->iface->iterateItems() as $iface) {
            $name = (string)$iface->interface;
            if ($iface->enabled->isEqual('1') && $name !== '' && !in_array($name, $result, true)) {
                $result[] = $name;
            }
        }
        return $result;
    }

    /**
     * The daemon requires at least two interfaces, so the service only
     * counts as enabled when the switch is on and two entries are enabled.
     * Used by the service controller, plugins.inc.d and the control script
     * so that all three agree. The rc.conf.d template applies the same rule.
     */
    public function isServiceEnabled(): bool
    {
        return $this->general->enabled->isEqual('1') && count($this->activeInterfaces()) >= 2;
    }

    /**
     * @return bool true if any field directly below $node changed
     */
    private function nodeChanged($node): bool
    {
        foreach ($node->iterateItems() as $field) {
            if ($field->isFieldChanged()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Validate one filter definition (mode + presets + custom filters).
     *
     * @param \OPNsense\Base\Messages\Messages $messages
     * @param string $mode none, allow or deny
     * @param \OPNsense\Base\FieldTypes\BaseField $presets
     * @param \OPNsense\Base\FieldTypes\BaseField $filters
     * @param bool $global true for the [global] filter, which may not be "<all>"
     */
    private function validateFilterSet($messages, string $mode, $presets, $filters, bool $global = false): void
    {
        $presetValues = $presets->getValues();
        $filterValues = $filters->getValues();
        $count = count($presetValues) + count($filterValues);

        if ($mode === 'none') {
            if ($count > 0) {
                $messages->appendMessage(new Message(
                    gettext('Services are listed but no filter mode is selected. Choose allow or deny, or clear the lists.'),
                    $filters->__reference
                ));
            }
            return;
        }

        if ($count === 0) {
            $messages->appendMessage(new Message(
                gettext('Select at least one common service or enter a custom filter, or set the mode to none.'),
                $filters->__reference
            ));
            return;
        }

        if (in_array(FilterListField::DENY_ALL, $filterValues, true)) {
            if ($global) {
                $messages->appendMessage(new Message(
                    gettext('The global filter cannot be <all>: mdns-bridge refuses a global deny-all list. Use per-interface filters instead.'),
                    $filters->__reference
                ));
            } elseif ($mode !== 'deny') {
                $messages->appendMessage(new Message(
                    gettext('<all> is only valid in a deny list.'),
                    $filters->__reference
                ));
            } elseif ($count !== 1) {
                $messages->appendMessage(new Message(
                    gettext('<all> must be the only entry in the list: it already denies every service.'),
                    $filters->__reference
                ));
            }
        }
    }

    public function performValidation($validateFullModel = false)
    {
        $messages = parent::performValidation($validateFullModel);

        /* [global] */
        $general = $this->general;
        if ($validateFullModel || $this->nodeChanged($general)) {
            if ($general->disable_ipv4->isEqual('1') && $general->disable_ipv6->isEqual('1')) {
                $messages->appendMessage(new Message(
                    gettext('IPv4 and IPv6 cannot both be disabled.'),
                    $general->disable_ipv6->__reference
                ));
            }
            $this->validateFilterSet(
                $messages,
                (string)$general->inbound_mode,
                $general->inbound_presets,
                $general->inbound_filters,
                true
            );
        }

        /* [interface] sections */
        foreach ($this->ifaces->iface->iterateItems() as $iface) {
            if (!$validateFullModel && !$this->nodeChanged($iface)) {
                continue;
            }
            if ($iface->disable_ipv4->isEqual('1') && $iface->disable_ipv6->isEqual('1')) {
                $messages->appendMessage(new Message(
                    gettext('IPv4 and IPv6 cannot both be disabled. Disable this entry instead.'),
                    $iface->disable_ipv6->__reference
                ));
            }
            $this->validateFilterSet(
                $messages,
                (string)$iface->inbound_mode,
                $iface->inbound_presets,
                $iface->inbound_filters
            );
            $this->validateFilterSet(
                $messages,
                (string)$iface->outbound_mode,
                $iface->outbound_presets,
                $iface->outbound_filters
            );
        }

        /* peer-specific outbound filters */
        $active = $this->activeInterfaces();
        $pairs = [];
        foreach ($this->peers->peer->iterateItems() as $uuid => $peer) {
            $pair = (string)$peer->interface . '|' . (string)$peer->source;
            $pairs[$pair] = ($pairs[$pair] ?? 0) + 1;
        }
        foreach ($this->peers->peer->iterateItems() as $peer) {
            if (!$validateFullModel && !$this->nodeChanged($peer)) {
                continue;
            }
            $interface = (string)$peer->interface;
            $source = (string)$peer->source;
            if ($interface !== '' && $interface === $source) {
                $messages->appendMessage(new Message(
                    gettext('The source interface must differ from the outbound interface.'),
                    $peer->source->__reference
                ));
            }
            if ($interface !== '' && !in_array($interface, $active, true)) {
                $messages->appendMessage(new Message(
                    gettext('This interface is not an enabled bridge interface. Add or enable it on the Interfaces tab first.'),
                    $peer->interface->__reference
                ));
            }
            if ($source !== '' && !in_array($source, $active, true)) {
                $messages->appendMessage(new Message(
                    gettext('This interface is not an enabled bridge interface. Add or enable it on the Interfaces tab first.'),
                    $peer->source->__reference
                ));
            }
            if (($pairs[$interface . '|' . $source] ?? 0) > 1) {
                $messages->appendMessage(new Message(
                    gettext('A rule for this interface and source already exists. mdns-bridge allows one list per pair.'),
                    $peer->source->__reference
                ));
            }
            $this->validateFilterSet(
                $messages,
                (string)$peer->mode,
                $peer->presets,
                $peer->filters
            );
        }

        return $messages;
    }
}
