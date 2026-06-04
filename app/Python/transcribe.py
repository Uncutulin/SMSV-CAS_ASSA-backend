import sys
import os
import re

if sys.platform.startswith('win'):
    import io
    sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')
    sys.stderr = io.TextIOWrapper(sys.stderr.buffer, encoding='utf-8')

# 1. Filtros globales para borrar basura estructural en silencios de fondo
PATRONES_LIMPIEZA = [
    (r"\b¡?SUSCRÍBETE!?\b", ""), 
    (r"\bsuscríbete\b", ""), 
    (r"\binhale\b", ""), 
    (r"\bexhale\b", ""), 
    (r"\bxD\b", ""), 
    (r"\bYay!?\b", ""), 
    (r"\bSubscríbete\b", ""),
    (r"\bGracias por ver\b", ""), 
    (r"\bMúsica\b", ""),
    (r"Subtítulos realizados por la comunidad de Amara\.org", ""),
    (r"Realizado por la comunidad de Amara\.org", ""),
    (r"Subtítulos realizados por.*", ""),
    (r".*Amara\.org.*", "")
]

def limpiar_texto_completo(texto):
    # Reemplazar cualquier salto de línea o espacios raros por espacios estándar
    texto_limpio = re.sub(r'\s+', ' ', texto)
    
    # Aplicar borrado de alucinaciones de la lista negra
    for patron, reemplazo in PATRONES_LIMPIEZA:
        texto_limpio = re.sub(patron, reemplazo, texto_limpio, flags=re.IGNORECASE)
        
    # Sanitización final de espacios duplicados que hayan quedado tras los reemplazos
    texto_limpio = re.sub(r'\s+', ' ', texto_limpio)
    return texto_limpio.strip()

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

        # Toma automáticamente 'medium' desde el .env
        whisper_model = os.environ.get("WHISPER_MODEL", "medium")
        model = WhisperModel(whisper_model, device="cpu", compute_type="int8", download_root=download_root)
        
        # CONTEXTO INSTITUCIONAL: Forzamos el contexto real de la mutual para guiar al modelo
        contexto_fuerzas = "Llamada telefónica de atención al cliente de Sociedad Militar Seguro de Vida. Vocabulario: DNI, Gendarmería Nacional, Ejército Argentino, mutual, seguro, asociado, gestoría, retirado, falleció."

        segments, info = model.transcribe(
            audio_path, 
            language="es", 
            beam_size=5, 
            condition_on_previous_text=False,
            initial_prompt=contexto_fuerzas  # Ayuda clave para la precisión del modelo en español
        )
        
        texto_raw = " ".join([segment.text for segment in segments])
        
        # Procesamos todo el bloque de texto de corrido para limpiar el string final
        texto_final = limpiar_texto_completo(texto_raw)
        
        print(texto_final)
        sys.exit(0)

    except Exception as e:
        print(f"[ERROR DE TRANSCRIPCIÓN]: {str(e)}")
        sys.exit(1)

if __name__ == "__main__":
    main()