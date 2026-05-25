/* Plugin Ecovacs – JS interface */

$("#table_cmd").sortable({
    axis: "y", cursor: "move", items: ".cmd",
    placeholder: "ui-state-highlight", tolerance: "intersect", forcePlaceholderSize: true
});

function addCmdToTable(_cmd) {
    if (!isset(_cmd)) {
        _cmd = {configuration: {}};
    }
    if (!isset(_cmd.configuration)) {
        _cmd.configuration = {};
    }

    var tr = '<tr class="cmd" data-cmd_id="' + init(_cmd.id) + '">';

    // ID
    tr += '<td class="hidden-xs">';
    tr += '<span class="cmdAttr" data-l1key="id"></span>';
    tr += '</td>';

    // Nom
    tr += '<td>';
    tr += '<div class="input-group">';
    tr += '<input class="cmdAttr form-control input-sm roundedLeft" data-l1key="name" placeholder="{{Nom}}">';
    tr += '<span class="input-group-btn">';
    tr += '<a class="cmdAction btn btn-sm btn-default" data-l1key="chooseIcon"><i class="fas fa-icons"></i></a>';
    tr += '</span>';
    tr += '<span class="cmdAttr input-group-addon roundedRight" data-l1key="display" data-l2key="icon" style="font-size:19px;padding:0 5px 0 0!important;"></span>';
    tr += '</div>';
    tr += '</td>';

    // Type
    tr += '<td>';
    tr += '<span class="type" type="' + init(_cmd.type) + '">' + jeedom.cmd.availableType() + '</span>';
    tr += '<span class="subType" subType="' + init(_cmd.subType) + '"></span>';
    tr += '</td>';

    // Options
    tr += '<td>';
    tr += '<input class="cmdAttr form-control input-sm" data-l1key="unite" placeholder="{{Unité}}" style="width:30%;display:inline-block;margin-right:2px;">';
    tr += '<input class="cmdAttr form-control input-sm" data-l1key="configuration" data-l2key="listValue" placeholder="{{Liste}}" style="width:65%;display:inline-block;">';
    tr += '<br>';
    tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isVisible" checked/>{{Afficher}}</label> ';
    tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isHistorized"/>{{Historiser}}</label> ';
    tr += '</td>';

    // État
    tr += '<td>';
    tr += '<span class="cmdAttr" data-l1key="htmlstate"></span>';
    tr += '</td>';

    // Actions
    tr += '<td>';
    if (is_numeric(_cmd.id)) {
        tr += '<a class="btn btn-default btn-xs cmdAction" data-action="configure"><i class="fas fa-cogs"></i></a> ';
        tr += '<a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fa fa-rss"></i> {{Tester}}</a>';
    }
    tr += '<i class="fas fa-minus-circle pull-right cmdAction cursor" data-action="remove"></i>';
    tr += '</td>';

    tr += '</tr>';

    $('#table_cmd tbody').append(tr);
    var trLast = $('#table_cmd tbody tr').last();
    trLast.setValues(_cmd, '.cmdAttr');
    jeedom.cmd.changeType(trLast, init(_cmd.subType));
}