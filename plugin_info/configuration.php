<?php
/* This file is part of Jeedom. */

require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';
include_file('core', 'authentification', 'php');
if (!isConnect()) {
    include_file('desktop', '404', 'php');
    die();
}
?>
<form class="form-horizontal">
    <fieldset>
        <div class="form-group">
            <label class="col-md-4 control-label">{{Email compte Ecovacs}}
                <sup><i class="fas fa-question-circle tooltips" title="{{Adresse email de votre compte Ecovacs}}"></i></sup>
            </label>
            <div class="col-md-4">
                <input type="text" class="configKey form-control" data-l1key="login" placeholder="votre@email.com">
            </div>
        </div>
        <div class="form-group">
            <label class="col-md-4 control-label">{{Mot de passe Ecovacs}}
                <sup><i class="fas fa-question-circle tooltips" title="{{Mot de passe de votre compte Ecovacs}}"></i></sup>
            </label>
            <div class="col-md-4">
                <input type="password" class="configKey form-control" data-l1key="password">
            </div>
        </div>
        <div class="form-group">
            <label class="col-md-4 control-label">{{Pays}}
                <sup><i class="fas fa-question-circle tooltips" title="{{Pays associé à votre compte Ecovacs}}"></i></sup>
            </label>
            <div class="col-md-3">
                <select class="configKey form-control" data-l1key="country">
                    <option value="FR">France (FR)</option>
                    <option value="BE">Belgique (BE)</option>
                    <option value="CH">Suisse (CH)</option>
                    <option value="DE">Allemagne (DE)</option>
                    <option value="ES">Espagne (ES)</option>
                    <option value="IT">Italie (IT)</option>
                    <option value="NL">Pays-Bas (NL)</option>
                    <option value="GB">Royaume-Uni (GB)</option>
                    <option value="US">États-Unis (US)</option>
                    <option value="CA">Canada (CA)</option>
                </select>
            </div>
        </div>
        <div class="form-group">
            <label class="col-md-4 control-label">{{Continent (serveur MQTT)}}
                <sup><i class="fas fa-question-circle tooltips" title="{{Continent du serveur MQTT Ecovacs : eu (Europe), na (Amérique du Nord), as (Asie)}}"></i></sup>
            </label>
            <div class="col-md-3">
                <select class="configKey form-control" data-l1key="continent">
                    <option value="eu">Europe (eu)</option>
                    <option value="na">Amérique du Nord (na)</option>
                    <option value="as">Asie (as)</option>
                </select>
            </div>
        </div>
        <div class="form-group">
            <label class="col-md-4 control-label">{{Port socket démon}}
                <sup><i class="fas fa-question-circle tooltips" title="{{Port TCP utilisé pour la communication entre Jeedom et le démon (défaut : 55009)}}"></i></sup>
            </label>
            <div class="col-md-2">
                <input type="number" class="configKey form-control" data-l1key="socketport" placeholder="55009">
            </div>
        </div>
    </fieldset>
</form>