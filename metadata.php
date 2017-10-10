<?php

require_once __DIR__ . '/config.php';

class MetadataGenerator {

    public function __construct() {

    }

    public function serve() {
        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="utf-8" ?><!DOCTYPE properties SYSTEM "http://java.sun.com/dtd/properties.dtd"><properties></properties>');
        $entry = $xml->addChild('entry', APP_ID);
        $entry->addAttribute('key', 'appid');
        $entry = $xml->addChild('entry', 'true');
        $entry->addAttribute('key', 'trustedclient');
        $entry = $xml->addChild('entry', 'LMS');
        $entry->addAttribute('key', 'type');
        $entry = $xml->addChild('entry', SSL_PUBLIC);
        $entry->addAttribute('key', 'public_key');
        $entry = $xml->addChild('entry', $_SERVER['SERVER_ADDR']);
        $entry->addAttribute('key', 'host');
        header('Content-type: text/xml');
        print(html_entity_decode($xml->asXML()));
    }
}

$metadatagenerator = new MetadataGenerator();
$metadatagenerator -> serve();