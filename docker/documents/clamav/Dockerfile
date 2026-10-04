FROM clamav/clamav:1.5.4_base@sha256:9b76cb83d965f66556bd75ac3d16955dc89f601830bd08a30c29167bde76598b
USER root
RUN apk upgrade --no-cache \
    && apk add --no-cache pcre2=10.49-r0 \
    && addgroup -g 1000 holoul && adduser -D -u 1000 -G holoul holoul \
    && mkdir -p /run/holoul-clamav /var/lib/clamav \
    && chown -R 1000:1000 /run/holoul-clamav /var/lib/clamav
COPY docker/documents/clamav/clamd.conf /etc/clamav/clamd.conf
COPY docker/documents/clamav/freshclam.conf /etc/clamav/freshclam.conf
USER 1000:1000
ENTRYPOINT ["clamd", "--foreground=true", "--config-file=/etc/clamav/clamd.conf"]
