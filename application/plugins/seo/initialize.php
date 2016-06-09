<?php

// initialize seo
include("seo.php");

$seo = new SEO(array(
    "title" => "vNative - Native advertising software for Publishers",
    "description" => "Latest native advertising application for publishers and advertisers.",
    "photo" => CDN . "images/logo.png"
));

Framework\Registry::set("seo", $seo);