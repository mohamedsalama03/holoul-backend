#!/usr/bin/env python3
"""Read-only topology checks. Never starts containers or authorizes deployment."""
import argparse
import hashlib
import json
import pathlib
import re
import subprocess

ROOT = pathlib.Path(__file__).resolve().parents[2]
CONTRACT = "3c93a09f08ce199cc1c2867ad846a1ca554fd03b6d934070178c5101646df410"
RUNTIME = {"app", "queue", "document-queue", "ai-queue", "notification-queue",
           "scheduler", "postgres", "redis", "storage", "portfolio-processor",
           "website", "dashboard", "ingress"}
PHP = {"app", "queue", "document-queue", "ai-queue", "notification-queue",
       "scheduler", "migrate", "storage-bootstrap"}

def check(plan):
    services = plan["services"]
    assert set(services) == RUNTIME | {"migrate", "storage-bootstrap"}, "unexpected/missing service"
    total = 0
    for name, service in services.items():
        assert not service.get("build") and not service.get("profiles"), "runtime images only"
        assert re.search(r"(?:@|^)sha256:[a-f0-9]{64}$", service["image"]), "immutable image required"
        assert int(service["mem_limit"]) > 0 and service["mem_limit"] == service["memswap_limit"], "no application swap"
        assert float(service["cpus"]) > 0 and service["pids_limit"] > 0, "process limits required"
        assert service.get("healthcheck"), "health policy required"
        assert service["logging"]["options"] == {"max-size": "5m", "max-file": "2"}, "bounded logs"
        if name in RUNTIME:
            total += int(service["mem_limit"])
            assert service["restart"] == "unless-stopped"
        else:
            assert service["restart"] == "no"
        assert not service.get("privileged") and service.get("network_mode") != "host"
        if name != "ingress":
            assert not service.get("ports"), "private upstream published"
        for volume in service.get("volumes", []):
            assert volume["type"] == "bind" and not volume["bind"]["create_host_path"]
            assert "docker.sock" not in volume["source"]
        if name in PHP:
            env = service["environment"]
            for key, value in {"APP_ENV": "production", "APP_DEBUG": "false",
                               "HOLOUL_DEPLOYMENT_PROFILE": "production",
                               "HOLOUL_DOCUMENT_UPLOADS_ENABLED": "false",
                               "HOLOUL_AI_ENABLED": "false", "HOLOUL_AI_DRIVER": "sandbox",
                               "HOLOUL_GEMINI_APPROVED": "false",
                               "HOLOUL_GEMINI_DOCUMENTS_APPROVED": "false",
                               "DB_SSLMODE": "verify-full", "REDIS_SCHEME": "tls",
                               "IDENTITY_MAIL_SANDBOX": "false", "MAIL_SCHEME": "smtps",
                               "APP_URL": "https://holoul.com.ly",
                               "APP_TRUSTED_HOSTS": "holoul.com.ly",
                               "APP_TRUSTED_PROXIES": "10.203.81.2"}.items():
                assert env[key] == value, "security configuration mismatch: "+key
            for key in ["APP_KEY", "DB_PASSWORD", "REDIS_PASSWORD", "MAIL_PASSWORD",
                        "DOCUMENTS_S3_ACCESS_KEY", "DOCUMENTS_S3_SECRET_KEY", "GEMINI_API_KEY",
                        "GEMINI_API_KEY_FILE"]:
                assert not env.get(key), "inline/provider credential forbidden"
            assert env["DB_USERNAME"] == ("holoul_migrator" if name == "migrate" else "holoul_app")
            assert service["read_only"] and "ALL" in service["cap_drop"]
            assert not any("clamav" in v["target"] for v in service["volumes"])
    ports = services["ingress"]["ports"]
    assert {(str(p["published"]), int(p["target"])) for p in ports} == {("80",8080),("443",443)}
    assert len(ports) == 2
    for name in ["backend", "web"]:
        assert plan["networks"][name]["internal"]
    for name in ["postgres", "redis", "storage"]:
        assert set(services[name]["networks"]) == {"backend"}
    for name in ["website", "dashboard"]:
        assert set(services[name]["networks"]) == {"web"}
        assert services[name]["environment"]["NODE_OPTIONS"] == "--max-old-space-size=256"
    assert services["website"]["environment"]["WEBSITE_DOCUMENT_UPLOADS_ENABLED"] == "false"
    assert total == 2888*1048576, "review changed resource budget"
    assert 3915.890625-total/1048576 >= 1024, "retain target reserve"
    return {"runtime_services": len(RUNTIME), "preparatory_services": 2,
            "runtime_limit_sum_mib": total//1048576,
            "target_reserve_mib": 3915.890625-total/1048576,
            "only_public_ports": [80,443], "passed": True}

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--env-file", required=True)
    args = parser.parse_args()
    data = subprocess.check_output(["docker","compose","--env-file",str(pathlib.Path(args.env_file).resolve()),
                                    "-f",str(ROOT/"compose.production.yaml"),"config","--format","json"], cwd=ROOT)
    result = check(json.loads(data))
    assert hashlib.sha256((ROOT/"docs/openapi.json").read_bytes()).hexdigest() == CONTRACT
    nginx = (ROOT/"deploy/production/nginx.conf").read_text()
    fastcgi = (ROOT/"deploy/production/fastcgi.conf").read_text()
    proxy = (ROOT/"deploy/production/proxy.conf").read_text()
    assert "proxy_set_header Origin" not in proxy
    assert "HTTP_ORIGIN" not in fastcgi and "Access-Control-Allow-Origin" not in nginx
    assert "ssl_reject_handshake on" in nginx
    assert "proxy_pass http://dashboard;" in nginx and "proxy_pass http://website;" in nginx
    result["contract_sha256"] = CONTRACT
    result["scope"] = "static only; SMTP, TLS, frontend readiness and VPS performance remain separate gates"
    print(json.dumps(result,indent=2))

if __name__ == "__main__":
    main()
