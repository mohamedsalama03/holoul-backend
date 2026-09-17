#!/usr/bin/env python3
"""Offline, deliberately restricted PDF/DOCX inspection. Never renders or fetches links."""
import base64
import json
import os
import posixpath
import re
import resource
import signal
import socket
import stat
import struct
import subprocess
import sys
import tempfile
import time
import urllib.parse
import xml.parsers.expat
import zipfile
import zlib

MAX_INPUT = 10 * 1024 * 1024
MAX_EXPANDED = 40 * 1024 * 1024
MAX_OUTPUT = 60 * 1024 * 1024
SOCKET = "/run/holoul-inspector/inspector.sock"


class Rejected(Exception):
    def __init__(self, reason="invalid_structure"):
        self.reason = reason


def reject(reason="invalid_structure"):
    raise Rejected(reason)


def reject_nested_payload(data):
    if any(magic in data for magic in (b"PK\x03\x04", b"PK\x05\x06", b"\x7fELF", b"PE\x00\x00", b"Rar!\x1a\x07", b"7z\xbc\xaf\x27\x1c")) or data.lstrip().startswith((b"MZ", b"#!", b"<?php")):
        reject("dangerous_content")


def inspect_image(name, data):
    data = bytes(data)
    reject_nested_payload(data)
    extension = posixpath.splitext(name)[1].lower()
    if extension == ".png":
        if not data.startswith(b"\x89PNG\r\n\x1a\n"):
            reject("format_mismatch")
        position = 8
        chunks = 0
        compressed = bytearray()
        while position < len(data):
            chunks += 1
            if chunks > 2048 or position + 12 > len(data):
                reject()
            length = int.from_bytes(data[position:position + 4], "big")
            kind = data[position + 4:position + 8]
            end = position + 12 + length
            if end > len(data) or not re.fullmatch(b"[A-Za-z]{4}", kind):
                reject()
            payload = data[position + 8:position + 8 + length]
            checksum = int.from_bytes(data[position + 8 + length:end], "big")
            if zlib.crc32(kind + payload) != checksum:
                reject()
            if chunks == 1:
                if kind != b"IHDR" or length != 13:
                    reject()
                width, height = struct.unpack(">II", payload[:8])
                if not width or not height or width * height > 20000000:
                    reject("resource_limit")
            elif kind == b"IHDR":
                reject()
            if kind == b"IDAT":
                compressed.extend(payload)
            if kind in {b"iCCP", b"zTXt", b"iTXt", b"eXIf", b"acTL", b"fcTL", b"fdAT"}:
                reject("dangerous_content")
            if kind == b"IEND":
                if length or end != len(data) or not compressed:
                    reject()
                decoder = zlib.decompressobj()
                decoder.decompress(compressed, MAX_EXPANDED + 1)
                if decoder.unconsumed_tail:
                    reject("resource_limit")
                if not decoder.eof or decoder.unused_data:
                    reject()
                return
            position = end
        reject()
    elif extension in {".jpg", ".jpeg"}:
        if not data.startswith(b"\xff\xd8") or not data.endswith(b"\xff\xd9"):
            reject("format_mismatch")
        position = 2
        frames = 0
        scans = 0
        while position < len(data):
            if data[position] != 255:
                reject()
            while position < len(data) and data[position] == 255:
                position += 1
            if position >= len(data):
                reject()
            marker = data[position]
            position += 1
            if marker == 0xD9:
                if position != len(data) or not frames or not scans:
                    reject()
                return
            if marker in {0, 0xD8} or 0xD0 <= marker <= 0xD7 or position + 2 > len(data):
                reject()
            length = int.from_bytes(data[position:position + 2], "big")
            if length < 2 or position + length > len(data):
                reject()
            payload = data[position + 2:position + length]
            if marker in {0xC0, 0xC1, 0xC2}:
                if len(payload) < 6:
                    reject()
                height, width = struct.unpack(">HH", payload[1:5])
                if not width or not height or width * height > 20000000:
                    reject("resource_limit")
                frames += 1
            position += length
            if marker == 0xDA:
                scans += 1
                while position < len(data):
                    if data[position] != 255:
                        position += 1
                    elif position + 1 < len(data) and (data[position + 1] == 0 or 0xD0 <= data[position + 1] <= 0xD7):
                        position += 2
                    else:
                        break
        reject()
    else:
        # B4 permits only inspected PNG/JPEG media, not executable-rich vector/font codecs.
        reject("dangerous_content")


def limits():
    resource.setrlimit(resource.RLIMIT_CPU, (15, 16))
    resource.setrlimit(resource.RLIMIT_AS, (384 * 1024 * 1024,) * 2)
    resource.setrlimit(resource.RLIMIT_FSIZE, (MAX_OUTPUT,) * 2)
    resource.setrlimit(resource.RLIMIT_NOFILE, (32, 32))
    resource.setrlimit(resource.RLIMIT_CORE, (0, 0))


def qpdf(path, arguments, output):
    command = ["qpdf", "--global", "--parser-max-nesting=64", "--parser-max-errors=1",
               "--parser-max-container-size=20000", "--parser-max-container-size-damaged=20000",
               "--max-stream-filters=4", "--", path] + arguments
    result = subprocess.run(command, stdout=output, stderr=subprocess.DEVNULL, timeout=15, check=False)
    if result.returncode < 0:
        reject("resource_limit")
    if result.returncode != 0:  # Warnings/repaired documents are rejected too.
        reject()


def inspect_pdf(path):
    if zipfile.is_zipfile(path):
        reject("dangerous_content")
    with open(path, "rb") as source:
        if not re.fullmatch(rb"%PDF-(?:1\.[0-7]|2\.0)[\r\n]", source.read(9)):
            reject("format_mismatch")
        source.seek(max(0, os.path.getsize(path) - 2048))
        if not re.search(rb"%%EOF[\x00\t\n\x0c\r ]*\Z", source.read()):
            reject()
    encrypted = subprocess.run(["qpdf", "--is-encrypted", path], stdout=subprocess.DEVNULL,
                               stderr=subprocess.DEVNULL, timeout=5, check=False)
    if encrypted.returncode == 0:
        reject("encrypted_document")
    if encrypted.returncode != 2:
        reject()
    qpdf(path, ["--check"], subprocess.DEVNULL)
    with tempfile.TemporaryFile() as output:
        qpdf(path, ["--json=2", "--json-key=qpdf", "--json-key=pages", "--json-key=encrypt",
                    "--json-key=attachments", "--json-stream-data=inline", "--decode-level=all"], output)
        if output.tell() > MAX_OUTPUT:
            reject("resource_limit")
        output.seek(0)
        data = json.load(output)
    if data.get("encrypt", {}).get("encrypted"):
        reject("encrypted_document")
    pages = data.get("pages")
    if not isinstance(pages, list) or not 1 <= len(pages) <= 500:
        reject("resource_limit")
    if data.get("attachments"):
        reject("dangerous_content")
    structure = data.get("qpdf")
    if not isinstance(structure, list) or len(structure) != 2 or not isinstance(structure[1], dict):
        reject()
    if len(structure[1]) > 10000:
        reject("resource_limit")
    forbidden = {"/JS", "/JavaScript", "/AA", "/OpenAction", "/AcroForm", "/XFA", "/EmbeddedFiles",
                 "/EmbeddedFile", "/EF", "/Filespec", "/Launch", "/SubmitForm", "/ImportData",
                 "/GoToR", "/GoToE", "/RichMedia", "/RichMediaContent", "/Rendition", "/Movie",
                 "/Sound", "/3D", "/URI", "/URL", "/FS", "/Collection", "/AF", "/FileAttachment"}
    total = 0
    nodes = 0
    stack = [(structure[1], 0)]
    while stack:
        value, depth = stack.pop()
        nodes += 1
        if nodes > 200000 or depth > 64:
            reject("resource_limit")
        if isinstance(value, str):
            name = re.sub(r"#([0-9a-fA-F]{2})", lambda m: chr(int(m[1], 16)), value[2:]) if value.startswith("n:/") else value
            if name in forbidden:
                reject("dangerous_content")
        elif isinstance(value, list):
            stack.extend((item, depth + 1) for item in value)
        elif isinstance(value, dict):
            if "stream" in value:
                stream = value["stream"]
                if not isinstance(stream, dict) or not isinstance(stream.get("dict"), dict) or not isinstance(stream.get("data"), str):
                    reject()
                # Every stream must be decoded successfully. Unsupported image codecs/filters fail closed.
                if stream["dict"].get("/Filter") is not None or "/F" in stream["dict"] or "/FFilter" in stream["dict"]:
                    reject("dangerous_content")
                decoded = base64.b64decode(stream["data"], validate=True)
                reject_nested_payload(decoded)
                total += len(decoded)
                if total > MAX_EXPANDED:
                    reject("resource_limit")
            stack.extend((key, depth + 1) for key in value)
            stack.extend((item, depth + 1) for key, item in value.items() if key != "data")


class XmlInspection:
    def __init__(self, name, available):
        self.name = name
        self.available = available
        self.depth = 0
        self.nodes = 0
        self.root = None
        self.main_type = False
        self.office_relationship = False
        self.relationship_ids = set()
        self.field = False
        self.parser = xml.parsers.expat.ParserCreate(namespace_separator="}")
        self.parser.StartElementHandler = self.start
        self.parser.EndElementHandler = self.end
        self.parser.StartDoctypeDeclHandler = lambda *_: reject("dangerous_content")
        self.parser.EntityDeclHandler = lambda *_: reject("dangerous_content")
        self.parser.ExternalEntityRefHandler = lambda *_: reject("dangerous_content")
        self.parser.ProcessingInstructionHandler = lambda *_: reject("dangerous_content")

    def start(self, tag, attrs):
        self.depth += 1
        self.nodes += 1
        if self.depth > 64 or self.nodes > 100000:
            reject("resource_limit")
        if self.root is None:
            self.root = tag
        local = tag.rsplit("}", 1)[-1]
        if local in {"altChunk", "object", "OLEObject", "control", "attachedTemplate", "subDoc", "instrText", "fldSimple", "fldChar"}:
            reject("dangerous_content")
        for value in attrs.values():
            if any(word in value.lower() for word in ("macroenabled", "vbaproject", "activex", "oleobject")):
                reject("dangerous_content")
        if self.name == "[Content_Types].xml" and local in {"Default", "Override"}:
            mime = attrs.get("ContentType", "")
            if mime == "application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml" and attrs.get("PartName") == "/word/document.xml":
                self.main_type = True
            if not (mime.endswith("+xml") or mime in {"application/xml", "image/png", "image/jpeg"}):
                reject("dangerous_content")
        if self.name.endswith(".rels") and local == "Relationship":
            relation = attrs.get("Type", "")
            if attrs.get("TargetMode", "Internal") != "Internal":
                reject("dangerous_content")
            if any(word in relation.lower() for word in ("oleobject", "package", "attachedtemplate", "afchunk", "activex", "vbaproject", "control")):
                reject("dangerous_content")
            identifier = attrs.get("Id", "")
            if not identifier or identifier in self.relationship_ids:
                reject()
            self.relationship_ids.add(identifier)
            target = urllib.parse.unquote(attrs.get("Target", ""))
            url = urllib.parse.urlsplit(target)
            if not target or url.scheme or url.netloc or url.query or url.fragment or "\\" in target or "\x00" in target:
                reject("dangerous_content")
            base = "" if self.name == "_rels/.rels" else self.name.rsplit("/_rels/", 1)[0]
            resolved = posixpath.normpath(posixpath.join(base, url.path)) if not url.path.startswith("/") else url.path[1:]
            if resolved.startswith("../") or resolved not in self.available:
                reject()
            if self.name == "_rels/.rels" and relation.endswith("/officeDocument"):
                if resolved != "word/document.xml":
                    reject()
                self.office_relationship = True

    def end(self, _):
        self.depth -= 1


def inspect_docx(path):
    size = os.path.getsize(path)
    with open(path, "rb") as source:
        if source.read(4) != b"PK\x03\x04":
            reject("format_mismatch")
        source.seek(max(0, size - 65557))
        tail = source.read()
        offset = tail.rfind(b"PK\x05\x06")
        if offset < 0 or len(tail) - offset < 22:
            reject()
        eocd = struct.unpack("<4s4H2LH", tail[offset:offset + 22])
        if eocd[1] or eocd[2] or eocd[3] != eocd[4] or eocd[4] == 65535 or eocd[7] or offset + 22 != len(tail):
            reject()
        if eocd[5] + eocd[6] != size - (len(tail) - offset):
            reject()
    total = 0
    with zipfile.ZipFile(path) as archive:
        entries = archive.infolist()
        if not 3 <= len(entries) <= 256 or len(entries) != eocd[4]:
            reject("resource_limit")
        names = set()
        case_names = set()
        # Reject prepended/gap payloads, duplicate local records and local/central disagreement.
        expected_offset = 0
        with open(path, "rb") as raw:
            for entry in sorted(entries, key=lambda item: item.header_offset):
                if entry.header_offset != expected_offset:
                    reject()
                raw.seek(entry.header_offset)
                header = raw.read(30)
                if len(header) != 30 or header[:4] != b"PK\x03\x04":
                    reject()
                local = struct.unpack("<4s5H3L2H", header)
                if local[2] != entry.flag_bits or local[3] != entry.compress_type:
                    reject()
                name = raw.read(local[9]).decode("utf-8" if entry.flag_bits & 2048 else "cp437")
                if name != entry.orig_filename:
                    reject()
                extra = raw.read(local[10])
                if len(extra) != local[10]:
                    reject()
                at = 0
                while at < len(extra):
                    if at + 4 > len(extra):
                        reject()
                    identifier, length = struct.unpack("<HH", extra[at:at + 4])
                    at += 4 + length
                    if identifier == 1 or at > len(extra):
                        reject()
                expected_offset = raw.tell() + entry.compress_size
                if entry.flag_bits & 8:
                    raw.seek(expected_offset)
                    descriptor = raw.read(4)
                    if descriptor == b"PK\x07\x08":
                        descriptor = raw.read(12)
                        expected_offset += 16
                    else:
                        descriptor += raw.read(8)
                        expected_offset += 12
                    if len(descriptor) != 12 or struct.unpack("<3L", descriptor) != (entry.CRC, entry.compress_size, entry.file_size):
                        reject()
                elif local[6:9] != (entry.CRC, entry.compress_size, entry.file_size):
                    reject()
            if expected_offset != eocd[6]:
                reject()
        for entry in entries:
            name = entry.filename
            if name != entry.orig_filename or name.startswith("/") or "\\" in name or ":" in name or "\x00" in name or any(part in {"", ".", ".."} for part in name.rstrip("/").split("/")):
                reject("dangerous_content")
            if name.casefold() in case_names or stat.S_ISLNK(entry.external_attr >> 16) or entry.flag_bits & (1 | 64 | 8192):
                reject("dangerous_content")
            case_names.add(name.casefold())
            names.add(name)
            if entry.compress_type not in {zipfile.ZIP_STORED, zipfile.ZIP_DEFLATED} or entry.file_size > 8 * 1024 * 1024 or entry.file_size > max(1, entry.compress_size) * 100:
                reject("resource_limit")
            total += entry.file_size
            if total > MAX_EXPANDED:
                reject("resource_limit")
            if any(part in name.lower() for part in ("vbaproject", "activex", "embeddings/", "customui/")) or not entry.is_dir() and not name.endswith(".rels") and posixpath.splitext(name)[1].lower() not in {".xml", ".png", ".jpg", ".jpeg"}:
                reject("dangerous_content")
        if not {"[Content_Types].xml", "_rels/.rels", "word/document.xml"} <= names:
            reject("format_mismatch")
        total = 0
        inspections = {}
        for entry in entries:
            inspector = XmlInspection(entry.filename, names) if entry.filename.endswith((".xml", ".rels")) else None
            expanded = 0
            media = bytearray()
            with archive.open(entry) as member:
                while chunk := member.read(65536):
                    expanded += len(chunk)
                    total += len(chunk)
                    if expanded > 8 * 1024 * 1024 or total > MAX_EXPANDED:
                        reject("resource_limit")
                    if inspector:
                        inspector.parser.Parse(chunk, False)
                    else:
                        media.extend(chunk)
                if inspector:
                    inspector.parser.Parse(b"", True)
                    inspections[entry.filename] = inspector
            if expanded != entry.file_size:
                reject()
            if not inspector and not entry.is_dir():
                inspect_image(entry.filename, media)
        if not inspections["[Content_Types].xml"].main_type or not inspections["_rels/.rels"].office_relationship:
            reject("format_mismatch")
        if inspections["word/document.xml"].root not in {"http://schemas.openxmlformats.org/wordprocessingml/2006/main}document", "http://purl.oclc.org/ooxml/wordprocessingml/main}document"}:
            reject("format_mismatch")


def worker(format_name, path):
    limits()
    try:
        if format_name == "pdf":
            inspect_pdf(path)
        elif format_name == "docx":
            inspect_docx(path)
        else:
            reject("format_mismatch")
        result = {"safe": True, "reason": None}
    except Rejected as rejection:
        result = {"safe": False, "reason": rejection.reason}
    except (MemoryError, subprocess.TimeoutExpired):
        result = {"safe": False, "reason": "resource_limit"}
    except (ValueError, KeyError, TypeError, RecursionError, zipfile.BadZipFile, xml.parsers.expat.ExpatError):
        result = {"safe": False, "reason": "invalid_structure"}
    except Exception:
        result = {"unavailable": True}
    print(json.dumps(result, separators=(",", ":")), flush=True)


def inspect_isolated(format_name, path):
    process = subprocess.Popen([sys.executable, "-I", __file__, "--worker", format_name, path],
                               stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, start_new_session=True)
    try:
        output, _ = process.communicate(timeout=20)
        if process.returncode in {-signal.SIGXCPU, -signal.SIGXFSZ, -signal.SIGKILL}:
            return b'{"safe":false,"reason":"resource_limit"}\n'
        if process.returncode != 0 or len(output) > 256:
            return b'{"unavailable":true}\n'
        return output
    except subprocess.TimeoutExpired:
        os.killpg(process.pid, signal.SIGKILL)
        process.communicate()
        return b'{"safe":false,"reason":"resource_limit"}\n'
    finally:
        # No descendants survive a successful or failed inspection.
        try:
            os.killpg(process.pid, signal.SIGKILL)
        except ProcessLookupError:
            pass


def serve():
    os.umask(0o077)
    if os.path.exists(SOCKET):
        os.unlink(SOCKET)
    with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as server:
        server.bind(SOCKET)
        os.chmod(SOCKET, 0o660)
        server.listen(4)
        while True:
            connection, _ = server.accept()
            with connection:
                try:
                    deadline = time.monotonic() + 10
                    header = b""
                    while not header.endswith(b"\n") and len(header) < 128:
                        connection.settimeout(max(0.01, deadline - time.monotonic()))
                        part = connection.recv(1)
                        if not part or time.monotonic() >= deadline:
                            raise ValueError()
                        header += part
                    if header == b'{"health":true}\n':
                        connection.sendall(b'{"ready":true}\n')
                        continue
                    request = json.loads(header)
                    if set(request) != {"format", "size"} or type(request["size"]) is not int or not 1 <= request["size"] <= MAX_INPUT or request["format"] not in {"pdf", "docx"}:
                        raise ValueError()
                    with tempfile.NamedTemporaryFile() as temporary:
                        remaining = request["size"]
                        while remaining:
                            connection.settimeout(max(0.01, deadline - time.monotonic()))
                            chunk = connection.recv(min(65536, remaining))
                            if not chunk or time.monotonic() >= deadline:
                                raise ValueError()
                            temporary.write(chunk)
                            remaining -= len(chunk)
                        temporary.flush()
                        result = inspect_isolated(request["format"], temporary.name)
                    connection.settimeout(2)
                    connection.sendall(result)
                except Exception:
                    try:
                        connection.sendall(b'{"unavailable":true}\n')
                    except OSError:
                        pass


if __name__ == "__main__":
    if len(sys.argv) == 4 and sys.argv[1] == "--worker":
        worker(sys.argv[2], sys.argv[3])
    elif len(sys.argv) == 2 and sys.argv[1] == "--health":
        with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as client:
            client.settimeout(2)
            client.connect(SOCKET)
            client.sendall(b'{"health":true}\n')
            sys.exit(0 if client.recv(64) == b'{"ready":true}\n' else 1)
    else:
        serve()
