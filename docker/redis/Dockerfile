FROM redis:8.2.9-alpine@sha256:30abb90e62f14b737010746def3ba99cc79fe19dcdb3d37b41f21fc62e7da19d

# Preserve Redis 8.2.9 and its entrypoint; patch the Alpine 3.22 packages
# identified by the B1 scan. Pin package revisions so builds fail on drift.
RUN apk add --no-cache --upgrade \
    libcrypto3=3.5.8-r0 \
    libssl3=3.5.8-r0 \
    setpriv=2.41.6-r1
