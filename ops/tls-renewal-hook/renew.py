#!/usr/bin/python3 -I
"""Root-only local Certbot deployment; no network ACME calls or container lifecycle commands."""
import argparse
import contextlib
from dataclasses import dataclass
import fcntl
import hashlib
import http.client
import json
import os
from pathlib import Path
import re
import resource
import shutil
import signal
import socket
import ssl
import stat
import subprocess
import sys
import tempfile
import time

DOMAINS = ("holoul.com.ly", "www.holoul.com.ly")
BOOT = "faa9fb98dfb7a559fe3a037a0fc320aa0f6fd8b0ada8f47fe3b4fea5c60c03d1"
LEGACY = "9f8a10570a0b08c9e4e123113ecdf17d5dac9c6a452ad00aa13067c5de03e147"
FINAL = "4d2a888d28645d3adb2677f15d2cb2a9a0fe742d212a2be29e4049bd8e768ea6"
INCLUDES = {
    "/etc/nginx/holoul-fastcgi.conf": "d59da30a5954b4703398002d8c5ad78660713e2dc16efabf44f29691f6d3af9e",
    "/etc/nginx/holoul-proxy.conf": "7545ae5e4382f273bef30c3da61a4d27eaff02088d54710efc933cc40d7040f5",
}
HEX = re.compile(r"[0-9a-f]{64}\Z")
CERT = re.compile(rb"-----BEGIN CERTIFICATE-----\s+[A-Za-z0-9+/=\s]+-----END CERTIFICATE-----")
ENV = {"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "LANG": "C", "HOME": "/root",
       "DOCKER_HOST": "unix:///var/run/docker.sock"}


class Blocked(Exception):
    """Only static event codes are exposed, never subprocess output or key material."""


def require(ok, code):
    if not ok:
        raise Blocked(code)


def digest(data):
    return hashlib.sha256(data).hexdigest()


@dataclass(frozen=True)
class Settings:
    # No environment/CLI override of security paths, trust roots or container target.
    # Tests instantiate this class directly with private synthetic temporary paths.
    lineage: Path = Path("/etc/letsencrypt/live/holoul.com.ly")
    archive: Path = Path("/etc/letsencrypt/archive/holoul.com.ly")
    edge: Path = Path("/etc/holoul/secrets/edge-tls")
    state: Path = Path("/var/lib/holoul/tls-renewal")
    lock: Path = Path("/run/holoul-tls-renewal.lock")
    ca: Path = Path("/etc/ssl/certs/ca-certificates.crt")
    container: str = "holoul-production-ingress-1"
    project: str = "holoul-production"
    http_port: int = 80
    https_port: int = 443
    wait_seconds: int = 20


def command(args, data=None):
    try:
        result = subprocess.run(args, input=data, stdout=subprocess.PIPE,
                                stderr=subprocess.PIPE, env=ENV, timeout=30, check=False)
    except (OSError, subprocess.TimeoutExpired):
        raise Blocked("COMMAND_UNAVAILABLE") from None
    require(result.returncode == 0, "COMMAND_FAILED")
    return result.stdout


def fsync_dir(path):
    fd = os.open(path, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def secure_parents(path):
    for parent in [path, *path.parents]:
        info = parent.lstat()
        require(stat.S_ISDIR(info.st_mode) and info.st_uid == 0 and
                not info.st_mode & 0o022, "UNSAFE_PARENT_DIRECTORY")


def secure_dir(path, gid, mode):
    secure_parents(path)
    info = path.stat()
    require(info.st_gid == gid and stat.S_IMODE(info.st_mode) == mode, "DIRECTORY_PERMISSIONS")


def read_file(path, *, exact_mode=None, gid=None, private=False):
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        info = os.fstat(fd)
        require(stat.S_ISREG(info.st_mode) and info.st_uid == 0 and info.st_nlink == 1 and
                info.st_size <= 131072 and not info.st_mode & 0o022, "UNSAFE_FILE")
        if exact_mode is not None:
            require(stat.S_IMODE(info.st_mode) == exact_mode and info.st_gid == gid, "FILE_PERMISSIONS")
        if private:
            require(not info.st_mode & 0o007 and
                    (not info.st_mode & 0o070 or info.st_gid == 101), "PRIVATE_KEY_PERMISSIONS")
        with os.fdopen(fd, "rb", closefd=False) as stream:
            data = stream.read(131073)
        require(len(data) <= 131072, "FILE_TOO_LARGE")
        return data
    finally:
        os.close(fd)


def write_file(path, data, gid=0, mode=0o600):
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    try:
        with os.fdopen(fd, "wb", closefd=False) as stream:
            stream.write(data)
            stream.flush()
        os.fchown(fd, 0, gid)
        os.fchmod(fd, mode)
        os.fsync(fd)
    finally:
        os.close(fd)


@contextlib.contextmanager
def locked(path):
    secure_parents(path.parent)
    fd = os.open(path, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW | os.O_NONBLOCK, 0o600)
    try:
        info = os.fstat(fd)
        require(stat.S_ISREG(info.st_mode) and info.st_uid == 0 and info.st_gid == 0 and
                stat.S_IMODE(info.st_mode) == 0o600 and info.st_nlink == 1, "UNSAFE_LOCK")
        try:
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise Blocked("BUSY") from None
        yield
    finally:
        os.close(fd)


class Hook:
    def __init__(self, settings=Settings(), run=command):
        self.s = settings
        self.run = run

    def openssl(self, *args, data=None):
        return self.run(["/usr/bin/openssl", *map(str, args)], data)

    def json_write(self, name, data):
        path = self.s.state / name
        pending = self.s.state / (".write-" + os.urandom(8).hex())
        write_file(pending, (json.dumps(data, sort_keys=True) + "\n").encode())
        os.replace(pending, path)
        fsync_dir(self.s.state)

    def json_read(self, name):
        data = json.loads(read_file(self.s.state / name, exact_mode=0o600, gid=0))
        return data

    def validate(self, chain, key, minimum=0):
        blocks = CERT.findall(chain)
        require(2 <= len(blocks) <= 8 and not CERT.sub(b"", chain).strip(), "MALFORMED_CHAIN")
        require(len(set(blocks)) == len(blocks), "DUPLICATE_CHAIN_CERTIFICATE")
        require(re.fullmatch(rb"-----BEGIN (PRIVATE KEY|RSA PRIVATE KEY|EC PRIVATE KEY)-----\s+"
                             rb"[A-Za-z0-9+/=\s]+-----END \1-----\s*", key) is not None, "MALFORMED_KEY")
        # Private scratch stays outside the bind mount; only filenames enter argv.
        with tempfile.TemporaryDirectory(prefix=".validate-", dir=self.s.state) as scratch:
            scratch = Path(scratch)
            write_file(scratch / "key.pem", key)
            write_file(scratch / "leaf.pem", blocks[0] + b"\n")
            write_file(scratch / "chain.pem", b"\n".join(blocks[1:]) + b"\n")
            leaf = scratch / "leaf.pem"
            self.openssl("pkey", "-in", scratch / "key.pem", "-passin", "fd:0", "-check", "-noout", data=b"\n")
            private_public = self.openssl("pkey", "-in", scratch / "key.pem", "-passin", "fd:0",
                                          "-pubout", "-outform", "DER", data=b"\n")
            public_pem = self.openssl("x509", "-in", leaf, "-pubkey", "-noout")
            public_der = self.openssl("pkey", "-pubin", "-outform", "DER", data=public_pem)
            require(public_der == private_public, "KEY_MISMATCH")
            san = self.openssl("x509", "-in", leaf, "-noout", "-ext", "subjectAltName").decode("ascii")
            entries = [item.strip() for item in san.split("\n", 1)[1].strip().split(",")]
            require(sorted(entries) == sorted("DNS:" + domain for domain in DOMAINS), "SAN_MISMATCH")
            constraints = self.openssl("x509", "-in", leaf, "-noout", "-ext", "basicConstraints")
            require(b"CA:FALSE" in constraints, "LEAF_NOT_END_ENTITY")
            self.openssl("x509", "-in", leaf, "-checkend", minimum, "-noout")
            for block in blocks[1:]:
                intermediate = scratch / "intermediate.pem"
                intermediate.unlink(missing_ok=True)
                write_file(intermediate, block + b"\n")
                ca_constraint = self.openssl("x509", "-in", intermediate, "-noout", "-ext", "basicConstraints")
                require(b"CA:TRUE" in ca_constraint, "CHAIN_CONTAINS_NON_CA")
                self.openssl("verify", "-x509_strict", "-auth_level", "2", "-CAfile", self.s.ca,
                             "-no-CApath", "-no-CAstore", "-untrusted", scratch / "chain.pem", intermediate)
            for domain in DOMAINS:
                self.openssl("verify", "-x509_strict", "-auth_level", "2", "-purpose", "sslserver",
                             "-verify_hostname", domain, "-CAfile", self.s.ca, "-no-CApath", "-no-CAstore",
                             "-untrusted", scratch / "chain.pem", leaf)
            leaf_der = self.openssl("x509", "-in", leaf, "-outform", "DER")
        return digest(leaf_der)

    def source(self):
        secure_parents(self.s.lineage)
        secure_parents(self.s.archive)
        paths = [(self.s.lineage / name).resolve(strict=True) for name in ("fullchain.pem", "privkey.pem")]
        for path in paths:
            require(path.parent == self.s.archive, "LINEAGE_ESCAPE")
        chain = read_file(paths[0])
        key = read_file(paths[1], private=True)
        self.validate(chain, key, 86400)
        return chain, key

    @staticmethod
    def fragment(version):
        require(HEX.fullmatch(version), "VERSION_ID")
        # One include is parsed into TWO immutable paths; never two moving symlinks.
        base = "/run/holoul-tls/versions/" + version
        return ("ssl_certificate " + base + "/fullchain.pem;\n" +
                "ssl_certificate_key " + base + "/privkey.pem;\n").encode()

    def version(self, version):
        require(isinstance(version, str) and HEX.fullmatch(version), "VERSION_ID")
        path = self.s.edge / "versions" / version
        secure_dir(path, 101, 0o750)
        chain = read_file(path / "fullchain.pem", exact_mode=0o440, gid=101)
        key = read_file(path / "privkey.pem", exact_mode=0o440, gid=101, private=True)
        fragment = read_file(path / "nginx-tls.conf", exact_mode=0o440, gid=101)
        require(digest(chain) == version and fragment == self.fragment(version), "VERSION_CHANGED")
        return self.validate(chain, key)

    def stage(self, chain, key):
        version = digest(chain)
        final = self.s.edge / "versions" / version
        if final.exists():
            self.version(version)
            return version
        pending = Path(tempfile.mkdtemp(prefix=".pending-", dir=self.s.edge / "versions"))
        try:
            write_file(pending / "fullchain.pem", chain, 101, 0o440)
            write_file(pending / "privkey.pem", key, 101, 0o440)
            write_file(pending / "nginx-tls.conf", self.fragment(version), 101, 0o440)
            fsync_dir(pending)
            os.chown(pending, 0, 101)
            os.chmod(pending, 0o750)
            fsync_dir(pending)
            os.rename(pending, final)
            fsync_dir(final.parent)
        finally:
            if pending.exists():
                shutil.rmtree(pending)
        self.version(version)
        return version

    def pointer(self):
        path = self.s.edge / "current"
        require(path.is_symlink() and path.lstat().st_uid == 0, "CURRENT_NOT_SYMLINK")
        target = os.readlink(path)
        require(target.startswith("versions/") and HEX.fullmatch(target[9:]), "CURRENT_ESCAPE")
        return target[9:]

    def switch(self, version):
        self.version(version)
        pending = self.s.edge / (".current-" + os.urandom(8).hex())
        os.symlink("versions/" + version, pending)
        os.replace(pending, self.s.edge / "current")
        fsync_dir(self.s.edge)

    def inspect(self):
        template = ('{"id":{{json .Id}},"running":{{json .State.Running}},'
                    '"started":{{json .State.StartedAt}},"user":{{json .Config.User}},'
                    '"labels":{{json .Config.Labels}},"mounts":{{json .Mounts}},'
                    '"ports":{{json .NetworkSettings.Ports}},"path":{{json .Path}},'
                    '"args":{{json .Args}},"readonly":{{json .HostConfig.ReadonlyRootfs}}}')
        data = json.loads(self.run(["/usr/bin/docker", "inspect", "--format", template, self.s.container]))
        labels = data["labels"]
        require(data["running"] and data["user"] == "101:101" and data["readonly"] and
                labels.get("com.docker.compose.project") == self.s.project and
                labels.get("com.docker.compose.service") == "ingress" and
                data["path"] == "nginx" and data["args"] == ["-g", "daemon off;"], "INGRESS_IDENTITY")
        mounts = [m for m in data["mounts"] if m["Destination"] == "/run/holoul-tls"]
        require(len(mounts) == 1 and mounts[0]["Type"] == "bind" and not mounts[0]["RW"] and
                mounts[0]["Source"] == str(self.s.edge), "TLS_BIND_MOUNT")
        require(not any(m["Destination"].startswith("/run/holoul-tls/") for m in data["mounts"]), "NESTED_TLS_MOUNT")
        for inside, outside in (("8080/tcp", self.s.http_port),):
            require(any(p["HostPort"] == str(outside) and p["HostIp"] in ("0.0.0.0", "127.0.0.1")
                        for p in data["ports"].get(inside) or []), "INGRESS_PORT_BINDING")
        return data

    def execute(self, instance, *args):
        return self.run(["/usr/bin/docker", "exec", "--user", "101:101", instance["id"], *args])

    def mode(self, instance):
        config = self.execute(instance, "/bin/cat", "/etc/nginx/nginx.conf")
        checksum = digest(config)
        require(checksum != LEGACY, "LEGACY_PRODUCTION_REQUIRES_VERSIONED_CONFIG")
        require(checksum in (BOOT, FINAL), "UNREVIEWED_NGINX_CONFIG")
        mode = "bootstrap" if checksum == BOOT else "production"
        if mode == "production":
            require(any(p["HostPort"] == str(self.s.https_port) and p["HostIp"] in ("0.0.0.0", "127.0.0.1")
                        for p in instance["ports"].get("443/tcp") or []), "HTTPS_PORT_BINDING")
            for path, expected in INCLUDES.items():
                require(digest(self.execute(instance, "/bin/cat", path)) == expected, "NGINX_INCLUDE_CHANGED")
        # A file on disk alone cannot prove which configuration the master loaded.
        for domain in DOMAINS:
            connection = http.client.HTTPConnection("127.0.0.1", self.s.http_port, timeout=3)
            try:
                connection.request("GET", "/", headers={"Host": domain, "Connection": "close"})
                response = connection.getresponse()
                require(response.status == (503 if mode == "bootstrap" else 308), "LOADED_CONFIG_MISMATCH")
                if mode == "production":
                    require(response.getheader("Location") == "https://holoul.com.ly/", "HTTP_ORIGIN_MISMATCH")
            finally:
                connection.close()
        return mode

    def guard(self, instance, mode):
        current = self.inspect()
        require((current["id"], current["started"]) == (instance["id"], instance["started"]), "INGRESS_CHANGED")
        require(self.mode(current) == mode, "INGRESS_MODE_CHANGED")

    def workers(self, instance):
        listing = self.execute(instance, "/bin/ps", "-o", "pid,args").decode("ascii")
        return set(re.findall(r"^\s*(\d+)\s+nginx: worker process\s*$", listing, re.M))

    def served(self, fingerprint):
        for domain in DOMAINS:
            context = ssl.create_default_context(cafile=str(self.s.ca))
            with socket.create_connection(("127.0.0.1", self.s.https_port), timeout=3) as connection:
                with context.wrap_socket(connection, server_hostname=domain) as tls:
                    require(digest(tls.getpeercert(binary_form=True)) == fingerprint, "SERVED_CERTIFICATE_MISMATCH")

    def reload(self, instance, fingerprint):
        self.guard(instance, "production")
        self.execute(instance, "/usr/sbin/nginx", "-t")
        workers = self.workers(instance)
        require(workers, "NO_NGINX_WORKERS")
        self.guard(instance, "production")
        self.execute(instance, "/usr/sbin/nginx", "-s", "reload")
        end = time.monotonic() + self.s.wait_seconds
        consecutive = 0
        while time.monotonic() < end:
            self.guard(instance, "production")
            try:
                self.served(fingerprint)
                require(self.workers(instance) - workers, "NO_NEW_WORKER_GENERATION")
                consecutive += 1
                if consecutive == 2:
                    return
            except (Blocked, OSError, ssl.SSLError):
                consecutive = 0
            time.sleep(0.2)
        raise Blocked("RELOAD_NOT_CONFIRMED")

    def clear_journal(self):
        (self.s.state / "transaction.json").unlink()
        fsync_dir(self.s.state)

    def restore(self, instance, mode, txn):
        require(txn["container_id"] == instance["id"] and txn["started"] == instance["started"] and
                txn["mode"] == mode, "RECOVERY_INGRESS_CHANGED")
        old = txn["before"]["active"]
        require(self.pointer() in (old, txn["new"]), "RECOVERY_POINTER_CHANGED")
        fingerprint = self.version(old)
        self.guard(instance, mode)
        self.switch(old)
        if mode == "production":
            self.reload(instance, fingerprint)
        self.json_write("verified.json", txn["before"])
        self.clear_journal()

    def transition(self, instance, mode, before, target, rollback=False):
        old = before["active"]
        self.version(old)  # Rollback must be viable BEFORE activation.
        fingerprint = self.version(target)
        self.guard(instance, mode)
        txn = {"before": before, "new": target, "mode": mode, "container_id": instance["id"],
               "started": instance["started"]}
        self.json_write("transaction.json", txn)
        try:
            self.switch(target)
            self.guard(instance, mode)
            if mode == "production":
                self.reload(instance, fingerprint)
            self.json_write("verified.json", {"active": target, "previous": target if rollback else old})
            self.clear_journal()
        except (Exception, KeyboardInterrupt) as error:
            reason = str(error) if isinstance(error, Blocked) else "INTERNAL_OR_IO_FAILURE"
            if re.fullmatch(r"[A-Z_]{1,100}", reason) is None:
                reason = "INTERNAL_OR_IO_FAILURE"
            try:
                self.restore(instance, mode, txn)
            except (Exception, KeyboardInterrupt):
                raise Blocked("ACTIVATION_FAILED_RECOVERY_BLOCKED_" + reason) from None
            raise Blocked("ACTIVATION_FAILED_ROLLED_BACK_" + reason) from None

    def process(self, action="renew"):
        require(os.geteuid() == 0, "ROOT_REQUIRED")
        os.umask(0o077)
        resource.setrlimit(resource.RLIMIT_CORE, (0, 0))
        with locked(self.s.lock):
            secure_dir(self.s.edge, 101, 0o750)
            secure_dir(self.s.edge / "versions", 101, 0o750)
            secure_dir(self.s.state, 0, 0o700)
            instance = self.inspect()
            mode = self.mode(instance)
            journal = self.s.state / "transaction.json"
            if journal.exists():
                try:
                    self.restore(instance, mode, self.json_read("transaction.json"))
                except Exception:
                    raise Blocked("INTERRUPTED_TRANSACTION_RECOVERY_BLOCKED") from None
                if action == "recover":
                    return "RECOVERED_PREVIOUS_VERSION"
            elif action == "recover":
                return "NO_PENDING_TRANSACTION"
            verified = self.s.state / "verified.json"
            if action == "initialize":
                require(mode == "bootstrap", "INITIALIZE_REQUIRES_BOOTSTRAP")
                if verified.exists():
                    before = self.json_read("verified.json")
                    require(self.pointer() == before["active"], "STATE_POINTER_MISMATCH")
                    self.version(before["active"])
                    return "ALREADY_INITIALIZED_NO_RELOAD"
                if (self.s.edge / "current").is_symlink():
                    version = self.pointer()
                    self.version(version)
                    self.json_write("verified.json", {"active": version, "previous": None})
                    return "INITIALIZATION_RECOVERED_NO_RELOAD"
                files = [self.s.edge / name for name in ("fullchain.pem", "privkey.pem")]
                require(files[0].exists() == files[1].exists(), "INCOMPLETE_LEGACY_PAIR")
                if files[0].exists():
                    chain, key = read_file(files[0]), read_file(files[1], private=True)
                    self.validate(chain, key)
                else:
                    chain, key = self.source()
                version = self.stage(chain, key)
                # A killed initialization is resumed only by this explicit bootstrap-only action.
                self.switch(version)
                self.json_write("verified.json", {"active": version, "previous": None})
                return "INITIALIZED_BOOTSTRAP_NO_RELOAD"
            require(verified.exists(), "CONTROLLED_INITIALIZATION_REQUIRED")
            before = self.json_read("verified.json")
            require(self.pointer() == before["active"], "STATE_POINTER_MISMATCH")
            fingerprint = self.version(before["active"])
            if mode == "production":
                self.served(fingerprint)
            if action == "rollback":
                require(before.get("previous"), "NO_VERIFIED_PREVIOUS_VERSION")
                target = before["previous"]
            else:
                chain, key = self.source()
                target = self.stage(chain, key)
            if target == before["active"]:
                return "UNCHANGED_NO_RELOAD"
            self.transition(instance, mode, before, target, rollback=action == "rollback")
            return "STAGED_BOOTSTRAP_NO_RELOAD" if mode == "bootstrap" else "ACTIVATED_AND_VERIFIED"


def interrupted(_signal, _frame):
    raise Blocked("INTERRUPTED")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    choices = parser.add_mutually_exclusive_group()
    choices.add_argument("--initialize", action="store_true")
    choices.add_argument("--recover", action="store_true")
    choices.add_argument("--rollback", action="store_true")
    args = parser.parse_args()
    action = next((name for name in ("initialize", "recover", "rollback") if getattr(args, name)), "renew")
    if action == "renew":
        if os.environ.get("RENEWED_LINEAGE", "").rstrip("/") != str(Settings.lineage):
            print("HOLOUL_TLS SKIPPED_OTHER_LINEAGE")
            return 0
        if sorted(os.environ.get("RENEWED_DOMAINS", "").split()) != sorted(DOMAINS):
            print("HOLOUL_TLS BLOCKED_RENEWED_DOMAINS", file=sys.stderr)
            return 1
    signal.signal(signal.SIGTERM, interrupted)
    signal.signal(signal.SIGINT, interrupted)
    try:
        print("HOLOUL_TLS " + Hook().process(action))
        return 0
    except Blocked as error:
        print("HOLOUL_TLS " + str(error), file=sys.stderr)
        return 75 if str(error) == "BUSY" else 1
    except Exception:
        print("HOLOUL_TLS BLOCKED_INTERNAL_OR_IO_FAILURE", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
