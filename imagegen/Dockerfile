# Génération d'images (Z-Image-Turbo) pour l'interface de chat, sur http://127.0.0.1:8890
FROM pytorch/pytorch:2.10.0-cuda13.0-cudnn9-runtime

# conteneur dédié : pas de risque à installer dans le Python de l'image
RUN pip install --no-cache-dir --break-system-packages \
        diffusers==0.42.1 transformers accelerate safetensors \
        fastapi uvicorn pillow httpx

WORKDIR /app
COPY server.py .

CMD ["uvicorn", "server:app", "--host", "127.0.0.1", "--port", "8890", "--log-level", "warning"]
