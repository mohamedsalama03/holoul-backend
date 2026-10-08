"""Root on the NON-production Ubuntu build host only; synthetic PKI and disposable Nginx."""
import contextlib
from dataclasses import replace
import datetime as dt
import importlib.util
import io
import json
import multiprocessing
import os
from pathlib import Path
import shutil
import signal
import socket
import ssl
import stat
import subprocess
import sys
import tempfile
import threading
import time
import unittest
from unittest import mock
import uuid

HERE = Path(__file__).resolve().parent
REPO = HERE.parents[1]
spec = importlib.util.spec_from_file_location("renew", HERE / "renew.py")
r = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = r
spec.loader.exec_module(r)
IMAGE = "sha256:893488317a0b87ecb34a07b8255c484e978b2bb640aec2f95555686ec0cb1185"


def quiet(args):
    return r.command(list(map(str, args)))


class PKI:
    def __init__(self, root):
        self.root = root
        root.mkdir()
        self.key(root / "root.key")
        quiet(["openssl", "req", "-new", "-x509", "-key", root / "root.key", "-out", root / "root.pem",
               "-days", "90", "-subj", "/CN=HOLOUL Synthetic Test Root", "-addext", "basicConstraints=critical,CA:TRUE",
               "-addext", "keyUsage=critical,keyCertSign,cRLSign", "-addext", "subjectKeyIdentifier=hash"])
        self.key(root / "inter.key")
        quiet(["openssl", "req", "-new", "-key", root / "inter.key", "-out", root / "inter.csr",
               "-subj", "/CN=HOLOUL Synthetic Intermediate"])
        (root / "inter.ext").write_text("basicConstraints=critical,CA:TRUE,pathlen:0\nkeyUsage=critical,keyCertSign,cRLSign\n"
                                        "subjectKeyIdentifier=hash\nauthorityKeyIdentifier=keyid,issuer\n")
        quiet(["openssl", "x509", "-req", "-in", root / "inter.csr", "-CA", root / "root.pem", "-CAkey", root / "root.key",
               "-set_serial", "2", "-days", "60", "-extfile", root / "inter.ext", "-out", root / "inter.pem"])
        (root / "index").write_text("")
        (root / "serial").write_text("1000\n")
        (root / "issued").mkdir()

    def key(self, path):
        quiet(["openssl", "genpkey", "-algorithm", "EC", "-pkeyopt", "ec_paramgen_curve:P-256", "-out", path])
        path.chmod(0o600)

    def issue(self, name, sans="DNS:holoul.com.ly,DNS:www.holoul.com.ly", offset=-1, end=30):
        folder = self.root / name
        folder.mkdir()
        self.key(folder / "key.pem")
        quiet(["openssl", "req", "-new", "-key", folder / "key.pem", "-out", folder / "request.csr", "-subj", "/CN=holoul.com.ly"])
        config = ("[ca]\ndefault_ca=issuer\n[issuer]\n"
                  f"database={self.root}/index\nserial={self.root}/serial\nnew_certs_dir={self.root}/issued\n"
                  f"certificate={self.root}/inter.pem\nprivate_key={self.root}/inter.key\n"
                  "default_md=sha256\npolicy=policy\nunique_subject=no\nx509_extensions=leaf\n"
                  "[policy]\ncommonName=supplied\n[leaf]\nbasicConstraints=critical,CA:FALSE\n"
                  "keyUsage=critical,digitalSignature\nextendedKeyUsage=serverAuth\n"
                  "subjectKeyIdentifier=hash\nauthorityKeyIdentifier=keyid,issuer\nsubjectAltName=" + sans + "\n")
        (folder / "ca.cnf").write_text(config)
        now = dt.datetime.now(dt.timezone.utc)
        quiet(["openssl", "ca", "-batch", "-notext", "-config", folder / "ca.cnf", "-in", folder / "request.csr",
               "-out", folder / "leaf.pem", "-startdate", (now + dt.timedelta(days=offset)).strftime("%Y%m%d%H%M%SZ"),
               "-enddate", (now + dt.timedelta(days=end)).strftime("%Y%m%d%H%M%SZ")])
        chain = (folder / "leaf.pem").read_bytes() + (self.root / "inter.pem").read_bytes()
        return chain, (folder / "key.pem").read_bytes()


class Audit:
    def __init__(self):
        self.args = []
        self.outputs = []
        self.fault = None

    def __call__(self, args, data=None):
        self.args.append(list(args))
        if self.fault:
            substitute = self.fault(args)
            if substitute is not None:
                return substitute
        result = r.command(args, data)
        self.outputs.append(result)
        return result

    def reloads(self):
        return sum(args[-3:] == ["/usr/sbin/nginx", "-s", "reload"] for args in self.args)


class RenewalTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        if os.geteuid() != 0:
            raise RuntimeError("Run only on the non-production local test host as root")
        os.umask(0o077)
        r.resource.setrlimit(r.resource.RLIMIT_CORE, (0, 0))
        cls.base = Path(tempfile.mkdtemp(prefix="holoul-tls-tests-", dir="/root"))
        cls.pki = PKI(cls.base / "pki")
        cls.initial = cls.pki.issue("initial")
        cls.renewal = cls.pki.issue("renewal")
        cls.wrong_san = cls.pki.issue("wrong-san", "DNS:other.example.test,DNS:www.holoul.com.ly")
        cls.expired = cls.pki.issue("expired", offset=-10, end=-2)
        cls.future = cls.pki.issue("future", offset=2, end=30)
        cls.near_expiry = cls.pki.issue("near-expiry", offset=-1, end=0.5)
        cls.extra_san = cls.pki.issue("extra-san", "DNS:holoul.com.ly,DNS:www.holoul.com.ly,DNS:extra.example.test")
        info = json.loads(quiet(["docker", "image", "inspect", IMAGE]))[0]
        if info["Id"] != IMAGE or info["Config"]["User"] != "101:101":
            raise RuntimeError("Reviewed local Nginx image required")
        cls.version = quiet(["docker", "run", "--rm", "--pull", "never", "--network", "none", "--entrypoint", "/bin/sh",
                             IMAGE, "-c", "nginx -v 2>&1"]).decode().strip()
        if cls.version != "nginx version: nginx/1.30.5":
            raise RuntimeError("Unexpected Nginx version")

    @classmethod
    def tearDownClass(cls):
        shutil.rmtree(cls.base)

    def setUp(self):
        self.root = self.base / uuid.uuid4().hex
        self.root.mkdir()
        self.edge = self.root / "edge-tls"
        self.state = self.root / "state"
        self.lineage = self.root / "live"
        self.archive = self.root / "archive"
        for path in (self.edge, self.edge / "versions", self.state, self.lineage, self.archive):
            path.mkdir(mode=0o700)
        for path in (self.edge, self.edge / "versions"):
            os.chown(path, 0, 101)
            path.chmod(0o750)
        self.set_source(self.renewal)
        r.write_file(self.edge / "fullchain.pem", self.initial[0], 101, 0o440)
        r.write_file(self.edge / "privkey.pem", self.initial[1], 101, 0o440)
        self.config = self.root / "nginx.conf"
        self.config.write_bytes((REPO / "deploy/production/acme-bootstrap.conf").read_bytes())
        self.config.chmod(0o644)
        self.webroot = self.root / "webroot"
        challenge = self.webroot / ".well-known/acme-challenge"
        challenge.mkdir(parents=True)
        for directory in (self.webroot, self.webroot / ".well-known", challenge):
            directory.chmod(0o755)
        (challenge / "proof").write_text("synthetic-http01-proof")
        (challenge / "proof").chmod(0o644)
        self.name = "holoul-tls-test-" + uuid.uuid4().hex
        self.audit = Audit()
        self.started = False
        args = ["docker", "run", "-d", "--pull", "never", "--name", self.name,
                "--label", "com.docker.compose.project=" + self.name,
                "--label", "com.docker.compose.service=ingress", "--user", "101:101", "--read-only", "--init",
                "--cap-drop", "ALL", "--security-opt", "no-new-privileges:true", "--pids-limit", "64",
                "--memory", "128m", "--memory-swap", "128m", "--cpus", "1",
                "--tmpfs", "/tmp:size=32m,uid=101,gid=101,mode=0700", "--sysctl", "net.ipv4.ip_unprivileged_port_start=0",
                "--log-opt", "max-size=1m", "--log-opt", "max-file=1",
                "-p", "127.0.0.1::8080", "-p", "127.0.0.1::443", "--entrypoint", "nginx"]
        for src, dest in [(self.config, "/etc/nginx/nginx.conf"), (self.edge, "/run/holoul-tls"),
                          (self.webroot, "/var/www/acme"),
                          (REPO / "deploy/production/fastcgi.conf", "/etc/nginx/holoul-fastcgi.conf"),
                          (REPO / "deploy/production/proxy.conf", "/etc/nginx/holoul-proxy.conf")]:
            args += ["--mount", f"type=bind,src={src},dst={dest},readonly"]
        quiet(args + [IMAGE, "-g", "daemon off;"])
        self.started = True
        self.addCleanup(self.cleanup)
        ports = self.wait_ports()
        self.s = r.Settings(lineage=self.lineage, archive=self.archive, edge=self.edge, state=self.state,
                            lock=self.root / "hook.lock", ca=self.pki.root / "root.pem", container=self.name, project=self.name,
                            http_port=int(ports["8080/tcp"][0]["HostPort"]), https_port=int(ports["443/tcp"][0]["HostPort"]),
                            wait_seconds=20)
        self.hook = r.Hook(self.s, self.audit)
        self.wait_mode("bootstrap")
        self.assertEqual(self.hook.process("initialize"), "INITIALIZED_BOOTSTRAP_NO_RELOAD")
        self.old = self.hook.pointer()
        self.old_fingerprint = self.hook.version(self.old)

    def cleanup(self):
        if self.started:
            if not self.name.startswith("holoul-tls-test-"):
                raise RuntimeError("Unsafe test cleanup target")
            quiet(["docker", "rm", "-f", self.name])
        shutil.rmtree(self.root)

    def set_source(self, pair):
        for name, data in zip(("fullchain.pem", "privkey.pem"), pair):
            target = self.archive / (uuid.uuid4().hex + ".pem")
            r.write_file(target, data)
            link = self.lineage / name
            link.unlink(missing_ok=True)
            link.symlink_to(target)

    def wait_mode(self, mode):
        last = None
        for _ in range(80):
            try:
                self.assertEqual(self.hook.mode(self.hook.inspect()), mode)
                return
            except (r.Blocked, OSError, AssertionError) as error:
                last = str(error)
                time.sleep(0.1)
        result = subprocess.run(["docker", "logs", self.name], capture_output=True)
        log = result.stdout + result.stderr
        if b"PRIVATE KEY" in log:
            self.fail("PRIVATE_OUTPUT_LEAK")
        self.fail("Disposable ingress did not become ready: " + str(last) + " " + log.decode()[-1200:])

    def wait_ports(self):
        # Docker may briefly return empty bindings after a disposable restart.
        deadline = time.monotonic() + 20
        while time.monotonic() < deadline:
            ports = json.loads(quiet(["docker", "inspect", "--format", "{{json .NetworkSettings.Ports}}", self.name]))
            if all(ports.get(port) for port in ("8080/tcp", "443/tcp")):
                return ports
            time.sleep(0.1)
        self.fail("Disposable ingress port bindings did not become ready")

    def production(self, legacy=False):
        source = REPO / "deploy/production/nginx.conf" if legacy else HERE / "nginx.versioned.conf"
        self.config.write_bytes(source.read_bytes())
        quiet(["docker", "restart", self.name])  # Only this synthetic disposable container.
        ports = self.wait_ports()
        self.s = replace(self.s, http_port=int(ports["8080/tcp"][0]["HostPort"]),
                         https_port=int(ports["443/tcp"][0]["HostPort"]))
        self.hook = r.Hook(self.s, self.audit)
        if not legacy:
            self.wait_mode("production")
            self.hook.served(self.old_fingerprint)

    def challenge(self):
        for domain in r.DOMAINS:
            connection = r.http.client.HTTPConnection("127.0.0.1", self.s.http_port, timeout=3)
            try:
                connection.request("GET", "/.well-known/acme-challenge/proof", headers={"Host": domain})
                response = connection.getresponse()
                self.assertEqual(response.status, 200)
                self.assertEqual(response.read(), b"synthetic-http01-proof")
            finally:
                connection.close()

    def unchanged(self):
        self.assertEqual(self.hook.pointer(), self.old)
        self.assertEqual(self.hook.json_read("verified.json")["active"], self.old)

    def test_bootstrap_stages_without_any_nginx_test_or_reload(self):
        self.challenge()
        self.assertEqual(self.hook.process(), "STAGED_BOOTSTRAP_NO_RELOAD")
        self.assertEqual(self.audit.reloads(), 0)
        self.assertFalse(any("-t" in args for args in self.audit.args))
        self.assertEqual(self.hook.json_read("verified.json")["previous"], self.old)
        self.challenge()

    def test_production_valid_renewal_and_permissions(self):
        self.production()
        self.challenge()
        before = self.hook.workers(self.hook.inspect())
        self.assertEqual(self.hook.process(), "ACTIVATED_AND_VERIFIED")
        self.assertEqual(self.audit.reloads(), 1)
        self.assertTrue(self.hook.workers(self.hook.inspect()) - before)
        self.hook.served(self.hook.version(self.hook.pointer()))
        for path in (self.edge / "versions" / self.hook.pointer()).iterdir():
            info = path.stat()
            self.assertEqual((info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode)), (0, 101, 0o440))
        self.assertEqual((self.state / "verified.json").stat().st_mode & 0o777, 0o600)
        self.assertEqual(self.hook.json_read("verified.json")["previous"], self.old)
        self.challenge()

    def test_repeated_invocation_no_extra_reload(self):
        self.production()
        self.hook.process()
        self.assertEqual(self.hook.process(), "UNCHANGED_NO_RELOAD")
        self.assertEqual(self.audit.reloads(), 1)

    def test_incorrect_san(self):
        self.set_source(self.wrong_san)
        with self.assertRaisesRegex(r.Blocked, "SAN_MISMATCH"):
            self.hook.process()
        self.unchanged()

    def test_extra_san(self):
        self.set_source(self.extra_san)
        with self.assertRaisesRegex(r.Blocked, "SAN_MISMATCH"):
            self.hook.process()
        self.unchanged()

    def test_mismatched_private_key(self):
        self.set_source((self.renewal[0], self.initial[1]))
        with self.assertRaisesRegex(r.Blocked, "KEY_MISMATCH"):
            self.hook.process()
        self.unchanged()

    def test_expired_certificate(self):
        self.set_source(self.expired)
        with self.assertRaises(r.Blocked):
            self.hook.process()
        self.unchanged()

    def test_not_yet_valid_certificate(self):
        self.set_source(self.future)
        with self.assertRaises(r.Blocked):
            self.hook.process()
        self.unchanged()

    def test_malformed_pem(self):
        self.set_source((b"malformed synthetic data", self.renewal[1]))
        with self.assertRaisesRegex(r.Blocked, "MALFORMED_CHAIN"):
            self.hook.process()
        self.unchanged()

    def test_untrusted_chain(self):
        other = PKI(self.root / "other-pki").issue("leaf")
        self.set_source(other)
        with self.assertRaises(r.Blocked):
            self.hook.process()
        self.unchanged()

    def test_incomplete_chain(self):
        self.set_source((r.CERT.findall(self.renewal[0])[0] + b"\n", self.renewal[1]))
        with self.assertRaisesRegex(r.Blocked, "MALFORMED_CHAIN"):
            self.hook.process()
        self.unchanged()

    def test_concurrent_invocation_and_flock_interoperability(self):
        with r.locked(self.s.lock):
            with self.assertRaisesRegex(r.Blocked, "BUSY"):
                self.hook.process()
            result = subprocess.run(["/usr/bin/flock", "-n", str(self.s.lock), "/usr/bin/true"], capture_output=True)
            self.assertNotEqual(result.returncode, 0)
        self.unchanged()

    def killed(self, point):
        parent = self.hook
        class KilledHook(r.Hook):
            def switch(child, version):
                super().switch(version)
                if point == "switched":
                    os.kill(os.getpid(), signal.SIGKILL)
            def json_write(child, name, data):
                if point == "reloaded" and name == "verified.json" and data["active"] != self.old:
                    os.kill(os.getpid(), signal.SIGKILL)
                super().json_write(name, data)
            def clear_journal(child):
                if point == "committed":
                    os.kill(os.getpid(), signal.SIGKILL)
                super().clear_journal()
        process = multiprocessing.get_context("fork").Process(target=lambda: KilledHook(parent.s).process())
        process.start()
        process.join(120)
        if process.is_alive():
            process.kill()
            process.join(10)
            self.fail("Synthetic interruption child exceeded its deadline")
        self.assertEqual(process.exitcode, -signal.SIGKILL)
        self.assertTrue((self.state / "transaction.json").exists())
        self.assertEqual(self.hook.process("recover"), "RECOVERED_PREVIOUS_VERSION")
        self.unchanged()
        self.hook.served(self.old_fingerprint)
        self.assertFalse((self.state / "transaction.json").exists())

    def test_sigkill_after_atomic_switch(self):
        self.production()
        self.killed("switched")

    def test_sigkill_after_reload_before_commit(self):
        self.production()
        self.killed("reloaded")

    def test_sigkill_after_state_commit(self):
        self.production()
        self.killed("committed")

    def test_partial_stage_never_active(self):
        real = r.write_file
        def fail(path, *args, **kwargs):
            if path.parent.name.startswith(".pending-") and path.name == "privkey.pem":
                raise OSError("synthetic write failure")
            return real(path, *args, **kwargs)
        with mock.patch.object(r, "write_file", fail):
            with self.assertRaises(OSError):
                self.hook.process()
        self.unchanged()
        self.assertFalse(list((self.edge / "versions").glob(".pending-*")))

    def test_manual_rollback_is_idempotent(self):
        self.production()
        self.hook.process()
        self.hook.process("rollback")
        self.unchanged()
        self.hook.served(self.old_fingerprint)
        reloads = self.audit.reloads()
        self.assertEqual(self.hook.process("rollback"), "UNCHANGED_NO_RELOAD")
        self.assertEqual(self.audit.reloads(), reloads)

    def test_nginx_real_configuration_failure_rolls_back(self):
        self.production()
        corrupted = False
        def fault(args):
            nonlocal corrupted
            if args[-2:] == ["/usr/sbin/nginx", "-t"] and not corrupted:
                corrupted = True
                path = self.edge / "versions" / self.hook.pointer() / "nginx-tls.conf"
                path.write_bytes(path.read_bytes() + b"synthetic_invalid_directive on;\n")
        self.audit.fault = fault
        with self.assertRaisesRegex(r.Blocked, "ACTIVATION_FAILED_ROLLED_BACK"):
            self.hook.process()
        self.unchanged()
        self.hook.served(self.old_fingerprint)
        self.challenge()

    def test_nginx_real_reload_command_failure_rolls_back(self):
        self.production()
        failed = False
        def fault(args):
            nonlocal failed
            if args[-3:] == ["/usr/sbin/nginx", "-s", "reload"] and not failed:
                failed = True
                return r.command([*args[:-1], "synthetic-invalid-signal"])
        self.audit.fault = fault
        with self.assertRaisesRegex(r.Blocked, "ACTIVATION_FAILED_ROLLED_BACK"):
            self.hook.process()
        self.unchanged()
        self.hook.served(self.old_fingerprint)

    def test_reload_exit_zero_without_activation_is_not_success(self):
        self.production()
        calls = 0
        def fault(args):
            nonlocal calls
            if args[-3:] == ["/usr/sbin/nginx", "-s", "reload"]:
                calls += 1
                if calls == 1:
                    return b""
        self.audit.fault = fault
        with self.assertRaisesRegex(r.Blocked, "ACTIVATION_FAILED_ROLLED_BACK"):
            self.hook.process()
        self.unchanged()

    def test_rollback_failure_leaves_recovery_journal_and_blocks(self):
        self.production()
        def fault(args):
            if args[-3:] == ["/usr/sbin/nginx", "-s", "reload"]:
                raise r.Blocked("SYNTHETIC_RELOAD_FAILURE")
        self.audit.fault = fault
        with self.assertRaisesRegex(r.Blocked, "ACTIVATION_FAILED_RECOVERY_BLOCKED"):
            self.hook.process()
        self.assertTrue((self.state / "transaction.json").exists())
        self.assertEqual(self.hook.pointer(), self.old)
        self.audit.fault = None
        self.assertEqual(self.hook.process("recover"), "RECOVERED_PREVIOUS_VERSION")

    def test_final_file_while_bootstrap_is_live_is_blocked(self):
        self.config.write_bytes((HERE / "nginx.versioned.conf").read_bytes())
        with self.assertRaisesRegex(r.Blocked, "LOADED_CONFIG_MISMATCH"):
            self.hook.process()
        self.assertEqual(self.audit.reloads(), 0)
        self.unchanged()

    def test_legacy_production_is_explicitly_blocked(self):
        self.production(legacy=True)
        with self.assertRaisesRegex(r.Blocked, "LEGACY_PRODUCTION_REQUIRES_VERSIONED_CONFIG"):
            self.hook.process()
        self.assertEqual(self.audit.reloads(), 0)

    def test_unknown_configuration_is_blocked(self):
        self.config.write_bytes(self.config.read_bytes() + b"# unreviewed change\n")
        with self.assertRaisesRegex(r.Blocked, "UNREVIEWED_NGINX_CONFIG"):
            self.hook.process()
        self.unchanged()

    def test_lineage_path_escape_is_blocked(self):
        source = self.lineage / "privkey.pem"
        source.unlink()
        source.symlink_to(self.edge / "privkey.pem")
        with self.assertRaisesRegex(r.Blocked, "LINEAGE_ESCAPE"):
            self.hook.process()
        self.unchanged()

    def test_insecure_private_key_mode_is_blocked(self):
        (self.lineage / "privkey.pem").resolve().chmod(0o644)
        with self.assertRaisesRegex(r.Blocked, "PRIVATE_KEY_PERMISSIONS"):
            self.hook.process()
        self.unchanged()

    def test_initialization_repeated_and_interrupted(self):
        self.assertEqual(self.hook.process("initialize"), "ALREADY_INITIALIZED_NO_RELOAD")
        (self.state / "verified.json").unlink()
        self.assertEqual(self.hook.process("initialize"), "INITIALIZATION_RECOVERED_NO_RELOAD")
        self.unchanged()

    def test_private_key_absent_from_output_arguments_logs_and_metadata(self):
        self.production()
        self.hook.process()
        records = b"\n".join(self.audit.outputs)
        records += json.dumps(self.audit.args).encode()
        logs = subprocess.run(["docker", "logs", self.name], capture_output=True, check=True)
        records += logs.stdout + logs.stderr
        for path in self.state.glob("*.json"):
            records += path.read_bytes()
        for version in (self.edge / "versions").iterdir():
            records += (version / "nginx-tls.conf").read_bytes()
        marker = b"-----BEGIN " + b"PRIVATE KEY-----"
        self.assertTrue(marker not in records, "Private key marker in output")
        for key in (self.initial[1], self.renewal[1]):
            self.assertTrue(key not in records and key.splitlines()[1] not in records, "Private key material in output")
        self.assertFalse(any(args[1:3] in (["restart", self.name], ["stop", self.name]) for args in self.audit.args))
        self.assertEqual(r.resource.getrlimit(r.resource.RLIMIT_CORE), (0, 0))

    def test_near_expiry_candidate_is_blocked(self):
        self.set_source(self.near_expiry)
        with self.assertRaises(r.Blocked):
            self.hook.process()
        self.unchanged()

    def test_symlink_lock_cannot_touch_private_key(self):
        target = self.edge / "privkey.pem"
        before = target.read_bytes()
        self.s.lock.unlink()
        self.s.lock.symlink_to(target)
        with self.assertRaises(OSError):
            self.hook.process()
        self.assertTrue(target.read_bytes() == before, "Lock touched private key")
        self.unchanged()

    def test_http01_continuously_available_during_renewal(self):
        self.production()
        stopped = threading.Event()
        failures = []
        successes = []
        def probe():
            while not stopped.is_set():
                try:
                    self.challenge()
                    successes.append(True)
                except Exception:
                    failures.append(True)
                stopped.wait(0.1)
        worker = threading.Thread(target=probe)
        worker.start()
        try:
            self.hook.process()
        finally:
            stopped.set()
            worker.join(10)
        self.assertFalse(worker.is_alive())
        self.assertTrue(len(successes) >= 2)
        self.assertFalse(failures)

    def test_sigterm_after_switch_rolls_back_immediately(self):
        self.production()
        target = r.digest(self.renewal[0])
        class InterruptedHook(r.Hook):
            def switch(child, version):
                super().switch(version)
                if version == target:
                    os.kill(os.getpid(), signal.SIGTERM)
        previous = signal.signal(signal.SIGTERM, r.interrupted)
        try:
            with self.assertRaisesRegex(r.Blocked, "ACTIVATION_FAILED_ROLLED_BACK_INTERRUPTED"):
                InterruptedHook(self.s, self.audit).process()
        finally:
            signal.signal(signal.SIGTERM, previous)
        self.unchanged()
        self.hook.served(self.old_fingerprint)

    def test_same_leaf_chain_change_still_requires_new_worker(self):
        self.production()
        self.set_source((self.initial[0] + (self.pki.root / "root.pem").read_bytes(), self.initial[1]))
        skipped = False
        def fault(args):
            nonlocal skipped
            if args[-3:] == ["/usr/sbin/nginx", "-s", "reload"] and not skipped:
                skipped = True
                return b""
        self.audit.fault = fault
        with self.assertRaisesRegex(r.Blocked, "ACTIVATION_FAILED_ROLLED_BACK_RELOAD_NOT_CONFIRMED"):
            self.hook.process()
        self.unchanged()

    def test_unknown_tls_name_remains_rejected(self):
        self.production()
        self.hook.process()
        # Disable client validation ONLY to prove the SERVER rejects unknown SNI,
        # rather than getting a false pass from client-side hostname validation.
        context = ssl.SSLContext(ssl.PROTOCOL_TLS_CLIENT)
        context.check_hostname = False
        context.verify_mode = ssl.CERT_NONE
        with socket.create_connection(("127.0.0.1", self.s.https_port), timeout=3) as connection:
            with self.assertRaisesRegex(ssl.SSLError, "UNRECOGNIZED_NAME"):
                context.wrap_socket(connection, server_hostname="unrelated.example.test")

    def test_restarted_ingress_blocks_pending_recovery(self):
        self.production()
        instance = self.hook.inspect()
        target = self.hook.stage(*self.renewal)
        self.hook.json_write("transaction.json", {"before": self.hook.json_read("verified.json"), "new": target,
                                                  "mode": "production", "container_id": instance["id"],
                                                  "started": instance["started"]})
        self.hook.switch(target)
        quiet(["docker", "restart", self.name])
        ports = self.wait_ports()
        self.s = replace(self.s, http_port=int(ports["8080/tcp"][0]["HostPort"]),
                         https_port=int(ports["443/tcp"][0]["HostPort"]))
        self.hook = r.Hook(self.s, self.audit)
        self.wait_mode("production")
        with self.assertRaisesRegex(r.Blocked, "INTERRUPTED_TRANSACTION_RECOVERY_BLOCKED"):
            self.hook.process("recover")
        self.assertTrue((self.state / "transaction.json").exists())
        self.assertEqual(self.audit.reloads(), 0)


class EntrypointTests(unittest.TestCase):
    def test_failed_command_output_is_never_in_error_or_logs(self):
        output, errors = io.StringIO(), io.StringIO()
        result = subprocess.CompletedProcess(["synthetic"], 1, b"synthetic-secret-out", b"synthetic-secret-err")
        with mock.patch.object(subprocess, "run", return_value=result), contextlib.redirect_stdout(output), \
             contextlib.redirect_stderr(errors):
            with self.assertRaisesRegex(r.Blocked, "^COMMAND_FAILED$"):
                r.command(["synthetic"])
        self.assertEqual(output.getvalue() + errors.getvalue(), "")

    def test_other_lineage_has_no_side_effects_or_value_logging(self):
        output = io.StringIO()
        with mock.patch.dict(os.environ, {"RENEWED_LINEAGE": "/unrelated/private-value"}, clear=True), \
             mock.patch.object(sys, "argv", ["renew.py"]), mock.patch.object(r, "Hook") as hook, \
             contextlib.redirect_stdout(output):
            self.assertEqual(r.main(), 0)
            hook.assert_not_called()
        self.assertEqual(output.getvalue(), "HOLOUL_TLS SKIPPED_OTHER_LINEAGE\n")

    def test_wrong_domains_have_no_side_effects(self):
        with mock.patch.dict(os.environ, {"RENEWED_LINEAGE": str(r.Settings.lineage), "RENEWED_DOMAINS": "other"}, clear=True), \
             mock.patch.object(sys, "argv", ["renew.py"]), mock.patch.object(r, "Hook") as hook, \
             contextlib.redirect_stderr(io.StringIO()):
            self.assertEqual(r.main(), 1)
            hook.assert_not_called()

    def test_configuration_delta_is_only_one_atomic_include(self):
        original = (REPO / "deploy/production/nginx.conf").read_text()
        expected = original.replace("http {\n", "http {\n    include /run/holoul-tls/current/nginx-tls.conf;\n")
        expected = expected.replace("        ssl_certificate /run/holoul-tls/fullchain.pem;\n"
                                    "        ssl_certificate_key /run/holoul-tls/privkey.pem;\n", "")
        actual = (HERE / "nginx.versioned.conf").read_text()
        self.assertEqual(expected, actual)
        self.assertEqual(r.digest(actual.encode()), r.FINAL)
        self.assertEqual(r.digest(original.encode()), r.LEGACY)


if __name__ == "__main__":
    unittest.main(verbosity=2)
