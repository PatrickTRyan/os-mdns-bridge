<?php

/*
 * Copyright (C) 2026 Patrick Ryan
 * All rights reserved.
 */

namespace OPNsense\MDNSBridge\Api;

use OPNsense\Base\ApiMutableServiceControllerBase;

class ServiceController extends ApiMutableServiceControllerBase
{
    protected static $internalServiceClass = '\OPNsense\MDNSBridge\MDNSBridge';
    protected static $internalServiceTemplate = 'OPNsense/MDNSBridge';
    protected static $internalServiceEnabled = 'enabled';
    protected static $internalServiceName = 'mdnsbridge';
}
