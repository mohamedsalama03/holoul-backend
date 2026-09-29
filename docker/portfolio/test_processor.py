import importlib.util
import io
import json
import socket
import struct
import unittest
import zlib
from PIL import Image

spec = importlib.util.spec_from_file_location("processor", "/opt/holoul/processor.py")
processor = importlib.util.module_from_spec(spec)
spec.loader.exec_module(processor)


def image(format="PNG", size=(100, 50), **kwargs):
    output = io.BytesIO()
    Image.new("RGB", size, (30, 90, 160)).save(output, format=format, **kwargs)
    return output.getvalue()


class ProcessorTest(unittest.TestCase):
    def test_supported_static_formats_strip_metadata_and_never_upscale(self):
        for format, mime in [("PNG", "image/png"), ("JPEG", "image/jpeg"), ("WEBP", "image/webp")]:
            with self.subTest(format=format):
                result = processor.transform(image(format), mime)
                self.assertEqual(list(result), ["card", "gallery"])
                for width, height, data in result.values():
                    self.assertEqual((width, height), (100, 50))
                    with Image.open(io.BytesIO(data)) as output:
                        self.assertEqual(output.format, "WEBP")
                        self.assertEqual(getattr(output, "n_frames", 1), 1)
                        self.assertFalse(output.getexif())
                        for key in ("exif", "icc_profile", "xmp", "comment"):
                            self.assertNotIn(key, output.info)

    def test_orientation_and_bounds(self):
        exif = Image.Exif()
        exif[274] = 6
        exif[270] = "Private source metadata"
        result = processor.transform(image("JPEG", (1200, 800), exif=exif), "image/jpeg")
        self.assertEqual(result["gallery"][:2], (800, 1200))
        self.assertLessEqual(result["card"][0], 640)
        self.assertEqual(result["card"][1], 640)
        self.assertNotIn(b"Private source metadata", result["gallery"][2])

    def test_animation_disguise_truncation_trailing_and_oversize_are_rejected(self):
        animation = io.BytesIO()
        Image.new("RGB", (20, 20), "red").save(animation, format="WEBP", save_all=True,
                    append_images=[Image.new("RGB", (20, 20), "blue")], duration=100, loop=0)
        png_animation = io.BytesIO()
        Image.new("RGB", (20, 20), "red").save(png_animation, format="PNG", save_all=True,
                    append_images=[Image.new("RGB", (20, 20), "blue")], duration=100, loop=0)
        cases = [(animation.getvalue(), "image/webp"), (png_animation.getvalue(), "image/png"),
                 (image("PNG"), "image/jpeg"), (b'<svg onload="alert(1)"/>', "image/png"),
                 (image("PNG")[:-10], "image/png"), (image("PNG")+b"private trailer", "image/png"),
                 (b"x"*(processor.MAX_BYTES+1), "image/png")]
        for data, mime in cases:
            with self.subTest(size=len(data), mime=mime):
                with self.assertRaises(Exception): processor.transform(data, mime)

    def test_pixel_bomb_is_rejected_before_decode(self):
        data = bytearray(image("PNG"))
        data[16:24] = struct.pack(">II", 6000, 5000)
        data[29:33] = struct.pack(">I", zlib.crc32(data[12:29]))
        with self.assertRaises(Exception): processor.transform(bytes(data), "image/png")

    def test_real_socket_uses_the_bounded_subprocess(self):
        data = image("PNG")
        with socket.socket(socket.AF_UNIX) as connection:
            connection.settimeout(40)
            connection.connect(processor.SOCKET)
            connection.sendall(json.dumps({"size":len(data),"media_type":"image/png"}).encode()+b"\n"+data)
            stream = connection.makefile("rb")
            response = json.loads(stream.readline(1024))
            self.assertTrue(response["safe"])
            for variant in ("card", "gallery"):
                raw = stream.read(response["variants"][variant]["size"])
                with Image.open(io.BytesIO(raw)) as converted:
                    self.assertEqual(converted.format,"WEBP")
                    self.assertEqual(converted.size,(100,50))

if __name__ == "__main__": unittest.main(verbosity=2)
