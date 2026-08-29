#  live-fixture control image.
#
# The stock `wordpress:cli-php8.3` image deliberately has no Git binary,
# while the public Refresh path proves the production site's checked-out
# commit through its configured driver.  This narrowly adds Git (and bash,
# which DockerTransport uses for raw control) without changing the pair's
# WordPress runtime image or mounting one site's checkout into the other.
FROM wordpress:cli-php8.3

USER root
RUN if command -v apk >/dev/null 2>&1; then \
      apk add --no-cache bash git; \
    else \
      apt-get update \
      && apt-get install -y --no-install-recommends bash git \
      && rm -rf /var/lib/apt/lists/*; \
    fi \
    && git config --system --add safe.directory /siterepo

USER 33:33
