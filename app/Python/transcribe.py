import sys
import os
import re

# Agregar ffmpeg de WinGet y rutas de Python al PATH del proceso
extra_paths = [
    r"C:\Users\Uncutulin\AppData\Local\Microsoft\WinGet\Links",
    r"C:\Users\Uncutulin\AppData\Local\Programs\Python\Python311",
    r"C:\Users\Uncutulin\AppData\Local\Programs\Python\Python311\Scripts"
]
for p in extra_paths:
    if os.path.exists(p) and p not in os.environ.get("PATH", ""):
        os.environ["PATH"] = p + os.pathsep + os.environ.get("PATH", "")

# Forzar codificación UTF-8 para evitar problemas de acentos en Windows
if sys.platform.startswith('win'):
    import io
    sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')
    sys.stderr = io.TextIOWrapper(sys.stderr.buffer, encoding='utf-8')

# Lista negra de alucinaciones comunes de Whisper en silencios/música
LISTA_NEGRA = [
    r"\b¡?SUSCRÍBETE!?\b", 
    r"\bsuscríbete\b", 
    r"\binhale\b", 
    r"\bexhale\b", 
    r"\bxD\b", 
    r"\bYay!?\b", 
    r"\bSubscríbete\b",
    r"\bGracias por ver\b",
    r"\bY\b"  # Evita bucles de letras sueltas si se queda tildado
]

def limpiar_texto_completo(texto):
    """
    Divide el texto por líneas o limpia frases completas que sean alucinaciones,
    evitando borrar palabras válidas que estén integradas en conversaciones reales.
    """
    lineas = texto.split('\n')
    lineas_limpias = []
    
    for linea in lineas:
        linea_test = linea.strip()
        # Si la línea entera coincide con una alucinación, la descartamos
        es_alucinacion = False
        for patron in LISTA_NEGRA:
            if re.match(patron, linea_test, re.IGNORECASE):
                # Si lo único que hay en la línea es la alucinación, la borramos
                if len(re.sub(patron, "", linea_test, flags=re.IGNORECASE).strip()) < 2:
                    es_alucinacion = True
                    break
        
        if not es_alucinacion:
            # Si la palabra está metida dentro de texto válido, solo limpiamos la palabra suelta
            for patron in LISTA_NEGRA:
                linea = re.sub(patron, "", linea, flags=re.IGNORECASE)
            lineas_limpias.append(linea.strip())
            
    return " ".join([l for l in lineas_limpias if l]).strip()

def main():
    if len(sys.argv) < 2:
        print("Error: Se requiere la ruta del archivo de audio como argumento.")
        sys.exit(1)

    audio_path = sys.argv[1]

    if not os.path.exists(audio_path):
        print(f"Error: El archivo '{audio_path}' no existe.")
        sys.exit(1)

    filename = os.path.basename(audio_path)
    # Limpiar el prefijo temporal de Laravel si existe (ej. audio_1780490925_)
    filename = re.sub(r'^audio_\d+_(.+)$', r'\1', filename)

    # Intento de transcripción real usando la librería whisper de OpenAI
    try:
        import whisper
        import warnings
        # Ignorar advertencias de fp16 en CPU o específicas de PyTorch
        warnings.filterwarnings("ignore", category=UserWarning)

        # 1. Cargamos 'medium' que demostró entender perfecto el audio telefónico.
        # Validamos si la ruta de cache es escribible; de lo contrario, usamos el directorio temporal del sistema.
        import tempfile
        download_root = None
        
        # Ruta de caché por defecto de Whisper
        default_cache = os.path.join(os.path.expanduser("~"), ".cache", "whisper")
        xdg_cache = os.environ.get("XDG_CACHE_HOME")
        if xdg_cache:
            default_cache = os.path.join(xdg_cache, "whisper")
            
        try:
            os.makedirs(default_cache, exist_ok=True)
            test_file = os.path.join(default_cache, ".write_test")
            with open(test_file, "w") as f:
                f.write("test")
            os.remove(test_file)
            download_root = default_cache
        except Exception:
            # Fallback a directorio temporal del sistema si hay error de permisos (ej. Errno 13)
            temp_dir = tempfile.gettempdir()
            download_root = os.path.join(temp_dir, "whisper_cache")
            try:
                os.makedirs(download_root, exist_ok=True)
            except Exception:
                download_root = temp_dir

        # Seleccionar dispositivo (GPU/CUDA si está disponible, de lo contrario CPU)
        import torch
        device = "cuda" if torch.cuda.is_available() else "cpu"
        whisper_model = os.environ.get("WHISPER_MODEL", "medium")
        model = whisper.load_model(whisper_model, device=device, download_root=download_root)
        
        # 2. Parámetros óptimos para evitar arrastre de errores y saltear silencios pesados
        opciones = {
            "language": "es",
            "condition_on_previous_text": False,
            "no_speech_threshold": 0.5
        }
        
        result = model.transcribe(audio_path, **opciones)
        
        # 3. Filtrar y sanitizar el output text
        texto_final = limpiar_texto_completo(result["text"])
        
        # Devolver el texto limpio de verdad para la base de datos
        print(texto_final)
        sys.exit(0)

    except ImportError:
        # Fallback si no está instalada la librería whisper
        print(f"[TRANSCRIPCIÓN SIMULADA] Llamada grabada en el archivo '{filename}'. "
              f"Este es un texto de prueba simulado porque la librería 'whisper' de Python no está instalada en este entorno. "
              f"Para habilitar transcripciones reales, ejecute: pip install openai-whisper")
        sys.exit(0)
    except Exception as e:
        # Fallback ante cualquier otro error
        print(f"[ERROR DE TRANSCRIPCIÓN] Ocurrió un error al procesar el archivo '{filename}': {str(e)}")
        sys.exit(0)

if __name__ == "__main__":
    main()