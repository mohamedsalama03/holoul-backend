FROM golang:1.26.8-alpine3.24@sha256:ce864e7223ac17b1775e6fd0b4c0db580c2eb50e7953a427916379e4b92a1628 AS builder

# SeaweedFS 4.47, commit c5073360007d28385a33426a42ac3e4ec504c5a3.
# The current upstream image embeds grpc v1.85.0-dev (CVE-2026-84445).
# Rebuild real code with the exact upstream fix; retain the release's empty
# build tags, CGO setting, Go patch version and SeaweedFS version identifier.
ADD --checksum=sha256:f2ac1ac2cbd4e72a135605b90f5369eb548303e2e60e376bf43cd49a59ddb0ec https://codeload.github.com/seaweedfs/seaweedfs/tar.gz/refs/tags/4.47 /tmp/seaweedfs.tar.gz
RUN mkdir /src /out \
    && tar -xzf /tmp/seaweedfs.tar.gz --strip-components=1 -C /src \
    && rm /tmp/seaweedfs.tar.gz
WORKDIR /src
ENV CGO_ENABLED=0 GOTOOLCHAIN=local GOPROXY=https://proxy.golang.org GOSUMDB=sum.golang.org GOMAXPROCS=2
RUN --mount=type=cache,target=/go/pkg/mod \
    --mount=type=cache,target=/root/.cache/go-build \
    cp go.mod /out/go.mod.upstream \
    && go get google.golang.org/grpc@v1.85.0-dev.0.20260825072537-93e31b48545e \
    && grep -Fx 'google.golang.org/grpc v1.85.0-dev.0.20260825072537-93e31b48545e h1:ar+feAPij1Znzug45m6AoShUCc5AaR8FLA3I6LZd94k=' go.sum \
    && go mod verify \
    && go build -mod=readonly -trimpath -p=2 -ldflags="-extldflags -static -X github.com/seaweedfs/seaweedfs/weed/util/version.COMMIT=c50733600" -o /out/weed ./weed \
    && go version -m /out/weed > /out/build-info.txt \
    && cp go.mod go.sum /out/

FROM chrislusf/seaweedfs:4.47@sha256:ce9e796f1fe6f06968f4c04bdaf8f678dad9c8acdfef3d244133d71bfa6bf882
USER root
RUN apk upgrade --no-cache
COPY --from=builder /out/weed /usr/bin/weed
COPY --from=builder /out/build-info.txt /out/go.mod /out/go.mod.upstream /out/go.sum /usr/share/doc/holoul-storage/
COPY docker/documents/storage/filer.toml /etc/seaweedfs/filer.toml
USER 1000:1000
ENTRYPOINT ["/usr/bin/weed"]
