import sys
import os
import re
import subprocess
import tempfile

if sys.platform.startswith('win'):
    import io
    sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')
    sys.stderr = io.TextIOWrapper(sys.stderr.buffer, encoding='utf-8')

PATRONES_LIMPIEZA = [
    (r"\b¡?SUSCRÍBETE!?\b", ""), (r"\bsuscríbete\b", ""), (r"\binhale\b", ""), 
    (r"\bexhale\b", ""), (r"\bxD\b", ""), (r"\bYay!?\b", ""), (r"\bSubscríbete\b", ""),
    (r"\bGracias por ver\b", ""), (r"\bMúsica\b", ""),
    (r"Subtítulos realizados por la comunidad de Amara\.org", ""),
    (r"Realizado por la comunidad de Amara\.org", ""),
    (r"Subtítulos realizados por.*", ""),
    (r".*Amara\.org.*", ""),
    (r"¡\s*el\s*vídeo\s*!", ""), # Limpia la alucinación del final
    (r"¡\s*el\s*video\s*!", "")
]

def limpiar_texto_completo(texto):
    texto_limpio = re.sub(r'\s+', ' ', texto)
    for patron, reemplazo in PATRONES_LIMPIEZA:
        texto_limpio = re.sub(patron, reemplazo, texto_limpio, flags=re.IGNORECASE)
    texto_limpio = re.sub(r'\s+', ' ', texto_limpio)
    return texto_limpio.strip()

def preprocesar_audio(input_path):
    """ Convierte el audio a WAV 16kHz Mono usando FFmpeg para máxima fidelidad en Whisper """
    temp_wav = tempfile.NamedTemporaryFile(suffix=".wav", delete=False)
    temp_wav.close() # Liberar el archivo para que FFmpeg pueda escribir
    
    # Comando de FFmpeg con la ruta absoluta para el usuario www-data
    comando = [
        '/usr/bin/ffmpeg', '-y', '-i', input_path,
        '-ar', '16000',  # 16kHz Sample Rate
        '-ac', '1',      # Mono canal
        '-c:a', 'pcm_s16le', # Codec sin pérdidas
        temp_wav.name
    ]
    
    # Ejecutar en silencio de fondo
    subprocess.run(comando, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=True)
    return temp_wav.name

def main():
    if len(sys.argv) < 2:
        print("Error: Se requiere la ruta del archivo de audio.")
        sys.exit(1)

    audio_path = sys.argv[1]
    if not os.path.exists(audio_path):
        print(f"Error: El archivo '{audio_path}' no existe.")
        sys.exit(1)

    archivo_wav_optimizado = None
    try:
        from faster_whisper import WhisperModel
        
        # 1. Pre-procesar el audio con FFmpeg antes de transcribir
        archivo_wav_optimizado = preprocesar_audio(audio_path)
        
        ruta_script = os.path.dirname(os.path.abspath(__file__))
        raiz_proyecto = os.path.abspath(os.path.join(ruta_script, "..", ".."))
        download_root = os.path.join(raiz_proyecto, "storage", "app", "whisper_cache")
        os.makedirs(download_root, exist_ok=True)

        whisper_model = os.environ.get("WHISPER_MODEL", "medium")
        model = WhisperModel(whisper_model, device="cpu", compute_type="int8", download_root=download_root)
        
        # Le pasamos un prompt explícito con puntuación perfecta para forzar el dialecto
        contexto_fuerzas = "Llamada telefónica de atención al cliente de la Sociedad Militar Seguro de Vida. Vocabulario operativo: Fabiola, DNI, Gendarmería Nacional, Ejército, mutual, seguro, asociado, gestoría, retirado, falleció, vigente, si me aguardás un segundito."

        # Transcribir usando el audio limpio a 16kHz
        segments, info = model.transcribe(
            archivo_wav_optimizado, # Pasamos el WAV de 16kHz
            language="es", 
            beam_size=5, 
            condition_on_previous_text=False,
            initial_prompt=contexto_fuerzas
        )
        
        texto_raw = " ".join([segment.text for segment in segments])
        texto_final = limpiar_texto_completo(texto_raw)
        
        print(texto_final)
        sys.exit(0)

    except Exception as e:
        print(f"[ERROR DE TRANSCRIPCIÓN]: {str(e)}")
        sys.exit(1)
        
    finally:
        # Limpieza obligatoria del archivo temporal del disco
        if archivo_wav_optimizado and os.path.exists(archivo_wav_optimizado):
            os.remove(archivo_wav_optimizado)

if __name__ == "__main__":
    main()