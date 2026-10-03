#!/usr/bin/env python3
"""Save a Gemini key locally without command-line arguments, echo, logs or Git."""
import argparse
import getpass
import os
from pathlib import Path
import sys
import tempfile


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--check", action="store_true", help="Report availability, never the key")
    args = parser.parse_args()
    if os.name != "posix" or os.getuid() != 1000:
        raise SystemExit("Run this helper as the normal Ubuntu user (UID 1000).")
    directory = Path.home() / ".config/holoul/secrets"
    target = directory / "gemini_api_key"
    if directory.is_symlink() or target.is_symlink():
        raise SystemExit("Refusing a symlink at the private key location.")
    if args.check:
        ready = target.is_file() and target.stat().st_uid == os.getuid() and target.stat().st_mode & 0o777 == 0o600
        print("Gemini key file is ready." if ready else "Gemini key has not been configured.")
        return
    if not sys.stdin.isatty():
        raise SystemExit("Open this helper in an interactive Ubuntu terminal; key entry must remain hidden.")
    key = getpass.getpass("Paste the Gemini API key (hidden), then press Enter: ").strip()
    if not 20 <= len(key) <= 512 or any(ord(c) <= 32 or ord(c) >= 127 for c in key):
        raise SystemExit("Invalid key format. Nothing saved.")
    directory.mkdir(mode=0o700, parents=True, exist_ok=True)
    directory.chmod(0o700)
    descriptor, temporary = tempfile.mkstemp(prefix=".gemini-", dir=directory)
    try:
        with os.fdopen(descriptor, "w") as handle:
            handle.write(key + "\n")
        os.replace(temporary, target)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)
    print("Key saved privately. No request was sent to Google and the assistant remains disabled.")


if __name__ == "__main__":
    try:
        main()
    except (OSError, EOFError, KeyboardInterrupt):
        raise SystemExit("Key setup did not complete. No secret is displayed.")
