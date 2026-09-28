"""Guarded recovery primitives for synthetic isolated Compose projects only.

This is a reproducible recovery drill, not a production backup scheduler. Production
credentials, live databases, arbitrary volumes and destructive in-place restores
are deliberately outside this tool's accepted inputs.
"""
from __future__ import annotations

from datetime import datetime, timezone
import hashlib
import hmac
import json
import os
from pathlib import Path, PurePosixPath
import re
import subprocess
import ssl
import urllib.error
import urllib.request
import tarfile
import time

ROOT = Path(__file__).resolve().parent.parent
SECRET_VOLUMES = ("app_secrets", "migrator_secrets", "bootstrap_secrets", "redis_secrets", "tls_secrets",
                  "documents_storage_server", "documents_storage_admin", "documents_storage_client")
ARCHIVE_VOLUMES = SECRET_VOLUMES + ("documents_storage_data",)


class RecoveryError(Exception):
    pass


def now():
    return datetime.now(timezone.utc).isoformat().replace("+00:00", "Z")


def write_json(path, value):
    temporary = path.with_name(path.name + ".tmp")
    with temporary.open("x", encoding="utf-8") as output:
        os.chmod(temporary, 0o600)
        json.dump(value, output, indent=2)
        output.write("\n")
    temporary.replace(path)


def status(path, state, started):
    write_json(path, {"state": state, "completed_at": now(), "duration_ms": round((time.monotonic() - started) * 1000)})


def sha(path):
    digest = hashlib.sha256()
    with path.open("rb") as source:
        for chunk in iter(lambda: source.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


class Drill:
    def __init__(self, context):
        self.context = Path(context).resolve(strict=True)
        self.private = self.context.parent
        self.config = json.loads(self.context.read_text())
        self.nonce = self.config["nonce"]
        if not re.fullmatch(r"[a-f0-9]{24}", self.nonce):
            raise RecoveryError("invalid_drill_nonce")
        if any(not re.fullmatch(r"sha256:[a-f0-9]{64}", self.config[name]) for name in ("app_image_id", "inspector_image_id")):
            raise RecoveryError("immutable_image_id_required")
        ports = self.config["ports"]["source"] + self.config["ports"]["target"]
        if len(ports) != 6 or len(set(ports)) != 6 or any(type(port) is not int or not 1024 <= port <= 65535 for port in ports):
            raise RecoveryError("six_distinct_unprivileged_ports_required")
        expected = ROOT / "artifacts" / ("b8-restore-" + self.nonce)
        if self.private != expected or self.private.is_symlink() or self.private.stat().st_mode & 0o077:
            raise RecoveryError("private_drill_directory_required")
        self.key = self.private / "recovery.key"
        if self.key.is_symlink() or self.key.stat().st_size != 32 or self.key.stat().st_mode & 0o077:
            raise RecoveryError("separate_private_key_required")
        self.bundle = self.private / "bundle"
        self.env = os.environ.copy()
        self.env.update({"HOLOUL_APP_IMAGE": self.config["app_image_id"], "HOLOUL_APP_ENV": "production",
                         "HOLOUL_AI_ENABLED": "false", "HOLOUL_RESTORE_DRILL": self.nonce,
                         "HOLOUL_RESTORE_INSPECTOR_IMAGE": self.config["inspector_image_id"]})
        self.log = self.private / "commands.log"

    def run(self, args, *, data=None, timeout=180, stdout=None):
        with self.log.open("ab") as error:
            result = subprocess.run(args, cwd=ROOT, env=self.env, input=data,
                                    stdout=stdout or subprocess.PIPE, stderr=error, timeout=timeout, check=False)
        if result.returncode:
            # Preserve diagnostics only inside this run's private 0700 directory.
            # A fixture/HTTP command can emit sensitive synthetic values on stdout.
            if isinstance(result.stdout, bytes):
                diagnostic = self.private / "failed-command-output.bin"
                with diagnostic.open("wb") as output:
                    os.chmod(diagnostic, 0o600)
                    output.write(result.stdout)
            raise RecoveryError("command_failed:" + args[0])
        return result.stdout

    def project(self, role):
        if role not in ("source", "target"):
            raise RecoveryError("invalid_recovery_role")
        return "holoul-b8-restore-" + self.nonce + "-" + role

    def compose(self, role, *args):
        ports = self.config["ports"][role]
        self.env.update({"HOLOUL_HTTP_PORT": str(ports[0]), "HOLOUL_HTTPS_PORT": str(ports[1]), "HOLOUL_MAIL_PORT": str(ports[2])})
        frozen = self.private / (role + "-compose.json")
        files = ["--file", str(frozen)] if frozen.exists() else ["--file", str(ROOT / "compose.yaml"), "--file", str(ROOT / "compose.restore.yaml")]
        return ["docker", "compose", "--project-name", self.project(role), *files, *args]

    def container(self, role, service):
        value = self.run(self.compose(role, "ps", "-aq", service)).decode().strip()
        if not re.fullmatch(r"[a-f0-9]{12,64}", value):
            raise RecoveryError("missing_isolated_container:" + service)
        label = self.run(["docker", "inspect", "--format", '{{index .Config.Labels "com.docker.compose.project"}}', value]).decode().strip()
        if label != self.project(role):
            raise RecoveryError("container_project_mismatch")
        return value

    def pin_image(self, image_id):
        if not re.fullmatch(r"sha256:[a-f0-9]{64}", image_id):
            raise RecoveryError("immutable_image_id_required")
        path = self.private / "owned-images.json"
        owned = json.loads(path.read_text()) if path.exists() else {}
        tag = "holoul-b8-restore-" + self.nonce + ":" + image_id.removeprefix("sha256:")
        if image_id not in owned:
            if self.run(["docker", "image", "ls", "-q", tag]).strip():
                raise RecoveryError("drill_image_tag_already_exists")
            owned[image_id] = tag
            # Record the exact owned tag before mutation so an interrupted tag
            # command can be cleaned without touching any shared image name.
            write_json(path, owned)
            self.run(["docker", "image", "tag", image_id, tag])
        elif owned[image_id] != tag:
            raise RecoveryError("drill_image_tag_identity_mismatch")
        actual = self.run(["docker", "image", "inspect", "--format", "{{.Id}}", tag]).decode().strip()
        if actual != image_id:
            raise RecoveryError("drill_image_tag_changed")
        return tag

    def validate_plan(self, role):
        plan = json.loads(self.run(self.compose(role, "config", "--format", "json")))
        project = self.project(role)
        if plan.get("name") != project:
            raise RecoveryError("compose_project_mismatch")
        for kind in ("volumes", "networks"):
            for logical, definition in plan.get(kind, {}).items():
                if definition.get("external") or definition.get("name") != project + "_" + logical:
                    raise RecoveryError("compose_resource_not_isolated")
        # F1 ingress pins exact public ports. Keep those protections when this
        # isolated drill uses random loopback ports; never broaden the allowlist.
        http_port, https_port, _ = self.config["ports"][role]
        ingress = (ROOT / "docker/nginx/nginx.conf").read_text()
        for host in ("localhost", "127.0.0.1"):
            ingress = ingress.replace(host + ":8080", host + ":" + str(http_port))
            ingress = ingress.replace(host + ":8443", host + ":" + str(https_port))
        ingress = ingress.replace("X-Forwarded-Port 8443", "X-Forwarded-Port " + str(https_port))
        ingress_path = self.private / (role + "-nginx.conf")
        ingress_path.write_text(ingress)
        os.chmod(ingress_path, 0o644)  # Non-secret config; readable by the non-root edge.
        ingress_mounts = [mount for mount in plan["services"]["nginx"]["volumes"]
                          if mount["target"] == "/etc/nginx/nginx.conf"]
        if len(ingress_mounts) != 1 or not ingress_mounts[0].get("read_only"):
            raise RecoveryError("isolated_ingress_mount_required")
        ingress_mounts[0]["source"] = str(ingress_path)
        # The container listens internally on 8080; probe with the exact public
        # Host used by this namespace rather than broadening the ingress map.
        plan["services"]["nginx"]["healthcheck"]["test"] = ["CMD-SHELL",
            "wget -q -O /dev/null --header 'Host: localhost:" + str(http_port)
            + "' http://127.0.0.1:8080/health/live"]
        images = {}
        for service in plan["services"].values():
            if service.get("container_name") or service.get("network_mode") not in (None, "none"):
                raise RecoveryError("compose_shared_container_or_network")
            for port in service.get("ports", []):
                if port.get("host_ip") != "127.0.0.1":
                    raise RecoveryError("compose_ingress_not_loopback")
            for volume in service.get("volumes", []):
                if volume["type"] == "bind" and (not volume.get("read_only") or not Path(volume["source"]).resolve().is_relative_to(ROOT)):
                    raise RecoveryError("compose_bind_outside_readonly_source")
            reference = service["image"]
            if reference not in images:
                image_id = self.run(["docker", "image", "inspect", "--format", "{{.Id}}", reference]).decode().strip()
                images[reference] = self.pin_image(image_id)
            service["image"] = images[reference]
            service["pull_policy"] = "never"
        if role == "target" and (self.private / "source-compose.json").exists():
            source = json.loads((self.private / "source-compose.json").read_text())
            if {name: value["image"] for name, value in plan["services"].items()} != {name: value["image"] for name, value in source["services"].items()}:
                raise RecoveryError("source_target_images_differ")
        # Freeze the validated resolved plan so concurrent edits to the main
        # development Compose file cannot change targets between safety checks.
        write_json(self.private / (role + "-compose.json"), plan)

    def volume(self, role, logical):
        if logical not in ARCHIVE_VOLUMES + ("postgres_data",):
            raise RecoveryError("volume_outside_recovery_allowlist")
        name = self.project(role) + "_" + logical
        label = self.run(["docker", "volume", "inspect", "--format", '{{index .Labels "com.docker.compose.project"}}', name]).decode().strip()
        if label != self.project(role):
            raise RecoveryError("volume_project_mismatch")
        return name

    def assert_quiesced(self, role, allow=()):
        ids = self.run(["docker", "ps", "-q", "--filter", "label=com.docker.compose.project=" + self.project(role)]).decode().splitlines()
        for container in ids:
            service = self.run(["docker", "inspect", "--format", '{{index .Config.Labels "com.docker.compose.service"}}', container]).decode().strip()
            if service not in allow:
                raise RecoveryError("recovery_requires_stopped_service:" + service)

    def helper(self, *args, mounts=(), user="1000:1000"):
        command = ["docker", "run", "--rm", "-i", "--network", "none", "--read-only", "--user", user,
                   "--cap-drop", "ALL", "--security-opt", "no-new-privileges:true"]
        if user == "0:0":
            command += ["--cap-add", "CHOWN", "--cap-add", "FOWNER", "--cap-add", "DAC_OVERRIDE"]
        for mount in mounts:
            command += ["--mount", mount]
        command += ["--entrypoint", args[0], self.config["app_image_id"], *args[1:]]
        return command

    def seal(self, mode, label):
        return self.helper("php", "/var/www/html/scripts/backup-seal.php", mode, self.nonce + ":" + label, mounts=(
            "type=bind,src=" + str(self.key) + ",dst=/run/backup/key,readonly",))

    def verify_encryption(self):
        plain = bytes(range(256)) * 513
        cipher = self.run(self.seal("seal", "probe"), data=plain)
        if self.run(self.seal("open", "probe"), data=cipher) != plain:
            raise RecoveryError("encryption_roundtrip_failed")
        cases = {"changed_ciphertext": cipher[:-1] + bytes([cipher[-1] ^ 1]),
                 "truncated_stream": cipher[:-1], "trailing_bytes": cipher + b"x", "bad_header": b"x" + cipher[1:]}
        rejected = []
        for name, value in cases.items():
            with self.log.open("ab") as error:
                result = subprocess.run(self.seal("open", "probe"), cwd=ROOT, env=self.env, input=value,
                    stdout=subprocess.DEVNULL, stderr=error, timeout=30, check=False)
            if result.returncode == 0:
                raise RecoveryError("unauthenticated_stream_accepted")
            rejected.append(name)
        with self.log.open("ab") as error:
            result = subprocess.run(self.seal("open", "other"), cwd=ROOT, env=self.env, input=cipher,
                stdout=subprocess.DEVNULL, stderr=error, timeout=30, check=False)
        if result.returncode == 0:
            raise RecoveryError("wrong_backup_context_accepted")
        rejected.append("wrong_context")
        original_key = self.key.read_bytes()
        try:
            self.key.write_bytes(os.urandom(32))
            with self.log.open("ab") as error:
                result = subprocess.run(self.seal("open", "probe"), cwd=ROOT, env=self.env, input=cipher,
                    stdout=subprocess.DEVNULL, stderr=error, timeout=30, check=False)
            if result.returncode == 0:
                raise RecoveryError("wrong_key_accepted")
            rejected.append("wrong_key")
        finally:
            self.key.write_bytes(original_key)
        return {"round_trip_bytes": len(plain), "negative_cases_rejected": rejected}

    def encrypted_stream(self, producer, label):
        path = self.bundle / (label + ".sealed")
        with self.log.open("ab") as error, path.open("xb") as output:
            os.chmod(path, 0o600)
            process = subprocess.Popen(producer, cwd=ROOT, env=self.env, stdout=subprocess.PIPE, stderr=error)
            try:
                cipher = subprocess.Popen(self.seal("seal", label), cwd=ROOT, env=self.env,
                                          stdin=process.stdout, stdout=output, stderr=error)
                process.stdout.close()
                try:
                    cipher_code = cipher.wait(timeout=240)
                except BaseException:
                    cipher.kill()
                    cipher.wait()
                    raise
                producer_code = process.wait(timeout=30)
                if cipher_code or producer_code:
                    raise RecoveryError("encrypted_backup_stream_failed")
            finally:
                if process.poll() is None:
                    process.kill()
                    process.wait()
        return {"file": path.name, "bytes": path.stat().st_size, "sha256": sha(path)}

    def decrypt(self, label, expected):
        path = self.bundle / (label + ".sealed")
        if expected["file"] != path.name or sha(path) != expected["sha256"]:
            raise RecoveryError("backup_checksum_mismatch")
        plain = self.private / (label + ".restore-temporary")
        with plain.open("xb") as output, path.open("rb") as source, self.log.open("ab") as error:
            os.chmod(plain, 0o600)
            result = subprocess.run(self.seal("open", label), cwd=ROOT, env=self.env, stdin=source,
                                    stdout=output, stderr=error, timeout=240, check=False)
        if result.returncode:
            plain.unlink(missing_ok=True)
            raise RecoveryError("backup_authentication_failed")
        return plain

    def archive_volume(self, role, logical):
        volume = self.volume(role, logical)
        return self.encrypted_stream(self.helper("tar", "-C", "/source", "-cf", "-", ".",
            mounts=("type=volume,src=" + volume + ",dst=/source,readonly",)), logical)

    def restore_volume(self, logical, expected):
        volume = self.volume("target", logical)
        # Image copy-up can seed empty directories (SeaweedFS's filerldb2).
        # Any file, link or special entry means this is not an empty target.
        files = self.run(self.helper("find", "/target", "-mindepth", "1", "!", "-type", "d", "-print", "-quit",
            mounts=("type=volume,src=" + volume + ",dst=/target,readonly",))).strip()
        if files:
            raise RecoveryError("restore_volume_must_be_empty:" + logical)
        plain = self.decrypt(logical, expected)
        try:
            size = 0
            with tarfile.open(plain, "r|") as archive:
                for member in archive:
                    path = PurePosixPath(member.name)
                    if path.is_absolute() or ".." in path.parts or not (member.isdir() or member.isreg()):
                        raise RecoveryError("unsafe_archive_member")
                    size += member.size
                    if size > 4 * 1024 * 1024 * 1024:
                        raise RecoveryError("drill_archive_limit_exceeded")
            with plain.open("rb") as source, self.log.open("ab") as error:
                result = subprocess.run(self.helper("tar", "-C", "/target", "-xpf", "-", user="0:0",
                    mounts=("type=volume,src=" + volume + ",dst=/target",)), cwd=ROOT, env=self.env,
                    stdin=source, stdout=subprocess.DEVNULL, stderr=error, timeout=240, check=False)
            if result.returncode:
                raise RecoveryError("volume_restore_failed")
        finally:
            plain.unlink(missing_ok=True)

    def verify_ingress(self, role):
        port = self.config["ports"][role][1]
        origin = "https://localhost:" + str(port)
        context = ssl.create_default_context(cafile=str(self.private / "ca.pem"))
        cases = [
            ("wrong_host", {"Host": "untrusted.invalid", "Origin": origin}, 400),
            ("wrong_origin", {"Origin": "https://untrusted.invalid"}, 403),
            ("forged_forwarded_metadata", {"Origin": origin, "X-Forwarded-Host": "untrusted.invalid",
             "X-Forwarded-Proto": "http", "Forwarded": "host=untrusted.invalid;proto=http"}, 204),
        ]
        checks = []
        for name, headers, expected in cases:
            request = urllib.request.Request(origin + "/sanctum/csrf-cookie", headers=headers)
            try:
                response = urllib.request.urlopen(request, context=context, timeout=15)
            except urllib.error.HTTPError as failure:
                response = failure
            with response:
                status_code = response.status
                has_cookie = response.headers.get("Set-Cookie") is not None
                response.read(65536)
            if status_code != expected or (expected != 204 and has_cookie):
                raise RecoveryError("isolated_ingress_check_failed:" + name)
            checks.append(name)
        return checks

    def snapshot(self, role):
        data = self.run(self.compose(role, "run", "--rm", "--no-deps", "-T", "app", "php", "scripts/verify-restore-runtime.php"), timeout=180)
        value = json.loads(data)
        if value.get("ok") is not True:
            raise RecoveryError("restored_inventory_failed")
        value["constraints"] = self.canonical_constraints(role, value["constraints"])
        write_json(self.private / (role + "-inventory.json"), value)
        return value

    def canonical_constraints(self, role, constraints):
        # PostgreSQL's dump/reparse can distribute varchar[] casts and flatten
        # nested boolean expressions. Let the same server parser canonicalize
        # CHECKs on empty TEMP tables; never rewrite or ignore real constraints.
        relations = sorted({row["relation"] for row in constraints if row["definition"].startswith("CHECK (")})
        statements = ["BEGIN;", "SET LOCAL search_path = public, pg_catalog;"]
        for index, relation in enumerate(relations):
            if not re.fullmatch(r"[a-z_]+", relation):
                raise RecoveryError("constraint_relation_outside_public_schema")
            table = "holoul_restore_check_" + str(index)
            statements.append('CREATE TEMP TABLE "' + table + '" (LIKE public."' + relation + '") ON COMMIT DROP;')
            for row in constraints:
                if row["relation"] == relation and row["definition"].startswith("CHECK ("):
                    name = row["conname"]
                    if not re.fullmatch(r"[a-z0-9_]+", name):
                        raise RecoveryError("invalid_constraint_name")
                    statements.append('ALTER TABLE pg_temp."' + table + '" ADD CONSTRAINT "' + name + '" ' + row["definition"] + ';')
            statements.append("SELECT json_build_object('relation','" + relation + "','conname',conname,'definition',pg_get_constraintdef(oid))::text FROM pg_constraint WHERE conrelid='pg_temp.\"" + table + "\"'::regclass AND contype='c' ORDER BY conname;")
        statements.append("ROLLBACK;")
        output = self.run(["docker", "exec", "-i", self.container(role, "postgres"), "psql", "-X", "-qAt", "-v", "ON_ERROR_STOP=1", "-U", "postgres", "-d", "holoul"],
                          data=("\n".join(statements) + "\n").encode(), timeout=180)
        checks = [json.loads(line) for line in output.decode().splitlines() if line]
        original_keys = {(row["relation"], row["conname"]) for row in constraints if row["definition"].startswith("CHECK (")}
        if len(checks) != len(original_keys) or {(row["relation"], row["conname"]) for row in checks} != original_keys:
            raise RecoveryError("canonical_constraint_inventory_incomplete")
        original = {(row["relation"], row["conname"]): row for row in constraints}
        canonical = [dict(original[(row["relation"], row["conname"])], definition=row["definition"]) for row in checks]
        return sorted([row for row in constraints if not row["definition"].startswith("CHECK (")] + canonical,
                      key=lambda row: (row["relation"], row["conname"]))

    def manifest_authentication(self, manifest):
        content = json.dumps(manifest, sort_keys=True, separators=(",", ":")).encode()
        manifest_key = hmac.new(self.key.read_bytes(), b"holoul-b8-manifest-authentication-v1", hashlib.sha256).digest()
        return hmac.new(manifest_key, content, hashlib.sha256).hexdigest()

    def transaction_high_water(self, container):
        value = self.run(["docker", "exec", container, "psql", "-X", "-v", "ON_ERROR_STOP=1", "-U", "postgres", "-d", "holoul", "-Atc",
            "SELECT greatest(pg_current_xact_id()::text::numeric,coalesce((SELECT max(attachment_creation_xid::text::numeric) FROM request_revisions),0))::text"]).decode().strip()
        if not value.isdigit():
            raise RecoveryError("invalid_transaction_high_water")
        return int(value)

    def advance_recovered_transactions(self, high_water):
        container = self.container("target", "postgres")
        command = ["docker", "exec", "-i", container, "psql", "-X", "-v", "ON_ERROR_STOP=1", "-U", "postgres", "-d", "holoul", "-At"]
        current = int(self.run(command, data=b"SELECT pg_current_xact_id();\n").decode().strip())
        gap = max(0, high_water - current + 1)
        if gap > 100000:
            raise RecoveryError("logical_restore_transaction_gap_requires_physical_recovery")
        if gap:
            # Each SELECT is a separate committed top-level transaction. No
            # business writes, server control-file edits or pg_resetwal.
            self.run(command, data=b"SELECT pg_current_xact_id();\n" * gap, timeout=180)
        final = int(self.run(command, data=b"SELECT pg_current_xact_id();\n").decode().strip())
        if final <= high_water:
            raise RecoveryError("historical_transaction_ids_could_be_reused")
        result = {"source_high_water": high_water, "target_before": current, "target_after": final,
                  "harmless_transactions": gap, "historical_ids_cannot_recur": True}
        write_json(self.private / "transaction-guard.json", result)

    def backup(self):
        started = time.monotonic()
        try:
            self.assert_quiesced("source", ("postgres", "storage"))
            inventory = self.snapshot("source")
            self.run(self.compose("source", "stop", "--timeout", "60", "storage"))
            self.assert_quiesced("source", ("postgres",))
            self.bundle.mkdir(mode=0o700)
            high_water = self.transaction_high_water(self.container("source", "postgres"))
            archives = {"postgres": self.encrypted_stream(["docker", "exec", self.container("source", "postgres"),
                "pg_dump", "--username=postgres", "--dbname=holoul", "--format=custom", "--lock-wait-timeout=10s"], "postgres")}
            for volume in ARCHIVE_VOLUMES:
                archives[volume] = self.archive_volume("source", volume)
            manifest = {"schema": "holoul-synthetic-recovery-v1", "nonce": self.nonce, "completed_at": now(),
                        "app_image_id": self.config["app_image_id"], "inventory_sha256": sha(self.private / "source-inventory.json"),
                        "source_transaction_high_water": high_water, "archives": archives, "synthetic_only": True}
            manifest["authentication"] = self.manifest_authentication(manifest)
            write_json(self.bundle / "manifest.json", manifest)
            status(self.private / "backup-status.json", "succeeded", started)
            return inventory
        except BaseException:
            status(self.private / "backup-status.json", "failed", started)
            raise

    def restore(self):
        started = time.monotonic()
        try:
            manifest = json.loads((self.bundle / "manifest.json").read_text())
            authentication = manifest.pop("authentication", "")
            if not isinstance(authentication, str) or not hmac.compare_digest(authentication, self.manifest_authentication(manifest)):
                raise RecoveryError("backup_manifest_authentication_failed")
            if manifest["nonce"] != self.nonce or manifest["app_image_id"] != self.config["app_image_id"] or not manifest["synthetic_only"]:
                raise RecoveryError("backup_manifest_identity_mismatch")
            if set(manifest["archives"]) != set(ARCHIVE_VOLUMES) | {"postgres"}:
                raise RecoveryError("backup_manifest_incomplete")
            self.validate_plan("target")
            self.assert_quiesced("target")
            self.run(self.compose("target", "create", "--no-build", "initialize", "postgres", "storage"), timeout=90)
            self.assert_quiesced("target")
            for volume in ARCHIVE_VOLUMES:
                self.restore_volume(volume, manifest["archives"][volume])
            self.run(self.compose("target", "up", "-d", "--no-build", "--no-deps", "--wait", "--wait-timeout", "180", "postgres", "storage"), timeout=210)
            postgres = self.container("target", "postgres")
            empty = self.run(["docker", "exec", postgres, "psql", "-U", "postgres", "-d", "holoul", "-Atc",
                              "SELECT count(*) FROM pg_tables WHERE schemaname='public'"]).decode().strip()
            if empty != "0":
                raise RecoveryError("restore_database_must_be_empty")
            plain = self.decrypt("postgres", manifest["archives"]["postgres"])
            try:
                with plain.open("rb") as source, self.log.open("ab") as error:
                    result = subprocess.run(["docker", "exec", "-i", postgres, "pg_restore", "-U", "postgres", "-d", "holoul",
                        "--clean", "--if-exists", "--exit-on-error", "--single-transaction"], cwd=ROOT, env=self.env,
                        stdin=source, stdout=subprocess.DEVNULL, stderr=error, timeout=180, check=False)
                if result.returncode:
                    raise RecoveryError("database_restore_failed")
            finally:
                plain.unlink(missing_ok=True)
            self.advance_recovered_transactions(manifest["source_transaction_high_water"])
            self.assert_quiesced("target", ("postgres", "storage"))
            inventory = self.snapshot("target")
            original = json.loads((self.private / "source-inventory.json").read_text())
            if sha(self.private / "source-inventory.json") != manifest["inventory_sha256"] or inventory != original:
                raise RecoveryError("restored_rows_versions_keys_or_guards_differ")
            status(self.private / "restore-status.json", "succeeded", started)
            return inventory
        except BaseException:
            status(self.private / "restore-status.json", "failed", started)
            raise

    def cleanup(self):
        # Compose labels AND exact generated namespace are checked before any
        # destructive cleanup. No pruning, wildcard deletion or shared volumes.
        ownership = self.private / "owned-projects.json"
        if not ownership.exists():
            # Validation failed before this invocation acquired either empty
            # namespace. Never clean a collision or use an unfrozen Compose file.
            self.cleanup_images()
            return
        if ownership.is_symlink() or json.loads(ownership.read_text()) != {
            role: self.project(role) for role in ("source", "target")
        }:
            raise RecoveryError("cleanup_project_ownership_missing")
        for role in ("source", "target"):
            frozen = self.private / (role + "-compose.json")
            if not frozen.is_file() or frozen.is_symlink():
                raise RecoveryError("cleanup_requires_frozen_plan")
            plan = json.loads(frozen.read_text())
            if plan.get("name") != self.project(role):
                raise RecoveryError("cleanup_plan_project_mismatch")
            for kind in ("volumes", "networks"):
                for logical, definition in plan.get(kind, {}).items():
                    if definition.get("external") or definition.get("name") != self.project(role) + "_" + logical:
                        raise RecoveryError("cleanup_plan_resource_outside_namespace")
        for role in ("source", "target"):
            project = self.project(role)
            volumes = self.run(["docker", "volume", "ls", "-q", "--filter", "label=com.docker.compose.project=" + project]).decode().splitlines()
            networks = self.run(["docker", "network", "ls", "-q", "--filter", "label=com.docker.compose.project=" + project]).decode().splitlines()
            for volume in volumes:
                if not volume.startswith(project + "_"):
                    raise RecoveryError("cleanup_volume_outside_namespace")
            for network in networks:
                name = self.run(["docker", "network", "inspect", "--format", "{{.Name}}", network]).decode().strip()
                if not name.startswith(project + "_"):
                    raise RecoveryError("cleanup_network_outside_namespace")
            self.run(self.compose(role, "down", "--volumes", "--remove-orphans", "--timeout", "150"), timeout=240)
        self.cleanup_images()

    def cleanup_images(self):
        path = self.private / "owned-images.json"
        if not path.exists():
            return
        if path.is_symlink():
            raise RecoveryError("cleanup_image_ownership_invalid")
        for image_id, tag in json.loads(path.read_text()).items():
            if not re.fullmatch(r"sha256:[a-f0-9]{64}", image_id) or tag != "holoul-b8-restore-" + self.nonce + ":" + image_id.removeprefix("sha256:"):
                raise RecoveryError("cleanup_image_outside_namespace")
            if not self.run(["docker", "image", "ls", "-q", tag]).strip():
                continue
            actual = self.run(["docker", "image", "inspect", "--format", "{{.Id}}", tag]).decode().strip()
            if actual != image_id:
                raise RecoveryError("cleanup_image_identity_changed")
            # Remove only this tag, without --force, prune, or a shared image ID.
            self.run(["docker", "image", "rm", tag])
