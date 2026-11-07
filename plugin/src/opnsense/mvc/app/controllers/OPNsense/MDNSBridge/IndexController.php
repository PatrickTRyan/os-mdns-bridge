<?php

/*
 * Copyright (C) 2026 Patrick Ryan
 * All rights reserved.
 */

namespace OPNsense\MDNSBridge;

class IndexController extends \OPNsense\Base\IndexController
{
    /**
     * mDNS Bridge index page
     *
     * @throws \Exception
     */
    public function indexAction()
    {
        $this->view->general = $this->getForm("general");
        $this->view->pick('OPNsense/MDNSBridge/index');
    }
}
