<?php

/*
 * Copyright (C) 2026 Patrick Ryan
 * All rights reserved.
 */

namespace OPNsense\MDNSBridge\Api;

use OPNsense\Base\ApiMutableModelControllerBase;

class SettingsController extends ApiMutableModelControllerBase
{
    protected static $internalModelClass = '\OPNsense\MDNSBridge\MDNSBridge';
    protected static $internalModelName = 'mdnsbridge';
}
