# syntax=docker/dockerfile:1@sha256:ecfaec9ed6d810b56388c508f4121597bfbba70d41a6dfeee4d8cad5f295fc32
FROM python:3.14.7-alpine3.24@sha256:c6ead215bfd31f1e433d968853b7a769989117115b728874824e6c0a27cb96fc AS base
RUN apk upgrade --no-cache && apk add --no-cache libstdc++ libgcc zlib libjpeg-turbo openssl

FROM base AS builder
RUN --mount=type=cache,id=holoul-apk-v3.24-v1,target=/var/cache/apk,sharing=locked \
    apk --cache-dir /var/cache/apk add build-base cmake zlib-dev libjpeg-turbo-dev openssl-dev curl
RUN curl --fail --location --retry 3 https://github.com/qpdf/qpdf/releases/download/v12.4.1/qpdf-12.4.1.tar.gz -o /tmp/qpdf.tar.gz \
    && echo 'f045aa277be2356ff53a89a8622945958291177d2483afc20ede7c8a8cd3873c  /tmp/qpdf.tar.gz' | sha256sum -c - \
    && mkdir /src && tar -xzf /tmp/qpdf.tar.gz -C /src --strip-components=1 \
    && cmake -S /src -B /build -DCMAKE_BUILD_TYPE=Release -DCMAKE_INSTALL_PREFIX=/opt/qpdf \
       -DBUILD_SHARED_LIBS=ON -DBUILD_STATIC_LIBS=OFF -DBUILD_TESTING=OFF -DINSTALL_EXAMPLES=OFF -DINSTALL_MANUAL=OFF \
       -DREQUIRE_CRYPTO_OPENSSL=ON -DDEFAULT_CRYPTO=openssl \
    && cmake --build /build -j2 && cmake --install /build

FROM base AS runtime
# Offline PDF text extraction remains inside the constrained, networkless worker.
RUN apk add --no-cache poppler-utils=25.12.0-r1
COPY --from=builder /opt/qpdf/bin/qpdf /usr/local/bin/qpdf
COPY --from=builder /opt/qpdf/lib/ /usr/local/lib/
# Inspection uses only the standard library. Remove pip's vendored dependencies
# and the optional offline installer wheel, not merely their vulnerability metadata.
RUN python3 -m pip uninstall --yes pip \
    && rm -rf /usr/local/lib/python3.14/ensurepip \
    && python3 -I -c 'import importlib.util; assert all(importlib.util.find_spec(name) is None for name in ("pip", "ensurepip", "setuptools", "msgpack"))'
RUN addgroup -g 1000 holoul && adduser -D -u 1000 -G holoul holoul \
    && mkdir -p /run/holoul-inspector && chown holoul:holoul /run/holoul-inspector \
    && qpdf --version | grep -q '12.4.1' \
    && pdftotext -v 2>&1 | grep -q '25.12.0'
COPY docker/documents/inspector/inspect.py /opt/holoul/inspect.py
ENV PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1
USER 1000:1000
ENTRYPOINT ["python3", "-I", "/opt/holoul/inspect.py"]
