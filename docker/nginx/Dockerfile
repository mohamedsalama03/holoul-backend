FROM nginx:1.30.5-alpine@sha256:73c75df4075c918f91017fdda46ad81e55e5af77ba3a64ca3d5014bd9244fe7f

# Reviewed fixes for CVE-2026-93990 and CVE-2026-103111.
RUN apk add --no-cache libexpat=2.8.5-r0 pcre2=10.49-r0
USER 101:101
