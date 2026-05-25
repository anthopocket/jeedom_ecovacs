<?php
/* This file is part of Jeedom. */

if (!isConnect('admin')) {
    throw new Exception('{{401 - Accès non autorisé}}');
}

$plugin   = plugin::byId('ecovacs');
sendVarToJS('eqType', $plugin->getId());
$eqLogics = eqLogic::byType($plugin->getId());
?>

<div class="row row-overflow">

    <!-- ══════════════════════════════════════════════════════════════
         PAGE D'ACCUEIL : tuiles équipements
    ══════════════════════════════════════════════════════════════ -->
    <div class="col-xs-12 eqLogicThumbnailDisplay">

        <legend><i class="fas fa-cog"></i> {{Gestion}}</legend>
        <div class="eqLogicThumbnailContainer">
            <div class="cursor eqLogicAction logoPrimary" data-action="add">
                <i class="fas fa-plus-circle"></i>
                <br>
                <span>{{Ajouter}}</span>
            </div>
            <div class="cursor eqLogicAction logoSecondary" data-action="gotoPluginConf">
                <i class="fas fa-wrench"></i>
                <br>
                <span>{{Configuration}}</span>
            </div>
        </div>

        <legend><i class="fas fa-robot"></i> {{Mes robots Ecovacs}}</legend>
        <?php
        if (count($eqLogics) == 0) {
            echo '<br><div class="text-center" style="font-size:1.1em;font-weight:bold;">';
            echo '{{Aucun robot Ecovacs trouvé. Configurez vos identifiants puis démarrez le démon pour une découverte automatique, ou cliquez sur "Ajouter".}}';
            echo '</div>';
        } else {
            echo '<div class="input-group" style="margin:5px;">';
            echo '<input class="form-control roundedLeft" placeholder="{{Rechercher}}" id="in_searchEqlogic">';
            echo '<div class="input-group-btn">';
            echo '<a id="bt_resetSearch" class="btn" style="width:30px"><i class="fas fa-times"></i></a>';
            echo '<a class="btn roundedRight hidden" id="bt_pluginDisplayAsTable" data-coreSupport="1" data-state="0"><i class="fas fa-grip-lines"></i></a>';
            echo '</div>';
            echo '</div>';
            echo '<div class="eqLogicThumbnailContainer">';
            foreach ($eqLogics as $eqLogic) {
                $opacity = ($eqLogic->getIsEnable()) ? '' : 'disableCard';
                echo '<div class="eqLogicDisplayCard cursor ' . $opacity . '" data-eqLogic_id="' . $eqLogic->getId() . '">';
                echo '<img src="' . $eqLogic->getImage() . '"/>';
                echo '<br>';
                echo '<span class="name">' . $eqLogic->getHumanName(true, true) . '</span>';
                echo '<span class="hiddenAsCard displayTableRight hidden">';
                echo ($eqLogic->getIsVisible() == 1)
                    ? '<i class="fas fa-eye" title="{{Equipement visible}}"></i>'
                    : '<i class="fas fa-eye-slash" title="{{Equipement non visible}}"></i>';
                echo '</span>';
                // Modèle
                $model = $eqLogic->getConfiguration('model', '');
                if ($model !== '') {
                    echo '<span class="hiddenAsCard displayTableRight hidden" style="font-size:0.8em;color:#888;">' . $model . '</span>';
                }
                echo '</div>';
            }
            echo '</div>';
        }
        ?>
    </div><!-- /.eqLogicThumbnailDisplay -->

    <!-- ══════════════════════════════════════════════════════════════
         PAGE DE CONFIGURATION D'UN ÉQUIPEMENT
    ══════════════════════════════════════════════════════════════ -->
    <div class="col-xs-12 eqLogic" style="display:none;">

        <!-- Barre d'actions -->
        <div class="input-group pull-right" style="display:inline-flex;">
            <span class="input-group-btn">
                <a class="btn btn-sm btn-default eqLogicAction roundedLeft" data-action="configure"><i class="fas fa-cogs"></i><span class="hidden-xs"> {{Configuration avancée}}</span>
                </a><a class="btn btn-sm btn-default eqLogicAction" data-action="copy"><i class="fas fa-copy"></i><span class="hidden-xs"> {{Dupliquer}}</span>
                </a><a class="btn btn-sm btn-warning" id="bt_createDefaultCmd" title="{{Crée les commandes action standards (start, stop, charge…)}}"><i class="fas fa-magic"></i><span class="hidden-xs"> {{Créer commandes}}</span>
                </a><a class="btn btn-sm btn-success eqLogicAction" data-action="save"><i class="fas fa-check-circle"></i> {{Sauvegarder}}
                </a><a class="btn btn-sm btn-danger eqLogicAction roundedRight" data-action="remove"><i class="fas fa-minus-circle"></i> {{Supprimer}}
                </a>
            </span>
        </div>

        <!-- Onglets -->
        <ul class="nav nav-tabs" role="tablist">
            <li role="presentation">
                <a href="#" class="eqLogicAction" aria-controls="home" role="tab" data-toggle="tab" data-action="returnToThumbnailDisplay">
                    <i class="fas fa-arrow-circle-left"></i>
                </a>
            </li>
            <li role="presentation" class="active">
                <a href="#eqlogictab" aria-controls="eqlogictab" role="tab" data-toggle="tab">
                    <i class="fas fa-tachometer-alt"></i> {{Équipement}}
                </a>
            </li>
            <li role="presentation">
                <a href="#commandtab" aria-controls="commandtab" role="tab" data-toggle="tab">
                    <i class="fas fa-list"></i> {{Commandes}}
                </a>
            </li>
        </ul>

        <div class="tab-content">

            <!-- ── Onglet Équipement ── -->
            <div role="tabpanel" class="tab-pane active" id="eqlogictab">
                <form class="form-horizontal">
                    <fieldset>

                        <!-- Colonne gauche : paramètres généraux + spécifiques -->
                        <div class="col-lg-6">
                            <legend><i class="fas fa-wrench"></i> {{Paramètres généraux}}</legend>

                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Nom de l'équipement}}</label>
                                <div class="col-sm-6">
                                    <input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display:none;">
                                    <input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{Nom de l'équipement}}">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Objet parent}}</label>
                                <div class="col-sm-6">
                                    <select id="sel_object" class="eqLogicAttr form-control" data-l1key="object_id">
                                        <option value="">{{Aucun}}</option>
                                        <?php
                                        foreach (jeeObject::buildTree(null, false) as $object) {
                                            echo '<option value="' . $object->getId() . '">'
                                                . str_repeat('&nbsp;&nbsp;', $object->getConfiguration('parentNumber'))
                                                . $object->getName()
                                                . '</option>';
                                        }
                                        ?>
                                    </select>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Catégorie}}</label>
                                <div class="col-sm-6">
                                    <?php
                                    foreach (jeedom::getConfiguration('eqLogic:category') as $key => $value) {
                                        echo '<label class="checkbox-inline">';
                                        echo '<input type="checkbox" class="eqLogicAttr" data-l1key="category" data-l2key="' . $key . '">' . $value['name'];
                                        echo '</label>';
                                    }
                                    ?>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Options}}</label>
                                <div class="col-sm-6">
                                    <label class="checkbox-inline">
                                        <input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" checked> {{Activer}}
                                    </label>
                                    <label class="checkbox-inline">
                                        <input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" checked> {{Visible}}
                                    </label>
                                </div>
                            </div>

                            <legend><i class="fas fa-robot"></i> {{Informations robot}}</legend>

                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Modèle}}
                                    <sup><i class="fas fa-question-circle tooltips" title="{{Modèle détecté automatiquement par le démon}}"></i></sup>
                                </label>
                                <div class="col-sm-6">
                                    <input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="model" readonly placeholder="{{Auto-détecté}}">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Device ID}}
                                    <sup><i class="fas fa-question-circle tooltips" title="{{Identifiant unique de l'appareil Ecovacs}}"></i></sup>
                                </label>
                                <div class="col-sm-6">
                                    <input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="device_id" readonly placeholder="{{Auto-détecté}}">
                                </div>
                            </div>
                        </div><!-- /.col-lg-6 gauche -->

                        <!-- Colonne droite : commentaire -->
                        <div class="col-lg-6">
                            <legend><i class="fas fa-info"></i> {{Informations}}</legend>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Commentaire}}</label>
                                <div class="col-sm-6">
                                    <textarea class="form-control eqLogicAttr autogrow" data-l1key="comment"></textarea>
                                </div>
                            </div>
                        </div><!-- /.col-lg-6 droite -->

                    </fieldset>
                </form>
            </div><!-- /#eqlogictab -->

            <!-- ── Onglet Commandes ── -->
            <div role="tabpanel" class="tab-pane" id="commandtab">
                <a class="btn btn-default btn-sm pull-right cmdAction" data-action="add" style="margin-top:5px;">
                    <i class="fas fa-plus-circle"></i> {{Ajouter une commande}}
                </a>
                <br><br>
                <div class="table-responsive">
                    <table id="table_cmd" class="table table-bordered table-condensed">
                        <thead>
                            <tr>
                                <th class="hidden-xs" style="min-width:50px;width:70px;">ID</th>
                                <th style="min-width:200px;width:350px;">{{Nom}}</th>
                                <th>{{Type}}</th>
                                <th style="min-width:260px;">{{Options}}</th>
                                <th>{{État}}</th>
                                <th style="min-width:80px;width:200px;">{{Actions}}</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div><!-- /#commandtab -->

        </div><!-- /.tab-content -->
    </div><!-- /.eqLogic -->

</div><!-- /.row row-overflow -->

<?php include_file('desktop', 'ecovacs', 'js', 'ecovacs'); ?>
<?php include_file('core', 'plugin.template', 'js'); ?>
