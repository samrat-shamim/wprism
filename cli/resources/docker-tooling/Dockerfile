FROM wordpress:cli-2.12.0-php8.3@sha256:2b5e9d4d3e51909dca1aaa4732e9f5e5bf0377c2114dbd8ff39f060bff202586

USER root

# The base digest currently resolves to Alpine 3.24.1. Exact package versions
# keep the controller-built helper reviewable; an unavailable pin must stop the
# build instead of silently selecting newer host tooling.
RUN apk add --no-cache \
        git=2.54.0-r0 \
        git-lfs=3.7.1-r1 \
    && git lfs install --system --skip-repo

LABEL io.wprism.managed="docker-tooling" \
      io.wprism.toolchain="wordpress-cli-2.12.0_php-8.3_git-2.54.0_git-lfs-3.7.1"

USER www-data
