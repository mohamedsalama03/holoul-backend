# syntax=docker/dockerfile:1@sha256:ecfaec9ed6d810b56388c508f4121597bfbba70d41a6dfeee4d8cad5f295fc32
FROM nginx:1.30.5-alpine@sha256:73c75df4075c918f91017fdda46ad81e55e5af77ba3a64ca3d5014bd9244fe7f
# Keep the reviewed Nginx version/configuration and enforce the security-fixed OS library.
RUN apk upgrade --no-cache && apk add --no-cache 'libexpat>=2.8.5-r0'
