FROM wordpress:cli-php8.3

USER root
RUN apk add --no-cache git
USER 33:33
