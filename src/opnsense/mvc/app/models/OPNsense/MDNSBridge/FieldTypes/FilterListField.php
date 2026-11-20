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

use OPNsense\Base\FieldTypes\BaseSetField;
use OPNsense\Base\Validators\CallbackValidator;

/**
 * A list of mdns-bridge filters, stored comma separated.
 *
 * A filter is one or more DNS labels joined by dots, such as "_ipp",
 * "Office-Printer" or "Office-Printer._ipp". The rules below mirror what
 * mdns-bridge 3.1 accepts when it parses its configuration file
 * (config.c split_comma_list() and dns_decode.c dns_save_match_name()),
 * so a value that passes here does not make the daemon exit at startup:
 *
 *   - commas separate list items, so an item cannot contain one;
 *   - the daemon trims whitespace around items, so leading or trailing
 *     whitespace would silently change the filter and is rejected;
 *   - control characters could break the generated INI file;
 *   - the whole filter must be shorter than 256 bytes, each label
 *     1 to 63 bytes, and there may be at most 127 labels.
 *
 * Spaces inside a label are allowed because DNS-SD instance names, which
 * are useful in deny filters, often contain them ("Office Printer").
 *
 * The special token "<all>" (deny everything) is accepted by this field.
 * Whether it is allowed in a given place is a model-level rule, see
 * MDNSBridge::performValidation().
 *
 * Labels are case sensitive in mdns-bridge, so "_IPP" and "_ipp" are
 * different filters and both are accepted.
 */
class FilterListField extends BaseSetField
{
    public const DENY_ALL = '<all>';

    protected $internalAsList = true;

    protected function defaultValidationMessage()
    {
        return gettext('"%s" is not a valid filter.');
    }

    /**
     * @param string $item one filter
     * @return string|null error message, or null when valid
     */
    public static function checkFilter(string $item): ?string
    {
        if ($item === self::DENY_ALL) {
            return null;
        }
        if ($item === '') {
            return gettext('Empty filters are not allowed.');
        }
        if (strlen($item) > 255) {
            return sprintf(gettext('"%s" is longer than 255 characters.'), $item);
        }
        if (trim($item) !== $item) {
            return sprintf(gettext('"%s" starts or ends with whitespace.'), $item);
        }
        if (preg_match('/[\x00-\x1f\x7f,]/', $item)) {
            return sprintf(gettext('"%s" contains a comma or a control character.'), $item);
        }
        $labels = explode('.', $item);
        if (count($labels) > 127) {
            return sprintf(gettext('"%s" has more than 127 labels.'), $item);
        }
        foreach ($labels as $label) {
            if ($label === '') {
                return sprintf(
                    gettext('"%s" contains an empty label: remove leading, trailing or doubled dots.'),
                    $item
                );
            }
            if (strlen($label) > 63) {
                return sprintf(gettext('"%s" contains a label longer than 63 bytes.'), $item);
            }
        }
        return null;
    }

    public function getValidators()
    {
        $validators = parent::getValidators();
        if ($this->internalValue != null) {
            $validators[] = new CallbackValidator(['callback' => function ($data) {
                $seen = [];
                foreach ($this->iterateInput($data) as $item) {
                    $error = self::checkFilter($item);
                    if ($error !== null) {
                        return [$error];
                    }
                    if (isset($seen[$item])) {
                        return [sprintf(gettext('"%s" is listed twice.'), $item)];
                    }
                    $seen[$item] = true;
                }
                return [];
            }]);
        }
        return $validators;
    }
}
