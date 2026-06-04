import sys
import os
import re

if sys.platform.startswith('win'):
    import io
    sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')
    sys.stderr = io.TextIOWrapper(sys.stderr.buffer, encoding='utf-8')

# Lista negra ampliada con los errores de tu server
LISTA_NEGRA = [
    r"\b¡?SUSCRÍBETE!?\b", r"\bsuscríbete\b", r"\binhale\b", 
    r"\bexhale\b", r"\bxD\b", r"\bYay!?\b", r"\bSubscríbete\b",
    r"\bGracias por ver\b", r"\bY\b", r"\bMúsica\b",
    r"Subtítulos realizados por.*", r".*Amara\.org.*"
]

def limpiar_texto_completo(texto):
    lineas = texto.split('\n')
    lineas_limpias = []
    for linea in lineas:
        linea_test = linea.strip()
        es_alucinacion = False
        for patron in LISTA_NEGRA:
            if re.match(patron, linea_test, re.IGNORECASE):
                if "Amara" in patron or "realizados por" in patron:
                    es_alucinacion = True
                    break
                if len(re.sub(patron, "", linea_test, flags=re.IGNORECASE).strip()) < 2:
                    es_alucinacion = True
                    break
        if not es_alucinacion:
            for patron in LISTA_NEGRA:
                if "Amara" not in patron and "realizados por" not in patron:
                    linea = re.sub(patron, "", linea, flags=re.IGNORECASE)
            lineas_limpias.append(linea.strip())
    return " ".join([l for l in lineas_limpias if l]).strip()

def main():
    if len(sys.argv) < 2:
        print("Error: Se requiere la ruta del archivo de audio.")
        sys.exit(1)

    audio_path = sys.argv[1]
    if not os.path.exists(audio_path):
        print(f"Error: El archivo '{audio_path}' no existe.")
        sys.exit(1)

    try:
        from faster_whisper import WhisperModel
        
        ruta_script = os.path.dirname(os.path.abspath(__file__))
        raiz_proyecto = os.path.abspath(os.path.join(ruta_script, "..", ".."))
        download_root = os.path.join(raiz_proyecto, "storage", "app", "whisper_cache")
        os.makedirs(download_root, exist_ok=True)

        whisper_model = os.environ.get("WHISPER_MODEL", "small")
        model = WhisperModel(whisper_model, device="cpu", compute_type="int8", download_root=download_root)
        
        # CONTEXTO: Le pasamos palabras clave frecuentes para "guiar" el diccionario del modelo small
        contexto_fuerzas = "Llamada telefónica de atención al cliente. Vocabulario: DNI, Gendarmería Nacional, Ejército Argentino, mutual, seguro, asociado, gestoría, retirado."

        segments, info = model.transcribe(
            audio_path, 
            language="es", 
            beam_size=5, 
            condition_on_previous_text=False,
            initial_prompt=contexto_fuerzas # Esto mejora la precisión fonética
        )
        
        texto_raw = " ".join([segment.text for segment in segments])
        texto_final = limpiar_texto_completo(texto_raw)
        
        print(texto_final)
        sys.exit(0)

    except Exception as e:
        print(f"[ERROR DE TRANSCRIPCIÓN]: {str(e)}")
        sys.exit(1)

if __name__ == "__main__":
    main()