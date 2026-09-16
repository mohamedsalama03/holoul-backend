FROM postgres:18.6-alpine3.24@sha256:d3e1620b530c944afa6e887d22eb899824da68e19c52024bf98f5220c88a65b2

# Keep PostgreSQL and its upstream initialization/privilege-drop behavior.
# Replace the bundled gosu (old embedded Go runtime) with Alpine's maintained
# rebuild and pin the security fixes identified by the B1 image scan.
RUN apk add --no-cache --upgrade \
    libcrypto3=3.5.8-r0 \
    libssl3=3.5.8-r0 \
    libuuid=2.42.3-r1 \
    gosu=1.19-r5 \
    && rm /usr/local/bin/gosu \
    && test "$(command -v gosu)" = /usr/bin/gosu \
    && gosu --version
