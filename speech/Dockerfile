# Transcription de la voix (Whisper via faster-whisper) pour le bouton 🎤 du chat, sur http://127.0.0.1:8891
FROM python:3.12-slim

# PyAV 16 a retiré l'option metadata_errors encore utilisée par faster-whisper 1.2.1
RUN pip install --no-cache-dir faster-whisper==1.2.1 "av<16" fastapi uvicorn

WORKDIR /app
COPY server.py .

CMD ["uvicorn", "server:app", "--host", "127.0.0.1", "--port", "8891", "--log-level", "warning"]
