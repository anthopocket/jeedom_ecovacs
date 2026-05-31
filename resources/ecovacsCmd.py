#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
ecovacsed.py – Démon Jeedom pour le plugin Ecovacs Deebot
==========================================================
Cible : deebot-client >= 18.0.0 / Python >= 3.14

Architecture :
  - Un Device par robot, tous abonnés via le même MqttClient
  - events.subscribe() officiel – aucun monkey-patch
  - Reconnexion MQTT gérée nativement par deebot-client
  - Serveur socket TCP (127.0.0.1) pour recevoir les commandes Jeedom
  - Callback HTTP vers Jeedom pour chaque événement robot
  - Loglevel configurable via --loglevel
"""

from __future__ import annotations

import argparse
import asyncio
import json
import logging
import os
import signal
import socket
import sys
import threading
from typing import Any

# ── Dépendances tierces ───────────────────────────────────────────────────────
try:
    import aiohttp
    from deebot_client.authentication import Authenticator, create_rest_config
    from deebot_client.api_client import ApiClient
    from deebot_client.device import Device
    from deebot_client.models import DeviceInfo, State, CleanAction
    from deebot_client.mqtt_client import MqttClient, create_mqtt_config
    from deebot_client.util import md5
    # Events principaux
    from deebot_client.events import (
        AvailabilityEvent,
        BatteryEvent,
        CleanLogEvent,
        ErrorEvent,
        FanSpeedEvent,
        LifeSpanEvent,
        NetworkInfoEvent,
        StateEvent,
        StatsEvent,
        TotalStatsEvent,
    )
    from deebot_client.events.work_mode import WorkModeEvent, WorkMode
    from deebot_client.events.fan_speed import FanSpeedLevel
    from deebot_client.events import LifeSpan
    from deebot_client.events import VolumeEvent
    from deebot_client.events.auto_empty import AutoEmptyEvent, Frequency
    from deebot_client.events.station import StationEvent as StationStateEvent, State as StationState
    from deebot_client.events.mop_auto_wash_frequency import MopAutoWashFrequencyEvent
    # Events water – 18.x : WaterInfoEvent éclatée en 3 events séparés
    from deebot_client.events.water_info import (
        WaterAmount,
        WaterAmountEvent,
        WaterCustomAmountEvent,
        WaterSweepTypeEvent,
        MopAttachedEvent,
        SweepType,
    )
    # Commandes
    from deebot_client.commands.json.clean import CleanV2, Clean
    from deebot_client.commands.json.charge import Charge
    from deebot_client.commands.json.station_action import StationAction as StationActionCmd
    from deebot_client.commands import StationAction
    from deebot_client.commands.json.charge_state import GetChargeState
    from deebot_client.commands.json.volume import GetVolume, SetVolume
    from deebot_client.commands.json.play_sound import PlaySound
    from deebot_client.commands.json.fan_speed import SetFanSpeed, GetFanSpeed
    from deebot_client.commands.json.work_mode import SetWorkMode, GetWorkMode
    from deebot_client.commands.json.water_info import SetWaterInfo, GetWaterInfo
    from deebot_client.commands.json.mop_auto_wash_frequency import GetMopAutoWashFrequency, SetMopAutoWashFrequency
    from deebot_client.commands.json.auto_empty import GetAutoEmpty, SetAutoEmpty
    from deebot_client.commands.json.stats import GetStats, GetTotalStats
    from deebot_client.commands.json.error import GetError
    from deebot_client.commands.json.clean_logs import GetCleanLogs
    from deebot_client.commands.json.life_span import GetLifeSpan
    from deebot_client.commands.json.network import GetNetInfo
    from deebot_client.commands.json.battery import GetBattery
except ImportError as exc:
    print(f"ERREUR : dépendances manquantes – {exc}")
    print("Lancez l'installation des dépendances depuis l'interface Jeedom.")
    sys.exit(1)

# ── Logger ────────────────────────────────────────────────────────────────────
logger = logging.getLogger("ecovacsed")

# ── Maps de conversion ────────────────────────────────────────────────────────
FAN_SPEED_MAP: dict[str, FanSpeedLevel] = {
    "quiet":    FanSpeedLevel.QUIET,
    "normal":   FanSpeedLevel.NORMAL,
    "max":      FanSpeedLevel.MAX,
    "max_plus": FanSpeedLevel.MAX_PLUS,
    "maxplus":  FanSpeedLevel.MAX_PLUS,
    "max+":     FanSpeedLevel.MAX_PLUS,
}

WORK_MODE_MAP: dict[str, WorkMode] = {
    "vacuum":           WorkMode.VACUUM,
    "mop":              WorkMode.MOP,
    "vacuum_and_mop":   WorkMode.VACUUM_AND_MOP,
    "mop_after_vacuum": WorkMode.MOP_AFTER_VACUUM,
}

WATER_AMOUNT_MAP: dict[str, WaterAmount] = {
    "low":       WaterAmount.LOW,
    "medium":    WaterAmount.MEDIUM,
    "high":      WaterAmount.HIGH,
    "ultrahigh": WaterAmount.ULTRAHIGH,
}

STATE_LABEL: dict[State, str] = {
    State.IDLE:      "idle",
    State.CLEANING:  "cleaning",
    State.RETURNING: "returning",
    State.DOCKED:    "docked",
    State.ERROR:     "error",
    State.PAUSED:    "paused",
}

# Commandes de rafraîchissement initial
_REFRESH_COMMANDS = [
    GetBattery(),
    GetFanSpeed(),
    GetStats(),
    GetTotalStats(),
    GetError(),
    GetCleanLogs(),
    GetNetInfo(),
    GetWorkMode(),
    GetWaterInfo(),
    GetMopAutoWashFrequency(),
    GetChargeState(),
    GetVolume(),
]


# ══════════════════════════════════════════════════════════════════════════════
#  Callback Jeedom
# ══════════════════════════════════════════════════════════════════════════════

def _make_fire(
    did: str,
    session: aiohttp.ClientSession,
    callback_url: str,
    apikey: str,
    loop: asyncio.AbstractEventLoop,
) -> callable:
    """Retourne une fonction fire(data) thread-safe."""
    def fire(data: dict) -> None:
        data["did"] = did
        asyncio.run_coroutine_threadsafe(
            _post_callback(session, callback_url, apikey, data), loop
        )
    return fire


async def _post_callback(
    session: aiohttp.ClientSession,
    callback_url: str,
    apikey: str,
    data: dict,
) -> None:
    payload = {**data, "apikey": apikey}
    try:
        async with session.post(
            callback_url,
            params={"apikey": apikey},
            json=payload,
            timeout=aiohttp.ClientTimeout(total=5),
        ) as resp:
            body = await resp.text()
            if resp.status != 200 or body.strip() != "OK":
                logger.warning("Callback inattendu %s – %s", resp.status, body[:200])
            else:
                logger.debug("Callback OK [%s] type=%s", data.get("did"), data.get("type"))
    except Exception as exc:  # noqa: BLE001
        logger.error("Callback HTTP échoué : %s", exc)


# ══════════════════════════════════════════════════════════════════════════════
#  Abonnement aux événements d'un robot
# ══════════════════════════════════════════════════════════════════════════════

def subscribe_all_events(device: Device, fire: callable) -> None:
    """Abonne le démon à tous les événements via l'API officielle deebot-client."""

    # ── Disponibilité ─────────────────────────────────────────────────────────
    async def on_availability(e: AvailabilityEvent) -> None:
        fire({"type": "availability", "available": e.available})
    device.events.subscribe(AvailabilityEvent, on_availability)

    # ── Batterie ──────────────────────────────────────────────────────────────
    async def on_battery(e: BatteryEvent) -> None:
        fire({"type": "battery", "value": e.value})
    device.events.subscribe(BatteryEvent, on_battery)

    # ── État ──────────────────────────────────────────────────────────────────
    async def on_state(e: StateEvent) -> None:
        fire({"type": "state", "state": STATE_LABEL.get(e.state, str(e.state))})
    device.events.subscribe(StateEvent, on_state)

    # ── Puissance d'aspiration ────────────────────────────────────────────────
    async def on_fan_speed(e: FanSpeedEvent) -> None:
        fire({"type": "fan_speed", "speed": e.speed.name})
    device.events.subscribe(FanSpeedEvent, on_fan_speed)

    # ── Mode de travail ───────────────────────────────────────────────────────
    async def on_work_mode(e: WorkModeEvent) -> None:
        fire({"type": "work_mode", "mode": e.mode.name})
    device.events.subscribe(WorkModeEvent, on_work_mode)

    # ── Eau : quantité (18.x : WaterAmountEvent séparé) ──────────────────────
    async def on_water_amount(e: WaterAmountEvent) -> None:
        fire({"type": "water_amount", "amount": e.value.name})
    device.events.subscribe(WaterAmountEvent, on_water_amount)

    # ── Eau : quantité personnalisée (customAmount – T20 Omni) ──────────────
    async def on_water_custom(e: WaterCustomAmountEvent) -> None:
        fire({"type": "water_custom_amount", "value": e.value})
    device.events.subscribe(WaterCustomAmountEvent, on_water_custom)

    # ── Eau : type de balayage ────────────────────────────────────────────────
    async def on_sweep_type(e: WaterSweepTypeEvent) -> None:
        fire({"type": "sweep_type", "sweep_type": e.value.name})
    device.events.subscribe(WaterSweepTypeEvent, on_sweep_type)

    # ── Eau : serpillère attachée ─────────────────────────────────────────────
    async def on_mop_attached(e: MopAttachedEvent) -> None:
        fire({"type": "mop_attached", "attached": e.value})
    device.events.subscribe(MopAttachedEvent, on_mop_attached)

    # ── Statistiques session ──────────────────────────────────────────────────
    async def on_stats(e: StatsEvent) -> None:
        fire({"type": "stats", "area": e.area, "duration": e.time, "mode": e.type})
    device.events.subscribe(StatsEvent, on_stats)

    # ── Statistiques totales ──────────────────────────────────────────────────
    async def on_total_stats(e: TotalStatsEvent) -> None:
        fire({"type": "total_stats", "area": e.area, "time": e.time, "cleanings": e.cleanings})
    device.events.subscribe(TotalStatsEvent, on_total_stats)

    # ── Erreurs ───────────────────────────────────────────────────────────────
    async def on_error(e: ErrorEvent) -> None:
        fire({"type": "error", "code": e.code, "description": e.description or ""})
    device.events.subscribe(ErrorEvent, on_error)

    # ── Consommables ──────────────────────────────────────────────────────────
    async def on_lifespan(e: LifeSpanEvent) -> None:
        fire({
            "type":      "lifespan",
            "component": e.type.name,   # "BRUSH"|"FILTER"|"SIDE_BRUSH"|"DUST_BAG"…
            "value":     e.type.value,  # "brush"|"heap"|"sideBrush"…
            "percent":   e.percent,
            "remaining": e.remaining,
        })
    device.events.subscribe(LifeSpanEvent, on_lifespan)

    # ── Infos réseau ──────────────────────────────────────────────────────────
    async def on_network(e: NetworkInfoEvent) -> None:
        fire({"type": "network", "ip": e.ip, "ssid": e.ssid, "rssi": e.rssi, "mac": e.mac})
    device.events.subscribe(NetworkInfoEvent, on_network)

    # ── Logs de nettoyage ─────────────────────────────────────────────────────
    async def on_clean_logs(e: CleanLogEvent) -> None:
        entries = [
            {
                "timestamp":   entry.timestamp,
                "area":        entry.area,
                "duration":    entry.duration,
                "type":        entry.type,
                "stop_reason": str(entry.stop_reason),
            }
            for entry in e.logs
        ]
        fire({"type": "clean_logs", "entries": entries})
    device.events.subscribe(CleanLogEvent, on_clean_logs)

    # ── Volume sonore ─────────────────────────────────────────────────────────
    async def on_volume(e: VolumeEvent) -> None:
        fire({"type": "volume", "value": e.volume})
    device.events.subscribe(VolumeEvent, on_volume)

    # ── État station (vidage / lavage / séchage) ─────────────────────────────
    async def on_station_state(e: StationStateEvent) -> None:
        station_labels = {
            StationState.IDLE:             "idle",
            StationState.EMPTYING_DUSTBIN: "emptying",
            StationState.WASHING_MOP:      "washing",
            StationState.DRYING_MOP:       "drying",
        }
        fire({"type": "station_state", "state": station_labels.get(e.state, str(e.state))})
    device.events.subscribe(StationStateEvent, on_station_state)

    # ── Vidage automatique (station) ──────────────────────────────────────────
    async def on_auto_empty(e: AutoEmptyEvent) -> None:
        fire({
            "type":      "auto_empty",
            "enabled":   e.enabled,
            "frequency": e.frequency.value if e.frequency else "",
        })
    device.events.subscribe(AutoEmptyEvent, on_auto_empty)

    # ── Fréquence lavage serpillère ───────────────────────────────────────────
    async def on_mop_wash(e: MopAutoWashFrequencyEvent) -> None:
        fire({"type": "mop_wash_frequency", "value": e.value})
    device.events.subscribe(MopAutoWashFrequencyEvent, on_mop_wash)


async def refresh_device(device: Device) -> None:
    """Rafraîchissement initial – ignore les commandes non supportées par le modèle."""
    cmds = list(_REFRESH_COMMANDS)

    # Utiliser uniquement les lifespans déclarés dans les capabilities du modèle
    # pour éviter les erreurs 20004 sur les composants non supportés
    if device.capabilities.life_span:
        supported = list(device.capabilities.life_span.types)
        if supported:
            cmds.append(GetLifeSpan(supported))
    else:
        # Fallback sur les composants connus du T20/T30
        known = [
            LifeSpan.BRUSH, LifeSpan.FILTER, LifeSpan.SIDE_BRUSH,
            LifeSpan.UNIT_CARE, LifeSpan.ROUND_MOP, LifeSpan.DUST_BAG,
            LifeSpan.CLEANING_SOLUTION, LifeSpan.SEWAGE_BOX, LifeSpan.WATER_SINK,
        ]
        cmds.append(GetLifeSpan(known))

    for cmd in cmds:
        try:
            await device.execute_command(cmd)
        except Exception as exc:  # noqa: BLE001
            logger.debug("Refresh : %s ignoré (%s)", type(cmd).__name__, exc)


# ══════════════════════════════════════════════════════════════════════════════
#  Serveur socket TCP – commandes depuis Jeedom
# ══════════════════════════════════════════════════════════════════════════════

class SocketServer(threading.Thread):
    """Thread TCP 127.0.0.1:<port> – reçoit les commandes JSON de Jeedom."""

    def __init__(
        self,
        port: int,
        apikey: str,
        devices: dict[str, Device],
        device_list: list[dict],
        loop: asyncio.AbstractEventLoop,
    ) -> None:
        super().__init__(daemon=True, name="SocketServer")
        self._port        = port
        self._apikey      = apikey
        self._devices     = devices
        self._device_list = device_list
        self._loop        = loop
        self._running     = True
        self._sock: socket.socket | None = None

    def stop(self) -> None:
        self._running = False
        try:
            self._sock and self._sock.close()
        except Exception:
            pass

    def run(self) -> None:
        self._sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        self._sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        try:
            self._sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEPORT, 1)
        except AttributeError:
            pass  # SO_REUSEPORT non disponible sur certains systèmes
        try:
            self._sock.bind(("127.0.0.1", self._port))
        except OSError as exc:
            logger.critical("FATAL: Impossible de binder le port %d : %s – arrêt du démon", self._port, exc)
            os._exit(1)  # Arrêt immédiat du processus entier
        self._sock.listen(10)
        logger.info("Socket Jeedom en écoute sur le port %d", self._port)
        while self._running:
            try:
                conn, _ = self._sock.accept()
            except OSError:
                break
            threading.Thread(target=self._handle, args=(conn,), daemon=True).start()

    def _handle(self, conn: socket.socket) -> None:
        try:
            raw = b""
            while b"\n" not in raw:
                chunk = conn.recv(4096)
                if not chunk:
                    return
                raw += chunk

            msg = json.loads(raw.split(b"\n")[0].decode())

            if msg.get("apikey") != self._apikey:
                logger.warning("Socket : clé API invalide – requête ignorée")
                return

            action = msg.get("action", "")

            # ── Réponse synchrone : liste des robots ──────────────────────────
            if action == "list_devices":
                conn.sendall((json.dumps(self._device_list) + "\n").encode())
                return

            did    = msg.get("did", "")
            device = self._devices.get(did)
            if device is None:
                logger.warning("Socket : did=%s inconnu (connus : %s)", did, list(self._devices))
                return

            asyncio.run_coroutine_threadsafe(
                self._dispatch(device, action, msg), self._loop
            )

        except json.JSONDecodeError as exc:
            logger.error("Socket : JSON invalide – %s", exc)
        except Exception as exc:  # noqa: BLE001
            logger.error("Socket : erreur inattendue – %s", exc)
        finally:
            conn.close()

    async def _dispatch(self, device: Device, action: str, msg: dict) -> None:
        did = device.device_info["did"]
        try:
            match action:

                case "clean":
                    ca_map = {
                        "start":  CleanAction.START,
                        "pause":  CleanAction.PAUSE,
                        "resume": CleanAction.RESUME,
                        "stop":   CleanAction.STOP,
                    }
                    ca = ca_map.get(msg.get("clean_action", "start"), CleanAction.START)
                    # Utiliser CleanV2 directement (compatible T20 Omni Gen2)
                    await device.execute_command(CleanV2(action=ca))

                case "charge":
                    await device.execute_command(device.capabilities.charge.execute())

                case "locate":
                    await device.execute_command(device.capabilities.play_sound.execute())

                case "fan_speed":
                    level = msg.get("level", "").strip().lower()
                    fan   = FAN_SPEED_MAP.get(level)
                    if fan is None:
                        logger.warning("Socket : fan_speed niveau invalide '%s'", level)
                        return
                    cmd = (device.capabilities.fan_speed.set(fan)
                           if device.capabilities.fan_speed
                           else SetFanSpeed(fan))
                    await device.execute_command(cmd)

                case "work_mode":
                    mode = msg.get("mode", "").strip().lower()
                    wm   = WORK_MODE_MAP.get(mode)
                    if wm is None:
                        logger.warning("Socket : work_mode invalide '%s'", mode)
                        return
                    cmd = (device.capabilities.clean.work_mode.set(wm)
                           if device.capabilities.clean.work_mode
                           else SetWorkMode(wm))
                    await device.execute_command(cmd)

                case "water_amount":
                    # T20 Omni : custom_amount est une valeur numérique (1-50)
                    # amount est l'ancien format LOW/MEDIUM/HIGH/ULTRAHIGH
                    if "custom_amount" in msg:
                        ca = int(msg.get("custom_amount", 25))
                        await device.execute_command(SetWaterInfo(custom_amount=ca))
                    else:
                        amount = msg.get("amount", "").strip().lower()
                        wa = WATER_AMOUNT_MAP.get(amount)
                        if wa is None:
                            logger.warning("Socket : water_amount invalide '%s'", amount)
                            return
                        await device.execute_command(SetWaterInfo(amount=wa))

                case "sweep_type":
                    sweep_map = {
                        "standard": SweepType.STANDARD,
                        "deep":     SweepType.DEEP,
                    }
                    st = sweep_map.get(msg.get("value", "standard").lower(), SweepType.STANDARD)
                    # Récupérer le custom_amount actuel pour ne pas le réinitialiser
                    current_amount = int(msg.get("custom_amount", 25))
                    await device.execute_command(SetWaterInfo(sweep_type=st, custom_amount=current_amount))

                case "wash_interval":
                    interval = int(msg.get("value", 25))
                    await device.execute_command(SetMopAutoWashFrequency(interval))

                case "clean_mode":
                    mode_map = {
                        "auto":   CleanAction.START,
                        "spot":   CleanAction.START,
                        "custom": CleanAction.START,
                    }
                    # Pour l'instant démarre en auto — à affiner avec CleanMode
                    cmd = device.capabilities.clean.action.command(CleanAction.START)
                    await device.execute_command(cmd)

                case "station_action":
                    action_map = {
                        "empty_dustbin": StationAction.EMPTY_DUSTBIN,
                        "wash_mop":      StationAction.WASH_MOP,
                        "dry_mop":       StationAction.DRY_MOP,
                        "clean_base":    StationAction.CLEAN_BASE,
                    }
                    action = msg.get("value", "empty_dustbin").strip().lower()
                    sa = action_map.get(action)
                    if sa is None:
                        logger.warning("Socket : station_action invalide '%s'", action)
                        return
                    await device.execute_command(StationActionCmd(sa))

                case "refresh":
                    await refresh_device(device)

                case _:
                    logger.warning("Socket : action inconnue '%s' pour did=%s", action, did)

            logger.debug("Commande '%s' exécutée pour did=%s", action, did)

        except Exception as exc:  # noqa: BLE001
            logger.error("Erreur dispatch action='%s' did=%s : %s", action, did, exc)


# ══════════════════════════════════════════════════════════════════════════════
#  Main
# ══════════════════════════════════════════════════════════════════════════════

def _handle_exception(loop, context):
    """Handler global pour les exceptions asyncio non catchées."""
    exc = context.get('exception')
    msg = context.get('message', 'Unknown')
    if exc:
        logger.critical("Exception asyncio non catchée: %s", msg, exc_info=exc)
    else:
        logger.critical("Erreur asyncio: %s", msg)


async def main() -> None:
    parser = argparse.ArgumentParser(description="Démon Jeedom – Ecovacs Deebot (deebot-client >= 18)")
    parser.add_argument("--loglevel",   default="info", type=str.lower,
                        choices=["debug", "info", "warning", "error"])
    parser.add_argument("--pid",        required=True)
    parser.add_argument("--apikey",     required=True)
    parser.add_argument("--callback",   required=True)
    parser.add_argument("--socketport", default=55025, type=int)
    parser.add_argument("--login",      required=True)
    parser.add_argument("--password",   required=True)
    parser.add_argument("--country",    default="FR")
    args = parser.parse_args()

    # ── Logging ───────────────────────────────────────────────────────────────
    logging.basicConfig(
        level=getattr(logging, args.loglevel.upper()),
        format="%(asctime)s %(levelname)-8s %(name)s : %(message)s",
        datefmt="[%Y-%m-%d %H:%M:%S]",
    )
    if args.loglevel != "debug":
        for noisy in ("deebot_client", "aiohttp", "aiomqtt"):
            logging.getLogger(noisy).setLevel(logging.WARNING)
        # Silencier les commandes non supportées par certains modèles
        logging.getLogger("deebot_client.message").setLevel(logging.CRITICAL)
        logging.getLogger("deebot_client.command").setLevel(logging.CRITICAL)
        logging.getLogger("deebot_client.commands.json.common").setLevel(logging.CRITICAL)
    else:
        # Mode debug : tout afficher y compris deebot_client internals
        for lib in ("deebot_client", "aiohttp", "aiomqtt"):
            logging.getLogger(lib).setLevel(logging.DEBUG)

    # ── PID ───────────────────────────────────────────────────────────────────
    os.makedirs(os.path.dirname(args.pid), exist_ok=True)
    with open(args.pid, "w") as f:
        f.write(str(os.getpid()))
    logger.info("=== ecovacsed démarré (PID %d) ===", os.getpid())

    # ── Arrêt propre ──────────────────────────────────────────────────────────
    stop_event = asyncio.Event()
    def _on_signal(*_: Any) -> None:
        logger.info("Signal reçu – arrêt en cours…")
        stop_event.set()
    signal.signal(signal.SIGTERM, _on_signal)
    signal.signal(signal.SIGINT,  _on_signal)

    loop = asyncio.get_event_loop()

    async with aiohttp.ClientSession() as http_session:

        # ── Authentification ──────────────────────────────────────────────────
        logger.info("Authentification Ecovacs (pays=%s)…", args.country.upper())
        device_id   = md5(args.login)[:16]
        rest_config = create_rest_config(
            http_session,
            device_id=device_id,
            alpha_2_country=args.country.upper(),
        )
        auth = Authenticator(rest_config, args.login, md5(args.password))
        try:
            await auth.authenticate()
            logger.info("Authentification réussie.")
        except Exception as exc:
            logger.error("Échec authentification : %s", exc)
            sys.exit(1)

        # ── Découverte des robots ─────────────────────────────────────────────
        api_client = ApiClient(auth)
        try:
            all_devices = await api_client.get_devices()
        except Exception as exc:
            logger.error("Impossible de récupérer les appareils : %s", exc)
            sys.exit(1)

        mqtt_devices: list[DeviceInfo] = all_devices.mqtt

        if not mqtt_devices:
            logger.warning(
                "Aucun robot MQTT compatible trouvé. Non supportés : %s",
                [d.get("name") for d in all_devices.not_supported],
            )
            await stop_event.wait()
            return

        logger.info("%d robot(s) MQTT détecté(s).", len(mqtt_devices))

        # ── Client MQTT ───────────────────────────────────────────────────────
        mqtt_cfg    = create_mqtt_config(device_id=device_id, country=args.country.upper())
        mqtt_client = MqttClient(mqtt_cfg, auth)

        # ── Initialisation des robots ─────────────────────────────────────────
        devices:     dict[str, Device] = {}
        device_list: list[dict]        = []
        fire_map:    dict[str, callable] = {}

        for dev_info in mqtt_devices:
            api_info = dev_info.api
            did   = api_info["did"]
            name  = api_info.get("nick") or api_info.get("name", did)
            model = api_info.get("deviceName", "")
            cls   = api_info.get("class", "")

            device = Device(dev_info, auth)
            await device.initialize(mqtt_client)

            fire = _make_fire(did, http_session, args.callback, args.apikey, loop)
            fire_map[did] = fire
            subscribe_all_events(device, fire)

            devices[did] = device
            device_list.append({"did": did, "name": name, "model": model, "class": cls})
            logger.info("Robot prêt : %s  did=%s  modèle=%s", name, did, model)

            # Métadonnées → Jeedom
            await _post_callback(http_session, args.callback, args.apikey, {
                "did": did, "type": "device_info", "name": name, "model": model, "class": cls,
            })

        # ── Hook MQTT brut – commandes non gérées par deebot-client ────────
        # did de chaque device pour router les callbacks
        _did_fire_map = {did: fire_map[did] for did in fire_map}
        original_handle = mqtt_client._handle_message

        def _extra_handler(message: Any) -> None:
            try:
                payload_raw = message.payload
                if isinstance(payload_raw, (bytes, bytearray)):
                    payload_raw = payload_raw.decode('utf-8', errors='replace')
                topic = str(message.topic)
                parts = topic.split('/')
                cmd_name = parts[2] if len(parts) > 2 else ""
                # DID = 4ème segment pour iot/atr/cmd/did/...
                did_raw = parts[3] if len(parts) > 3 else ""

                fire_fn = _did_fire_map.get(did_raw)
                if not fire_fn:
                    return

                data = json.loads(payload_raw)
                body_data = data.get("body", {}).get("data", {})
                if not body_data or data.get("body", {}).get("code", 0) != 0:
                    return

                # Log tous les messages reçus pour debug
                logger.debug("EXTRA_HANDLER cmd=%s topic=%s", cmd_name, topic)

                if cmd_name == "getWashInfo":
                    # Données complètes du lavage serpillère
                    fire_fn({"type": "wash_info",
                             "amount":    body_data.get("amount"),
                             "dry_mop":   body_data.get("dryMop"),
                             "duration":  body_data.get("duration"),
                             "interval":  body_data.get("interval"),
                             "mode":      body_data.get("mode"),
                             "wise_mode": body_data.get("wiseMode"),
                    })

                elif cmd_name == "getChargeState":
                    fire_fn({"type": "charge_state",
                             "is_charging": body_data.get("isCharging"),
                             "mode":        body_data.get("mode", ""),
                    })

                elif cmd_name == "getSpeed":
                    # speed brute : 0=quiet,1=normal,2=max,3=max_plus
                    speed_map = {0: "QUIET", 1: "NORMAL", 2: "MAX", 3: "MAX_PLUS"}
                    raw = body_data.get("speed", -1)
                    fire_fn({"type": "fan_speed_raw",
                             "raw":   raw,
                             "speed": speed_map.get(raw, str(raw)),
                    })

                elif cmd_name == "getWaterInfo":
                    fire_fn({"type":          "water_info_full",
                             "custom_amount": body_data.get("customAmount"),
                             "mop_count":     body_data.get("mopCount"),
                             "side_mop":      body_data.get("sideMop"),
                             "sweep_type":    body_data.get("sweepType"),
                             "water_type":    body_data.get("type"),
                             "enable":        body_data.get("enable"),
                    })

                elif cmd_name == "getOta":
                    fire_fn({"type": "ota",
                             "version":      body_data.get("ver", ""),
                             "status":       body_data.get("status", ""),
                             "auto_switch":  body_data.get("autoSwitch", 0),
                             "is_force":     body_data.get("isForce", 0),
                    })

                elif cmd_name in ("getStationState", "onStationState"):
                    state_raw    = body_data.get("state", 0)
                    content_data = body_data.get("content", {}) or {}
                    errors       = content_data.get("error", []) or []
                    station_type = content_data.get("type", 0)
                    motion       = content_data.get("motionState", 0)

                    # Mapper l'état de la station
                    if state_raw == 0:
                        mapped = "idle"
                    elif station_type == 1 and motion == 1:
                        mapped = "emptying"
                    elif station_type == 2 and motion == 1:
                        mapped = "drying"
                    elif station_type == 3 and motion == 1:
                        mapped = "washing"
                    else:
                        mapped = "idle"

                    # Code 302 = capteur flotteur bac eau sale déclenché
                    dirty_water_full = 1 if 302 in errors else 0

                    fire_fn({"type":             "station_state_raw",
                             "state":            mapped,
                             "raw":              state_raw,
                             "errors":           errors,
                             "dirty_water_full": dirty_water_full,
                    })

                elif cmd_name == "getVolume":
                    fire_fn({"type": "volume",
                             "value": body_data.get("volume", 0),
                    })

                elif cmd_name in ("onFwBuryPoint-common-setting", "onFwBuryPoint-formulate-setting"):
                    # Réglages statiques — ignorés volontairement
                    pass

                elif cmd_name == "onWorkProgressReport":
                    fire_fn({
                        "type":    "work_progress",
                        "clean":   body_data.get("clean", 0),
                        "dry":     body_data.get("dry",   0),
                        "wash":    body_data.get("wash",  0),
                    })

                elif cmd_name == "onBattery":
                    # Payload étendu avec courants, tension, température
                    fire_fn({"type": "battery_extended",
                             "value":       body_data.get("value"),
                             "voltage":     body_data.get("voltage"),
                             "temperature": body_data.get("temperature"),
                             "current":     body_data.get("current"),
                             "is_low":      body_data.get("isLow", 0),
                    })

                elif cmd_name == "onWorkState":
                    # État combiné robot + station en temps réel
                    robot   = body_data.get("robotState", {})
                    station = body_data.get("stationState", {})
                    fire_fn({"type": "work_state",
                             "robot_state":   robot.get("state", ""),
                             "station_state": station.get("state", "idle"),
                             "paused":        body_data.get("paused", 0),
                    })

                elif cmd_name == "onLifeSpan":
                    # Liste complète de tous les consommables en un seul message
                    items = body_data if isinstance(body_data, list) else []
                    for item in items:
                        ltype   = item.get("type", "")
                        left    = item.get("left", 0)
                        total   = item.get("total", 1)
                        percent = round(left / total * 100, 2) if total else 0
                        if ltype:
                            fire_fn({"type": "lifespan",
                                     "component": ltype.upper(),
                                     "value":     ltype,
                                     "percent":   percent,
                                     "remaining": left,
                            })

            except Exception as exc:
                logger.debug("Extra handler error: %s", exc)
            finally:
                original_handle(message)

        mqtt_client._handle_message = _extra_handler

        # ── Connexion MQTT ────────────────────────────────────────────────────
        try:
            await mqtt_client.connect()
            logger.info("MQTT connecté.")
        except Exception as exc:
            logger.error("Erreur connexion MQTT : %s", exc)
            sys.exit(1)

        # ── Rafraîchissement initial ───────────────────────────────────────────
        for device in devices.values():
            asyncio.create_task(refresh_device(device))

        # ── Serveur socket ────────────────────────────────────────────────────
        socket_server = SocketServer(
            port=args.socketport, apikey=args.apikey,
            devices=devices, device_list=device_list, loop=loop,
        )
        socket_server.start()

        # Handler global pour les exceptions asyncio
        loop.set_exception_handler(_handle_exception)
        logger.info("Démon opérationnel – en attente d'événements MQTT…")


        await stop_event.wait()

        # ── Arrêt propre ──────────────────────────────────────────────────────
        logger.info("Arrêt propre en cours…")
        socket_server.stop()
        for device in devices.values():
            try:
                await device.teardown()
            except Exception:
                pass
        try:
            await mqtt_client.disconnect()
        except Exception:
            pass

    try:
        os.remove(args.pid)
    except Exception:
        pass
    logger.info("=== ecovacsed arrêté proprement ===")


if __name__ == "__main__":
    try:
        asyncio.run(main())
    except Exception as exc:
        logger.critical("CRASH FATAL démon: %s", exc, exc_info=True)
        sys.exit(1)
