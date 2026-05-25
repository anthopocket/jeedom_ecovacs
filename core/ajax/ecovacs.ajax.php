<?php
/* This file is part of Jeedom. */

try {
    require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');
    if (!isConnect('admin')) {
        throw new Exception('{{401 - Accès non autorisé}}');
    }
    ajax::init();
} catch (Exception $e) {
    ajax::error(displayExceptionMessage($e), $e->getCode());
}

switch (init('action')) {
    case 'createDefaultCommands':
        $eqLogic = ecovacs::byId(init('eqLogic_id'));
        if (!is_object($eqLogic)) {
            ajax::error('Équipement introuvable');
        }
        ecovacs::createDefaultCommands($eqLogic);
        ajax::success();
        break;

    default:
        ajax::error('Action inconnue : ' . init('action'));
        break;
}
