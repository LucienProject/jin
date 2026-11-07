"""
Génère des images avec Z-Image-Turbo (Tongyi-MAI) pour l'interface de chat.

POST /generate {"prompt", "width"?, "height"?, "seed"?} -> {"image": "<JPEG base64>", "seed", "seconds"}
GET  /health -> {"ready": bool, "error"?: str}

Le modèle (≈ 20 Go en bf16) est gardé en RAM et ne monte en VRAM que pendant une génération,
morceau par morceau (encodeur de texte, puis transformer, puis VAE). Les modèles de chat d'Ollama
occupent déjà les GPU : on les décharge avant de générer, ils se rechargeront au prochain message.
"""

import asyncio
import base64
import io
import logging
import os
import random
import threading
import time

import httpx
import torch
from diffusers import ZImagePipeline
from fastapi import FastAPI, HTTPException
from pydantic import BaseModel, Field

MODEL = os.environ.get("IMAGEGEN_MODEL", "Tongyi-MAI/Z-Image-Turbo")
OLLAMA_URL = os.environ.get("OLLAMA_URL", "http://127.0.0.1:11434")

log = logging.getLogger("imagegen")
logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")

app = FastAPI()
state: dict = {"pipe": None, "error": None}
# une génération à la fois : le GPU n'en supporte pas deux
gpu_lock = asyncio.Lock()


def load() -> None:
    """Télécharge le modèle au premier démarrage (dans le cache Hugging Face monté en volume), puis le charge en RAM."""
    try:
        started = time.monotonic()
        pipe = ZImagePipeline.from_pretrained(MODEL, torch_dtype=torch.bfloat16)
        pipe.enable_model_cpu_offload()
        state["pipe"] = pipe
        log.info("%s prêt en %.0f s", MODEL, time.monotonic() - started)
    except Exception as e:  # noqa: BLE001 - signalé par /health
        log.exception("chargement impossible")
        state["error"] = str(e)


@app.on_event("startup")
def startup() -> None:
    threading.Thread(target=load, daemon=True).start()


class GenerateRequest(BaseModel):
    prompt: str = Field(min_length=1, max_length=4000)
    width: int = Field(default=1024, ge=512, le=1536, multiple_of=16)
    height: int = Field(default=1024, ge=512, le=1536, multiple_of=16)
    seed: int | None = Field(default=None, ge=0, lt=2**32)


@app.get("/health")
def health() -> dict:
    return {"ready": state["pipe"] is not None, "error": state["error"]}


async def unload_ollama() -> None:
    """Libère la VRAM occupée par les modèles de chat."""
    try:
        async with httpx.AsyncClient(base_url=OLLAMA_URL, timeout=30) as client:
            for model in (await client.get("/api/ps")).json().get("models", []):
                await client.post("/api/generate", json={"model": model["name"], "keep_alive": 0})
                log.info("%s déchargé d'Ollama", model["name"])
            # Ollama libère la mémoire juste après avoir répondu
            for _ in range(20):
                if not (await client.get("/api/ps")).json().get("models"):
                    break
                await asyncio.sleep(0.5)
    except httpx.HTTPError as e:
        log.warning("Ollama injoignable (%s) : génération sans décharger", e)


def generate_sync(request: GenerateRequest, seed: int) -> bytes:
    image = state["pipe"](
        prompt=request.prompt,
        width=request.width,
        height=request.height,
        # réglages recommandés pour la version Turbo (distillée)
        num_inference_steps=9,
        guidance_scale=0.0,
        generator=torch.Generator("cuda").manual_seed(seed),
    ).images[0]
    torch.cuda.empty_cache()
    buffer = io.BytesIO()
    image.save(buffer, format="JPEG", quality=92)
    return buffer.getvalue()


@app.post("/generate")
async def generate(request: GenerateRequest) -> dict:
    if state["pipe"] is None:
        raise HTTPException(503, state["error"] or "Le modèle d'images est en cours de chargement, réessaie dans quelques minutes.")
    seed = request.seed if request.seed is not None else random.randrange(2**32)
    async with gpu_lock:
        await unload_ollama()
        started = time.monotonic()
        try:
            jpeg = await asyncio.to_thread(generate_sync, request, seed)
        except torch.OutOfMemoryError:
            torch.cuda.empty_cache()
            raise HTTPException(507, "Mémoire GPU insuffisante pour générer l'image.")
    seconds = round(time.monotonic() - started, 1)
    log.info("image %dx%d en %.1f s", request.width, request.height, seconds)
    return {"image": base64.b64encode(jpeg).decode(), "seed": seed, "seconds": seconds}
