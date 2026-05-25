<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published
 * by the Free Software Foundation, either version 3 of the License,
 * or (at your option) any later version.
 */

require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';

function ecovacs_install() {
    // Création du dossier tmp
    if (!file_exists(jeedom::getTmpFolder('ecovacs'))) {
        mkdir(jeedom::getTmpFolder('ecovacs'), 0755, true);
    }
}

function ecovacs_update() {
    // Redémarrer le démon après mise à jour
    $info = ecovacs::deamon_info();
    if ($info['state'] === 'ok') {
        ecovacs::deamon_stop();
        sleep(1);
        ecovacs::deamon_start();
    }
}

function ecovacs_remove() {
    ecovacs::deamon_stop();
}
