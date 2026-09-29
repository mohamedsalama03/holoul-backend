"""Offline, one-at-a-time image parser with a fresh bounded subprocess per input."""
import io
import json
import os
import resource
import signal
import socket
import struct
import subprocess
import sys
import tempfile
import time
import warnings

MAX_BYTES = 5 * 1024 * 1024
SOCKET = "/run/holoul-portfolio/processor.sock"


def transform(data, claimed):
    from PIL import Image, ImageOps
    Image.MAX_IMAGE_PIXELS = 24_000_000
    warnings.simplefilter("error", Image.DecompressionBombWarning)
    formats = {"image/jpeg": "JPEG", "image/png": "PNG", "image/webp": "WEBP"}
    if not 0 < len(data) <= MAX_BYTES or claimed not in formats:
        raise ValueError("invalid_image")
    if claimed == "image/jpeg" and (not data.startswith(b"\xff\xd8\xff") or not data.endswith(b"\xff\xd9")):
        raise ValueError("invalid_image")
    if claimed == "image/png" and (not data.startswith(b"\x89PNG\r\n\x1a\n") or not data.endswith(b"\x00\x00\x00\x00IEND\xaeB`\x82")):
        raise ValueError("invalid_image")
    if claimed == "image/webp" and (data[:4] != b"RIFF" or data[8:12] != b"WEBP" or struct.unpack("<I", data[4:8])[0] + 8 != len(data)):
        raise ValueError("invalid_image")
    with Image.open(io.BytesIO(data), formats=[formats[claimed]]) as probe:
        if probe.format != formats[claimed] or getattr(probe, "n_frames", 1) != 1:
            raise ValueError("invalid_image")
        if probe.width * probe.height > 24_000_000 or min(probe.size) < 1:
            raise ValueError("invalid_image")
        probe.verify()
    with Image.open(io.BytesIO(data), formats=[formats[claimed]]) as original:
        original.load()
        corrected = ImageOps.exif_transpose(original)
        rgba = corrected.convert("RGBA")
        # Fresh pixel-only object cannot carry EXIF/XMP/ICC/comment metadata.
        clean = Image.frombytes("RGBA", rgba.size, rgba.tobytes())
    outputs = {}
    for variant, bounds in [("card", (640, 640)), ("gallery", (1800, 1400))]:
        pixels = clean.copy()
        pixels.thumbnail(bounds, Image.Resampling.LANCZOS, reducing_gap=3.0)
        buffer = io.BytesIO()
        pixels.save(buffer, format="WEBP", quality=82, method=4, exact=True)
        result = buffer.getvalue()
        if not 0 < len(result) <= MAX_BYTES:
            raise ValueError("invalid_image")
        outputs[variant] = (pixels.width, pixels.height, result)
    return outputs


def child(path, claimed):
    resource.setrlimit(resource.RLIMIT_AS, (512 * 1024 * 1024,) * 2)
    resource.setrlimit(resource.RLIMIT_CPU, (20, 20))
    resource.setrlimit(resource.RLIMIT_FSIZE, (12 * 1024 * 1024,) * 2)
    resource.setrlimit(resource.RLIMIT_NOFILE, (32, 32))
    try:
        with open(path, "rb") as source:
            outputs = transform(source.read(MAX_BYTES + 1), claimed)
        header = {"safe": True, "variants": {name: {"width": w, "height": h, "size": len(data)} for name, (w, h, data) in outputs.items()}}
        sys.stdout.buffer.write(json.dumps(header, separators=(",", ":")).encode() + b"\n")
        for _, _, data in outputs.values():
            sys.stdout.buffer.write(data)
    except Exception:
        sys.stdout.buffer.write(b'{"safe":false,"reason":"invalid_image"}\n')


def read_exact(connection, length, deadline):
    result = bytearray()
    while len(result) < length:
        remaining = deadline - time.monotonic()
        if remaining <= 0:
            raise TimeoutError()
        connection.settimeout(remaining)
        chunk = connection.recv(min(65536, length - len(result)))
        if not chunk:
            raise ValueError()
        result.extend(chunk)
    return bytes(result)


def serve():
    os.umask(0o007)
    if os.path.exists(SOCKET):
        os.unlink(SOCKET)
    server = socket.socket(socket.AF_UNIX)
    server.bind(SOCKET)
    os.chmod(SOCKET, 0o660)
    server.listen(8)
    while True:
        connection, _ = server.accept()
        with connection:
            try:
                deadline = time.monotonic() + 35
                header = bytearray()
                while not header.endswith(b"\n"):
                    if len(header) >= 256:
                        raise ValueError()
                    header.extend(read_exact(connection, 1, deadline))
                request = json.loads(header)
                if request == {"health": True}:
                    connection.sendall(b'{"ready":true}\n')
                    continue
                if set(request) != {"size", "media_type"} or type(request["size"]) is not int or not 0 < request["size"] <= MAX_BYTES:
                    raise ValueError()
                if request["media_type"] not in ("image/jpeg", "image/png", "image/webp"):
                    raise ValueError()
                data = read_exact(connection, request["size"], deadline)
                with tempfile.NamedTemporaryFile() as source:
                    source.write(data)
                    source.flush()
                    result = subprocess.run([sys.executable, "-I", __file__, "--child", source.name, request["media_type"]],
                                            stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=25, check=False)
                if result.returncode != 0 or len(result.stdout) > MAX_BYTES * 2 + 1024:
                    connection.sendall(b'{"safe":false,"reason":"resource_limit"}\n')
                else:
                    connection.settimeout(max(0.1, deadline - time.monotonic()))
                    connection.sendall(result.stdout)
            except subprocess.TimeoutExpired:
                try:
                    connection.sendall(b'{"safe":false,"reason":"resource_limit"}\n')
                except OSError:
                    pass
            except (OSError, ValueError, KeyError, TypeError):
                pass


if __name__ == "__main__":
    if len(sys.argv) > 1 and sys.argv[1] == "--child":
        child(sys.argv[2], sys.argv[3])
    elif len(sys.argv) > 1 and sys.argv[1] == "--health":
        with socket.socket(socket.AF_UNIX) as connection:
            connection.settimeout(2)
            connection.connect(SOCKET)
            connection.sendall(b'{"health":true}\n')
            assert connection.recv(128) == b'{"ready":true}\n'
    else:
        serve()
