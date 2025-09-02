<?php

/*
 *  Credit to Stanford's REDCap development team and their OnCore Integration Project.
 *  A lot of inspiration or pieces of code come from that project.
 */

namespace UKModules\ROCS;

use ExternalModules\ExternalModules;
use GuzzleHttp\Exception\GuzzleException;
use stdClass;
use GuzzleHttp\Psr7;
use GuzzleHttp\Psr7\Response;

class OnCore
{
    /**
     * @var string
     */
    private $PREFIX;

    /**
     * @var string
     */
    private $OnCoreAPIURL;

    /**
     * @var string
     */
    private $OnCoreSecret;

    /**
     * @var string
     */
    private $OnCoreClientID;

    /**
     * @var array
     */
    private $Adjudicators;

    /**
     * @param $PREFIX
     */
    public function __construct() {
        $this->setAPIURL(ExternalModules::getSystemSetting($this->getPrefix(), 'oncore-api-url'));
        $this->setOnCoreSecret(ExternalModules::getSystemSetting($this->getPREFIX(), 'oncore-secret'));
        $this->setOnCoreClientID(ExternalModules::getSystemSetting($this->getPREFIX(), 'oncore-client-id'));
    }

    /* -------------------------- SET FUNCTIONS -------------------------- */
    public function setPREFIX($PREFIX): void
    {
        $this->PREFIX = $PREFIX;
    }

    public function setAPIURL($url) {
        return $this->OnCoreAPIURL = $url;
    }

    public function setSecret($secret) {
        return $this->OnCoreSecret = $secret;
    }

    public function setClientID($client) {
        return $this->OnCoreClientID = $client;
    }

    public function setAdjudicators($adjudicators) {
        return $this->Adjudicators = $adjudicators;
    }

    /* -------------------------- GET FUNCTIONS -------------------------- */

    public function getPREFIX()
    {
        return $this->PREFIX;
    }

    public function getAPIURL($url) {
        return $this->OnCoreAPIURL;
    }

    public function getSecret($secret) {
        return $this->OnCoreSecret;
    }

    public function getClientID($client) {
        return $this->OnCoreClientID;
    }

    public function getAdjudicators($adjudicators) {
        return $this->Adjudicators;
    }
}

