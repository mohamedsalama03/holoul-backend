# syntax=docker/dockerfile:1@sha256:ecfaec9ed6d810b56388c508f4121597bfbba70d41a6dfeee4d8cad5f295fc32
FROM python:3.14.7-alpine3.24@sha256:c6ead215bfd31f1e433d968853b7a769989117115b728874824e6c0a27cb96fc AS base
RUN apk upgrade --no-cache && apk add --no-cache libjpeg-turbo libwebp zlib
FROM base AS build
RUN apk add --no-cache build-base zlib-dev jpeg-dev libwebp-dev
COPY docker/portfolio/requirements.txt /tmp/requirements.txt
RUN python3 -m pip install --no-cache-dir --no-binary=pillow --require-hashes --no-deps -r /tmp/requirements.txt --target /opt/pillow
FROM base AS runtime
RUN apk add --no-cache libwebpdemux libwebpmux
COPY --from=build /opt/pillow/ /usr/local/lib/python3.14/site-packages/
RUN python3 -m pip uninstall --yes pip && rm -rf /usr/local/lib/python3.14/ensurepip \
    && addgroup -g 1000 holoul && adduser -D -u 1000 -G holoul holoul \
    && mkdir -p /run/holoul-portfolio && chown holoul:holoul /run/holoul-portfolio \
    && python3 -I -c 'from PIL import Image, features; assert features.check("webp"); assert Image.__version__ == "12.3.0"'
COPY docker/portfolio/processor.py /opt/holoul/processor.py
ENV PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1
USER 1000:1000
ENTRYPOINT ["python3", "-I", "/opt/holoul/processor.py"]
