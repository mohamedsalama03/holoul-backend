"""Ephemeral smoke checks of the exact exported runtime config; no production secrets."""
import json
import os
import secrets
import subprocess
import time

import publish as p


def redis_smoke(work, reference):
    tls = work / "smoke-tls"
    tls.mkdir(mode=0o755)
    tls.chmod(0o755)
    password = secrets.token_hex(32)
    for stem in ("server", "wrong"):
        p.run(["openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1",
               "-subj", "/CN=localhost", "-addext", "subjectAltName=DNS:localhost,IP:127.0.0.1",
               "-keyout", str(tls / (stem + ".key")), "-out", str(tls / (stem + ".crt"))],
              stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    (tls / "redis.conf").write_text("\n".join([
        "bind 127.0.0.1", "port 0", "tls-port 6379", "tls-cert-file /run/smoke/server.crt",
        "tls-key-file /run/smoke/server.key", "tls-ca-cert-file /run/smoke/server.crt",
        "tls-auth-clients no", "requirepass " + password, "save \"\"", "appendonly no", "dir /data", ""]))
    # The parent work directory remains 0700 on the host; only this ephemeral
    # fixture is readable by container UID 999 through a read-only bind mount.
    for path in tls.iterdir():
        path.chmod(0o644)
    name = "holoul-publication-redis-" + os.environ["GITHUB_RUN_ID"]
    try:
        p.run(["docker", "run", "--detach", "--rm", "--name", name, "--network", "none",
               "--user", "999:999", "--read-only", "--cap-drop", "ALL",
               "--security-opt", "no-new-privileges:true", "--tmpfs", "/data:uid=999,gid=999,mode=0700",
               "--volume", str(tls) + ":/run/smoke:ro", reference,
               "redis-server", "/run/smoke/redis.conf"], stdout=subprocess.DEVNULL)

        def request(*args, credential=None, ca="server", encrypted=True):
            environment = os.environ.copy()
            command = ["docker", "exec"]
            if credential is not None:
                environment["REDISCLI_AUTH"] = credential
                command += ["--env", "REDISCLI_AUTH"]
            command += [name, "redis-cli", "--raw", "-h", "127.0.0.1", "-p", "6379"]
            if encrypted:
                command += ["--tls", "--cacert", "/run/smoke/" + ca + ".crt"]
            return subprocess.run(command + list(args), env=environment, capture_output=True,
                                  text=True, timeout=6)

        ready = False
        for _ in range(30):
            reply = request("PING", credential=password)
            if reply.returncode == 0 and reply.stdout.strip() == "PONG":
                ready = True
                break
            time.sleep(1)
        p.require(ready, "Redis TLS startup failed")
        p.require("NOAUTH" in request("PING").stdout, "Redis allowed anonymous access")
        rejected = request("PING", credential="incorrect-test-password")
        p.require(rejected.stdout.strip() != "PONG" and "WRONGPASS" in rejected.stderr,
                  "Redis accepted wrong credentials")
        rejected = request("PING", credential=password, ca="wrong")
        p.require(rejected.returncode != 0 and rejected.stdout.strip() != "PONG", "Redis accepted wrong CA")
        try:
            plain = request("PING", credential=password, encrypted=False)
            p.require(plain.returncode != 0 and plain.stdout.strip() != "PONG", "Redis accepted plaintext")
        except subprocess.TimeoutExpired:
            # No plaintext Redis listener exists; the TLS socket may wait for a handshake.
            pass
        p.require(request("SET", "publication-smoke", "fixture", credential=password).stdout.strip() == "OK",
                  "Redis authenticated write failed")
        p.require(request("GET", "publication-smoke", credential=password).stdout.strip() == "fixture",
                  "Redis authenticated read failed")
        return {"startup": True, "tls_authenticated_ping": True, "tls_write_read": True,
                "anonymous_rejected": True, "wrong_password_rejected": True,
                "wrong_ca_rejected": True, "plaintext_rejected": True, "network": "none"}
    finally:
        subprocess.run(["docker", "rm", "--force", name], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        # Remove only the synthetic credentials created above.
        for path in tls.iterdir():
            path.unlink()
        tls.rmdir()


def check(work, image, inspection, expected_packages):
    reference = "holoul-publication-check:" + image
    archive = work / "smoke.tar"
    p.run(p.container(p.SKOPEO, work, "--override-os", "linux", "--override-arch", "amd64", "copy",
                      "oci:/work/layout", "docker-archive:/work/smoke.tar:" + reference))
    try:
        p.run(["docker", "image", "load", "--input", str(archive)], stdout=subprocess.DEVNULL)
        inspection_loaded = json.loads(p.output(["docker", "image", "inspect", reference]))[0]
        p.require(inspection_loaded["Id"] == inspection["config_digest"], "smoke image config differs from OCI")
        packages = p.output(["docker", "run", "--rm", "--network", "none", "--entrypoint", "/sbin/apk",
                             reference, "info", "-v"]).splitlines()
        for package, version in expected_packages.items():
            p.require(package + "-" + version in packages, "installed package differs: " + package)
        commands = {"backend": ["php", "--version"], "postgres": ["postgres", "--version"],
                    "redis": ["redis-server", "--version"], "storage": ["/usr/bin/weed", "version"],
                    "portfolio": ["python3", "-I", "-c", "from PIL import Image; print(Image.__version__)"],
                    "nginx": ["nginx", "-v"]}
        command = commands[image]
        version = subprocess.run(["docker", "run", "--rm", "--network", "none", "--entrypoint",
                                  command[0], reference, *command[1:]], capture_output=True, text=True, check=True)
        if image == "redis":
            p.require("v=8.2.9" in version.stdout, "Redis release changed")
        result = {"passed": True, "loaded_config_digest": inspection_loaded["Id"],
                  "verified_packages": expected_packages, "installed_packages": packages,
                  "version_output": (version.stdout + version.stderr).strip()}
        if image == "redis":
            result["redis"] = redis_smoke(work, reference)
        return result
    finally:
        subprocess.run(["docker", "image", "rm", reference], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        archive.unlink(missing_ok=True)
