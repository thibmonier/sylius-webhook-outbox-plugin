<?php

return [
    Tmonier\SyliusWebhookOutboxPlugin\TmonierSyliusWebhookOutboxPlugin::class => ['all' => true],
    // US-304: the probes of this plugin are loaded because the health plugin is installed in the test application.
    Tmonier\SyliusPlatformHealthPlugin\TmonierSyliusPlatformHealthPlugin::class => ['all' => true],
];
