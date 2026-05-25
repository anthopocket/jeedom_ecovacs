<?php
/* Callback HTTP du démon ecovacsed.py → Jeedom */
require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';

if (!jeedom::apiAccess(init('apikey'), 'ecovacs')) {
    log::add('ecovacs', 'warning', 'Callback : clé API invalide');
    echo 'Accès refusé';
    die();
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    echo 'Données invalides';
    die();
}

log::add('ecovacs', 'debug', 'Callback reçu : ' . json_encode($data));

try {
    ecovacs::callback($data);
    echo 'OK';
} catch (Exception $e) {
    log::add('ecovacs', 'error', 'Erreur callback : ' . $e->getMessage());
    http_response_code(500);
    echo 'Erreur : ' . $e->getMessage();
}
