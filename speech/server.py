"""
Transcrit la voix enregistrée dans le navigateur (dictée du chat) avec Whisper.

POST /transcribe  corps = fichier audio brut (webm/opus, ogg, mp4/aac, wav…) -> {"text", "language", "seconds"}
     ?language=fr|en facultatif (détection automatique sinon)
GET  /health -> {"ready": bool, "error"?: str}

Tourne sur CPU : les GPU sont occupés par Ollama (et imagegen), et quelques secondes de dictée
se transcrivent en une à deux secondes avec large-v3-turbo en int8.
"""

import asyncio
import io
import logging
import os
import threading
import time

from fastapi import FastAPI, HTTPException, Request
from faster_whisper import WhisperModel

MODEL = os.environ.get("SPEECH_MODEL", "large-v3-turbo")
DEVICE = os.environ.get("SPEECH_DEVICE", "cpu")
COMPUTE_TYPE = os.environ.get("SPEECH_COMPUTE_TYPE", "int8")
THREADS = int(os.environ.get("SPEECH_THREADS", "12"))
MAX_BYTES = 25 * 1024 * 1024
LANGUAGES = {"fr", "en"}

log = logging.getLogger("speech")
logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")

app = FastAPI()
state: dict = {"model": None, "error": None}
# une transcription à la fois : elles se partagent les mêmes cœurs
lock = asyncio.Lock()


def load() -> None:
    """Télécharge le modèle au premier démarrage (cache Hugging Face monté en volume), puis le charge."""
    try:
        started = time.monotonic()
        state["model"] = WhisperModel(MODEL, device=DEVICE, compute_type=COMPUTE_TYPE, cpu_threads=THREADS)
        log.info("whisper %s prêt en %.0f s (%s, %s)", MODEL, time.monotonic() - started, DEVICE, COMPUTE_TYPE)
    except Exception as e:  # noqa: BLE001 - signalé par /health
        log.exception("chargement impossible")
        state["error"] = str(e)


@app.on_event("startup")
def startup() -> None:
    threading.Thread(target=load, daemon=True).start()


@app.get("/health")
def health() -> dict:
    return {"ready": state["model"] is not None, "error": state["error"]}


def transcribe_sync(audio: bytes, language: str | None) -> tuple[str, str]:
    segments, info = state["model"].transcribe(
        io.BytesIO(audio),
        language=language,
        beam_size=5,
        # ignore les silences (début/fin d'enregistrement), évite les phrases inventées
        vad_filter=True,
        condition_on_previous_text=False,
        # le nom de l'assistant, sinon souvent compris « Jean »
        hotwords="Jin",
    )
    text = " ".join(segment.text.strip() for segment in segments).strip()
    return text, info.language


@app.post("/transcribe")
async def transcribe(request: Request, language: str | None = None) -> dict:
    if state["model"] is None:
        raise HTTPException(503, state["error"] or "Le modèle de transcription est en cours de chargement, réessaie dans une minute.")
    audio = await request.body()
    if not audio:
        raise HTTPException(422, "Enregistrement vide.")
    if len(audio) > MAX_BYTES:
        raise HTTPException(413, "Enregistrement trop long.")
    if language is not None and language not in LANGUAGES:
        raise HTTPException(422, "Langue invalide (fr ou en).")

    async with lock:
        started = time.monotonic()
        try:
            text, detected = await asyncio.to_thread(transcribe_sync, audio, language)
        except Exception as e:  # noqa: BLE001 - format audio illisible le plus souvent
            log.warning("transcription impossible : %s", e)
            raise HTTPException(422, "Audio illisible.") from e
    seconds = round(time.monotonic() - started, 1)
    log.info("%d octets transcrits en %.1f s (%s)", len(audio), seconds, detected)
    return {"text": text, "language": detected, "seconds": seconds}
