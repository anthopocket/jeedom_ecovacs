<?php
/* This file is part of Jeedom. */

require_once __DIR__ . '/../../../../core/php/core.inc.php';

class ecovacs extends eqLogic {

	public static function dependancy_info() {
		$return = array(
			'log'           => 'ecovacs_dep_install',
			'progress_file' => jeedom::getTmpFolder('ecovacs') . '/dependance',
			'state'         => 'nok',
		);
		$venv_python = dirname(__FILE__) . '/../../resources/python_venv/bin/python3';
		if (!file_exists($venv_python)) return $return;
		$py_ver = shell_exec(escapeshellarg($venv_python) . ' --version 2>&1');
		if (strpos($py_ver, '3.14') === false) return $return;
		$pkg = shell_exec(escapeshellarg($venv_python) . ' -m pip show deebot-client 2>/dev/null');
		if (preg_match('/^Version:\s*(\d+)\./m', $pkg, $m) && intval($m[1]) >= 18) {
			$return['state'] = 'ok';
		}
		return $return;
	}

	public static function dependancy_install() {
		log::remove('ecovacs_dep_install');
		$script = dirname(__FILE__) . '/../../resources/install_packages.sh';
		return array(
			'script' => $script . ' ' . jeedom::getTmpFolder('ecovacs') . '/dependance',
			'log'    => log::getPathToLog('ecovacs_dep_install'),
		);
	}

	public static function cron5() {
		$info = self::deamon_info();
		if ($info['launchable'] === 'ok' && $info['state'] !== 'ok') {
			self::deamon_start();
		}
	}

	public static function deamon_info() {
		$return = array(
			'log'   => __CLASS__,
			'state' => 'nok',
		);
		$pid_file = jeedom::getTmpFolder(__CLASS__) . '/deamon.pid';
		if (file_exists($pid_file)) {
			$pid = trim(file_get_contents($pid_file));
			if ($pid !== '' && posix_getsid(intval($pid))) {
				$return['state'] = 'ok';
				$return['pid']   = $pid;
			}
		}
		$return['launchable'] = 'ok';
		$dep = self::dependancy_info();
		if ($dep['state'] !== 'ok') {
			$return['launchable']         = 'nok';
			$return['launchable_message'] = __('Dependances non installees (Python 3.14 + deebot-client 18.x). Relancez les dependances.', __FILE__);
			return $return;
		}
		if (config::byKey('login', __CLASS__) == '' || config::byKey('password', __CLASS__) == '') {
			$return['launchable']         = 'nok';
			$return['launchable_message'] = __('Identifiants non configures', __FILE__);
		}
		return $return;
	}

	public static function deamon_start($_debug = false) {
		self::deamon_stop();
		$path     = realpath(dirname(__FILE__) . '/../../resources');
		$pid_file = jeedom::getTmpFolder(__CLASS__) . '/deamon.pid';
		$python   = $path . '/python_venv/bin/python3';
		if (!file_exists($python)) {
			throw new Exception('Python 3.14 venv introuvable. Relancez les dependances.');
		}
		if (!is_dir(jeedom::getTmpFolder(__CLASS__))) {
			mkdir(jeedom::getTmpFolder(__CLASS__), 0775, true);
		}
		// Tuer tout processus occupant le port socket avant de démarrer
		$port_kill = intval(config::byKey('socketport', __CLASS__, 55009));
		exec("ss -tlnp 2>/dev/null | grep ':{$port_kill}' | grep -oP 'pid=\K[0-9]+' | xargs -r kill -9 2>/dev/null");
		exec("lsof -ti tcp:{$port_kill} 2>/dev/null | xargs -r kill -9 2>/dev/null");
		sleep(2);
		$callback = network::getNetworkAccess('internal', 'htmlfull')
		          . '/plugins/ecovacs/core/php/ecovacs.inc.php';
		$cmd  = $python . ' ' . $path . '/ecovacsCmd.py';
		$cmd .= ' --apikey '     . jeedom::getApiKey(__CLASS__);
		$cmd .= ' --callback '   . escapeshellarg($callback);
		$cmd .= ' --login '      . escapeshellarg(config::byKey('login',    __CLASS__));
		$cmd .= ' --password '   . escapeshellarg(config::byKey('password', __CLASS__));
		$cmd .= ' --socketport ' . intval(config::byKey('socketport', __CLASS__, 55009));
		$cmd .= ' --loglevel '   . ($_debug ? 'debug' : 'warning');
		$cmd .= ' --pid '        . $pid_file;
		$cmd .= ' >> ' . log::getPathToLog('ecovacs') . ' 2>&1 &';
		log::add('ecovacs', 'info', 'Demarrage demon : ' . $cmd);
		exec($cmd);
		for ($i = 0; $i < 10; $i++) {
			if (file_exists($pid_file)) break;
			usleep(200000);
		}
	}

	public static function deamon_stop() {
		$pid_file = jeedom::getTmpFolder(__CLASS__) . '/deamon.pid';
		if (file_exists($pid_file)) {
			$pid = intval(trim(file_get_contents($pid_file)));
			if ($pid > 0 && posix_getsid($pid)) {
				posix_kill($pid, 15);
				sleep(1);
			}
			@unlink($pid_file);
		}
		@exec("pkill -f 'ecovacsCmd.py'");
	}

	public static function callback($_params) {
		$did = isset($_params['did']) ? $_params['did'] : (isset($_params['device_id']) ? $_params['device_id'] : '');
		if ($did === '') {
			log::add('ecovacs', 'warning', 'Callback sans did');
			return;
		}
		$eqLogic = null;
		foreach (self::byType('ecovacs') as $eq) {
			if ($eq->getConfiguration('did') == $did || $eq->getConfiguration('device_id') == $did) {
				$eqLogic = $eq;
				break;
			}
		}
		if (!is_object($eqLogic)) {
			$eqLogic = self::_autoCreateEquipment($did, $_params);
		}
		$type = isset($_params['type']) ? $_params['type'] : '';
		switch ($type) {
			case 'device_info':
				if (!empty($_params['name'])) $eqLogic->setName($_params['name']);
				$eqLogic->setConfiguration('model', isset($_params['model']) ? $_params['model'] : '');
				$eqLogic->setConfiguration('class', isset($_params['class']) ? $_params['class'] : '');
				$eqLogic->save();
				return;
			case 'availability':
				$eqLogic->checkAndUpdateInfoCmd('availability', $_params['available'] ? 1 : 0);
				return;
			case 'battery':
				$eqLogic->checkAndUpdateInfoCmd('battery', isset($_params['value']) ? $_params['value'] : 0);
				return;
			case 'state':
				$eqLogic->checkAndUpdateInfoCmd('state', self::translate(isset($_params['state']) ? $_params['state'] : ''));
				return;
			case 'fan_speed':
				$eqLogic->checkAndUpdateInfoCmd('fan_speed', strtolower(isset($_params['speed']) ? $_params['speed'] : ''));
				return;
			case 'work_mode':
				$eqLogic->checkAndUpdateInfoCmd('work_mode', strtolower(isset($_params['mode']) ? $_params['mode'] : ''));
				return;
			case 'water_amount':
				$eqLogic->checkAndUpdateInfoCmd('water_amount', isset($_params['amount']) ? $_params['amount'] : 0);
				return;
			case 'water_custom_amount':
				$eqLogic->checkAndUpdateInfoCmd('water_amount', isset($_params['value']) ? $_params['value'] : 0);
				return;
			case 'sweep_type':
				$eqLogic->checkAndUpdateInfoCmd('sweep_type', strtolower(isset($_params['sweep_type']) ? $_params['sweep_type'] : ''));
				return;
			case 'mop_attached':
				$eqLogic->checkAndUpdateInfoCmd('mop_attached', $_params['attached'] ? 1 : 0);
				return;
			case 'stats':
				$eqLogic->checkAndUpdateInfoCmd('stats_area',     isset($_params['area'])     ? $_params['area']     : 0);
				$eqLogic->checkAndUpdateInfoCmd('stats_duration', isset($_params['duration']) ? round($_params['duration'] / 60, 1) : 0);
				$eqLogic->checkAndUpdateInfoCmd('stats_mode', self::translate(isset($_params['mode']) ? $_params['mode'] : ''));
				return;
			case 'total_stats':
				$eqLogic->checkAndUpdateInfoCmd('total_area',      isset($_params['area'])      ? $_params['area']      : 0);
				$eqLogic->checkAndUpdateInfoCmd('total_time',      isset($_params['time'])      ? round($_params['time'] / 3600, 1) : 0);
				$eqLogic->checkAndUpdateInfoCmd('total_cleanings', isset($_params['cleanings']) ? $_params['cleanings'] : 0);
				return;
			case 'error':
				$code = isset($_params['code']) ? intval($_params['code']) : 0;
				$desc = isset(self::$ERROR_CODES[$code])
					? self::$ERROR_CODES[$code]
					: (isset($_params['description']) ? $_params['description'] : '');
				$eqLogic->checkAndUpdateInfoCmd('error_code', $code);
				$eqLogic->checkAndUpdateInfoCmd('error_desc', $desc);
				return;
			case 'lifespan':
				$component = strtolower(isset($_params['component']) ? $_params['component'] : '');
				if ($component !== '') {
					$eqLogic->checkAndUpdateInfoCmd('lifespan_' . $component, isset($_params['percent']) ? $_params['percent'] : 0);
				}
				return;
			case 'network':
				$eqLogic->checkAndUpdateInfoCmd('network_ip',   isset($_params['ip'])   ? $_params['ip']   : '');
				$eqLogic->checkAndUpdateInfoCmd('network_ssid', isset($_params['ssid']) ? $_params['ssid'] : '');
				$eqLogic->checkAndUpdateInfoCmd('network_rssi', isset($_params['rssi']) ? $_params['rssi'] : 0);
				$eqLogic->checkAndUpdateInfoCmd('network_mac',  isset($_params['mac'])  ? $_params['mac']  : '');
				return;
			case 'clean_logs':
				if (!empty($_params['entries'])) {
					$last = $_params['entries'][0];
					$eqLogic->checkAndUpdateInfoCmd('last_clean_area',     isset($last['area'])        ? $last['area']        : 0);
					$eqLogic->checkAndUpdateInfoCmd('last_clean_duration', isset($last['duration'])    ? $last['duration']    : 0);
					$eqLogic->checkAndUpdateInfoCmd('last_clean_type',     isset($last['type'])        ? $last['type']        : '');
					$eqLogic->checkAndUpdateInfoCmd('last_clean_stop',     isset($last['stop_reason']) ? $last['stop_reason'] : '');
				}
				return;
			case 'station_state':
				$state = isset($_params['state']) ? $_params['state'] : '';
				$eqLogic->checkAndUpdateInfoCmd('station_state',   $state);
				$eqLogic->checkAndUpdateInfoCmd('station_cleaning', ($state === 'emptying') ? 1 : 0);
				$eqLogic->checkAndUpdateInfoCmd('station_washing',  ($state === 'washing')  ? 1 : 0);
				$eqLogic->checkAndUpdateInfoCmd('station_drying',   ($state === 'drying')   ? 1 : 0);
				return;
			case 'auto_empty':
				$eqLogic->checkAndUpdateInfoCmd('auto_empty_enabled',   $_params['enabled'] ? 1 : 0);
				$eqLogic->checkAndUpdateInfoCmd('auto_empty_frequency', isset($_params['frequency']) ? $_params['frequency'] : '');
				return;
			case 'mop_wash_frequency':
				$eqLogic->checkAndUpdateInfoCmd('mop_wash_frequency', isset($_params['value']) ? $_params['value'] : 0);
				return;
			case 'wash_info':
				$eqLogic->checkAndUpdateInfoCmd('wash_amount',    isset($_params['amount'])   ? $_params['amount']   : 0);
				$eqLogic->checkAndUpdateInfoCmd('wash_dry_mop',  isset($_params['dry_mop'])  ? $_params['dry_mop']  : 0);
				$eqLogic->checkAndUpdateInfoCmd('wash_duration', isset($_params['duration']) ? $_params['duration'] : 0);
				$eqLogic->checkAndUpdateInfoCmd('wash_interval', isset($_params['interval']) ? $_params['interval'] : 0);
				$eqLogic->checkAndUpdateInfoCmd('wash_mode',     isset($_params['mode'])     ? $_params['mode']     : 0);
				return;
			case 'charge_state':
				$eqLogic->checkAndUpdateInfoCmd('is_charging', isset($_params['is_charging']) ? $_params['is_charging'] : 0);
				$eqLogic->checkAndUpdateInfoCmd('charge_mode', self::translate(isset($_params['mode']) ? $_params['mode'] : ''));
				return;
			case 'water_info_full':
				$eqLogic->checkAndUpdateInfoCmd('water_amount', isset($_params['custom_amount']) ? $_params['custom_amount'] : 0);
				return;
			case 'volume':
				$eqLogic->checkAndUpdateInfoCmd('volume', isset($_params['value']) ? $_params['value'] : 0);
				return;
			case 'ota':
				$eqLogic->checkAndUpdateInfoCmd('firmware_version', isset($_params['version']) ? $_params['version'] : '');
				return;
			case 'battery_extended':
				$eqLogic->checkAndUpdateInfoCmd('battery_voltage',     isset($_params['voltage'])     ? $_params['voltage']     : 0);
				$eqLogic->checkAndUpdateInfoCmd('battery_temperature', isset($_params['temperature']) ? $_params['temperature'] : 0);
				return;
			case 'work_state':
				$eqLogic->checkAndUpdateInfoCmd('state', self::translate(isset($_params['robot_state']) ? $_params['robot_state'] : ''));
				$eqLogic->checkAndUpdateInfoCmd('station_state', self::translate(isset($_params['station_state']) ? $_params['station_state'] : ''));
				return;

			case 'work_progress':
				// clean/dry/wash sont des pourcentages 0-100
				$eqLogic->checkAndUpdateInfoCmd('station_cleaning', isset($_params['clean']) ? intval($_params['clean']) : 0);
				$eqLogic->checkAndUpdateInfoCmd('station_drying',   isset($_params['dry'])   ? intval($_params['dry'])   : 0);
				$eqLogic->checkAndUpdateInfoCmd('station_washing',  isset($_params['wash'])  ? intval($_params['wash'])  : 0);
				return;
			case 'station_state_raw':
				$eqLogic->checkAndUpdateInfoCmd('station_state', self::translate(isset($_params['state']) ? $_params['state'] : ''));
				$eqLogic->checkAndUpdateInfoCmd('dirty_water_full', isset($_params['dirty_water_full']) ? intval($_params['dirty_water_full']) : 0);
				return;
			default:
				log::add('ecovacs', 'debug', "Type callback non gere : {$type}");
				return;
		}
	}

	// Traductions des valeurs
	private static $ERROR_CODES = array(
		0    => 'Aucune erreur',
		100  => 'Aucune erreur',
		101  => 'Batterie faible',
		102  => 'Robot soulevé',
		103  => 'Roue motrice bloquée',
		104  => 'Capteurs anti-chute encrassés',
		105  => 'Robot coincé',
		106  => 'Brosse latérale usée',
		107  => 'Filtre poussière usé',
		108  => 'Brosse latérale emmêlée',
		109  => 'Brosse principale emmêlée',
		110  => 'Bac à poussière non installé',
		111  => 'Capteur de choc bloqué',
		112  => 'Capteur laser défaillant',
		113  => 'Brosse principale usée',
		114  => 'Bac à poussière plein',
		115  => 'Erreur batterie',
		118  => 'Filtre bloqué',
		119  => 'Erreur ventilateur',
		120  => 'Erreur bac eau',
		121  => 'Serpillière bloquée',
		125  => 'Réservoir défaillant',
		126  => 'Réservoir non installé',
		128  => 'Serpillières non installées',
		129  => 'Serpillière emmêlée',
		201  => 'Filtre à air non installé',
		203  => 'Petite roue bloquée',
		204  => 'Roue suspendue',
		209  => 'Capteur ToF défaillant',
		301  => 'Réservoir eau propre vide',
		302  => 'Bac eaux usées plein',
		303  => 'Réservoir eau propre absent',
		304  => 'Bac eaux usées absent',
		305  => 'Bac eau sale plein',
		306  => 'Filtre station non installé',
		307  => 'Station de nettoyage défaillante',
		308  => 'Problème de communication',
		310  => 'Couvercle ouvert',
		311  => 'Remplacer le sac à poussière',
		312  => 'Sac à poussière plein',
		314  => 'Module eau non installé',
		315  => 'Module eau non installé',
		316  => 'Soie de nettoyage pleine',
		317  => 'Problème remplissage eau propre',
		318  => 'Bac eau sale plein',
		319  => 'Solution nettoyante presque vide',
		322  => 'Réservoir eau propre vide ou absent',
		323  => 'Bac eau sale plein ou absent',
		1007 => 'Serpillière bouchée',
		1021 => 'Nettoyage terminé',
		1024 => 'Batterie faible, retour base',
		1052 => 'Remplacer la serpillière',
		1053 => 'Tâche interrompue, retour base',
		1094 => 'Séchage serpillière en cours',
		2036 => 'Obstacle détecté',
	);

	private static $TRANSLATIONS = array(
		// États robot
		'docked'    => 'En base',
		'cleaning'  => 'Nettoyage',
		'returning' => 'Retour base',
		'paused'    => 'En pause',
		'idle'      => 'Inactif',
		'error'     => 'Erreur',
		'sleeping'  => 'Veille',
		// États station
		'emptying'  => 'Vidage bac',
		'washing'   => 'Lavage serpillière',
		'drying'    => 'Séchage serpillière',
		// Puissance
		'quiet'     => 'Silencieux',
		'normal'    => 'Normal',
		'max'       => 'Maximum',
		'max_plus'  => 'Maximum+',
		// Mode travail
		'vacuum'           => 'Aspiration',
		'mop'              => 'Serpillière',
		'vacuum_and_mop'   => 'Aspiration + Serpillière',
		'mop_after_vacuum' => 'Serpillière après aspiration',
		// Type balayage
		'standard'  => 'Standard',
		'deep'      => 'Profond',
		// Mode charge
		'slot'      => 'Sur base',
		// Erreurs
		'NoError: Robot is operational' => 'Aucune erreur',
		'Mopping Pad Plate is tangled'  => 'Serpillière emmêlée',
		'WheelAbnormal: Driving Wheel malfunction' => 'Roue bloquée',
		// Mode auto
		'auto'      => 'Auto',
	);

	private static function translate($value) {
		if (!is_string($value)) return $value;
		$key = strtolower($value);
		if (isset(self::$TRANSLATIONS[$key])) return self::$TRANSLATIONS[$key];
		if (isset(self::$TRANSLATIONS[$value])) return self::$TRANSLATIONS[$value];
		return $value;
	}

	// Met a jour une commande info existante (ne cree pas)
	public function checkAndUpdateInfoCmd($logicalId, $value) {
		$cmd = $this->getCmd('info', $logicalId);
		if (is_object($cmd)) {
			$cmd->event($value);
		}
	}

	private static function _autoCreateEquipment($did, $params = array()) {
		$name = isset($params['name']) ? $params['name'] : ('Ecovacs ' . substr($did, 0, 8));
		$eqLogic = new self();
		$eqLogic->setName($name);
		$eqLogic->setEqType_name('ecovacs');
		$eqLogic->setConfiguration('did',       $did);
		$eqLogic->setConfiguration('device_id', $did);
		$eqLogic->setConfiguration('model',     isset($params['model']) ? $params['model'] : '');
		$eqLogic->setIsEnable(1);
		$eqLogic->setIsVisible(1);
		$eqLogic->save();
		log::add('ecovacs', 'info', "Equipement cree : {$name} (did={$did})");
		return $eqLogic;
	}

	public function postSave() {
		$this->createCommands();
	}

	public function createCommands() {
		// Format de chaque entree : array(nom, logicalId, type, subType, order, opts)
		// opts est un tableau associatif : unite, listValue, value
		$defs = array(
			// ── Actions simples ───────────────────────────────────────────────
			array('Nettoyer',              'clean',              'action', 'other',   1,  array()),
			array('Pause',                 'pause',              'action', 'other',   2,  array()),
			array('Reprendre',             'resume',             'action', 'other',   3,  array()),
			array('Arrêter',               'stop',               'action', 'other',   4,  array()),
			array('Retour base',           'charge',             'action', 'other',   5,  array()),
			array('Localiser',             'locate',             'action', 'other',   6,  array()),
			array('Rafraîchir',            'refresh',            'action', 'other',   7,  array()),
			// ── Actions liste ─────────────────────────────────────────────────
			array('Puissance aspiration',  'set_fan_speed',      'action', 'select',  10,
				array('listValue' => 'quiet|Silencieux;normal|Normal;max|Maximum;max_plus|Maximum+', 'value' => 'fan_speed')),
			array('Mode travail',          'set_work_mode',      'action', 'select',  11,
				array('listValue' => 'vacuum|Aspiration;mop|Serpillière;vacuum_and_mop|Aspiration+Serpillière;mop_after_vacuum|Serpillière après aspiration', 'value' => 'work_mode')),
			array('Niveau eau serpillère', 'set_water_amount',   'action', 'slider',  12,
				array('minValue' => 1, 'maxValue' => 50, 'value' => 'water_amount')),
			array('Mode lavage serpillère','set_sweep_type',     'action', 'select',  13,
				array('listValue' => 'standard|Standard;deep|Profond')),
			// Actions station
			array('Vider le bac',          'station_empty',      'action', 'other',   15, array()),
			array('Laver serpillières',      'station_wash',       'action', 'other',   16, array()),
			array('Sécher serpillières',     'station_dry',        'action', 'other',   17, array()),
			array('Nettoyer station',       'station_clean',      'action', 'other',   18, array()),
			// ── Infos etat ────────────────────────────────────────────────────
			array('Disponible',            'availability',       'info', 'binary',    20, array()),
			array('Batterie',              'battery',            'info', 'numeric',   21, array('unite' => '%')),
			array('État',                  'state',              'info', 'string',    22, array()),
			array('Puissance',             'fan_speed',          'info', 'string',    23, array()),
			array('Mode travail actuel','work_mode',          'info', 'string',    24, array()),
			array('Serpillière',            'mop_attached',       'info', 'binary',    25, array()),
			array('Type balayage',         'sweep_type',         'info', 'string',    26, array()),
			array('Niveau eau',            'water_amount',       'info', 'numeric',   27, array()),
			array('État station',          'station_state',      'info', 'string',    28, array()),
			array('En charge',             'is_charging',        'info', 'binary',    29, array()),
			array('Mode charge',           'charge_mode',        'info', 'string',    30, array()),
			// ── Infos session ─────────────────────────────────────────────────
			array('Surface session',       'stats_area',         'info', 'numeric',   40, array('unite' => 'm2')),
			array('Durée session',         'stats_duration',     'info', 'numeric',   41, array('unite' => 'min')),
			array('Surface totale',        'total_area',         'info', 'numeric',   43, array('unite' => 'm2')),
			array('Temps total',           'total_time',         'info', 'numeric',   44, array('unite' => 'h')),
			array('Nettoyages total',      'total_cleanings',    'info', 'numeric',   45, array()),
			// ── Erreurs ───────────────────────────────────────────────────────
			array('Code erreur',           'error_code',         'info', 'numeric',   50, array()),
			array('Description erreur',    'error_desc',         'info', 'string',    51, array()),
			// ── Consommables ──────────────────────────────────────────────────
			array('Brosse principale',     'lifespan_brush',             'info', 'numeric', 60, array('unite' => '%')),
			array('Brosse latérale',       'lifespan_side_brush',        'info', 'numeric', 61, array('unite' => '%')),
			array('Filtre HEPA',           'lifespan_filter',            'info', 'numeric', 62, array('unite' => '%')),
			array('Sac à poussière',       'lifespan_dust_bag',          'info', 'numeric', 63, array('unite' => '%')),
			array('Serpillière rotative',   'lifespan_round_mop',         'info', 'numeric', 64, array('unite' => '%')),
			array('Entretien station',     'lifespan_unit_care',         'info', 'numeric', 65, array('unite' => '%')),
			array('Bac eaux usées',        'lifespan_sewage_box',        'info', 'numeric', 66, array('unite' => '%')),
			array('Réservoir eau',         'lifespan_water_sink',        'info', 'numeric', 67, array('unite' => '%')),
			array('Solution nettoyante',   'lifespan_cleaning_solution', 'info', 'numeric', 68, array('unite' => '%')),
			// ── Reseau ────────────────────────────────────────────────────────
			array('IP',                    'network_ip',         'info', 'string',    80, array()),
			array('SSID',                  'network_ssid',       'info', 'string',    81, array()),
			array('Signal WiFi',           'network_rssi',       'info', 'numeric',   82, array()),
			array('MAC',                   'network_mac',        'info', 'string',    83, array()),
			// ── Station / lavage ──────────────────────────────────────────────
			// Station en cours (progression 0-100%)
			array('Station vidage %',      'station_cleaning',  'info', 'numeric',   105, array('unite' => '%')),
			array('Station séchage %',     'station_drying',    'info', 'numeric',   106, array('unite' => '%')),
			array('Station lavage %',      'station_washing',   'info', 'numeric',   107, array('unite' => '%')),
			// Bac eau sale plein (capteur flotteur)
			array('Bac eau sale plein',    'dirty_water_full',  'info', 'binary',    109, array()),
			// ── Divers ────────────────────────────────────────────────────────
			array('Volume',                'volume',             'info', 'numeric',   100, array()),
		);

		foreach ($defs as $d) {
			$name      = $d[0];
			$logicalId = $d[1];
			$type      = $d[2];
			$subType   = $d[3];
			$order     = $d[4];
			$opts      = isset($d[5]) ? $d[5] : array();

			$cmd = $this->getCmd($type, $logicalId);
			if (!is_object($cmd)) {
				$cmd = new ecovacsCmd();
				$cmd->setLogicalId($logicalId);
				$cmd->setEqLogic_id($this->getId());
				$cmd->setIsVisible(1);
			}
			$cmd->setName($name);
			$cmd->setType($type);
			$cmd->setSubType($subType);
			$cmd->setOrder($order);
			if (!empty($opts['unite']))     $cmd->setUnite($opts['unite']);
			if (!empty($opts['listValue'])) $cmd->setConfiguration('listValue', $opts['listValue']);
			if (isset($opts['minValue']))   $cmd->setConfiguration('minValue', $opts['minValue']);
			if (isset($opts['maxValue']))   $cmd->setConfiguration('maxValue', $opts['maxValue']);
			$cmd->save();
		}

		// Lier actions select aux infos correspondantes
		$valueMap = array(
			'set_fan_speed'    => 'fan_speed',
			'set_work_mode'    => 'work_mode',
			'set_water_amount' => 'water_amount',
			'set_sweep_type'   => 'sweep_type',
			'set_wash_interval'=> 'mop_wash_frequency',
		);
		foreach ($valueMap as $actionId => $infoId) {
			$cmdAction = $this->getCmd('action', $actionId);
			$cmdInfo   = $this->getCmd('info',   $infoId);
			if (is_object($cmdAction) && is_object($cmdInfo)) {
				$cmdAction->setValue($cmdInfo->getId());
				$cmdAction->save();
			}
		}
	}

	public static function createDefaultCommands($eqLogic) {
		$eqLogic->createCommands();
	}

}

class ecovacsCmd extends cmd {

	public function execute($_options = array()) {
		if ($this->getType() !== 'action') return null;

		$eqLogic   = $this->getEqLogic();
		$logicalId = $this->getLogicalId();
		$did       = $eqLogic->getConfiguration('did');
		if (empty($did)) $did = $eqLogic->getConfiguration('device_id');

		switch ($logicalId) {
			case 'clean':
				$msg = array('action' => 'clean', 'clean_action' => 'start');
				break;
			case 'pause':
				$msg = array('action' => 'clean', 'clean_action' => 'pause');
				break;
			case 'resume':
				$msg = array('action' => 'clean', 'clean_action' => 'resume');
				break;
			case 'stop':
				$msg = array('action' => 'clean', 'clean_action' => 'stop');
				break;
			case 'charge':
				$msg = array('action' => 'charge');
				break;
			case 'locate':
				$msg = array('action' => 'locate');
				break;
			case 'refresh':
				$msg = array('action' => 'refresh');
				break;
			case 'set_fan_speed':
				$msg = array('action' => 'fan_speed', 'level' => isset($_options['select']) ? $_options['select'] : 'normal');
				break;
			case 'set_work_mode':
				$msg = array('action' => 'work_mode', 'mode' => isset($_options['select']) ? $_options['select'] : 'vacuum');
				break;
			case 'set_water_amount':
				// T20 Omni : valeur numérique via slider
				$val = isset($_options['slider']) ? intval($_options['slider']) : (isset($_options['select']) ? $_options['select'] : 25);
				$msg = array('action' => 'water_amount', 'custom_amount' => intval($val));
				break;
			case 'set_sweep_type':
				// Récupérer le niveau d'eau actuel pour ne pas le réinitialiser
				$water_cmd = $eqLogic->getCmd('info', 'water_amount');
				$current_amount = is_object($water_cmd) ? intval($water_cmd->execCmd()) : 25;
				$msg = array(
					'action'        => 'sweep_type',
					'value'         => isset($_options['select']) ? $_options['select'] : 'standard',
					'custom_amount' => $current_amount,
				);
				break;
			case 'set_wash_interval':
				$msg = array('action' => 'wash_interval', 'value' => intval(isset($_options['select']) ? $_options['select'] : 25));
				break;
			case 'station_empty':
				$msg = array('action' => 'station_action', 'value' => 'empty_dustbin');
				break;
			case 'station_wash':
				$msg = array('action' => 'station_action', 'value' => 'wash_mop');
				break;
			case 'station_dry':
				$msg = array('action' => 'station_action', 'value' => 'dry_mop');
				break;
			case 'station_clean':
				$msg = array('action' => 'station_action', 'value' => 'clean_base');
				break;
			default:
				log::add('ecovacs', 'warning', "Commande inconnue : {$logicalId}");
				return null;
		}

		$msg['apikey'] = jeedom::getApiKey('ecovacs');
		$msg['did']    = $did;

		$port = intval(config::byKey('socketport', 'ecovacs', 55009));
		$sock = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		if ($sock && @socket_connect($sock, '127.0.0.1', $port)) {
			socket_write($sock, json_encode($msg) . "\n");
			socket_close($sock);
			return true;
		}
		log::add('ecovacs', 'error', "Impossible de contacter le demon sur le port {$port}");
		return false;
	}
}
